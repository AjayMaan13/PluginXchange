# Test cases

What is tested, how, and whether it is automated. Status is from the last full run (2026-11-13): PHPUnit, `dotnet test`,
`make loadtest` and `make integrity` all green, plus the manual cases below.

Local keys: reseller `pxc_local_reseller_1_key`, admin `pxc_local_admin_key` (see the README).

## How to run

| What | Command |
|---|---|
| PHP unit + integration tests | `make test` |
| PHP coverage (pcov) | `make coverage` |
| Lint and static analysis | `make lint` |
| .NET client tests | `make dotnet-test` |
| Concurrency / load test | `make loadtest` (runs `make integrity` afterwards) |
| Database integrity checks | `make integrity` |
| End-to-end smoke | `make smoke` |

## Cases

Automated cases name the test class that covers them.

| ID | Area | Scenario | Steps | Expected | Automated | Status |
|---|---|---|---|---|---|---|
| AUTH-01 | Auth | Public routes need no key | `GET /` and `GET /health` without a key | 200 | Y (ApiTest) | Pass |
| AUTH-02 | Auth | Missing key | `GET /api/v1/products` without `X-API-Key` | 401 `UNAUTHORIZED` | Y (ApiTest) | Pass |
| AUTH-03 | Auth | Unknown key | Same request with a made-up key | 401 `UNAUTHORIZED` | Y (ApiTest) | Pass |
| AUTH-04 | Auth | Reseller key on an admin route | `POST /api/v1/orders/{id}/cancel`, `POST /api/v1/catalog/import`, `POST /api/v1/resellers` with a reseller key | 403 `FORBIDDEN` | Y (ApiTest, SupportOpsTest, ImportTest) | Pass |
| HTTP-01 | HTTP core | Unknown route and wrong method | `GET /nope`, `DELETE /api/v1/products` | 404, then 405 | Y (ApiTest, RouterTest) | Pass |
| HTTP-02 | HTTP core | Unexpected failure never leaks details | Force an exception inside a handler | 500 `INTERNAL_ERROR`, generic message, `request_id` present, no stack trace | Y (ApiTest) | Pass |
| HTTP-03 | HTTP core | Every error has the standard shape | Trigger any error (for example 401) | `{"error": {"code", "message", "request_id"}}`, `request_id` is 16 hex characters and equals the `X-Request-Id` header | Y (ApiTest) | Pass |
| HTTP-04 | HTTP core | Database is down | `docker compose stop db`, then `GET /health` and an authenticated call | `/health` 503 `{"status":"degraded","db":"down"}`, API call 500 generic `INTERNAL_ERROR`; both recover after `docker compose start db` | N (manual) | Pass |
| HTTP-05 | HTTP core | Request parsing | Method, path (trailing slash dropped), query and headers from the PHP globals; JSON bodies that are empty, a list, a string, `null` or broken | Parsed values; 400 `BAD_JSON` for anything that is not an object | Y (RequestTest) | Pass |
| CAT-01 | Catalog | List active products | `GET /api/v1/products` | 200, only active products | Y (ApiTest) | Pass |
| CAT-02 | Catalog | Filter by category | `?category=synth` | Only synth products | Y (ApiTest) | Pass |
| CAT-03 | Catalog | Invalid filters | `?category=toaster`, `?vendor_id=abc`, `?page=0` | 422 `VALIDATION_FAILED` | Y (ApiTest, PaginationTest) | Pass |
| CAT-04 | Catalog | Pagination | `?page=2&per_page=5`, `per_page=500` | Correct slice and `meta`; `per_page` capped at 100 | Y (ApiTest, PaginationTest) | Pass |
| CAT-05 | Catalog | Product detail | `GET /api/v1/products/12` | 200 with `available_keys` | Y (ApiTest) | Pass |
| CAT-06 | Catalog | Unknown product | `GET /api/v1/products/99999` | 404 `NOT_FOUND` | Y (ApiTest) | Pass |
| CAT-07 | Catalog | Page number beyond the limit | `?page=9223372036854775807` on the products list and on a reseller's history | 422 `VALIDATION_FAILED` "page must be at most 1000000", not a 500 (found in the Phase 7 review) | Y (PaginationTest, ApiTest, SupportOpsTest) | Pass |
| RES-01 | Resellers | Create a reseller | `POST /api/v1/resellers` as admin | 201, `api_key` returned once; only its SHA-256 hash is stored | Y (ApiTest) | Pass |
| RES-02 | Resellers | Validation and bad JSON | Missing name, bad country, broken JSON | 422 / 400 | Y (ApiTest) | Pass |
| ORD-01 | Orders | Happy path | Reseller orders 2 keys of a product | 201 `fulfilled`, 2 distinct keys, keys marked sold, audit events written | Y (OrderTest) | Pass |
| ORD-02 | Orders | Out of stock | Order more keys than available | 409 `INSUFFICIENT_STOCK`, no key sold, a `failed` order and `order.failed` audit event recorded | Y (OrderTest) | Pass |
| ORD-03 | Orders | Invalid body | `quantity` 0, -1, 101 or `"2"` (string); missing `product_id` or `quantity`; empty `idempotency_key` | 422 `VALIDATION_FAILED`, no key sold | Y (OrderTest) | Pass |
| ORD-04 | Orders | Missing or inactive product | Order a non-existent and an inactive product | 404, 422 | Y (OrderTest) | Pass |
| ORD-05 | Orders | Admin key cannot order | `POST /api/v1/orders` with the admin key | 403 | Y (OrderTest) | Pass |
| ORD-06 | Orders | Idempotent retry | Send the same `idempotency_key` twice | Second call 200, same order and keys, no extra stock sold | Y (OrderTest) | Pass |
| ORD-07 | Orders | Read access | Reseller B reads reseller A's order; admin reads it | 403 for B, 200 for admin | Y (OrderTest) | Pass |
| ORD-08 | Orders | Failure mid-transaction | Pass an oversized request id so the audit insert fails after the keys were marked sold | Everything rolled back: no key sold, no order row, no open transaction | Y (OrderTest) | Pass |
| ORD-09 | Orders | Idempotency key length counts characters | Key of 64 `é` (128 bytes), then 65 characters | 201, then 422 | Y (OrderTest) | Pass |
| ORD-10 | Orders | Keys exist but another in-flight order holds locks on some | A second connection locks 3 of 5 keys, the order asks for 3, the locks are released while it waits | The order retries and is `fulfilled`, no failed order recorded | Y (OrderTest) | Pass |
| ORD-11 | Orders | Real shortage does not wait | Ask for 8 keys when only 5 exist | 409 at once, no retry, one failed order recorded | Y (OrderTest) | Pass |
| ORD-12 | Orders | Retries are bounded | Another connection holds its locks for good | 5 attempts (4 pauses), then 409 `INSUFFICIENT_STOCK` and one failed order recorded | Y (OrderTest) | Pass |
| SUP-01 | Support | Cancel a fulfilled order | `POST /api/v1/orders/{id}/cancel` as admin | 200 `cancelled`, keys `revoked` (never resold), audit events | Y (SupportOpsTest) | Pass |
| SUP-02 | Support | Cancel twice | Cancel the same order again | 409 `ALREADY_CANCELLED` | Y (SupportOpsTest) | Pass |
| SUP-03 | Support | Cancel rejects bad targets | Failed order, missing order, reseller key | 409 `NOT_CANCELLABLE`, 404, 403 | Y (SupportOpsTest) | Pass |
| SUP-04 | Support | Reseller order history | `GET /api/v1/resellers/{id}/orders?page=1&per_page=2` | Newest first, paginated, no keys | Y (SupportOpsTest) | Pass |
| SUP-05 | Support | History access rules | Reseller reads another reseller's history; admin reads it; unknown reseller; `per_page=0` | 403, 200, 404, 422 | Y (SupportOpsTest) | Pass |
| SUP-06 | Support | Trace of a failed order | `GET /api/v1/support/orders/{id}/trace` | Order, no keys, `order.requested` then `order.failed` | Y (SupportOpsTest) | Pass |
| SUP-07 | Support | Trace of a cancelled order | Place, cancel, trace | Full life in order: requested, fulfilled, key.sold, cancelled, key.revoked | Y (SupportOpsTest) | Pass |
| SUP-08 | Support | Trace access | Reseller key; unknown order | 403, 404 | Y (SupportOpsTest) | Pass |
| IMP-01 | Import | New vendor | Import `valid.json` | 200: 3 products and 7 keys created, vendor created, one audit event | Y (ImportTest) | Pass |
| IMP-02 | Import | Re-run is a no-op | Import `valid.json` twice | Second run reports 0 changes and the database is identical (row counts, checksum, audit) | Y (ImportTest) | Pass |
| IMP-03 | Import | Update existing | Change a price and add a key, re-import | `products_updated` 1, only the new key added | Y (ImportTest) | Pass |
| IMP-04 | Import | Partial rejection | Import `partial-bad.json` | 200, 2 products created, rejected rows 1-4 and 6 with reasons, good rows still imported | Y (ImportTest) | Pass |
| IMP-05 | Import | Key owned by another product | Import `duplicate-keys.json` | Only that product is rejected | Y (ImportTest) | Pass |
| IMP-06 | Import | SKU owned by another vendor | Import a SKU that exists under another vendor | Row rejected | Y (ImportTest) | Pass |
| IMP-07 | Import | Malformed JSON | Import `malformed.json` | 400 | Y (ImportTest) | Pass |
| IMP-08 | Import | Limits | Body over 2,000,000 bytes; more than 1000 products | 413 `PAYLOAD_TOO_LARGE`; 422 | Y (ImportTest) | Pass |
| IMP-09 | Import | Missing vendor or products | `{}` | 422 | Y (ImportTest) | Pass |
| IMP-10 | Import | Imported products are visible | Import, then `GET /api/v1/products` | New products listed | Y (ImportTest) | Pass |
| IMP-11 | Import | Body above nginx's 2m limit | `curl --data-binary @2.5MB-file` through nginx on :8080 | nginx's own HTML 413 (known caveat, not the JSON format) | N (manual, Phase 5) | Pass |
| VAL-01 | Validator | Every import rule | File-level checks, SKU, name, category, price, key format and length, per-product key cap, duplicate keys, duplicate SKUs, all errors of a row reported together | Matching error message for each | Y (ImportValidatorTest, 15 tests) | Pass |
| INT-01 | Integrity | Healthy database | Orders, a failed order, a cancel and three imports, then run the checks | 0 violations | Y (IntegrityChecksTest, ImportTest) | Pass |
| INT-02 | Integrity | Each check catches its corruption | Insert one corrupt row per check | All 6 checks report it | Y (IntegrityChecksTest) | Pass |
| INT-03 | Integrity | `make integrity` fails on a violation | Set a key to `sold` with a missing order, run `make integrity` | Prints the violation, exit code 1; exit 0 after the repair | N (manual) | Pass |
| CON-01 | Concurrency | No key sold twice | `make loadtest`: 50 parallel orders of 3 against 100 keys, 10 runs | 0 duplicate keys in every run | Y (loadtest) | Pass |
| CON-02 | Concurrency | Every request accounted for | Same run | fulfilled + failed = 50, 0 errors | Y (loadtest) | Pass |
| CON-03 | Concurrency | Nothing oversold | Same run | Keys handed out never above the stock, and stock afterwards = before - handed out | Y (loadtest) | Pass |
| CON-04 | Concurrency | No false out-of-stock | Stock that exactly fits all orders (`--keys 150`) | All 50 orders fulfilled | Y (loadtest) | Pass |
| CON-05 | Concurrency | Idempotent replay | Replay one finished order's key | Same order and keys, stock unchanged | Y (loadtest) | Pass |
| CON-06 | Concurrency | Same key, 10 requests at once | Fire 10 identical requests in parallel | One order, same keys for all, stock down once | Y (loadtest) | Pass |
| CON-07 | Concurrency | Integrity after load | `make integrity` after the load test | 0 rows | Y (`make loadtest`, CI) | Pass |
| CON-08 | Concurrency | No false out-of-stock at the tail of the stock | `make loadtest` against the production image (Apache): after each run, if any order failed, fewer keys than the quantity must be left | 0 violations in 90 runs after the retry fix (about 1 run in 10 failed before it) | Y (loadtest) | Pass |
| NET-01 | .NET client | JSON models | Parse order, product, product page, trace | Fields mapped from snake_case | Y (PluginXchangeClientTests, 4 tests) | Pass |
| NET-02 | .NET client | API key header | Two requests through a fake handler | `X-API-Key` on both | Y (PluginXchangeClientTests) | Pass |
| NET-03 | .NET client | Error mapping | 401, 409, 422 and a non-JSON body | `UnauthorizedApiException`, `ConflictApiException`, `ValidationApiException`, `ApiException` `HTTP_413` | Y (PluginXchangeClientTests, 4 tests) | Pass |
| NET-04 | .NET client | Retry keeps the idempotency key | 503, network error, then success | 3 requests, same idempotency key and body | Y (PluginXchangeClientTests) | Pass |
| NET-05 | .NET client | No retry on client errors; retries are capped | 409 / 422; endless 502 | One request; stops after `MaxRetries` | Y (PluginXchangeClientTests, 2 tests) | Pass |
| NET-06 | .NET client | Idempotency key generated | Place an order without a key | 32-character key sent | Y (PluginXchangeClientTests) | Pass |
| OPS-01 | Smoke | End-to-end smoke | `make smoke` | health, list, place and fetch one order all PASS, exit 0 | N (manual and pipeline) | Pass |
| OPS-02 | Release image | Production image contents | Build the `production` target, run it, inspect it | Runs as `www-data` (not root), no `tests/` or `vendor/phpunit`, no `X-Powered-By` header, `composer.json`, `src/` and `vendor/` not reachable over HTTP, health check turns `healthy` | N (manual here, the release workflow checks non-root and dev files) | Pass |
| OPS-03 | Release image | API works through Apache | Run the image against the dev database, then `make smoke` and `make loadtest` with the image's URL | Routing, 401/404/405 JSON errors, smoke OK, load test 0 duplicates and 0 violations | N (manual here, the release workflow runs smoke and a short load test) | Pass |
