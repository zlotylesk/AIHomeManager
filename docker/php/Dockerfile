FROM php:8.5-fpm-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# bash (HMAI-397): the base image is Alpine, not Debian — only POSIX `sh`
# (busybox ash) ships by default. DatabaseBackupService pipes mysqldump into
# gzip via `bash -o pipefail` so a failed mysqldump is detected instead of
# masked by gzip's own exit code; without bash installed here that Process
# call would fail to spawn at all.
#
# mariadb-connector-c (HMAI-395 epic review): Alpine's `mysql-client` is in fact
# MariaDB's client, and on its own it cannot authenticate against MySQL 8.4 —
# whose default plugin is `caching_sha2_password`, for which it ships no shared
# object ("Plugin caching_sha2_password could not be loaded"). Every scheduled
# backup therefore connected to nothing and produced a 20-byte, empty gzip
# stream; because the pipeline used to run under POSIX `sh`, gzip's success hid
# mysqldump's failure and the run reported "Backup created". This package
# supplies caching_sha2_password.so, so the dump can actually log in.
RUN apk add --no-cache $PHPIZE_DEPS rabbitmq-c-dev mysql-client mariadb-connector-c bash \
    && pecl install amqp \
    && pecl install redis \
    && pecl install pcov \
    && docker-php-ext-enable amqp redis pcov \
    && docker-php-ext-install pdo_mysql

COPY php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html