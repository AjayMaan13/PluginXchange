# PluginXchange

Mini reseller marketplace API: PHP 8.3 + MySQL 8 + JSON. See [BUILD_PLAN.md](BUILD_PLAN.md) for the phased plan.

## Quickstart

```bash
make up            # docker compose up -d --build (first start installs composer deps)
curl localhost:8080/          # {"status":"ok"}
curl localhost:8080/health    # {"status":"ok","db":"up"}
make test          # PHPUnit inside the app container
make lint          # phpcs (PSR-12) + phpstan (level 5)
make seed          # wipe the DB volume and reload schema.sql + seed.sql
```

MySQL is also exposed on `localhost:3307` (user/password/db: `pluginxchange`).

## API

Every request except `/` and `/health` needs an `X-API-Key` header. Errors always look like
`{"error": {"code", "message", "request_id"}}`, and every response carries an `X-Request-Id` header.

| Endpoint | Access | Notes |
|---|---|---|
| `GET /api/v1/products` | any valid key | active products; filters `category`, `vendor_id`; `page`, `per_page` (max 100) |
| `GET /api/v1/products/{id}` | any valid key | includes `available_keys` |
| `POST /api/v1/orders` | reseller key | body `{"product_id", "quantity" (1-100), "idempotency_key"?}`; 201, or 200 for an idempotent retry; 409 if out of stock |
| `GET /api/v1/orders/{id}` | reseller (own orders) or admin | includes the license `keys` |
| `POST /api/v1/orders/{id}/cancel` | admin key | fulfilled orders only; keys are revoked (never resold); 409 if already cancelled |
| `GET /api/v1/resellers/{id}/orders` | reseller (own) or admin | newest first; `page`, `per_page` |
| `GET /api/v1/support/orders/{id}/trace` | admin key | the order, its keys' status and every audit event, oldest first |
| `POST /api/v1/catalog/import` | admin key | bulk-load a vendor's catalog (below); max 2 MB and 1000 products |
| `POST /api/v1/resellers` | admin key | body `{"name", "country"}`; the new API key is returned once |

```bash
curl -H 'X-API-Key: pxc_local_reseller_1_key' 'localhost:8080/api/v1/products?category=synth&per_page=5'
curl -H 'X-API-Key: pxc_local_reseller_1_key' localhost:8080/api/v1/products/1
curl -X POST -H 'X-API-Key: pxc_local_reseller_1_key' -d '{"product_id":12,"quantity":3,"idempotency_key":"abc"}' localhost:8080/api/v1/orders
curl -X POST -H 'X-API-Key: pxc_local_admin_key' -d '{"name":"My Shop","country":"CA"}' localhost:8080/api/v1/resellers
```

### Catalog import

```bash
curl -X POST -H 'X-API-Key: pxc_local_admin_key' --data-binary @tests/fixtures/partial-bad.json localhost:8080/api/v1/catalog/import
```

Body: `{"vendor": "...", "products": [{"sku", "name", "category", "price_cents", "keys": [...]}]}`. Products are upserted by SKU
(the vendor is created if new) and keys that already exist are skipped, so re-running a file changes nothing. Each product is
its own transaction. Bad rows are rejected with reasons and the rest still import:
`{"data": {"products_created", "products_updated", "keys_added", "rejected": [{"index", "sku", "errors": [...]}]}}`.
Keys are 4-64 characters (letters, digits, `_`, `-`); a key that already belongs to another product rejects that product.

See [docs/troubleshooting.md](docs/troubleshooting.md) for support scenarios.

The local admin key is set in `docker-compose.yml` (`ADMIN_API_KEY`).

## Seed data (local use only)

3 vendors, 12 products, 353 license keys, 2 resellers.

| Reseller | Plain API key (`X-API-Key`) |
|---|---|
| 1 Harmony Music Store | `pxc_local_reseller_1_key` |
| 2 Beatline Distribution | `pxc_local_reseller_2_key` |

Only the SHA-256 hash is stored in the DB. Special products: #8 has 3 keys (out-of-stock tests), #10 is inactive, #12 has 100 keys (load test).
