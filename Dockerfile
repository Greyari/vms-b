# syntax=docker/dockerfile:1
# INIT-ASL backend image — one container per app (nginx + php-fpm + scheduler).
# Pipeline: Developer -> Local Ubuntu test server (docker compose) -> Azure Container Apps.

# ---- stage 1: composer dependencies ------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts --ignore-platform-reqs

# ---- stage 2: runtime ----------------------------------------------------------
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor postgresql-dev icu-dev libzip-dev libpng-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip opcache bcmath pcntl gd \
 && rm -rf /var/cache/apk/*

COPY docker/php.ini          /usr/local/etc/php/conf.d/99-app.ini
COPY docker/nginx.conf       /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh    /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=25s \
  CMD wget -qO- http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/entrypoint.sh"]
