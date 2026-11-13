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

# Line coverage per class (pcov is installed in the app image)
coverage:
	docker compose exec -T app vendor/bin/phpunit --coverage-text

lint:
	docker compose exec app composer lint
	docker compose exec app composer analyse

# Runs database/integrity-checks.sql, fails if any check returns rows
integrity:
	docker compose exec -T app php bin/integrity.php

# The .NET tools run in the official SDK image, so no local dotnet is needed.
# host.docker.internal reaches the app published on localhost:8080.
DOTNET = docker run --rm -v "$(CURDIR)/tools:/src" -v pxc-nuget:/root/.nuget -w /src \
	-e DOTNET_NOLOGO=1 -e DOTNET_CLI_TELEMETRY_OPTOUT=1 \
	--add-host=host.docker.internal:host-gateway mcr.microsoft.com/dotnet/sdk:8.0 dotnet
BASE_URL ?= http://host.docker.internal:8080

dotnet-test:
	$(DOTNET) test

# Example: make loadtest ARGS="--orders 50 --qty 3 --runs 10"
loadtest:
	$(DOTNET) run --project PluginXchange.Cli -- loadtest --base-url $(BASE_URL) $(ARGS)
	$(MAKE) integrity

smoke:
	$(DOTNET) run --project PluginXchange.Cli -- smoke --base-url $(BASE_URL) $(ARGS)

.PHONY: up down seed test coverage lint integrity dotnet-test loadtest smoke
