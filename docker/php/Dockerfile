# syntax=docker/dockerfile:1

# The build context is the repository ROOT, not this directory — the prod stage
# copies `app/` in. See .dockerignore for what is kept out of it.

# ---------------------------------------------------------------------------
# base — the runtime that development and production share.
#
# Both stages below start here on purpose: the two images differ in what is
# copied and how PHP is tuned, never in which extensions exist. An extension
# present in one and missing in the other is how a bug becomes reproducible
# only after deployment.
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-alpine AS base

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# bash: the base image is Alpine, not Debian — only POSIX `sh` (busybox ash)
# ships by default. DatabaseBackupService pipes mysqldump into gzip via
# `bash -o pipefail` so a failed mysqldump is detected instead of masked by
# gzip's own exit code; without bash installed here that Process call would fail
# to spawn at all.
#
# mariadb-connector-c: Alpine's `mysql-client` is in fact MariaDB's client, and
# on its own it cannot authenticate against MySQL 8.4 — whose default plugin is
# `caching_sha2_password`, for which it ships no shared object ("Plugin
# caching_sha2_password could not be loaded"). Every scheduled backup therefore
# connected to nothing and produced a 20-byte, empty gzip stream; because the
# pipeline used to run under POSIX `sh`, gzip's success hid mysqldump's failure
# and the run reported "Backup created". This package supplies
# caching_sha2_password.so, so the dump can actually log in.
# rclone: what carries the encrypted dump off the machine when
# BACKUP_REMOTE_BACKEND=rclone. Installed rather than left to the operator for
# the same reason as the two packages above — a missing binary here surfaces only
# as a nightly off-host copy that stops happening, and the whole point of
# encrypting and copying backups is that nobody finds out during a restore. The
# `directory` backend needs none of this, and an instance using it simply carries
# an unused binary; `make doctor` checks rclone only when it is actually selected.
# fcgi: supplies `cgi-fcgi`, the client the compose healthcheck uses to speak
# FastCGI to php-fpm's own `ping` endpoint on 127.0.0.1:9000 — there is no HTTP
# server in this container, so a `wget`/`curl` probe like nginx's has nothing to
# ask.
RUN apk add --no-cache $PHPIZE_DEPS rabbitmq-c-dev mysql-client mariadb-connector-c bash rclone fcgi \
    && pecl install amqp \
    && pecl install redis \
    && pecl install pcov \
    && docker-php-ext-enable amqp redis pcov \
    && docker-php-ext-install pdo_mysql

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Enables the pool's built-in `ping` responder (off by default) so the
# healthcheck has something to ask that answers from php-fpm itself, not from
# the application it serves — a broken Symfony boot must not read as "up".
# Appended rather than a second php-fpm.d/*.conf file: a later file declaring
# the same `[www]` pool name is a second pool, not an override, and php-fpm
# refuses to start with two pools of the same name.
RUN printf '\nping.path = /ping\nping.response = pong\n' >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# dev — what `docker compose up` runs.
#
# Deliberately empty: the source arrives as a bind mount, so an edit is live
# without a rebuild and nothing is baked in. pcov is inert here because php.ini
# sets pcov.enabled=0; `make test-coverage` turns it on per run.
# ---------------------------------------------------------------------------
FROM base AS dev

# ---------------------------------------------------------------------------
# assets — the Encore production build.
#
# Runs during the image build rather than in the long-running `node` container,
# so a deployed image carries the exact bundle its code was built against
# instead of whatever the last `make assets-prod` happened to leave on the host.
# ---------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /var/www/html

# Lockfile first: this layer is rebuilt only when a dependency actually changes.
COPY app/package.json app/package-lock.json ./
RUN npm ci

COPY app/ ./

# Emits public/build/* and — via Workbox InjectManifest — public/sw.js at the
# site root, where its scope is the whole origin.
RUN npm run build

# ---------------------------------------------------------------------------
# prod — the deployed image: code inside, no dev dependencies, cache warmed.
# ---------------------------------------------------------------------------
FROM base AS prod

# Real environment variables win over the values in `.env`, so this is what
# makes the container `prod` regardless of the APP_ENV=dev shipped in app/.env.
ENV APP_ENV=prod \
    APP_DEBUG=0

COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache-prod.ini

# Dependencies from the lockfiles alone, before the source: this layer survives
# every edit that does not touch composer.lock. --no-scripts because the
# auto-scripts want a full application tree, which does not exist yet.
COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
        --no-interaction --no-progress

COPY app/ ./
COPY --from=assets /var/www/html/public/build ./public/build
COPY --from=assets /var/www/html/public/sw.js ./public/sw.js

# --classmap-authoritative: with the file set fixed at build time, the autoloader
# never needs to stat() the filesystem for a class it does not find in the map.
#
# cache:warmup compiles the service container here rather than on the first
# request, which is what the 500 ms health-check budget depends on. It runs with
# placeholder secrets: TokenCipher rejects a key that is not 32 bytes and is
# built at container-compile time, so warming needs keys of the right SHAPE —
# never the real ones, which arrive as environment variables at run time and
# override these. Symfony compiles %env()% as a runtime placeholder, so no value
# below is baked into the warmed container.
RUN APP_SECRET=build-time-only \
    DISCOGS_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
    GOOGLE_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
    TRAKT_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
    SPOTIFY_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
    BACKUP_ENCRYPTION_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && APP_SECRET=build-time-only \
       DISCOGS_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
       GOOGLE_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
       TRAKT_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
       SPOTIFY_TOKEN_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
       BACKUP_ENCRYPTION_KEY=YnVpbGQtdGltZS1wbGFjZWhvbGRlci0zMi1ieXRlcyE= \
       php bin/console cache:warmup --no-interaction \
    && php bin/console assets:install public --no-interaction \
    && chown -R www-data:www-data var public
