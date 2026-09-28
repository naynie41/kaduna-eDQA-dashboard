#!/usr/bin/env bash
# Container roles (DEPLOY.md §6.3). One image runs every PHP role; the role is the command.
set -euo pipefail
cd /var/www/html
role="${1:-app}"; shift || true

prepare() {
  mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/odk storage/app/exports bootstrap/cache
  if [ "${APP_ENV:-production}" = "local" ]; then
    # Source is bind-mounted in dev: cached config/routes would hide every edit.
    php artisan optimize:clear --no-interaction >/dev/null
  else
    php artisan optimize --no-interaction >/dev/null   # env differs per environment → cache at start, never at build
  fi
}

case "$role" in
  app)       prepare; exec php-fpm -F ;;
  worker)    prepare; exec php artisan queue:work "${QUEUE_CONNECTION:-redis}" \
               --queue="${WORKER_QUEUES:?}" --tries="${WORKER_TRIES:-3}" \
               --timeout="${WORKER_TIMEOUT:-120}" --max-time=3600 --memory="${WORKER_MEMORY:-256}" "$@" ;;
  scheduler) prepare; exec php artisan schedule:work ;;
  migrate)   php artisan migrate --force --no-interaction
             php artisan db:execute-sql database/sql/post-migrate-grants.sql  # app-repo command (build step 2)
             ;;
  artisan)   prepare; exec php artisan "$@" ;;
  *)         exec "$role" "$@" ;;
esac
