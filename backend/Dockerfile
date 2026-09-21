FROM composer:2 AS composer

FROM php:8.4-fpm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libxml2-dev unzip curl \
    && docker-php-ext-install pdo_pgsql zip xml \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress
COPY . .
