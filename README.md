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

## Seed data (local use only)

3 vendors, 12 products, 353 license keys, 2 resellers.

| Reseller | Plain API key (`X-API-Key`) |
|---|---|
| 1 Harmony Music Store | `pxc_local_reseller_1_key` |
| 2 Beatline Distribution | `pxc_local_reseller_2_key` |

Only the SHA-256 hash is stored in the DB. Special products: #8 has 3 keys (out-of-stock tests), #10 is inactive, #12 has 100 keys (load test).
