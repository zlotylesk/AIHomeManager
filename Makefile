.PHONY: prod-build prod-up prod-down prod-migrate prod-logs prod-shell prod-about up min-up down build install migrate migrate-test schema-validate search-index search-reindex search-populate test test-unit test-integration test-coverage test-parallel test-e2e test-e2e-install test-newman test-newman-install shell logs logs-php logs-nginx logs-mysql logs-redis logs-rabbitmq logs-worker logs-scheduler logs-node cc routes services messenger-status setup monitoring-up monitoring-down monitoring-logs monitoring-bootstrap phpstan phpstan-baseline cs-check cs-fix rector rector-dry deptrac deptrac-baseline audit analyse openapi-dump openapi-lint fixtures node-install node-audit assets assets-watch assets-prod test-js backup-now restore doctor monitor-run

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
COMPOSE_PROD = docker compose -p aihm-prod -f docker-compose.yml -f docker-compose.prod.yml

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

restore:
	@test -n "$(BACKUP)" || (echo "Usage: make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz" && exit 1)
	gunzip -c $(BACKUP) | docker compose exec -T mysql mysql -uhomemanager -phomemanager homemanager

doctor:
	bash scripts/doctor.sh

monitor-run:
	docker compose exec php bin/console app:monitor:run