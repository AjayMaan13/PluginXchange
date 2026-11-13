# API reference

Base URL locally: `http://localhost:8080`. All bodies are JSON. Examples use the local seed keys from the README
(`pxc_local_reseller_1_key`, `pxc_local_admin_key`). Every `curl` below works against a fresh `make up`.

## Conventions

**Authentication.** Send `X-API-Key: <key>` on every request except `GET /` and `GET /health`. A route is open to one of:

| Access | Who can call it |
|---|---|
| public | anyone, no key |
| reseller | any valid key, reseller or admin |
| admin | the admin key only |

**Errors.** Every error has the same shape, and the same `request_id` is in the `X-Request-Id` response header. Quote it to
support: it finds the order's audit events and the server log line.

```json
{"error": {"code": "INSUFFICIENT_STOCK", "message": "Only 3 license keys available, 5 requested", "request_id": "bd4fe5648b34fdea"}}
```

**Lists** take `page` (1 to 1,000,000, default 1) and `per_page` (1 to 100, default 20; larger values are capped at 100) and
return `{"data": [...], "meta": {"page", "per_page", "total"}}`.

**Money** is always an integer number of cents (`price_cents`, `unit_price_cents`, `total_cents`). See ADR 0003.

**Timestamps** in `created_at` are `YYYY-MM-DD HH:MM:SS` from the database clock (UTC in the Docker setup). Trace event times (`at`) are the same clock as ISO 8601 with a trailing `Z`.

### Error codes

| HTTP | Code | When |
|---|---|---|
| 400 | `BAD_JSON` | body is not a valid JSON object (a list, a string and broken JSON all count) |
| 401 | `UNAUTHORIZED` | `X-API-Key` missing or not a known key |
| 403 | `FORBIDDEN` | valid key without permission: reseller key on an admin route, reading someone else's order or history, admin key placing an order |
| 404 | `NOT_FOUND` | unknown route, product, order or reseller |
| 405 | `METHOD_NOT_ALLOWED` | the path exists but not for this HTTP method |
| 409 | `INSUFFICIENT_STOCK` | fewer license keys available than the quantity ordered |
| 409 | `ALREADY_CANCELLED` | the order was already cancelled |
| 409 | `NOT_CANCELLABLE` | only `fulfilled` orders can be cancelled |
| 413 | `PAYLOAD_TOO_LARGE` | import body over 2,000,000 bytes (above nginx's 2 MiB limit nginx answers with its own HTML 413) |
| 422 | `VALIDATION_FAILED` | a field is missing or invalid, the message names it |
| 500 | `INTERNAL_ERROR` | unexpected failure; the message is always generic, the cause is in the server log under the `request_id` |

## Health

### `GET /` and `GET /health` (public)

```bash
curl localhost:8080/health
```

```json
{"status": "ok", "db": "up"}
```

`/health` returns 503 with `{"status": "degraded", "db": "down"}` when the database is unreachable.

## Products

### `GET /api/v1/products` (reseller)

Active products only, ordered by id. Filters: `category` (`synth`, `effect`, `sample_pack`, `daw`, `other`), `vendor_id`.

```bash
curl -H 'X-API-Key: pxc_local_reseller_1_key' 'localhost:8080/api/v1/products?category=synth&per_page=2'
```

```json
{
  "data": [
    {"id": 1, "vendor_id": 1, "sku": "AUR-SYNTH-001", "name": "Nebula Synth", "category": "synth", "price_cents": 4999, "active": true},
    {"id": 5, "vendor_id": 2, "sku": "BRW-SYNTH-001", "name": "Solstice Wavetable", "category": "synth", "price_cents": 6999, "active": true}
  ],
  "meta": {"page": 1, "per_page": 2, "total": 3}
}
```

Errors: 401, 422 (bad `category`, `vendor_id`, `page` or `per_page`).

### `GET /api/v1/products/{id}` (reseller)

Same fields plus `available_keys`, the number of unsold keys. Inactive products can still be read.

```bash
curl -H 'X-API-Key: pxc_local_reseller_1_key' localhost:8080/api/v1/products/12
```

```json
{"data": {"id": 12, "vendor_id": 3, "sku": "CBL-OTH-001", "name": "MIDI Toolkit", "category": "other",
          "price_cents": 1999, "active": true, "available_keys": 100}}
```

Errors: 401, 404.

## Orders

### `POST /api/v1/orders` (reseller key only)

Buys license keys. All or nothing: every key is sold and the order is `fulfilled`, or nothing changes.

| Field | Type | Rules |
|---|---|---|
| `product_id` | integer | required, an existing active product |
| `quantity` | integer | required, 1 to 100 |
| `idempotency_key` | string | optional, 1 to 64 characters. Sending the same key again returns the original order |

```bash
curl -X POST -H 'X-API-Key: pxc_local_reseller_1_key' \
  -d '{"product_id": 12, "quantity": 2, "idempotency_key": "demo-1"}' localhost:8080/api/v1/orders
```

```json
{"data": {"id": 26, "status": "fulfilled", "product_id": 12, "quantity": 2, "unit_price_cents": 1999,
          "total_cents": 3998, "keys": ["CBL-OTH-001-0100", "CBL-OTH-001-0099"],
          "failure_reason": null, "created_at": "2026-11-13 21:41:04"}}
```

- **201** a new order. **200** the same `idempotency_key` was used before by this reseller: the original order comes back and nothing more is sold. Keys are per reseller, so another reseller can reuse the same string.
- `unit_price_cents` is the price at purchase time. Later price changes do not touch it.
- On `INSUFFICIENT_STOCK` a `failed` order is recorded (visible in the history and the trace), no key is sold, and failed orders carry no idempotency key, so the same key can be retried after a restock.

Errors: 401, 403 (admin key), 404 (unknown product), 409 `INSUFFICIENT_STOCK`, 422 (bad fields or inactive product).

### `GET /api/v1/orders/{id}` (reseller: own orders, admin: any)

Returns the order as above, including `keys`.

```bash
curl -H 'X-API-Key: pxc_local_reseller_1_key' localhost:8080/api/v1/orders/26
```

Errors: 401, 403 (someone else's order), 404.

### `POST /api/v1/orders/{id}/cancel` (admin)

Cancels a `fulfilled` order. Its keys become `revoked` and are never resold. Returns the updated order (`status: "cancelled"`).

```bash
curl -X POST -H 'X-API-Key: pxc_local_admin_key' localhost:8080/api/v1/orders/26/cancel
```

Errors: 401, 403, 404, 409 `ALREADY_CANCELLED`, 409 `NOT_CANCELLABLE` (a failed order).

### `GET /api/v1/resellers/{id}/orders` (reseller: own, admin: any)

A reseller's order history, newest first, without keys. Paginated.

```bash
curl -H 'X-API-Key: pxc_local_reseller_1_key' 'localhost:8080/api/v1/resellers/1/orders?per_page=2'
```

```json
{
  "data": [{"id": 26, "status": "cancelled", "product_id": 12, "quantity": 2, "unit_price_cents": 1999,
            "total_cents": 3998, "failure_reason": null, "created_at": "2026-11-13 21:41:04"}],
  "meta": {"page": 1, "per_page": 2, "total": 1}
}
```

Errors: 401, 403 (another reseller's history), 404 (unknown reseller), 422 (bad paging).

## Support

### `GET /api/v1/support/orders/{id}/trace` (admin)

Everything needed to explain one order: the order (with its `reseller_id`), the current status of every key attached to it,
and every audit event for the order and its keys, oldest first. Each event carries the `request_id` of the call that caused it.

```bash
curl -H 'X-API-Key: pxc_local_admin_key' localhost:8080/api/v1/support/orders/26/trace
```

```json
{"data": {
  "order": {"id": 26, "reseller_id": 2, "status": "cancelled", "product_id": 12, "quantity": 2,
            "unit_price_cents": 1999, "total_cents": 3998, "failure_reason": null, "created_at": "2026-11-13 21:41:04"},
  "keys": [{"id": 204, "key_value": "CBL-OTH-001-0100", "status": "revoked"},
           {"id": 205, "key_value": "CBL-OTH-001-0099", "status": "revoked"}],
  "events": [
    {"at": "2026-11-13T21:41:04Z", "event": "order.requested", "entity_type": "order", "entity_id": 26,
     "detail": {"quantity": 2, "product_id": 12, "idempotency_key": "demo-1"}, "request_id": "b8a0a1153c7dc0d9"},
    {"at": "2026-11-13T21:41:04Z", "event": "key.sold", "entity_type": "license_key", "entity_id": 204,
     "detail": {"order_id": 26}, "request_id": "b8a0a1153c7dc0d9"}
  ]}}
```

Event names: `order.requested`, `order.fulfilled`, `order.failed`, `order.cancelled`, `key.sold`, `key.revoked`
(`catalog.imported` and `reseller.created` belong to a vendor or reseller, not an order).

Errors: 401, 403, 404.

## Admin

### `POST /api/v1/resellers` (admin)

| Field | Rules |
|---|---|
| `name` | required, 1 to 120 characters |
| `country` | required, two letters (stored upper case) |

```bash
curl -X POST -H 'X-API-Key: pxc_local_admin_key' -d '{"name": "My Shop", "country": "CA"}' localhost:8080/api/v1/resellers
```

```json
{"data": {"id": 3, "name": "My Shop", "country": "CA", "api_key": "b4697c15c96683e5...5eebed294"}}
```

**201.** The `api_key` is shown once and only its SHA-256 hash is stored (ADR 0004), so a lost key cannot be recovered.

Errors: 400, 401, 403, 422.

### `POST /api/v1/catalog/import` (admin)

Bulk-loads one vendor's catalog. The vendor is created if the name is new. Products are upserted by `sku`, and keys that already
exist are skipped, so running the same file again changes nothing. Each product is its own transaction: a bad row is rejected and
the rest still import.

```bash
curl -X POST -H 'X-API-Key: pxc_local_admin_key' --data-binary @tests/fixtures/partial-bad.json localhost:8080/api/v1/catalog/import
```

Body: `{"vendor": "...", "products": [{"sku", "name", "category", "price_cents", "keys": [...]}]}`

| Field | Rules |
|---|---|
| `vendor` | required, 1 to 120 characters |
| `products` | non-empty list, at most 1000 |
| `sku` | letters, digits, `.`, `_`, `-`, 1 to 64 characters, starting with a letter or digit; unique in the file; must not belong to another vendor |
| `name` | 1 to 200 characters |
| `category` | `synth`, `effect`, `sample_pack`, `daw` or `other` |
| `price_cents` | integer from 1 to 10,000,000 |
| `keys` | at most 5000, each 4 to 64 letters, digits, `_` or `-`; unique in the file; a key that belongs to another product rejects this product |

```json
{"data": {
  "products_created": 2, "products_updated": 0, "keys_added": 3,
  "rejected": [
    {"index": 1, "sku": "FIX-FX-009", "errors": ["price_cents must be an integer between 1 and 10000000"]},
    {"index": 6, "sku": null, "errors": ["product must be an object"]}
  ]}}
```

The response is **200** even when rows were rejected: read `rejected` (`index` is the position in `products`). A product is counted
as updated only if its name, category or price changed.

Errors: 400 (malformed JSON), 401, 403, 413 (body over 2,000,000 bytes), 422 (missing `vendor` or `products`, or more than 1000 products).
