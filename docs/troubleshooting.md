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
