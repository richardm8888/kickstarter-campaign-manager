#!/bin/sh
#
# Container entrypoint for the API.
#
# Everything here needs the environment, so it cannot be done at build
# time: config:cache freezes the result of every env() call, and at build
# time there is no APP_KEY and no database.
set -e

# Without this Laravel re-reads and re-merges every file in config/ on
# each request. It is the single cheapest thing on this list.
php artisan config:cache

php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8000
