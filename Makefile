.PHONY: prod-build prod-up prod-down prod-migrate prod-logs prod-shell prod-about prod-cert-init prod-cert-renew up min-up down build install migrate migrate-test schema-validate search-index search-reindex search-populate test test-unit test-integration test-coverage test-parallel test-e2e test-e2e-install test-newman test-newman-install shell logs logs-php logs-nginx logs-mysql logs-redis logs-rabbitmq logs-worker logs-scheduler logs-node cc routes services messenger-status setup monitoring-up monitoring-down monitoring-logs monitoring-bootstrap phpstan phpstan-baseline cs-check cs-fix rector rector-dry deptrac deptrac-baseline audit analyse openapi-dump openapi-lint fixtures node-install node-audit assets assets-watch assets-prod test-js backup-now restore restore-drill doctor monitor-run

# Production runs from a different pair of compose files, and every target below
# names both explicitly. A prod command that worked by leaving out `-f` would be
# one forgotten flag away from doing the same thing to the development stack.
#
# The deployment procedure these implement — including where migrations go in
# the order — is in docs/operations.md.
#
# `-p aihm-prod` keeps the production stack in its own Compose project, with its
# own containers and its own volumes. Without it both stacks share the project
# name Compose derives from the directory, and `prod-up` on a machine that also
# develops here would RECREATE the running development containers as production
# ones — in place, without asking. With it the two collide on published ports
# instead, which is a refusal to start rather than a silent conversion.
# The infrastructure credentials, layered the way app/.env and app/.env.local
# already are. `.env` is tracked and holds development values so a fresh clone
# comes up without a setup step; `.env.local` is ignored by git and, when it
# exists, is read second and wins. Production therefore gets a broker password
# and a Redis password that were never committed, without templating anything.
#
# Naming `.env` explicitly is required rather than tidy: passing --env-file at
# all replaces the default file, so listing only `.env.local` would drop
# MYSQL_* and every other value the compose files interpolate.
#
# The `wildcard` guard is what keeps this working on a machine that has no
# `.env.local` — Compose errors on a missing --env-file rather than skipping it,
# which would make `prod-build` fail on a clean clone.
COMPOSE_PROD_ENV = --env-file .env $(if $(wildcard .env.local),--env-file .env.local)
COMPOSE_PROD = docker compose -p aihm-prod $(COMPOSE_PROD_ENV) -f docker-compose.yml -f docker-compose.prod.yml

# Two steps, in this order, on purpose: the nginx image is built FROM the
# application image so that public/ has exactly one source. Compose does not
# order parallel builds by depends_on, so the order is spelled out here.
prod-build:
	$(COMPOSE_PROD) build php
	$(COMPOSE_PROD) build nginx

prod-up:
	$(COMPOSE_PROD) up -d

prod-down:
	$(COMPOSE_PROD) down

# `run --rm`, not `exec`: this has to work before the application containers are
# swapped, so migrations from the NEW image land while the OLD one is still
# serving. It also starts the database via depends_on, so it works on a host
# where nothing is up yet.
prod-migrate:
	$(COMPOSE_PROD) run --rm php bin/console doctrine:migrations:migrate --no-interaction

prod-logs:
	$(COMPOSE_PROD) logs -f

prod-shell:
	$(COMPOSE_PROD) exec php bash

# Reports the environment and debug state the container actually booted with —
# the check that would have caught `dev` running in production.
prod-about:
	$(COMPOSE_PROD) exec php bin/console about

# The first certificate. Run once per instance, with nginx already up on the
# self-signed placeholder — the challenge is answered over HTTP by the running
# server, so this cannot work before `prod-up`.
#
# DOMAIN is the only place the public hostname appears in this repository.
# `--cert-name aihm` is what keeps it that way: it fixes the path the
# certificate lands at, so nginx and the entrypoint script name a lineage rather
# than a domain and neither changes when the domain does.
#
# The restart at the end is not optional. Until the lineage exists the container
# is serving a self-signed placeholder, and the entrypoint script only swaps it
# for the real certificate at start — a reload would re-read the placeholder.
# Renewals need nothing: they replace the file this symlink already points at,
# and nginx reloads on its own timer.
prod-cert-init:
	@test -n "$(DOMAIN)" || { echo "Usage: make prod-cert-init DOMAIN=example.com EMAIL=you@example.com"; exit 1; }
	@test -n "$(EMAIL)" || { echo "Usage: make prod-cert-init DOMAIN=example.com EMAIL=you@example.com"; exit 1; }
	$(COMPOSE_PROD) run --rm --entrypoint certbot certbot certonly \
		--webroot -w /var/www/certbot \
		--cert-name aihm -d $(DOMAIN) \
		--email $(EMAIL) --agree-tos --no-eff-email --non-interactive
	$(COMPOSE_PROD) restart nginx

# Renewal on demand. The certbot service already does this twice a day; this is
# for reading the outcome rather than for causing it — after a failed renewal,
# or to check the plumbing without waiting out the expiry window. `--dry-run`
# first: it exercises the whole challenge against the CA's staging environment
# and so costs nothing against the rate limit for the domain.
prod-cert-renew:
	$(COMPOSE_PROD) run --rm --entrypoint certbot certbot renew --dry-run
	$(COMPOSE_PROD) run --rm --entrypoint certbot certbot renew
	$(COMPOSE_PROD) exec nginx nginx -s reload

up:
	docker compose --profile monitoring up -d

min-up:
	docker compose up -d

down:
	docker compose --profile monitoring down

build:
	docker compose build

install:
	docker compose exec php composer install

migrate:
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

migrate-test:
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction --env=test

schema-validate:
	docker compose exec php bin/console doctrine:schema:validate

# HMAI-362: the OpenSearch index schema is code, so provisioning it is a command
# — the search-side counterpart of doctrine:migrations. `search-index` is safe to
# re-run (it only creates a missing index); `search-reindex` rebuilds under the
# current mappings and switches the alias without a search outage.
search-index:
	docker compose exec php bin/console app:search:index

search-reindex:
	docker compose exec php bin/console app:search:index --reindex

# HMAI-363: fills the index with documents (the scheduler does this every 15 min;
# this is the manual handle — after a restore or a backend switch).
search-populate:
	docker compose exec php bin/console app:search:populate

test:
	docker compose exec php vendor/bin/phpunit

test-unit:
	docker compose exec php vendor/bin/phpunit --testsuite=unit

test-integration:
	docker compose exec php vendor/bin/phpunit --testsuite=integration

# HMAI-245: minimum line-coverage % enforced locally and in CI (see ci.yml
# "Enforce coverage threshold"). Measured baseline (2026-06-30) was 93.66%;
# the gate sits conservatively below it and can be tightened later.
# Override per-run: `make test-coverage COVERAGE_MIN=92`.
COVERAGE_MIN ?= 90

test-coverage:
	docker compose exec php sh -c "mkdir -p var/coverage && php -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover var/coverage/clover.xml --coverage-html var/coverage/html"
	docker compose exec php php bin/coverage-check.php var/coverage/clover.xml $(COVERAGE_MIN) --history=var/coverage-history.txt

# HMAI-355: parallel PHPUnit via paratest with per-process state isolation —
# each worker gets its own database (homemanager_test{token}, via the
# dbname_suffix in doctrine.yaml) and its own Redis logical DB
# (tests/bootstrap.php), so integration tests never collide on the shared
# MySQL/Redis. CI mirrors this. Override the worker count:
#   make test-parallel PARATEST_PROCESSES=8
# pcov is off in php.ini, so it is passed through to each worker for coverage.
PARATEST_PROCESSES ?= 4

test-parallel:
	docker compose exec -T mysql sh -c 'for i in $$(seq 1 $(PARATEST_PROCESSES)); do mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS homemanager_test$$i CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON homemanager_test$$i.* TO \"homemanager\"@\"%\";"; done'
	docker compose exec php sh -c 'for i in $$(seq 1 $(PARATEST_PROCESSES)); do TEST_TOKEN=$$i php bin/console doctrine:migrations:migrate --no-interaction --env=test -q; done'
	docker compose exec php sh -c "mkdir -p var/coverage && php -d pcov.enabled=1 vendor/bin/paratest -p $(PARATEST_PROCESSES) --passthru-php='-d pcov.enabled=1' --coverage-clover var/coverage/clover.xml"
	docker compose exec php php bin/coverage-check.php var/coverage/clover.xml $(COVERAGE_MIN) --history=var/coverage-history.txt

test-e2e-install:
	npm install
	npx playwright install chromium

test-e2e:
	docker compose exec -T mysql mysql -uhomemanager -phomemanager homemanager -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE series_episodes; TRUNCATE TABLE series_seasons; TRUNCATE TABLE series; SET FOREIGN_KEY_CHECKS=1;"
	npx playwright test

test-newman-install:
	npm install

test-newman:
	docker compose exec -T mysql mysql -uhomemanager -phomemanager homemanager -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE series_episodes; TRUNCATE TABLE series_seasons; TRUNCATE TABLE series; TRUNCATE TABLE books; TRUNCATE TABLE articles; TRUNCATE TABLE article_daily_picks; TRUNCATE TABLE budget_transactions; TRUNCATE TABLE budget_categories; TRUNCATE TABLE meal_plan; TRUNCATE TABLE recipe_ingredients; TRUNCATE TABLE recipe_steps; TRUNCATE TABLE recipes; SET FOREIGN_KEY_CHECKS=1;"
	npx newman run tests-e2e/postman/AIHomeManager.postman_collection.json --ignore-redirects --reporters cli

fixtures:
	docker compose exec php bin/console doctrine:fixtures:load --no-interaction --env=dev

shell:
	docker compose exec php bash

logs:
	docker compose logs -f

logs-php:
	docker compose logs -f php

logs-nginx:
	docker compose logs -f nginx

logs-mysql:
	docker compose logs -f mysql

logs-redis:
	docker compose logs -f redis

logs-rabbitmq:
	docker compose logs -f rabbitmq

logs-worker:
	docker compose logs -f messenger_worker

logs-scheduler:
	docker compose logs -f scheduler_worker

logs-node:
	docker compose logs -f node

cc:
	docker compose exec php bin/console cache:clear

routes:
	docker compose exec php bin/console debug:router

services:
	docker compose exec php bin/console debug:container

messenger-status:
	docker compose exec php bin/console debug:messenger

setup: build up install
	docker compose exec php bin/console doctrine:database:create --if-not-exists
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

monitoring-up:
	docker compose --profile monitoring up -d

monitoring-down:
	docker compose --profile monitoring down

monitoring-logs:
	docker compose --profile monitoring logs -f graylog

monitoring-bootstrap:
	bash scripts/graylog-bootstrap.sh

phpstan:
	docker compose exec php vendor/bin/phpstan analyse --memory-limit=1G

phpstan-baseline:
	docker compose exec php vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G

cs-check:
	docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	docker compose exec php vendor/bin/php-cs-fixer fix

rector-dry:
	docker compose exec php vendor/bin/rector process --dry-run

rector:
	docker compose exec php vendor/bin/rector process

deptrac:
	docker compose exec php vendor/bin/deptrac analyse --no-progress

deptrac-baseline:
	docker compose exec php vendor/bin/deptrac analyse --formatter=baseline --output=deptrac-baseline.yaml

audit:
	docker compose exec php composer audit --abandoned=report

analyse: cs-check phpstan deptrac audit

# HMAI-343: dump the generated OpenAPI 3.1 contract to a static openapi.json — the
# same artifact CI publishes. -T avoids a pseudo-TTY so stdout stays clean JSON.
openapi-dump:
	docker compose exec -T php bin/console nelmio:apidoc:dump --format=json --no-ansi > openapi.json

# HMAI-343: lint the contract with Spectral (error severity blocks; warnings nag).
# Runs on the host Node, mirroring the CI openapi-contract job; CLI version pinned
# for reproducibility.
openapi-lint: openapi-dump
	npx --yes @stoplight/spectral-cli@6.16.1 lint openapi.json --ruleset .spectral.yaml --fail-severity=error

node-install:
	docker compose exec node npm install

node-audit:
	docker compose exec node npm audit --audit-level=high

assets:
	docker compose exec node npm run dev

assets-watch:
	docker compose exec node npm run watch

assets-prod:
	docker compose exec node npm run build

test-js:
	docker compose exec node npm test

backup-now:
	docker compose exec php bin/console app:backup-database

# Decrypt in the php container, load in the mysql container, nothing on disk in
# between — the plaintext dump exists only as bytes moving down a pipe.
#
# Neither half takes a credential from this file. The dump's key comes from
# BACKUP_ENCRYPTION_KEY in the app's environment, and the database password is
# read inside the mysql container from the variable Compose already put there,
# via MYSQL_PWD rather than -p: an argument is visible to every process on the
# box through `ps`. The old hard-coded `-uhomemanager -phomemanager` worked on
# exactly one machine, and not on the one where this command matters.
#
# `bash -o pipefail` for the reason the backup pipeline uses it: without it the
# pipeline reports gunzip's status, so a failed decrypt would exit 0 and this
# would look like a restore that loaded nothing.
restore:
	@test -n "$(BACKUP)" || (echo "Usage: make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz.enc" && exit 1)
	docker compose exec -T php bash -o pipefail -c 'bin/console app:backup:decrypt "$$0" | gunzip' "$(BACKUP)" \
		| docker compose exec -T mysql sh -c 'MYSQL_PWD="$$MYSQL_PASSWORD" exec mysql --default-character-set=utf8mb4 -h127.0.0.1 -u"$$MYSQL_USER" "$$MYSQL_DATABASE"'

# Proves the backups restore, without touching the live database.
#
# The one thing every freshness and size check in this system cannot tell you:
# they establish that a file is recent and plausibly large, never that a database
# can be rebuilt from it. This restores into a scratch schema and compares row
# counts against the live one, so "our backups work" is a thing that gets
# re-established on demand rather than remembered from the day someone tried it.
restore-drill:
	bash scripts/restore-drill.sh

doctor:
	bash scripts/doctor.sh

monitor-run:
	docker compose exec php bin/console app:monitor:run