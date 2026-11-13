# ADR 0002: Allocate license keys with `FOR UPDATE SKIP LOCKED`

**Status:** accepted

**Context:** Two resellers can order the same product at the same moment. A license key must never be sold twice, and one slow order should not block the others.

**Decision:** Each order runs in one transaction. It selects the keys it needs with `SELECT ... WHERE status = 'available' ORDER BY id LIMIT n FOR UPDATE SKIP LOCKED`, then marks them `sold`, inserts the order and writes the audit rows, then commits. Rows locked by another in-flight order are skipped, so concurrent orders get different keys instead of waiting. If fewer than `n` keys come back, the transaction is rolled back and a `failed` order is written in a second small transaction, so the failure is still recorded.

Idempotency: `(reseller_id, idempotency_key)` is unique. A retry returns the original order with HTTP 200. If two identical requests race, the unique index rejects the second insert and the service returns the winner's order. Failed orders are stored without an idempotency key, so the same key can be retried after a restock.

**Consequences:**
- Needs MySQL 8.0+ (`SKIP LOCKED`). The docker-compose and CI images use 8.0.
- Under heavy load an order can get "out of stock" while another in-flight order holds the last keys, even though they may be released if that order fails. This is acceptable: the client can retry.
- Fallback for MySQL 5.7 or MariaDB < 10.6: plain `FOR UPDATE` (drop `SKIP LOCKED`). It is still correct, but concurrent orders for the same product queue up behind each other.
