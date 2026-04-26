.PHONY: up down build install migrate migrate-test test test-unit test-integration shell logs cc routes services messenger-status setup monitoring-up monitoring-down monitoring-logs

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