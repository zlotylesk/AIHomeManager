.PHONY: up min-up down build install migrate migrate-test schema-validate test test-unit test-integration test-e2e test-e2e-install test-newman test-newman-install shell logs logs-php logs-nginx logs-mysql logs-redis logs-rabbitmq logs-worker logs-scheduler logs-node cc routes services messenger-status setup monitoring-up monitoring-down monitoring-logs monitoring-bootstrap phpstan phpstan-baseline cs-check cs-fix rector rector-dry deptrac deptrac-baseline audit analyse fixtures node-install node-audit assets assets-watch assets-prod backup-now restore doctor

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

test:
	docker compose exec php vendor/bin/phpunit

test-unit:
	docker compose exec php vendor/bin/phpunit --testsuite=unit

test-integration:
	docker compose exec php vendor/bin/phpunit --testsuite=integration

test-e2e-install:
	npm install
	npx playwright install chromium

test-e2e:
	docker compose exec -T mysql mysql -uhomemanager -phomemanager homemanager -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE series_episodes; TRUNCATE TABLE series_seasons; TRUNCATE TABLE series; SET FOREIGN_KEY_CHECKS=1;"
	npx playwright test

test-newman-install:
	npm install

test-newman:
	docker compose exec -T mysql mysql -uhomemanager -phomemanager homemanager -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE series_episodes; TRUNCATE TABLE series_seasons; TRUNCATE TABLE series; TRUNCATE TABLE books; TRUNCATE TABLE articles; TRUNCATE TABLE article_daily_picks; SET FOREIGN_KEY_CHECKS=1;"
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

backup-now:
	docker compose exec php bin/console app:backup-database

restore:
	@test -n "$(BACKUP)" || (echo "Usage: make restore BACKUP=backups/homemanager-YYYY-MM-DD.sql.gz" && exit 1)
	gunzip -c $(BACKUP) | docker compose exec -T mysql mysql -uhomemanager -phomemanager homemanager

doctor:
	bash scripts/doctor.sh