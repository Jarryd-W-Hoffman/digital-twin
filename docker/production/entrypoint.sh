#!/bin/sh
# Caches the configuration, routes and views from this container's
# environment, then runs the container's command: php-fpm, a queue worker,
# the scheduler or a migration.
set -e

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Run docker/production/meridian install, or set it in docker/production/.env." >&2
    exit 1
fi

php artisan optimize --quiet

exec "$@"
