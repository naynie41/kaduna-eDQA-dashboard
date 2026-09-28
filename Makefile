# Everyday commands (CLAUDE.md §8, CONVENTION.md §12.4). Everything runs inside the containers.
COMPOSE := docker compose -f compose.dev.yml
EXEC    := $(COMPOSE) exec -T app
RUNPHP  := $(COMPOSE) run --rm --no-deps -T --entrypoint

# Dev-only credentials from docker/postgres/init-dev.sql.
APP_DB_USER     := edqa_app
APP_DB_PASSWORD := edqa_app_dev

.DEFAULT_GOAL := help
.PHONY: help up down build sh logs artisan composer npm test lint check fresh routes prod-build grants-check

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-13s %s\n", $$1, $$2}'

up: ## Start the dev stack (first run: .env, vendor, key, migrations)
	@test -f .env || cp .env.example .env
	$(COMPOSE) build app
	@test -f vendor/autoload.php || $(RUNPHP) composer app install --no-interaction
	@grep -q '^APP_KEY=base64:' .env || $(RUNPHP) php app artisan key:generate --no-interaction
	$(COMPOSE) up -d --wait app web worker postgres redis mailpit
	$(COMPOSE) up -d vite
	$(EXEC) php artisan migrate --no-interaction
	@$(MAKE) --no-print-directory routes
	@echo "App: http://localhost:8080   Mail: http://localhost:8025   Vite: http://localhost:5173"

down: ## Stop the dev stack (data volumes are kept)
	$(COMPOSE) --profile scheduler down

build: ## Rebuild the dev images
	$(COMPOSE) build

sh: ## Shell in the app container
	$(COMPOSE) exec app bash

logs: ## Follow logs: make logs s=app
	$(COMPOSE) logs -f $(s)

artisan: ## Run artisan: make artisan c="migrate:status"
	$(EXEC) php artisan $(c)

composer: ## Run composer: make composer c="require foo/bar"
	$(EXEC) composer $(c)

npm: ## Run npm in the vite container: make npm c="run build"
	$(COMPOSE) exec -T vite npm $(c)

test: ## Pest against edqa_test (parallel)
	$(EXEC) composer test

lint: ## Pint (test mode) and Larastan
	$(EXEC) composer lint

check: ## lint + test (must be green before every commit)
	$(EXEC) composer check

fresh: ## Drop, migrate and seed the dev database
	$(EXEC) php artisan migrate:fresh --seed --no-interaction

routes: ## Regenerate Wayfinder route helpers (the vite container has no PHP)
	$(EXEC) php artisan wayfinder:generate --with-form

prod-build: ## Build the production app, worker and web images locally
	docker build --target app -t edqa-app:local .
	docker build --target worker -t edqa-worker:local .
	docker build --target web -t edqa-web:local .

grants-check: ## Run tests/Grants as edqa_app, the runtime role, on a migrated edqa_test
	$(COMPOSE) exec -T -e DB_DATABASE=edqa_test app php artisan migrate:fresh --force --no-interaction
	@test ! -f database/sql/post-migrate-grants.sql || \
		$(COMPOSE) exec -T -e DB_DATABASE=edqa_test app php artisan db:execute-sql database/sql/post-migrate-grants.sql
	$(COMPOSE) exec -T -e DB_USERNAME=$(APP_DB_USER) -e DB_PASSWORD=$(APP_DB_PASSWORD) app vendor/bin/pest tests/Grants
