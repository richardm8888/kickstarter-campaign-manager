# Combined single-service image: builds the React app and serves it from
# Laravel, so the whole platform fits one free-tier web service.

FROM node:22-alpine AS frontend

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ .
RUN npm run build


FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev icu-dev \
    && docker-php-ext-install pdo_pgsql intl opcache

# Installed but inert without this: `artisan serve` is the CLI SAPI,
# where opcache is off by default.
COPY backend/docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

ENV PHP_CLI_SERVER_WORKERS=8

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ .
RUN composer dump-autoload --optimize \
    && php artisan package:discover \
    && php artisan route:cache \
    && php artisan event:cache

COPY --from=frontend /app/dist ./public

EXPOSE 8000

# config:cache is deliberately at start-up rather than here: it freezes
# every env() call, and APP_KEY and the database do not exist at build.
CMD ["sh", "-c", "php artisan config:cache && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
