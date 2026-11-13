# ADR 0002: Allocate license keys with `FOR UPDATE SKIP LOCKED`

**Status:** accepted

**Context:** Two resellers can order the same product at the same moment. A license key must never be sold twice, and one slow order should not block the others.

**Decision:** Each order runs in one transaction. It selects the keys it needs with `SELECT ... WHERE status = 'available' ORDER BY id LIMIT n FOR UPDATE SKIP LOCKED`, then marks them `sold`, inserts the order and writes the audit rows, then commits. Rows locked by another in-flight order are skipped, so concurrent orders get different keys instead of waiting. If fewer than `n` keys come back, the transaction is rolled back and a `failed` order is written in a second small transaction, so the failure is still recorded.

Idempotency: `(reseller_id, idempotency_key)` is unique. A retry returns the original order with HTTP 200. If two identical requests race, the unique index rejects the second insert and the service returns the winner's order. Failed orders are stored without an idempotency key, so the same key can be retried after a restock.

**Consequences:**
- Needs MySQL 8.0+ (`SKIP LOCKED`). The docker-compose and CI images use 8.0.
- Under heavy load an order can see too few *unlocked* keys while other in-flight orders hold locks on some of them (see the Phase 8 update below).
- Fallback for MySQL 5.7 or MariaDB < 10.6: plain `FOR UPDATE` (drop `SKIP LOCKED`). It is still correct, but concurrent orders for the same product queue up behind each other.

## Update (Phase 8): retry when keys exist but are locked

This ADR first called a false "out of stock" under load acceptable. The load test showed it is not: against the production image (Apache, many parallel workers) roughly one run in ten ended with orders recorded as failed while keys were still unsold, for example "19 orders failed as out of stock although 4 keys were left". At the tail of the stock, two orders each lock part of the last four keys, each sees fewer than it needs, both fail, and the keys stay unsold. No key was ever sold twice, but the failure was wrong and it was recorded.

**Decision:** when an order comes up short, roll back and compare with a plain, non-locking count of `available` keys. If enough keys exist (they are just locked by other in-flight orders) and fewer than 5 attempts were made, wait 5-25 ms (random, so competing orders do not retry in step) and try again. Only a real shortage, or 5 failed attempts, records the failed order and returns `409 INSUFFICIENT_STOCK`.

**Consequences:**
- The load test now finds 0 false out-of-stock results over 90 runs against the production image (it found one in roughly every 10 runs before).
- A real shortage still fails at once, with no waiting.
- A false failure is still possible if another order holds its locks for longer than the retries last (about 75 ms). A transaction here lasts a few milliseconds.
- Tests hold locks from a second database connection to cover the retry, the immediate failure and the bounded retries.
