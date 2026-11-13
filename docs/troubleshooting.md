# Troubleshooting

Each scenario: symptom, how to confirm it with the API, SQL to check, and the fix. The admin key and
`localhost:8080` below are the local Docker defaults.

## 1. Order shows `fulfilled` but the reseller has no key

**Symptom:** a reseller says order 501 was fulfilled but they did not receive license keys.

**1. Trace the order** (admin only). It shows the order, the state of every key attached to it, and each audit event with the request id:

```bash
curl -H 'X-API-Key: pxc_local_admin_key' localhost:8080/api/v1/support/orders/501/trace
```

Read the result:
- `order.status` is `fulfilled` and `keys` has `quantity` entries with status `sold`: the system did its job. Ask the reseller to fetch the keys again with `GET /api/v1/orders/501` (their own key). The problem is on their side (for example they lost the response and did not retry with the same `idempotency_key`).
- `keys` is empty or shorter than `quantity`: the order and its keys are out of sync. Go to step 2.
- `keys` shows `revoked`, and `events` has `order.cancelled`: the order was cancelled afterwards. The `request_id` on that event shows which call did it.
- `order.status` is `cancelled` or `failed`: the reseller is looking at the wrong order. `failure_reason` says why.

**2. Check the database** (`mysql -h127.0.0.1 -P3307 -upluginxchange -ppluginxchange pluginxchange`):

```sql
-- Fulfilled orders whose key count does not match the quantity
SELECT o.id, o.quantity, COUNT(k.id) AS keys_found
FROM orders o
LEFT JOIN license_keys k ON k.order_id = o.id AND k.status = 'sold'
WHERE o.status = 'fulfilled'
GROUP BY o.id, o.quantity
HAVING keys_found <> o.quantity;

-- Everything attached to one order
SELECT id, key_value, status FROM license_keys WHERE order_id = 501;
```

**3. Fix.** Orders are written in one transaction, so a mismatch should never happen. If it does, treat it as a bug and keep the `request_id` from the trace for the investigation. To make the reseller whole, cancel the order (`POST /api/v1/orders/501/cancel`, admin) and ask them to place it again.

## 2. `make integrity` reports violations

**Symptom:** `make integrity` (or the CI step) prints `VIOLATION <check_name> id=<n> <detail>` and exits 1. The script runs
[database/integrity-checks.sql](../database/integrity-checks.sql). Every query must return zero rows.

| Check | Meaning | Look at |
|---|---|---|
| `sold_key_without_order` | a key is `sold` but its `order_id` points at no order | the key's id in the output, `license_keys` |
| `fulfilled_order_key_count_mismatch` | a fulfilled order has a different number of sold keys than its `quantity` | the order's trace (scenario 1) |
| `order_total_mismatch` | `total_cents` is not `quantity * unit_price_cents` | `orders` row, then the code path that wrote it |
| `sold_key_on_unfulfilled_order` | a key is still `sold` on a cancelled or failed order (it should be `revoked`) | the order's trace, `order.cancelled` event |
| `available_key_with_order` | an `available` key still references an order | `license_keys` row |
| `key_product_mismatch` | a key is attached to an order for a different product | `license_keys` and `orders` rows |

**1. Find the order.** The `detail` column names it. Trace it with
`GET /api/v1/support/orders/{id}/trace` (admin). The events show which request touched it, and the `request_id` finds the same request in the app log.

**2. Decide if it is data or a bug.** Rows edited by hand in SQL are the usual cause locally. Rows written by the API are not supposed to
break any check, because orders, cancels and imports each run in one transaction. If the API produced it, keep the `request_id` and treat it as a bug.

**3. Repair.** Fix the single row with a targeted `UPDATE` (for example set the key back to `available` and clear `order_id`), then run `make integrity` again until it prints `Integrity OK`.

## 3. A retry gets `409` (or the reseller was charged twice)

**Symptom:** a reseller's request timed out, they retried, and the retry returned `409 INSUFFICIENT_STOCK`. Or they say they received keys twice.

**What happened:** the first request probably succeeded and only the response was lost. What the retry does depends on `idempotency_key`:
- **Same `idempotency_key` sent again:** the API returns the original order with `200` and sells nothing more. This is the safe path.
- **No key, or a new key on each try:** the retry is a new order. It either buys again (a second fulfilled order) or, when the stock ran out in between, fails with `409`.

**1. Look at the reseller's recent orders** (reseller or admin key):

```bash
curl -H 'X-API-Key: pxc_local_admin_key' 'localhost:8080/api/v1/resellers/2/orders?per_page=10'
```

Two `fulfilled` orders for the same product and quantity a few seconds apart point to a retry without an idempotency key. A `failed` order next to a `fulfilled` one is the 409 case.

**2. Find all such pairs in SQL:**

```sql
SELECT product_id, quantity, COUNT(*) AS orders, MIN(created_at) AS first, MAX(created_at) AS last
FROM orders
WHERE reseller_id = 2 AND status = 'fulfilled'
GROUP BY product_id, quantity
HAVING orders > 1;
```

**3. Fix.** If the duplicate is unwanted, cancel it (`POST /api/v1/orders/{id}/cancel`, admin). Its keys are revoked and never resold. Tell the reseller to send a stable `idempotency_key` per purchase and reuse the same one on every retry (the .NET client in `tools/` does this automatically).

## 4. An import reports rejected rows

**Symptom:** `POST /api/v1/catalog/import` returned `200`, but `products_created` is lower than the number of products in the file.

A `200` does not mean every row was imported. Read `data.rejected`: each entry has the row's position in `products` (`index`, counting from 0), its `sku` (null if the row was not an object) and a list of `errors`.

| Error | Cause and fix |
|---|---|
| `price_cents must be an integer between 1 and 10000000` | the price is a decimal, a string, zero or too large. Send whole cents |
| `category must be one of: ...` | fix the spelling to one of the listed values |
| `keys[n] must be 4-64 letters, digits, _ or -` | a key has a space, other punctuation or the wrong length |
| `duplicate key X (already used at index n)` / `duplicate sku` | the same key or SKU appears twice in the file. The later row is rejected |
| `sku X belongs to another vendor` | that SKU is already in the catalog under a different vendor name |
| `key X already belongs to another product` | a key is already stored for a different product. Check with `SELECT p.sku FROM license_keys k JOIN products p ON p.id = k.product_id WHERE k.key_value = 'X'` |
| `could not be imported because of a database error` | a database error. The cause is in the server log under the response's `X-Request-Id` (scenario 6) |

**Fix:** correct only the rejected rows and import the file again. Re-running is safe: products that already imported are unchanged and their keys are skipped, so only the fixed rows are added. Whole-file problems (`400` malformed JSON, `413` over 2,000,000 bytes, `422` missing `vendor` or `products`, or more than 1000 products) import nothing.

## 5. `401` or `403`?

**Symptom:** a client says "my key does not work".

| Status | Meaning | Check |
|---|---|---|
| `401 UNAUTHORIZED`, "Missing X-API-Key header" | the header is absent or empty | the client is sending `X-API-Key` (not `Authorization`) and a proxy is not stripping it |
| `401 UNAUTHORIZED`, "Invalid API key" | the key is unknown | the exact string, with no spaces or newline. Keys are only shown once at creation: check `SELECT id, name FROM resellers WHERE api_key_hash = SHA2('the-key', 256)` |
| `403 FORBIDDEN`, "Admin key required" | the key is valid but the route is admin only (cancel, import, trace, create reseller) | use the admin key (`ADMIN_API_KEY` in the environment) |
| `403 FORBIDDEN`, "You can only read your own orders" | a reseller asked for another reseller's order or history | use the right key, or the admin key |
| `403 FORBIDDEN`, "Orders must be placed with a reseller key" | the admin key tried to place an order | use a reseller key |

Rule of thumb: 401 means "who are you?" (the API does not know the key) and 403 means "I know who you are, but you may not do this".

## 6. `500 INTERNAL_ERROR` or `/health` says `degraded`

**Symptom:** a request returns `500` with the message "Internal server error", or `GET /health` returns `503`.

The `500` body is deliberately generic. The cause is in the server log, tagged with the same `request_id` that is in the response body and the `X-Request-Id` header:

```bash
docker compose logs app | grep 61d5a4a137a284ca
# NOTICE: PHP message: [61d5a4a137a284ca] TypeError: PluginXchange\Http\Pagination::offset(): Return value must be of type int, float returned
```

**Worked example (fixed in Phase 7).** `GET /api/v1/products?page=9223372036854775807` returned a 500. The log line shows `(page - 1) * per_page` overflowed the integer type. The fix is to cap `page` at 1,000,000, so the request now returns `422 VALIDATION_FAILED`, and a test covers it. Found by sending extreme values, not by the test suite: that is why the self-review does it.

**Database down.** If the logs show `SQLSTATE[HY000] [2002]` (connection refused) or the `/health` body is `{"status": "degraded", "db": "down"}`:

```bash
docker compose ps                  # is db up and healthy?
docker compose logs db --tail 50   # why did it stop?
docker compose start db            # then: curl localhost:8080/health
```

The app opens its database connection per request, so it starts without the database and recovers on its own once the database is back, with no restart. Check `DB_HOST`, `DB_PORT`, `DB_USER` and `DB_PASS` in the app's environment if the database is up but the app still cannot reach it (inside Docker the host is `db`, not `localhost`).
