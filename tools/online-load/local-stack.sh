#!/usr/bin/env bash
# The local stack the load script runs against (README.md "Local run") - throwaway containers on the dev Docker
# network, every name prefixed NAME_PREFIX, nothing of the shared services' data touched:
#   <prefix>-web       FrankenPHP in worker mode the way production runs it (APP_ENV=prod, OPcache without timestamp
#                      checks, `num WORKERS` workers, max_requests 500 - lily.srv apps/myspeedpuzzling/compose.yaml)
#   <prefix>-consumer  messenger:consume async (VerifyOnlineMedia - the HEAD after every upload)
#   <prefix>-redis     the cache (db 1) and the state (db 2: presence, the breaker's counters) - its own instance
#   <prefix>-mercure   the Mercure image production runs (same digest), anonymous subscribers allowed
#   <prefix>-minio     the primary evidence store, bucket <prefix>-primary
#   <prefix>-garage    the second store (Garage, path-style; bucket + key made with the garage CLI)
#
# Usage: DATABASE_URL=postgresql://…/<db>_test… local-stack.sh up
#        local-stack.sh down|reload-app|status|stats|block-primary|unblock-primary|console <command…>
#   - the database must be a throwaway copy (named …_test_<suffix>, e.g. a copy of a test database's template) - never
#     the dev database, never a test suite's own
#   - APP_DIR (default: this repository) is mounted at /app; VENDOR_DIR / BUILD_DIR default to its vendor/ and
#     public/build/ (a worktree without them: point both at the main checkout's)
#   - generated secrets and configuration live in out/stack-<prefix>/ (gitignored)
set -euo pipefail

PREFIX="${NAME_PREFIX:-online-load}"
NETWORK="${NETWORK:-speedpuzzlingcz_default}"
DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$DIR/../.." && pwd)}"
VENDOR_DIR="${VENDOR_DIR:-$APP_DIR/vendor}"
BUILD_DIR="${BUILD_DIR:-$APP_DIR/public/build}"
WORKERS="${WORKERS:-32}"
STATE_DIR="$DIR/out/stack-$PREFIX"

PHP_IMAGE="${PHP_IMAGE:-ghcr.io/myspeedpuzzling/web-base-php85:main}"
# Production's hub (lily.srv: dunglas/mercure v2.11.3 = Mercure 0.24.2)
MERCURE_IMAGE="${MERCURE_IMAGE:-dunglas/mercure@sha256:916834e499613d475e9ad5118cd2349875338f52d99d65b58aeff82f97ccb0c6}"
REDIS_IMAGE="${REDIS_IMAGE:-redis:7.2}"
MINIO_IMAGE="${MINIO_IMAGE:-minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e}"
GARAGE_IMAGE="${GARAGE_IMAGE:-dxflrs/garage:v2.4.1}"

WEB="$PREFIX-web"
CONSUMER="$PREFIX-consumer"
REDIS="$PREFIX-redis"
MERCURE="$PREFIX-mercure"
MINIO="$PREFIX-minio"
GARAGE="$PREFIX-garage"
PRIMARY_BUCKET="$PREFIX-primary"
SECONDARY_BUCKET="$PREFIX-secondary"

die() {
  echo "local-stack.sh: $*" >&2
  exit 1
}

check_database() {
  [ -n "${DATABASE_URL:-}" ] || die "DATABASE_URL is required (a throwaway copy named …_test_<suffix>)"
  local name="${DATABASE_URL%%\?*}"
  name="${name##*/}"
  # A copy of its own: never the dev database, never a test suite's database or its template
  [[ "$name" =~ _test_[a-z0-9]+$ ]] || die "refusing database '$name': only a throwaway copy (…_test_<suffix>)"
  [[ "$name" != *_template ]] || die "refusing database '$name': a template is copied, never used"
}

secret() {
  openssl rand -hex 32
}

app_env_file() {
  # shellcheck disable=SC1091
  source "$STATE_DIR/secrets.env"
  local lock_dsn="${DATABASE_URL/postgresql:/postgresql+advisory:}"
  # Real values here win over .env / .env.local (Symfony Dotenv never overrides set variables) - nothing of the
  # developer's own keys (Stripe, internal API, mailers) reaches these containers
  cat > "$STATE_DIR/app.env" <<EOF
APP_ENV=prod
APP_DEBUG=0
PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
PHP_ZEND_ASSERTIONS=-1
APP_URL=http://$WEB:8080/
DATABASE_URL=$DATABASE_URL
LOCK_DSN=$lock_dsn
MESSENGER_TRANSPORT_DSN=doctrine://default
REDIS_CACHE_DSN=redis://$REDIS:6379/1
REDIS_STATE_DSN=redis://$REDIS:6379/2
MERCURE_URL=http://$MERCURE/.well-known/mercure
MERCURE_PUBLIC_URL=http://$MERCURE/.well-known/mercure
MERCURE_JWT_SECRET=$MERCURE_SECRET
S3_ENDPOINT=http://$MINIO:9000
S3_REGION=us-east-1
S3_BUCKET=$PRIMARY_BUCKET
S3_ACCESS_KEY=$MINIO_USER
S3_SECRET_KEY=$MINIO_PASSWORD
UPLOADS_BASE_URL=http://$MINIO:9000/$PRIMARY_BUCKET
ONLINE_MEDIA_PUBLIC_ENDPOINT=http://$MINIO:9000
ONLINE_MEDIA_SECONDARY_ENDPOINT=http://$GARAGE:3900
ONLINE_MEDIA_SECONDARY_REGION=garage
ONLINE_MEDIA_SECONDARY_BUCKET=$SECONDARY_BUCKET
ONLINE_MEDIA_SECONDARY_ACCESS_KEY=${GARAGE_KEY_ID:-}
ONLINE_MEDIA_SECONDARY_SECRET_KEY=${GARAGE_SECRET_KEY:-}
ONLINE_MEDIA_SECONDARY_PATH_STYLE=1
UPLOAD_SPOOL_DIR=/var/spool/online-load
TRUSTED_PROXIES=PRIVATE_SUBNETS
SENTRY_DSN=
MAILER_TRANSACTIONAL_DSN=null://null
MAILER_NOTIFICATIONS_DSN=null://null
LISTMONK_API_TOKEN=
STRIPE_API_KEY=
STRIPE_WEBHOOK_SECRET=
INTERNAL_API_TOKEN=
LIVEKIT_API_URL=
EOF
}

php_run() {
  # A one-off PHP command with the stack's environment (cache warm-up, the app's console commands)
  docker run --rm --name "$PREFIX-php-$$" --network "$NETWORK" \
    --env-file "$STATE_DIR/app.env" \
    -v "$APP_DIR:/app" -v "$VENDOR_DIR:/app/vendor:ro" -v "$BUILD_DIR:/app/public/build:ro" \
    -w /app --entrypoint php "$PHP_IMAGE" "$@"
}

wait_for() {
  local what="$1"
  shift

  for _ in $(seq 1 60); do
    if "$@" > /dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  die "$what did not come up"
}

up() {
  check_database
  [ -d "$VENDOR_DIR" ] || die "no vendor/ at $VENDOR_DIR (set VENDOR_DIR)"
  mkdir -p "$STATE_DIR"

  if [ ! -f "$STATE_DIR/secrets.env" ]; then
    cat > "$STATE_DIR/secrets.env" <<EOF
MERCURE_SECRET=$(secret)
MINIO_USER=loadtest
MINIO_PASSWORD=$(secret)
GARAGE_RPC_SECRET=$(secret)
GARAGE_ADMIN_TOKEN=$(secret)
EOF
  fi

  # shellcheck disable=SC1091
  source "$STATE_DIR/secrets.env"

  cat > "$STATE_DIR/garage.toml" <<EOF
metadata_dir = "/tmp/garage/meta"
data_dir = "/tmp/garage/data"
db_engine = "sqlite"
replication_factor = 1
rpc_bind_addr = "[::]:3901"
rpc_public_addr = "127.0.0.1:3901"
rpc_secret = "$GARAGE_RPC_SECRET"

[s3_api]
s3_region = "garage"
api_bind_addr = "[::]:3900"

[admin]
api_bind_addr = "[::]:3903"
admin_token = "$GARAGE_ADMIN_TOKEN"
EOF

  docker run -d --name "$REDIS" --network "$NETWORK" "$REDIS_IMAGE" redis-server --save "" --appendonly no > /dev/null

  docker run -d --name "$MERCURE" --network "$NETWORK" \
    -e SERVER_NAME=:80 \
    -e MERCURE_PUBLISHER_JWT_KEY="$MERCURE_SECRET" \
    -e MERCURE_SUBSCRIBER_JWT_KEY="$MERCURE_SECRET" \
    -e GLOBAL_OPTIONS="metrics" \
    -e MERCURE_EXTRA_DIRECTIVES=$'cors_origins *\nanonymous' \
    "$MERCURE_IMAGE" > /dev/null

  docker run -d --name "$MINIO" --network "$NETWORK" \
    -e MINIO_ROOT_USER="$MINIO_USER" -e MINIO_ROOT_PASSWORD="$MINIO_PASSWORD" \
    "$MINIO_IMAGE" server /data > /dev/null

  docker run -d --name "$GARAGE" --network "$NETWORK" \
    -v "$STATE_DIR/garage.toml:/etc/garage.toml:ro" \
    "$GARAGE_IMAGE" /garage server --single-node > /dev/null

  wait_for "MinIO" docker exec "$MINIO" mc alias set local http://127.0.0.1:9000 "$MINIO_USER" "$MINIO_PASSWORD"
  docker exec "$MINIO" mc mb --ignore-existing "local/$PRIMARY_BUCKET" > /dev/null

  wait_for "Garage" docker exec "$GARAGE" /garage bucket list
  docker exec "$GARAGE" /garage bucket create "$SECONDARY_BUCKET" > /dev/null
  local key
  key="$(docker exec "$GARAGE" /garage key create "$PREFIX-key")"
  GARAGE_KEY_ID="$(printf '%s\n' "$key" | sed -n 's/^Key ID:[[:space:]]*//p' | head -1)"
  GARAGE_SECRET_KEY="$(printf '%s\n' "$key" | sed -n 's/^Secret key:[[:space:]]*//p' | head -1)"
  [ -n "$GARAGE_KEY_ID" ] && [ -n "$GARAGE_SECRET_KEY" ] || die "could not read the Garage key: $key"
  docker exec "$GARAGE" /garage bucket allow --read --write --owner "$SECONDARY_BUCKET" --key "$PREFIX-key" > /dev/null

  app_env_file

  # The production cache, built once before the workers start (they would race to build it)
  php_run bin/console cache:clear --no-warmup > /dev/null
  php_run bin/console cache:warmup > /dev/null

  docker run -d --name "$WEB" --network "$NETWORK" \
    --env-file "$STATE_DIR/app.env" \
    -e FRANKENPHP_WORKER=1 \
    -e "FRANKENPHP_CONFIG=$(printf 'worker {\n    file /app/public/index.php\n    num %s\n}\nmax_wait_time 30s\nmax_requests 500' "$WORKERS")" \
    -v "$APP_DIR:/app" -v "$VENDOR_DIR:/app/vendor:ro" -v "$BUILD_DIR:/app/public/build:ro" \
    "$PHP_IMAGE" > /dev/null

  docker run -d --name "$CONSUMER" --network "$NETWORK" \
    --env-file "$STATE_DIR/app.env" \
    -v "$APP_DIR:/app" -v "$VENDOR_DIR:/app/vendor:ro" -v "$BUILD_DIR:/app/public/build:ro" \
    -w /app --entrypoint php "$PHP_IMAGE" bin/console messenger:consume async --time-limit=7200 --memory-limit=256M > /dev/null

  wait_for "the web container" docker exec "$WEB" curl -fsS http://localhost:8080/-/health-check/container
  status
}

down() {
  # One container per command, with its anonymous volumes - nothing else
  for name in "$CONSUMER" "$WEB" "$GARAGE" "$MINIO" "$MERCURE" "$REDIS"; do
    if docker container inspect "$name" > /dev/null 2>&1; then
      docker rm -f -v "$name" > /dev/null
      echo "removed $name"
    fi
  done
}

# After a code change: the production cache again, the workers and the consumer started anew (OPcache never looks at
# the files' timestamps in production mode)
reload_app() {
  [ -f "$STATE_DIR/app.env" ] || die "no stack - run up first"
  php_run bin/console cache:clear --no-warmup > /dev/null
  php_run bin/console cache:warmup > /dev/null
  docker restart "$WEB" "$CONSUMER" > /dev/null
  wait_for "the web container" docker exec "$WEB" curl -fsS http://localhost:8080/-/health-check/container
  status
}

status() {
  docker ps -a --filter "name=^$PREFIX-" --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
}

stats() {
  docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}' \
    "$WEB" "$CONSUMER" "$REDIS" "$MERCURE" "$MINIO" "$GARAGE"
}

case "${1:-}" in
  up) up ;;
  down) down ;;
  reload-app) reload_app ;;
  # The app's console with the stack's environment, e.g. `console myspeedpuzzling:online-media:cors --store secondary`
  console)
    [ -f "$STATE_DIR/app.env" ] || die "no stack - run up first"
    shift
    php_run bin/console "$@"
    ;;
  status) status ;;
  stats) stats ;;
  # The primary store "down": stopped (connections refused). `unblock-primary` starts the same container again -
  # its data and bucket are kept
  block-primary) docker stop "$MINIO" > /dev/null && echo "$MINIO stopped" ;;
  unblock-primary) docker start "$MINIO" > /dev/null && echo "$MINIO started" ;;
  *) die "usage: local-stack.sh up|down|reload-app|status|stats|block-primary|unblock-primary|console <command…>" ;;
esac
