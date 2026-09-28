# syntax=docker/dockerfile:1.7
#
# Kaduna eDQA: one Dockerfile, eight targets (DEPLOY.md §6.1).
#   base    PHP-FPM 8.3 + extensions, non-root user, production ini          (internal)
#   vendor  base + Composer: production vendor/ and Wayfinder route files    (internal)
#   assets  Node: npm ci + vite build, precompressed .br/.gz                 (internal)
#   app     code + vendor + public/build; runs app, workers, scheduler, migrate
#   worker  app + Node, Chromium, fonts, Puppeteer (PDF exports)
#   web     Caddy + public/ only (no PHP)
#   ci      app + dev Composer deps and tests (Pest, Larastan, Pint)
#   dev     base + Composer + Xdebug; source is bind-mounted, not copied
#
# Base images are pinned by digest; the tag is in the comment. Renovate keeps them current.

# php:8.3-fpm-bookworm
ARG PHP_IMAGE=php@sha256:536a1188a926efe7e6f927b7ca01f3a043c63d0a527567c6d4d3bf9fbd637866
# node:24-bookworm-slim (ARCHITECTURE.md D-21)
ARG NODE_IMAGE=node@sha256:0e0ff40c39bc087845bfb27465a0df4ea419520094bc35842ff83dd8cbe6f9b6
# composer:2
ARG COMPOSER_IMAGE=composer@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7
# mlocati/php-extension-installer:2
ARG PHPEXT_IMAGE=mlocati/php-extension-installer@sha256:1afade3e29cfc97362cf5885e5ac333bf2faab1146cb28ebbb59b17e68f87e88
# caddy:2
ARG CADDY_IMAGE=caddy@sha256:0c994536bddb66445885237f1a5dcc1916bccea922661c76b4e9fc24061f9b52

FROM ${COMPOSER_IMAGE} AS composer-bin
FROM ${PHPEXT_IMAGE} AS phpext-bin

# ------------------------------------------------------------------------------------ base
FROM ${PHP_IMAGE} AS base
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
COPY --from=phpext-bin /usr/bin/install-php-extensions /usr/local/bin/
# libfcgi-bin provides cgi-fcgi for the FPM /ping healthcheck.
RUN install-php-extensions pdo_pgsql redis intl bcmath gd zip opcache pcntl \
 && apt-get update \
 && apt-get install -y --no-install-recommends libfcgi-bin=2.4.2-2+deb12u1 \
 && rm -rf /var/lib/apt/lists/*
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g www-data www-data
# Numeric IDs for USER, so the runtime user is unambiguous on any host (hadolint DL3066).
ENV EDQA_UID=${UID} EDQA_GID=${GID}
COPY docker/php/php.ini docker/php/opcache.ini /usr/local/etc/php/conf.d/
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-edqa.conf
WORKDIR /var/www/html

# ---------------------------------------------------------------------------------- vendor
FROM base AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && php artisan package:discover --ansi \
 # Wayfinder's TypeScript route helpers need PHP; the assets stage has none (D-19).
 && php artisan wayfinder:generate --with-form

# ---------------------------------------------------------------------------------- assets
FROM ${NODE_IMAGE} AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
COPY --from=vendor /var/www/html/resources/js/actions resources/js/actions
COPY --from=vendor /var/www/html/resources/js/routes resources/js/routes
COPY --from=vendor /var/www/html/resources/js/wayfinder resources/js/wayfinder
# The Wayfinder files above are already generated; don't let the Vite plugin call PHP.
ENV WAYFINDER_SKIP=1
RUN npm run build

# ------------------------------------------------------------------------------------- app
FROM base AS app
COPY --chown=www-data:www-data --from=vendor /var/www/html /var/www/html
COPY --chown=www-data:www-data --from=assets /build/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/edqa-entrypoint
RUN rm -rf tests node_modules .git resources/js/actions resources/js/routes resources/js/wayfinder \
 && chmod +x /usr/local/bin/edqa-entrypoint
# Numeric (the UID/GID build args); hadolint cannot expand variables.
# hadolint ignore=DL3066
USER ${EDQA_UID}:${EDQA_GID}
EXPOSE 9000
# No HEALTHCHECK here: the same image runs app, workers, scheduler and migrate.
# The FPM healthcheck is declared on the 'app' service in Compose.
ENTRYPOINT ["edqa-entrypoint"]
CMD ["app"]

# ---------------------------------------------------------------------------------- worker
# Node and Puppeteer come from the pinned Node image, not Debian's nodejs (v18, end of life).
FROM ${NODE_IMAGE} AS puppeteer
ARG PUPPETEER_VERSION=25.12.0
RUN PUPPETEER_SKIP_DOWNLOAD=1 npm install --global "puppeteer@${PUPPETEER_VERSION}" \
 && npm cache clean --force

FROM app AS worker
USER 0
# Fonts are pinned. Chromium is deliberately not: Debian drops superseded Chromium builds from
# its mirrors every few weeks, so a pin would break builds and hold back security fixes.
# hadolint ignore=DL3008
RUN apt-get update \
 && apt-get install -y --no-install-recommends chromium \
      fonts-liberation=1:1.07.4-11 fonts-noto-core=20201225-1 \
 && rm -rf /var/lib/apt/lists/*
COPY --from=puppeteer /usr/local/bin/node /usr/local/bin/node
COPY --from=puppeteer /usr/local/lib/node_modules /usr/local/lib/node_modules
# DEPLOY.md §15.2 sets BROWSERSHOT_NODE_BINARY=/usr/bin/node, so make that path valid too.
RUN ln -s /usr/local/bin/node /usr/bin/node \
 && ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
ENV BROWSERSHOT_CHROME_PATH=/usr/bin/chromium \
    BROWSERSHOT_NODE_BINARY=/usr/bin/node \
    NODE_PATH=/usr/local/lib/node_modules
# Numeric (the UID/GID build args); hadolint cannot expand variables.
# hadolint ignore=DL3066
USER ${EDQA_UID}:${EDQA_GID}
CMD ["worker"]

# ------------------------------------------------------------------------------------- web
FROM ${CADDY_IMAGE} AS web
COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /srv/public

# -------------------------------------------------------------------------------------- ci
FROM app AS ci
USER 0
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY --chown=www-data:www-data tests tests
# Numeric (the UID/GID build args); hadolint cannot expand variables.
# hadolint ignore=DL3066
USER ${EDQA_UID}:${EDQA_GID}
RUN composer install --prefer-dist --no-interaction --no-progress
ENTRYPOINT []
CMD ["composer", "check"]

# ------------------------------------------------------------------------------------- dev
FROM base AS dev
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
RUN install-php-extensions xdebug
COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
COPY docker/entrypoint.sh /usr/local/bin/edqa-entrypoint
RUN chmod +x /usr/local/bin/edqa-entrypoint
ENV XDEBUG_MODE=off \
    COMPOSER_HOME=/tmp/composer
# Numeric (the UID/GID build args); hadolint cannot expand variables.
# hadolint ignore=DL3066
USER ${EDQA_UID}:${EDQA_GID}
EXPOSE 9000
ENTRYPOINT ["edqa-entrypoint"]
CMD ["app"]
