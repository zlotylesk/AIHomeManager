.PHONY: up down build install migrate migrate-test test test-unit test-integration test-e2e test-e2e-install shell logs cc routes services messenger-status setup monitoring-up monitoring-down monitoring-logs phpstan phpstan-baseline cs-check cs-fix rector rector-dry analyse

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

install:
	docker compose exec php composer install

migrate:
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

migrate-test:
	docker compose exec php bin/console doctrine:migrations:migrate --no-interaction --env=test

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

shell:
	docker compose exec php bash

logs:
	docker compose logs -f

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

analyse: cs-check phpstan