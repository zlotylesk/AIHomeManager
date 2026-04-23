FROM php:8.4-fpm-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache $PHPIZE_DEPS rabbitmq-c-dev \
    && pecl install amqp \
    && pecl install redis \
    && docker-php-ext-enable amqp redis \
    && docker-php-ext-install pdo_mysql

COPY php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html