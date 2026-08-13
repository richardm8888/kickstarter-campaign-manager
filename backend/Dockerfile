FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev icu-dev \
    && docker-php-ext-install pdo_pgsql intl opcache

COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Multi-worker built-in server; suitable behind a reverse proxy for
# small-instance deployments.
ENV PHP_CLI_SERVER_WORKERS=8

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

# Routes and events are the same whoever runs this image, so they are
# compiled once here. Config is not: caching it freezes the values of
# every env() call, and at build time APP_KEY and the database
# credentials do not exist yet. That one happens at start-up.
RUN composer dump-autoload --optimize \
    && php artisan package:discover \
    && php artisan route:cache \
    && php artisan event:cache

EXPOSE 8000

COPY docker/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start

CMD ["start"]
