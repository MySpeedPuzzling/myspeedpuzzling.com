#!/usr/bin/env bash
# The judge page chaos suite (tools/judge-chaos/README.md) - one command for the whole campaign:
#
#   chaos.sh all [scenario…]   build, up, seed, run (all or the named scenarios), down - the orchestrator's command
#   chaos.sh up                the throwaway database copy + seed + the stack (web, redis, mercure, proxy, selenium)
#   chaos.sh run [scenario…]   the scenarios against a running stack (`chaos.sh list` names them); QUICK=1 shortens
#                              the long waits (the 10-minute background tab → 2 minutes)
#   chaos.sh seed              the round again (results, receipts, proposals cleared; stopwatch restarted)
#   chaos.sh down              every container of the stack removed with its volumes, the database copy dropped
#   chaos.sh status | logs <web|proxy|selenium|mercure> | list | build | videos
#
# Every name is prefixed NAME_PREFIX (default rlt3); the database is speedpuzzling_<prefix>_test_chaos, copied from
# the test suite's template speedpuzzling_<prefix>_test_template (`run.sh <prefix>` with any test makes it). Nothing of
# the shared dev services is touched: the shared Postgres only gets (and loses) the copy.
set -euo pipefail

PREFIX="${NAME_PREFIX:-rlt3}"
NETWORK="${NETWORK:-speedpuzzlingcz_default}"
DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$DIR/../.." && pwd)}"
MAIN_CHECKOUT="${MAIN_CHECKOUT:-/Users/janmikes/www/speedpuzzling.cz}"
VENDOR_DIR="${VENDOR_DIR:-$([ -f "$APP_DIR/vendor/autoload.php" ] && echo "$APP_DIR/vendor" || echo "$MAIN_CHECKOUT/vendor")}"
BUILD_DIR="${BUILD_DIR:-$APP_DIR/public/build}"
OUT="${OUT:-$DIR/out}"
WORKERS="${WORKERS:-8}"
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-speedpuzzlingcz-postgres-1}"
STATE_DIR="$DIR/out/stack-$PREFIX"

DB="speedpuzzling_${PREFIX}_test_chaos"
TEMPLATE="speedpuzzling_${PREFIX}_test_template"
DATABASE_URL="postgresql://postgres:postgres@postgres:5432/$DB?serverVersion=18&charset=utf8"

PHP_IMAGE="${PHP_IMAGE:-ghcr.io/myspeedpuzzling/web-base-php85:main}"
# Production's hub (as tools/online-load/local-stack.sh)
MERCURE_IMAGE="${MERCURE_IMAGE:-dunglas/mercure@sha256:27845c71dd26878bfb77bb0723fd3ea26e55d07ad0025b1c0948533ac30a5afc}"
REDIS_IMAGE="${REDIS_IMAGE:-redis:7.2}"
SELENIUM_IMAGE="${SELENIUM_IMAGE:-selenium/standalone-chromium:latest}"
RUNNER_IMAGE="$PREFIX-judge-chaos:local"

WEB="$PREFIX-web"
REDIS="$PREFIX-redis"
MERCURE="$PREFIX-mercure"
PROXY="$PREFIX-proxy"
SELENIUM="$PREFIX-selenium"

die() {
  echo "chaos.sh: $*" >&2
  exit 1
}

psql_admin() {
  docker exec "$POSTGRES_CONTAINER" psql -U postgres -v ON_ERROR_STOP=1 -q "$@"
}

build() {
  docker build -q -t "$RUNNER_IMAGE" "$DIR/docker" > /dev/null
  [ -f "$BUILD_DIR/entrypoints.json" ] || die "no asset build at $BUILD_DIR - run \`npx encore production\` in the worktree first (README)"
}

database() {
  [[ "$DB" =~ _test_chaos$ ]] || die "refusing database $DB"
  psql_admin -tc "SELECT 1 FROM pg_database WHERE datname = '$TEMPLATE'" | grep -q 1 \
    || die "no $TEMPLATE - run any test through run.sh $PREFIX first (the bootstrap makes it)"
  psql_admin -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)" -c "CREATE DATABASE $DB TEMPLATE $TEMPLATE"
  seed
}

seed() {
  docker exec -i "$POSTGRES_CONTAINER" psql -U postgres -q -d "$DB" < "$DIR/seed.sql"
  echo "seeded $DB"
}

app_env_file() {
  mkdir -p "$STATE_DIR"
  [ -f "$STATE_DIR/secrets.env" ] || echo "MERCURE_SECRET=$(openssl rand -hex 32)" > "$STATE_DIR/secrets.env"
  # shellcheck disable=SC1091
  source "$STATE_DIR/secrets.env"
  # Real values win over .env / .env.local - nothing of the developer's own keys reaches these containers. The browser
  # reaches the site and the hub only through the proxy (one origin, one place to cut the network)
  cat > "$STATE_DIR/app.env" <<EOF
APP_ENV=prod
APP_DEBUG=0
PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
PHP_ZEND_ASSERTIONS=-1
APP_URL=http://$PROXY:8080/
DATABASE_URL=$DATABASE_URL
LOCK_DSN=${DATABASE_URL/postgresql:/postgresql+advisory:}
MESSENGER_TRANSPORT_DSN=doctrine://default
REDIS_CACHE_DSN=redis://$REDIS:6379/1
REDIS_STATE_DSN=redis://$REDIS:6379/2
MERCURE_URL=http://$MERCURE/.well-known/mercure
MERCURE_PUBLIC_URL=http://$PROXY:8080/.well-known/mercure
MERCURE_JWT_SECRET=$MERCURE_SECRET
UPLOAD_SPOOL_DIR=/tmp/judge-chaos-spool
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
  docker run --rm --name "$PREFIX-php-$$" --network "$NETWORK" --env-file "$STATE_DIR/app.env" \
    -v "$APP_DIR:/app" -v "$VENDOR_DIR:/app/vendor:ro" -v "$BUILD_DIR:/app/public/build:ro" \
    -w /app --entrypoint php "$PHP_IMAGE" "$@"
}

wait_for() {
  local what="$1"
  shift
  for _ in $(seq 1 90); do
    if "$@" > /dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  die "$what did not come up"
}

up() {
  build
  database
  app_env_file
  mkdir -p "$OUT" "$DIR/media"
  # shellcheck disable=SC1091
  source "$STATE_DIR/secrets.env"

  docker run -d --name "$REDIS" --network "$NETWORK" "$REDIS_IMAGE" redis-server --save "" --appendonly no > /dev/null

  docker run -d --name "$MERCURE" --network "$NETWORK" \
    -e SERVER_NAME=:80 \
    -e MERCURE_PUBLISHER_JWT_KEY="$MERCURE_SECRET" \
    -e MERCURE_SUBSCRIBER_JWT_KEY="$MERCURE_SECRET" \
    -e MERCURE_EXTRA_DIRECTIVES=$'cors_origins *\nanonymous' \
    "$MERCURE_IMAGE" > /dev/null

  php_run bin/console cache:clear --no-warmup > /dev/null
  php_run bin/console cache:warmup > /dev/null

  docker run -d --name "$WEB" --network "$NETWORK" --env-file "$STATE_DIR/app.env" \
    -e FRANKENPHP_WORKER=1 \
    -e "FRANKENPHP_CONFIG=$(printf 'worker {\n    file /app/public/index.php\n    num %s\n}\nmax_wait_time 30s\nmax_requests 500' "$WORKERS")" \
    -v "$APP_DIR:/app" -v "$VENDOR_DIR:/app/vendor:ro" -v "$BUILD_DIR:/app/public/build:ro" \
    "$PHP_IMAGE" > /dev/null

  docker run -d --name "$PROXY" --network "$NETWORK" \
    -e UPSTREAM="http://$WEB:8080" -e MERCURE_UPSTREAM="http://$MERCURE:80" \
    -v "$DIR/chaos:/chaos/chaos:ro" -w /chaos "$RUNNER_IMAGE" python -u chaos/proxy.py > /dev/null

  docker run -d --name "$SELENIUM" --network "$NETWORK" --shm-size 2g \
    -e SE_NODE_MAX_SESSIONS=3 -e SE_NODE_OVERRIDE_MAX_SESSIONS=true -e SE_NODE_SESSION_TIMEOUT=1800 \
    -e SE_START_VNC=false -e SE_SCREEN_WIDTH=1280 -e SE_SCREEN_HEIGHT=1024 \
    -v "$DIR/media:/chaos-media:ro" \
    "$SELENIUM_IMAGE" > /dev/null

  wait_for "the web container" docker exec "$WEB" curl -fsS http://localhost:8080/-/health-check/container
  wait_for "the proxy" docker exec "$PROXY" wget -qO- http://127.0.0.1:9999/state
  wait_for "selenium" docker exec "$SELENIUM" curl -fsS http://localhost:4444/status
  status
}

runner() {
  docker run --rm --name "$PREFIX-runner-$$" --network "$NETWORK" \
    -e NAME_PREFIX="$PREFIX" -e QUICK="${QUICK:-0}" \
    -e CHAOS_DATABASE_URL="postgresql://postgres:postgres@postgres:5432/$DB" \
    -v "$DIR:/chaos" -v "$OUT:/out" -w /chaos "$RUNNER_IMAGE" python -u -m chaos.run "$@"
}

videos() {
  # The camera videos of the real printed tags (scenario 11 makes them itself when missing)
  runner --videos-only
}

down() {
  for name in "$SELENIUM" "$PROXY" "$WEB" "$MERCURE" "$REDIS"; do
    if docker container inspect "$name" > /dev/null 2>&1; then
      docker rm -f -v "$name" > /dev/null
      echo "removed $name"
    fi
  done
  if [ "${KEEP_DB:-0}" != "1" ]; then
    psql_admin -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)" && echo "dropped $DB"
  fi
}

status() {
  docker ps -a --filter "name=^$PREFIX-" --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
}

case "${1:-}" in
  up) up ;;
  down) down ;;
  seed) seed ;;
  build) build ;;
  run) shift; runner "$@" ;;
  list) runner --list ;;
  videos) videos ;;
  status) status ;;
  logs) docker logs --tail "${TAIL:-200}" "$PREFIX-$2" ;;
  all)
    shift
    trap down EXIT
    up
    runner "$@"
    ;;
  *) die "usage: chaos.sh all|up|run|seed|down|status|logs|list|build|videos" ;;
esac
