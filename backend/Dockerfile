FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev icu-dev \
    && docker-php-ext-install pdo_pgsql intl opcache

# Multi-worker built-in server; suitable behind a reverse proxy for
# small-instance deployments.
ENV PHP_CLI_SERVER_WORKERS=8

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover

EXPOSE 8000

CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
