FROM php:8.5-fpm-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# bash (HMAI-397): the base image is Alpine, not Debian — only POSIX `sh`
# (busybox ash) ships by default. DatabaseBackupService pipes mysqldump into
# gzip via `bash -o pipefail` so a failed mysqldump is detected instead of
# masked by gzip's own exit code; without bash installed here that Process
# call would fail to spawn at all.
RUN apk add --no-cache $PHPIZE_DEPS rabbitmq-c-dev mysql-client bash \
    && pecl install amqp \
    && pecl install redis \
    && pecl install pcov \
    && docker-php-ext-enable amqp redis pcov \
    && docker-php-ext-install pdo_mysql

COPY php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html