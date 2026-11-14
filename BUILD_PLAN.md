# PluginXchange: Phased Build Plan

A mini reseller marketplace API in PHP 8 + MySQL + JSON, built to match the XCHANGE Market Corp Junior Software Developer posting (PHP, JSON, SQL/MySQL, REST, Git, testing, code review, CI/CD, cloud).

Total: about 28-34 hours core (Phases 0-8, includes about 3-4 hours for the .NET tool), plus 4-6 hours for the optional AWS phases (9-10).

**How to use this plan**
- One Git branch and one PR per phase (`phase-1-schema`, etc.). Merge only on green CI.
- Don't start a phase until the previous phase's exit criteria pass.
- Each phase lists "Capture" items: numbers to record for your resume bullets. Use only what you actually measure.

---

## Overview

**What it is:** Vendors list software products (synths, effects, sample packs). Resellers order them through a JSON REST API. The system allocates license keys safely under concurrent orders and keeps an audit trail.

**Languages:** PHP 8 for the API, plus a small C# / .NET console tool that calls the API over HTTP/JSON to load-test it and smoke-test releases. The .NET tool is the JD's "at least one additional modern language" and shows the API works for a non-PHP client. It is an add-on: nothing in the PHP phases depends on it.
**Auth (minimal):** API key per reseller in `X-API-Key`; one admin key from env.

**Out of scope:** frontend, payments, email, login UI, multi-currency, refunds.

**Architecture**
```
Client -> public/index.php -> Router -> Middleware (auth, JSON parse, errors)
       -> Controllers (thin) -> Services (rules) -> Repositories (PDO/SQL) -> MySQL 8
```

**Final folder structure**
```
pluginxchange/
  public/index.php
  src/{Controllers,Services,Repositories,Http,Domain}/ + Database.php
  database/{schema.sql,seed.sql,integrity-checks.sql}
  tests/{Unit,Integration}/
  tools/
    PluginXchange.Client/        (C# class library: typed HttpClient + System.Text.Json models)
    PluginXchange.Cli/           (C# console app: `loadtest` and `smoke` commands)
    PluginXchange.Client.Tests/  (xUnit tests)
    PluginXchange.sln
  docs/{api.md,test-cases.md,troubleshooting.md,deploy-aws.md,adr/}
  docker/{Dockerfile,nginx.conf}
  .github/workflows/{ci.yml,deploy.yml}
  docker-compose.yml  composer.json  phpcs.xml  phpstan.neon  Makefile  README.md
```

**JD mapping (quick view)**

| JD line | Phase |
|---|---|
| PHP, JSON, extra language | 2-5 (PHP/JSON), 6 (C# / .NET tool) |
| SQL/MySQL queries, integrity checks | 1, 3, 6 |
| REST APIs, data structures | 2-5 |
| Git, code review, standards | 0, 7 |
| Create/execute/document test cases | 2-6 |
| Troubleshooting, root cause | 4, 7 |
| Documentation | 7 |
| CI/CD | 0, 8 |
| AWS / cloud | 9-10 |

---

## Phase 0: Project setup and green CI (about 2 hours)

> **Status:** done, except branch protection on `main`, which is a GitHub setting to check by hand.

**Goal:** an empty-but-working project whose pipeline is green from day one.

**Tasks**
- [x] Create repo `pluginxchange`, add `.gitignore`, `.env.example`, MIT license, README stub
- [x] `composer init`; add dev dependencies: `phpunit/phpunit`, `squizlabs/php_codesniffer`, `phpstan/phpstan`
- [x] `phpcs.xml` (PSR-12, scan `src` and `tests`), `phpstan.neon` (level 5, scan `src`)
- [x] `docker/Dockerfile` (php:8.3-fpm + pdo_mysql + composer), `nginx.conf`, `docker-compose.yml` with `app`, `web`, `db` (mysql:8.0)
- [x] `public/index.php` returning `{"status":"ok"}` for now
- [x] `Makefile`: `up`, `down`, `test`, `lint`, `seed`, `integrity`
- [x] One trivial passing PHPUnit test
- [x] `.github/workflows/ci.yml` (below)
- [ ] Branch protection on `main`: require CI to pass; add a PR template (tests added, docs updated, no secrets)

**CI workflow (`.github/workflows/ci.yml`)**
```yaml
name: CI
on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: pluginxchange_test
        ports: ["3306:3306"]
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -proot"
          --health-interval=10s --health-timeout=5s --health-retries=10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          extensions: pdo_mysql
          coverage: xdebug
      - run: composer install --no-interaction --prefer-dist
      - run: composer validate --strict
      - run: vendor/bin/phpcs
      - run: vendor/bin/phpstan analyse
      - name: Load schema
        run: |
          if [ -f database/schema.sql ]; then
            mysql -h127.0.0.1 -uroot -proot pluginxchange_test < database/schema.sql
          fi
      - name: Tests
        env:
          DB_HOST: 127.0.0.1
          DB_NAME: pluginxchange_test
          DB_USER: root
          DB_PASS: root
        run: vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml
      - uses: actions/upload-artifact@v4
        with:
          name: coverage
          path: coverage.xml

  dotnet:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-dotnet@v4
        with:
          dotnet-version: "10.0.x"   # use the current LTS when you build this
      - run: dotnet restore tools/PluginXchange.sln
      - run: dotnet build tools/PluginXchange.sln --no-restore -c Release
      - run: dotnet test tools/PluginXchange.sln --no-build -c Release

  docker-build:
    runs-on: ubuntu-latest
    needs: [test, dotnet]
    steps:
      - uses: actions/checkout@v4
      - run: docker build -f docker/Dockerfile -t pluginxchange:ci .
```
Pin action versions as shown and check for newer majors when you set this up.

**Exit criteria**
- `docker compose up` serves `/` with JSON on localhost
- CI green on a PR; lint and static analysis run; badge in README

**Capture:** number of CI checks configured.

---

## Phase 1: Database schema, seed data, DB layer (about 3 hours)

**Goal:** a correct, constrained schema and a working PDO connection.

**Schema (`database/schema.sql`)**
```sql
CREATE TABLE vendors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  sku VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  category ENUM('synth','effect','sample_pack','daw','other') NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id)
);

CREATE TABLE license_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  key_value VARCHAR(64) NOT NULL UNIQUE,
  status ENUM('available','sold','revoked') NOT NULL DEFAULT 'available',
  order_id BIGINT UNSIGNED NULL,
  INDEX idx_product_status (product_id, status),
  FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE resellers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  api_key_hash CHAR(64) NOT NULL UNIQUE,   -- SHA-256 of the key
  country CHAR(2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reseller_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price_cents INT UNSIGNED NOT NULL,   -- price at time of purchase
  total_cents INT UNSIGNED NOT NULL,
  status ENUM('fulfilled','failed','cancelled') NOT NULL,
  failure_reason VARCHAR(255) NULL,
  idempotency_key VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reseller_idem (reseller_id, idempotency_key),
  FOREIGN KEY (reseller_id) REFERENCES resellers(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(60) NOT NULL,
  detail JSON NULL,
  request_id CHAR(16) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id)
);
```
Note: `license_keys.order_id` has no FK on purpose (avoids a circular dependency); the integrity script checks it instead.

**Tasks**
- [x] Write `schema.sql`; mount into the db container's `/docker-entrypoint-initdb.d`
- [x] `seed.sql`: 3 vendors, about 12 products, a few hundred keys, 2 resellers (store hashed keys; document the plain keys in the README for local use only)
- [x] `src/Database.php`: PDO singleton from env vars, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`
- [x] `GET /health` pinging the DB (`SELECT 1`)
- [x] First integration test: connects to the test DB and checks the tables exist
- [x] ADR 0001: no framework (reason: show the fundamentals, small scope)

**Exit criteria**
- Fresh `docker compose up` creates the schema and seed data
- `/health` returns `{"status":"ok","db":"up"}`
- Integration test passes in CI

**Capture:** table count, seed row counts.

---

## Phase 2: HTTP core, auth, products, resellers (about 4 hours)

**Goal:** a small router and consistent JSON responses, plus the read-only catalog.

**Tasks**
- [x] `Router` (method + path pattern with `{id}`), `Request` (parsed JSON body, headers, query), `JsonResponse`
- [x] Error handler middleware: all errors return the standard format, never a PHP stack trace; log details server-side
- [x] Request ID middleware (16 hex chars), added to every response header and error body
- [x] Auth middleware: SHA-256 the `X-API-Key`, look up the reseller, or compare to the admin key; set role on the request
- [x] `GET /products` (filters `category`, `vendor_id`; pagination `page`, `per_page` capped at 100)
- [x] `GET /products/{id}` including `available_keys` count
- [x] `POST /resellers` (admin only): validate, generate a random 32-byte key, store only its hash, return the key once
- [x] Tests: router matching and 404/405, auth (missing, bad, good key), pagination, filters, bad JSON

**Error format (used everywhere)**
```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "price_cents must be greater than 0",
    "request_id": "9f3a1c7e2b4d6a80"
  }
}
```
Status codes: 200, 201, 400 (bad JSON), 401, 403, 404, 405, 409, 422, 500.

**Exit criteria**
- All three endpoints work through curl
- Every error path returns the standard format
- About 10 tests passing

**Capture:** endpoints so far, test count.

---

## Phase 3: Order flow with safe key allocation (about 5 hours)

**Goal:** the core feature. No key is ever sold twice.

**Endpoints:** `POST /orders`, `GET /orders/{id}`

**Request / response**
```json
POST /api/v1/orders
{ "product_id": 12, "quantity": 3, "idempotency_key": "a1b2c3d4" }

201
{ "data": { "id": 501, "status": "fulfilled", "product_id": 12, "quantity": 3,
            "total_cents": 14997,
            "keys": ["AAAA-1111-BBBB", "CCCC-2222-DDDD", "EEEE-3333-FFFF"] } }
```

**Allocation logic (inside `OrderService`)**
```php
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT id, key_value FROM license_keys
         WHERE product_id = :pid AND status = 'available'
         LIMIT :qty FOR UPDATE SKIP LOCKED"
    );
    // bind pid and qty as ints, execute, fetch rows
    if (count($rows) < $qty) {
        throw new InsufficientStockException(count($rows), $qty);
    }
    // insert order row (with unit_price_cents), update keys to sold + order_id,
    // write audit_log rows, then:
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    // record a FAILED order + audit entry in a separate small transaction
    throw $e;
}
```

**Rules**
- Validate: product exists and is active, `quantity` between 1 and 100
- Idempotency: if `(reseller_id, idempotency_key)` already exists, return the original order and its keys (HTTP 200), don't create another
- Store `unit_price_cents` on the order; money is always integer cents
- Write the failed-order record outside the rolled-back transaction, or the failure trace is lost
- `SKIP LOCKED` needs MySQL 8.0+; ADR 0002 documents this and the fallback (`FOR UPDATE` without it)
- A reseller can read only their own orders (403 otherwise); admin can read any

**Tests**
- Happy path: keys allocated, status fulfilled, audit rows written, total correct
- Out of stock: 409, failed order stored with a reason
- Quantity 0, negative, 101; missing product; inactive product
- Idempotent retry returns the same order and keys, and sold count didn't change
- Cross-reseller read gets 403
- Rollback test: force an error mid-transaction and assert no keys were left in `sold`

**Exit criteria**
- Orders work end to end via curl
- All tests above pass in CI
- ADR 0002 written

**Capture:** test count, time for the order transaction on the seeded data (ms).

---

## Phase 4: Cancel, history, support trace (about 3 hours)

**Goal:** the operational side, which mirrors the "first-level support" part of the job.

**Endpoints**
- `POST /orders/{id}/cancel` (admin): only `fulfilled` orders; keys set to `revoked`; status `cancelled`; audit entry; 409 if already cancelled
- `GET /resellers/{id}/orders`: paginated, newest first, own orders only (admin sees any)
- `GET /support/orders/{id}/trace` (admin): the order, its keys' status, and the ordered audit events

**Trace response sketch**
```json
{ "data": {
    "order": { "id": 501, "status": "failed", "failure_reason": "INSUFFICIENT_STOCK" },
    "events": [
      { "at": "2026-11-14T15:02:11Z", "event": "order.requested", "detail": { "quantity": 5 } },
      { "at": "2026-11-14T15:02:11Z", "event": "order.failed",    "detail": { "available": 2 } }
    ] } }
```

**Tasks**
- [x] Implement the three endpoints with repository methods and tests
- [x] Make sure every state change in Phases 3-4 writes an audit event with the request ID
- [x] Start `docs/troubleshooting.md` with the first scenario: "order shows fulfilled but the reseller has no key" (symptom, trace call, SQL to check, fix)

**Exit criteria**
- Cancel, history, and trace work and are tested (about 8 new tests)
- Trace output actually explains a failed order you created on purpose

**Capture:** total test count; number of audit event types.

---

## Phase 5: JSON catalog import (about 4 hours)

**Goal:** bulk-load a vendor's catalog from a JSON file with clear rejection reports.

**Endpoint:** `POST /catalog/import` (admin)

**Input**
```json
{
  "vendor": "Aurora Audio",
  "products": [
    { "sku": "AUR-SYNTH-001", "name": "Nebula Synth", "category": "synth",
      "price_cents": 4999, "keys": ["NEB-0001", "NEB-0002", "NEB-0003"] }
  ]
}
```

**Output**
```json
{ "data": { "products_created": 8, "products_updated": 2, "keys_added": 340,
            "rejected": [
              { "index": 4, "sku": "AUR-FX-009", "errors": ["price_cents must be > 0"] },
              { "index": 7, "sku": "AUR-FX-002", "errors": ["duplicate key NEB-0002"] } ] } }
```

**Rules**
- Validate every row (required fields, category in the enum, price > 0, key format and length, no duplicate keys inside the file or already in the DB)
- Bad rows are rejected with reasons; good rows still import
- One transaction per product so a failure doesn't leave half a product
- Upsert by SKU; skip keys that already exist, so re-running the same file changes nothing
- Cap file size (e.g. 2 MB) and product count; return 413/422 beyond that
- Create the vendor if the name is new

**Tasks**
- [x] `ImportValidator` (pure, unit-testable), `ImportService`, controller
- [x] Sample files in `tests/fixtures/`: valid, partially bad, duplicate keys, huge, malformed JSON
- [x] Tests: new vendor, update existing, partial rejection, idempotent re-run, malformed JSON, oversize file

**Exit criteria**
- Importing the valid fixture twice gives identical DB state the second time
- Rejection report is accurate for the bad fixture
- Validator has unit tests for every rule

**Capture:** products and keys imported, import time (s) for a large fixture (e.g. 500 products / 10,000 keys), rejected-row count on the bad fixture.

---

## Phase 6: Hardening: .NET load tool, integrity, test documentation (about 7-8 hours)

**Goal:** prove it's correct, and document the proof.

**Tasks**
- [x] **.NET tool (`tools/`, about 3-4 hours)**
  - `PluginXchange.Client`: class library with a typed `HttpClient` wrapper (`PlaceOrderAsync`, `GetProductAsync`, `GetTraceAsync`), `System.Text.Json` models matching the API's request/response/error JSON, and the `X-API-Key` header set from config
  - `PluginXchange.Cli`: console app with two commands
    - `loadtest --orders 50 --product 12 --qty 3 --runs 10`: fires N parallel order requests with `Task.WhenAll`, each with a unique idempotency key, against a product with M keys (e.g. 100). It asserts: no key string appears twice across all responses, fulfilled + failed orders equals N, and the number of keys handed out never exceeds M. It also replays one idempotency key twice and asserts the same keys come back. It prints a summary table (fulfilled, failed, p50/p95 latency) and exits non-zero on any violation.
    - `smoke --base-url https://...`: health, list products, place and fetch one order; exits non-zero on failure. Phase 8 and 9 call this in the pipeline instead of raw curl.
  - `PluginXchange.Client.Tests` (xUnit): JSON model parsing for success and error bodies, error-to-exception mapping (401, 409, 422), and a fake `HttpMessageHandler` test for retry/idempotency behavior. Aim for 8-10 tests.
  - After each load test run, run `make integrity` to check the database side (the tool can only see what the API returns).
- [x] **`database/integrity-checks.sql`** with queries for:
  - sold keys with no matching order
  - fulfilled orders where key count != quantity
  - orders where `total_cents` != `quantity * unit_price_cents`
  - keys marked `sold` on cancelled orders (should be `revoked`)
  - `make integrity` runs them and fails if any returns rows
- [x] Run the integrity script after the concurrency test and after an import
- [x] **`docs/test-cases.md`**: a table with columns ID, Area, Scenario, Steps, Expected, Automated (Y/N), Status. Target 30+ rows covering everything above.
- [x] Coverage report; add tests until Services and Repositories are at or above 70%
- [x] Fix any real bug the concurrency or integrity checks uncover and write it up in the troubleshooting doc (this makes a strong interview story)

**Exit criteria**
- 25+ automated tests passing in CI
- 0 duplicate keys across all concurrency runs
- Integrity script returns zero rows after every scenario
- `dotnet test` passes locally and in CI; `loadtest` exits 0 across all runs

**Capture:** total tests (PHP + .NET), PHP coverage %, load test parameters (orders, keys, runs), duplicates found (target 0), p95 latency, integrity checks written.

---

## Phase 7: Code quality and documentation (about 3 hours)

**Goal:** make it read like a team project.

**Tasks**
- [x] Get `phpcs` and `phpstan` fully clean (try raising PHPStan one level)
- [x] Self-review every PR diff with a checklist: input validation, prepared statements only, no secrets, error paths tested
- [x] **README:** what it is, architecture sketch, quickstart (`docker compose up`), sample curl calls for each endpoint, how to run tests/lint/integrity, CI badge
- [x] **`docs/api.md`:** every endpoint with request/response examples and error codes
- [x] **`docs/troubleshooting.md`:** 4-5 scenarios (symptom, how to investigate with the trace endpoint and SQL, fix). Ideas: fulfilled order with missing key; 409 on retry; import rejected rows; 401 vs 403 confusion; DB connection failure on startup.
- [x] ADR 0003 (money as integer cents) and ADR 0004 (API-key hashing)
- [x] Optional: a Postman or `.http` collection in the repo

**Exit criteria**
- Someone can clone the repo, run one command, and call every endpoint using only the README
- Lint and static analysis clean in CI

**Capture:** number of endpoints documented, number of troubleshooting scenarios.

---

## Phase 8: Continuous delivery with GitHub Container Registry (about 2 hours)

> **Status:** written, linted and rehearsed locally (the image, smoke test and load test against it). The first tagged run on GitHub is still to do, and the `production` environment with required reviewers has to be created in GitHub settings (unticked below for that reason).

**Goal:** a release pipeline that builds, publishes, and smoke-tests an image. This is also the foundation for AWS.

**Tasks**
- [x] Production Dockerfile stage: `composer install --no-dev --optimize-autoloader`, non-root user, no dev files
- [x] `.github/workflows/deploy.yml`: triggers on tag `v*` (and `workflow_dispatch`); jobs: build image, push to GHCR tagged with the version and `latest`, then run a smoke test
- [x] Smoke test job: start the new image with a MySQL service container, wait for `/health`, then run the .NET `smoke` command (health, list products with the seeded key, place and fetch one order)
- [ ] Use a GitHub Environment named `production` with required approval for the deploy step (used in Phase 9)
- [x] Document the rollback: redeploy the previous image tag

**Skeleton**
```yaml
name: Release
on:
  push:
    tags: ["v*"]
  workflow_dispatch:

permissions:
  contents: read
  packages: write

jobs:
  build-and-push:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}
      - uses: docker/build-push-action@v6
        with:
          context: .
          file: docker/Dockerfile
          push: true
          tags: |
            ghcr.io/${{ github.repository }}:${{ github.ref_name }}
            ghcr.io/${{ github.repository }}:latest
```

**Exit criteria**
- Pushing tag `v0.1.0` publishes an image and the smoke test passes
- Release steps and rollback written in the README

**Capture:** pipeline stages, release time end to end.

---

## Phase 9 (optional): AWS Level 1, single VM (about 3-4 hours)

> **Status:** done as code, with Terraform instead of console clicks (see `docs/deploy-aws.md`). `terraform validate`, a Trivy scan, shellcheck and a local rehearsal of `deploy.sh` (good deploy, bad image, broken compose file, rollback) passed. **Not applied to a real AWS account yet**, so the exit criteria below are untested and no deploy time or cost has been measured.

**Goal:** a live deployment on AWS driven by the pipeline. Cheapest option that honestly counts as "cloud".

**Steps**
- [x] **Before anything else:** create an AWS Budget with an email alert
- [x] Create an ECR repository; change the release workflow to push there as well (or instead)
- [x] Launch one small EC2 instance (or Lightsail) with Docker and Docker Compose installed; attach an instance role with ECR pull permission and SSM access
- [x] Security group: 80/443 open; SSH closed (use SSM Session Manager) or open to your IP only
- [x] Production `docker-compose.prod.yml` (app, web, db with a named volume on EBS); secrets via an env file on the host or SSM Parameter Store, never in Git
- [x] GitHub OIDC: create an IAM role trusted for your repo and the `production` environment; permissions limited to ECR push and SSM SendCommand on this instance. No long-lived AWS keys in GitHub.
- [x] Extend `deploy.yml`: after the image push, job `deploy` (environment `production`, with approval) assumes the role, then uses SSM `send-command` to run `docker compose pull && docker compose up -d`, then `curl -f` the public `/health`
- [x] TLS: Caddy or nginx + Let's Encrypt if you have a domain; otherwise HTTP on the public IP for the demo
- [x] Nightly `mysqldump` cron to an S3 bucket (with a lifecycle rule to expire old dumps)
- [x] `docs/deploy-aws.md`: architecture diagram, one-time setup, how a deploy works, rollback steps, monthly cost estimate

**Exit criteria**
- Tagging a release deploys to the instance and the smoke test passes against the public URL
- A deliberate bad deploy (failing health check) fails the job, and you can roll back by redeploying the previous tag

**Capture:** deploy time (tag to healthy), number of manual steps remaining (target 0), monthly cost.

---

## Phase 10 (optional stretch): AWS Level 2, managed services (about 4-6 hours)

> **Status:** skipped (optional stretch).

**Goal:** closer to a production layout. Do this only if Phase 9 is done and you have time.

**Steps**
- [ ] RDS MySQL 8 (single-AZ, smallest class) in private subnets; security group allows only the app
- [ ] Run the container on ECS Fargate (or ECS on EC2) from ECR behind an Application Load Balancer with a `/health` target check
- [ ] Config and secrets from SSM Parameter Store or Secrets Manager; task role with least privilege
- [ ] Logs to CloudWatch; a metric filter and alarm on 5xx responses
- [ ] Infrastructure as code (Terraform or CloudFormation) in `infra/`: VPC, subnets, security groups, RDS, ECS service, ALB
- [ ] Update the pipeline to register a new task definition revision and wait for the service to stabilize
- [ ] Tear it down after demoing and record cost; keep a short demo GIF in the README

**Exit criteria**
- `terraform apply` (or stack deploy) from scratch gives a working environment; destroy removes everything

**Capture:** resources managed as code, time to create the environment from scratch.

---

## Schedule suggestion

| Block | Phases |
|---|---|
| Weekend 1, Sat | 0, 1, start 2 |
| Weekend 1, Sun | finish 2, 3 |
| Weekend 2, Sat | 4, 5 |
| Weekend 2, Sun | 6 (includes the .NET tool) |
| Weekend 3 | 7, 8 |
| Weekend 4 (optional) | 9, then 10 if you want it |

If time is tight, the minimum presentable version is Phases 0-3 + 7 (README) + 8. Add 5 and 6 before you apply anywhere if you can; the concurrency proof is the strongest part of the project.

---

## Definition of done (core)

- `docker compose up` gives a working API from a fresh clone
- CI green on `main` (PHP lint, static analysis, tests, .NET build and tests, Docker build)
- 25+ automated PHP tests with a recorded coverage number, plus the xUnit tests
- .NET load test shows 0 duplicate keys
- README, API docs, test-case table, troubleshooting doc, ADRs complete
- A tagged release publishes an image and passes the smoke test

---

## Resume bullets (real numbers, measured on 2026-11-13)

- Built a PHP 8.3 / MySQL REST API with **9** JSON endpoints for a software-license reseller marketplace, covered by **80** PHPUnit tests (385 assertions) at **96%** line coverage, plus **13** xUnit tests for its C# client.
- Implemented transactional license-key allocation using row-level locking (`FOR UPDATE SKIP LOCKED`) and idempotency keys; verified **0** duplicate keys across **3,000** parallel orders in **40** runs against the release image, plus same-key retry storms that always produced exactly one order.
- Designed a JSON catalog import with per-row validation that processed **500** products / **10,000** keys in **1.0** s (**0.27** s for an unchanged re-run) and returned structured reports for rejected rows (**5** on the bad fixture).
- Built a C# (.NET 8) load-testing client that ran **50** parallel orders per run over **20** runs against the PHP API (p95 latency **83** ms) and confirmed **0** duplicate license keys; it found a real bug (false out-of-stock under parallel load, about 1 run in 10) that was fixed with a bounded retry.
- Set up GitHub Actions CI/CD with **11** automated checks (composer validate, phpcs, phpstan, PHPUnit with coverage, database integrity checks, .NET tests, terraform fmt and validate, shellcheck, a load test, image build) and a release workflow that builds, smoke-tests and publishes a container image to GHCR; defined the AWS deployment in Terraform (EC2, ECR, OIDC roles, S3 backups) with an automated health-check gate and rollback.

Left out on purpose until they are true: "released in N minutes" (the release workflow has not run on a tag yet) and "deployed to AWS" (the Terraform is validated but not applied). Add them after you have run both.

## Interview talking points

- Why integer cents for money
- How you prevented double-selling keys, and what `SKIP LOCKED` does
- Why idempotency keys matter for retried requests
- A real bug the load test or integrity checks found, and how the trace endpoint helped
- Why you wrote the load tester in C#: it exercises the API as an outside client would, over plain HTTP and JSON
- How a deploy and a rollback work in your pipeline
- What you'd add next: rate limiting, webhooks to vendors, key rotation, a read replica
