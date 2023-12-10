#!/usr/bin/env bash

set -e

ENVIRONMENT="${APP_ENV:-dev}"

if [[ "$ENVIRONMENT" == "dev" ]]
then
    echo "== Clearing cache and installing composer =="
    # Delete temp, it might be incompatible with current changes
    rm -rf var/cache/*

    # Always have up to date dependencies
    composer install --no-interaction
fi

## Database setup

if [[ "$ENVIRONMENT" == "dev" ]] || [[ "$SKIP_DATABASE_MIGRATIONS" != "true" ]]; then
    wait-for-it ${DATABASE_HOST:-postgres}:${DATABASE_PORT:-5432} --timeout=15
fi

if [[ "$SKIP_DATABASE_MIGRATIONS" != "true" ]]; then
    time bin/console doctrine:migrations:migrate -vv --allow-no-migration --all-or-nothing --no-interaction
else
    echo "== Skipping database migrations =="
fi

echo "== Setting 777 permission to var/ =="
mkdir -p var/cache
time chmod -R 777 var
