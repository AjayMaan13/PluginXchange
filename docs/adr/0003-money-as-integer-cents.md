# ADR 0003: Money is an integer number of cents

**Status:** accepted

**Context:** The API stores product prices and order totals. Floating-point numbers cannot represent most decimal amounts exactly (`0.1 + 0.2` is not `0.3`), and rounding errors in money are both hard to see and hard to fix later. `DECIMAL` is exact but turns into a string or a float as soon as it passes through PDO and `json_decode`.

**Decision:** Every amount is an integer count of the smallest currency unit (cents), in the database, in PHP and in the JSON API: `products.price_cents`, `orders.unit_price_cents`, `orders.total_cents`, all `INT UNSIGNED`. The API fields carry the unit in their name. Requests and responses never contain a decimal price. The order stores `unit_price_cents` at purchase time and computes `total_cents = quantity * unit_price_cents` once, so later price changes never rewrite history. Imports accept `price_cents` as an integer from 1 to 10,000,000 (100,000.00).

`database/integrity-checks.sql` checks `total_cents = quantity * unit_price_cents` on every order, so a bad write is found even if the code is wrong.

**Consequences:**
- Exact arithmetic, and totals can be compared with `=`.
- One currency only. A second currency needs a `currency` column next to each amount, and sums across currencies must be forbidden.
- Display formatting (dollars and cents) is the client's job.
- `INT UNSIGNED` caps one amount at about 42.9 million dollars. An order of 100 keys at the maximum price (10,000,000 cents) is 1,000,000,000 cents, still inside the limit of 4,294,967,295.
- No negative amounts: refunds are modelled as cancelled orders, not negative totals.
