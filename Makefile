up:
	docker compose up -d --build

down:
	docker compose down

# Wipe the DB volume so schema.sql + seed.sql run again
seed:
	docker compose down -v
	docker compose up -d --build

test:
	docker compose exec app vendor/bin/phpunit

lint:
	docker compose exec app composer lint
	docker compose exec app composer analyse

.PHONY: up down seed test lint
