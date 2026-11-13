# ADR 0004: API keys are random, shown once and stored as a SHA-256 hash

**Status:** accepted

**Context:** Resellers authenticate with an API key in the `X-API-Key` header. If the database leaks (a backup, a SQL injection, a developer's laptop), the stored credentials must not be usable. Keys are checked on every request, so the check must be cheap.

**Decision:**
- A key is 32 bytes from `random_bytes`, hex encoded (64 characters, 256 bits of entropy). It is returned once, in the response that creates the reseller.
- The database stores only `SHA-256(key)` in `resellers.api_key_hash` (`CHAR(64)`, unique). Authentication hashes the incoming header and looks the hash up by that unique index.
- A plain fast hash is enough here, unlike for passwords: a password hash (bcrypt, argon2) is slow on purpose because human passwords are guessable, but a 256-bit random key cannot be guessed or brute-forced, whatever the hash speed. A slow hash would only add latency to every request. No salt is needed for the same reason: precomputed tables are useless against random 256-bit input.
- The admin key is not in the database. It comes from the `ADMIN_API_KEY` environment variable and is compared with `hash_equals` (constant time). An empty variable disables admin access.
- The seed data's plain keys (`pxc_local_reseller_*_key`) exist only for local development and are documented as such.

**Consequences:**
- A leaked database does not leak usable keys.
- A lost key cannot be recovered. The fix is to create a new reseller key. There is no rotation endpoint yet, and adding one means a second hash column or a `resellers_keys` table so old and new keys overlap.
- Keys are bearer secrets: they must only travel over HTTPS in any real deployment (Phase 9), and must never be logged. The app logs request ids and error messages, never headers.
- Keys carry no identity or expiry. Revoking access means deleting or changing the reseller row.
