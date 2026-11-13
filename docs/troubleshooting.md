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
