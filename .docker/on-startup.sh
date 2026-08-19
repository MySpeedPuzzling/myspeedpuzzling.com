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

wait-for-it ${REDIS_URI:-"redis:6379"} --timeout=15

## Database setup

if [[ "$ENVIRONMENT" == "dev" ]] || [[ "$SKIP_DATABASE_MIGRATIONS" != "true" ]]; then
    wait-for-it ${DATABASE_HOST:-postgres}:${DATABASE_PORT:-5432} --timeout=15
fi

if [[ "$SKIP_DATABASE_MIGRATIONS" != "true" ]]; then
    time bin/console doctrine:migrations:migrate -vv --allow-no-migration --all-or-nothing --no-interaction
else
    echo "== Skipping database migrations =="
fi

mkdir -p var/cache

# The recursive chmod is for the bind-mounted environments only: dev and test
# mount the repo at /app, so host- and container-created files under var/ must
# stay mutually writable. In production var/ is not a mount at all - it is the
# image's own overlayfs layer, private to each container - and every process in
# the stack (web, api, messenger-consumer and the one-off cron containers) runs
# as root, so 777 buys nothing there. It costs plenty: chmod makes overlayfs copy
# up all ~3.5k build-warmed cache files (~58 MB) on EVERY container start, which
# measured 37-66s under I/O contention. That was the entire container start
# latency and it blew the deploy health gate three times on 2026-08-19.
if [[ "$ENVIRONMENT" != "prod" ]]; then
    echo "== Setting 777 permission to var/ =="
    time chmod -R 777 var
fi

# Failed S3 uploads spool here until the retry cron drains them; in production
# this is a persistent named volume mounted outside of /app
SPOOL_DIR="${UPLOAD_SPOOL_DIR:-var/upload-spool}"
mkdir -p "$SPOOL_DIR"
chmod 777 "$SPOOL_DIR"
