# PluginXchange

[![CI](https://github.com/AjayMaan13/PluginXchange/actions/workflows/ci.yml/badge.svg)](https://github.com/AjayMaan13/PluginXchange/actions/workflows/ci.yml)

A small reseller marketplace API for audio plugins, written in plain PHP 8.3 with MySQL 8. Vendors list products and license
keys, resellers buy keys, and support can trace and cancel any order. The interesting parts are correctness ones: a license key
is never sold twice, even under parallel load; retries are safe; and every order leaves an audit trail.

See [BUILD_PLAN.md](BUILD_PLAN.md) for the phased plan and [docs/](docs/) for the details.

## How it fits together

```
client ──HTTP──▶ nginx ──FastCGI──▶ php-fpm ──▶ public/index.php
                                                    │
                                    App: router, API-key auth, JSON errors, X-Request-Id
                                                    │
                          Controllers ──▶ Services ──▶ Repositories ──PDO──▶ MySQL 8
                       (validate input)  (rules and    (SQL, prepared
                                          transactions)  statements only)
```

- **Controllers** check the input and shape the JSON. **Services** hold the rules and the transactions (placing and cancelling an
  order, importing a catalog). **Repositories** are the only place with SQL.
- **Orders** run in one transaction that locks the keys with `SELECT ... FOR UPDATE SKIP LOCKED`, so parallel orders get different
  keys without waiting for each other ([ADR 0002](docs/adr/0002-skip-locked-key-allocation.md)).
- **Retries** are safe: an `idempotency_key` makes a repeated order return the first one.
- **Audit log:** every order and key change is written with the request id, so `GET /api/v1/support/orders/{id}/trace` can explain what happened.
- **`tools/`** holds a .NET client library, a CLI (`loadtest`, `smoke`) and its tests.

## Quickstart

You need Docker. From a fresh clone:

```bash
docker compose up -d --build
```

The first start builds the images, installs the dependencies, creates the database and loads the sample data. Give it about a minute,
then check:

```bash
curl localhost:8080/health      # {"status":"ok","db":"up"}
```

`make up`, `make down` and `make seed` (wipe the database and reload the sample data) are shortcuts. MySQL is also on `localhost:3307`
(user, password and database are all `pluginxchange`).

## Try the API

Every request except `/` and `/health` needs an `X-API-Key` header. The local sample keys are:

| Who | Key |
|---|---|
| reseller 1 (Harmony Music Store) | `pxc_local_reseller_1_key` |
| reseller 2 (Beatline Distribution) | `pxc_local_reseller_2_key` |
| admin | `pxc_local_admin_key` (set as `ADMIN_API_KEY` in `docker-compose.yml`) |

```bash
R='X-API-Key: pxc_local_reseller_1_key'
A='X-API-Key: pxc_local_admin_key'

# Browse the catalog
curl -H "$R" 'localhost:8080/api/v1/products?category=synth&per_page=2'
curl -H "$R" localhost:8080/api/v1/products/12

# Buy 2 keys (retrying with the same idempotency_key is safe)
curl -X POST -H "$R" -d '{"product_id":12,"quantity":2,"idempotency_key":"readme-1"}' localhost:8080/api/v1/orders
ORDER=$(curl -s -X POST -H "$R" -d '{"product_id":12,"quantity":2,"idempotency_key":"readme-1"}' \
  localhost:8080/api/v1/orders | sed -E 's/.*"data":\{"id":([0-9]+).*/\1/')

curl -H "$R" localhost:8080/api/v1/orders/$ORDER                      # the order and its keys
curl -H "$R" 'localhost:8080/api/v1/resellers/1/orders?per_page=5'    # reseller 1's history

# Support (admin)
curl -H "$A" localhost:8080/api/v1/support/orders/$ORDER/trace        # order, key status, audit events
curl -X POST -H "$A" localhost:8080/api/v1/orders/$ORDER/cancel       # keys are revoked, never resold

# Admin
curl -X POST -H "$A" -d '{"name":"My Shop","country":"CA"}' localhost:8080/api/v1/resellers     # returns a new key, once
curl -X POST -H "$A" --data-binary @tests/fixtures/partial-bad.json localhost:8080/api/v1/catalog/import
```

| Endpoint | Access | What it does |
|---|---|---|
| `GET /health` | public | API and database status |
| `GET /api/v1/products` | any key | active products; `category`, `vendor_id`, `page`, `per_page` |
| `GET /api/v1/products/{id}` | any key | one product with `available_keys` |
| `POST /api/v1/orders` | reseller | buy keys; 201, or 200 for an idempotent retry; 409 if out of stock |
| `GET /api/v1/orders/{id}` | reseller (own) or admin | an order with its keys |
| `POST /api/v1/orders/{id}/cancel` | admin | cancel a fulfilled order, revoke its keys |
| `GET /api/v1/resellers/{id}/orders` | reseller (own) or admin | order history, newest first |
| `GET /api/v1/support/orders/{id}/trace` | admin | order, key status and every audit event |
| `POST /api/v1/resellers` | admin | create a reseller; the API key is shown once |
| `POST /api/v1/catalog/import` | admin | bulk-load a vendor's products and keys (max 2 MB, 1000 products) |

Errors always look like `{"error": {"code", "message", "request_id"}}`. Full request and response examples, validation rules and every
error code are in [docs/api.md](docs/api.md). The same calls as a ready-to-run request file: [docs/requests.http](docs/requests.http)
(VS Code REST Client; the header explains the JetBrains variant).

## Tests, lint and checks

```bash
make test          # PHPUnit (unit + integration, against the Docker MySQL)
make coverage      # the same, with line coverage per class
make lint          # phpcs (PSR-12) and phpstan (level 8)
make integrity     # database consistency checks, must print "Integrity OK"
make dotnet-test   # .NET client tests (runs in the dotnet SDK Docker image)
make loadtest      # parallel orders against the running API, then make integrity
make smoke         # health, list, place and fetch one order
```

- Every test case is listed in [docs/test-cases.md](docs/test-cases.md).
- `database/integrity-checks.sql` holds queries that must return zero rows (sold keys without an order, key count vs quantity,
  order totals, keys left `sold` on cancelled orders, and more). `make integrity` runs them and fails on any row.
- **Load test:** `make loadtest ARGS="--orders 50 --qty 3 --runs 10"` fires the orders in parallel. Each run imports its own product with
  100 keys (`--keys N` changes that, `--product ID` uses an existing product). Per run it checks that no key was handed out twice,
  fulfilled + failed equals the orders sent, nothing was oversold, stock went down by exactly what was sold, an idempotency-key
  replay returns the same keys, and 10 simultaneous requests with one idempotency key make exactly one order. It exits non-zero on any
  violation. Runs leave their products behind, so use `make seed` to reset.
- The .NET tools run in the official SDK image, so no local .NET install is needed. With a local SDK use
  `dotnet test tools/PluginXchange.sln` and `dotnet run --project tools/PluginXchange.Cli -- <command>`.
- `docker/php-fpm-pool.conf` raises php-fpm from 5 to 25 workers (local Docker only) so the load test's requests really overlap.
- CI (`.github/workflows/ci.yml`) runs lint, static analysis, the PHP tests with coverage, the integrity checks, the .NET tests and a
  load test on every push.

## Documentation

| | |
|---|---|
| [docs/api.md](docs/api.md) | every endpoint with examples, validation rules and error codes |
| [docs/troubleshooting.md](docs/troubleshooting.md) | six support scenarios: symptom, how to investigate, fix |
| [docs/test-cases.md](docs/test-cases.md) | the test case table |
| [docs/adr/](docs/adr/) | design decisions: no framework, SKIP LOCKED key allocation, money as integer cents, API-key hashing |

## Sample data (local use only)

3 vendors, 12 products, 353 license keys and 2 resellers. Only the SHA-256 hash of each key is stored in the database. Special
products: #8 has 3 keys (out-of-stock tests), #10 is inactive, #12 has 100 keys (load tests).

## Project layout

```
public/index.php     entry point (one line)
src/Http/            request, router, auth, JSON response, pagination
src/Controllers/     input validation and response shaping
src/Services/        order and import rules, transactions
src/Repositories/    all SQL (prepared statements only)
database/            schema.sql, seed.sql, integrity-checks.sql
docker/              Dockerfile, nginx and php-fpm config
tests/               PHPUnit: Unit/ and Integration/, fixtures/
tools/               .NET client, CLI and tests
docs/                API reference, troubleshooting, test cases, ADRs
```
