# Everyday commands (CLAUDE.md §8, CONVENTION.md §12.4). Everything runs inside the containers.
COMPOSE := docker compose -f compose.dev.yml
EXEC    := $(COMPOSE) exec -T app
RUNPHP  := $(COMPOSE) run --rm --no-deps -T --entrypoint

# Dev-only credentials from docker/postgres/init-dev.sql.
APP_DB_USER     := edqa_app
APP_DB_PASSWORD := edqa_app_dev

.DEFAULT_GOAL := help
.PHONY: help up down build sh logs artisan composer npm test test-slow lint check fresh routes prod-build grants-check e2e size lighthouse odk-mock-up odk-mock-seed

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-13s %s\n", $$1, $$2}'

up: ## Start the dev stack (first run: .env, vendor, key, migrations)
	@test -f .env || cp .env.example .env
	$(COMPOSE) build app
	$(RUNPHP) composer app install --no-interaction   # into the vendor volume; a no-op when up to date
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

test-slow: ## The slow group only (rescore time, server p95 on the perf dataset: ARCHITECTURE.md §10)
	$(EXEC) vendor/bin/pest --group=slow

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

grants-check: ## Prove the least-privilege grants: tests/Grants act as edqa_app on a migrated edqa_test
	$(COMPOSE) exec -T -e DB_DATABASE=edqa_test app php artisan migrate:fresh --force --no-interaction
	$(COMPOSE) exec -T -e DB_DATABASE=edqa_test app php artisan edqa:db:apply-grants --no-interaction
	$(COMPOSE) exec -T -e DB_APP_TEST_USERNAME=$(APP_DB_USER) -e DB_APP_TEST_PASSWORD=$(APP_DB_PASSWORD) \
		app vendor/bin/pest tests/Grants

e2e: ## Playwright against the running dev stack (seeds the local e2e admin first)
	$(EXEC) php artisan db:seed --class=E2eAdminSeeder --no-interaction
	$(COMPOSE) --profile e2e run --rm playwright $(c)

odk-mock-up: ## Start the mock ODK Central (odk-mock:8080 in the stack, 127.0.0.1:8090 on the host)
	$(COMPOSE) --profile odk up -d odk-mock

odk-mock-seed: ## Write raw submissions for the mock: make odk-mock-seed n=200
	$(EXEC) php artisan edqa:odk-mock:generate --count=$(or $(n),200)

lighthouse: ##Lighthouse on Slow 3G (Home, Facility view, Visuals) against a production build; Vite restarts after
	$(EXEC) php artisan db:seed --class=E2eAdminSeeder --no-interaction
	$(COMPOSE) stop vite
	$(EXEC) rm -f public/hot  # vite runs under sh -c, so it never gets to remove it: Laravel would keep using the dev server
	$(COMPOSE) run --rm --no-deps -e WAYFINDER_SKIP=1 vite npm run build
	# PHP as in production for the run (opcache.ini: no file re-checks; config and routes cached):
	# the dev setting re-checks every file on the Windows bind mount, ~6 s a request.
	$(COMPOSE) exec -T -u root app sh -c 'echo "opcache.validate_timestamps = 0" > /usr/local/etc/php/conf.d/zz-lighthouse.ini && kill -USR2 1'
	$(EXEC) sh -c 'php artisan config:cache -q && php artisan route:cache -q'
	-$(COMPOSE) --profile e2e run --rm --no-deps --entrypoint sh playwright tests/perf/run-lighthouse.sh
	$(EXEC) sh -c 'php artisan config:clear -q && php artisan route:clear -q'
	$(COMPOSE) exec -T -u root app sh -c 'rm -f /usr/local/etc/php/conf.d/zz-lighthouse.ini && kill -USR2 1'
	$(COMPOSE) start vite

size: ## Bundle budget: gzipped JS on first visit to /dashboard/home must stay under 300 KB
	$(COMPOSE) exec -T vite npm run size
