#!/usr/bin/env bash
# The competition load test (README.md) - a minimal prod-mode stack + k6:
#
#   load.sh up                 the throwaway database copy, the stack (web in FrankenPHP worker mode, redis, mercure),
#                              the event (setup.php) and the credentials
#   load.sh setup              the round anew (results, receipts, proposals cleared; credentials written again);
#                              ROUND_FORMAT=total: a one-puzzle `total` round instead of the `pick` one (2 of 3)
#   load.sh run <name> [MIX]   k6 against the stack (MIX = saves | proposals | all | single, default all; more k6 -e
#                              options after it), with the advisory-lock sampler beside it; report in out/<name>/
#   load.sh timings            the judge page on a throttled phone (judge_timings.py; README "Judge page timings")
#   load.sh reload-app         after a PHP change: the prod cache again, the workers restarted (OPcache never re-reads)
#   load.sh down               every container removed with its volumes, the database copy dropped
#   load.sh status | stats | logs
#
# Every name is prefixed NAME_PREFIX (default rlt4); the database is speedpuzzling_<prefix>_test_load, copied from the
# test suite's template speedpuzzling_<prefix>_test_template (any test through the delivery run.sh <prefix> makes it).
# Nothing of the shared dev services is touched: the shared Postgres only gets (and loses) the copy.
set -euo pipefail

PREFIX="${NAME_PREFIX:-rlt4}"
NETWORK="${NETWORK:-speedpuzzlingcz_default}"
DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$DIR/../.." && pwd)}"
MAIN_CHECKOUT="${MAIN_CHECKOUT:-/Users/janmikes/www/speedpuzzling.cz}"
VENDOR_DIR="${VENDOR_DIR:-$([ -f "$APP_DIR/vendor/autoload.php" ] && echo "$APP_DIR/vendor" || echo "$MAIN_CHECKOUT/vendor")}"
BUILD_DIR="${BUILD_DIR:-$([ -f "$APP_DIR/public/build/entrypoints.json" ] && echo "$APP_DIR/public/build" || echo "$MAIN_CHECKOUT/public/build")}"
# Production: 32 workers per replica; locally fewer - the machine also runs Postgres, k6 and everything else
WORKERS="${WORKERS:-16}"
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-speedpuzzlingcz-postgres-1}"
STATE_DIR="$DIR/out/stack-$PREFIX"

DB="speedpuzzling_${PREFIX}_test_load"
TEMPLATE="speedpuzzling_${PREFIX}_test_template"
DATABASE_URL="postgresql://postgres:postgres@postgres:5432/$DB?serverVersion=18&charset=utf8"

PHP_IMAGE="${PHP_IMAGE:-ghcr.io/myspeedpuzzling/web-base-php85:main}"
MERCURE_IMAGE="${MERCURE_IMAGE:-dunglas/mercure@sha256:27845c71dd26878bfb77bb0723fd3ea26e55d07ad0025b1c0948533ac30a5afc}"
REDIS_IMAGE="${REDIS_IMAGE:-redis:7.2}"
K6_IMAGE="${K6_IMAGE:-grafana/k6:2.3.0}"

WEB="$PREFIX-web"
REDIS="$PREFIX-redis"
MERCURE="$PREFIX-mercure"
SELENIUM="$PREFIX-selenium"
SELENIUM_IMAGE="${SELENIUM_IMAGE:-selenium/standalone-chromium:latest}"
PYTHON_IMAGE="${PYTHON_IMAGE:-python:3.12-alpine}"

die() {
  echo "load.sh: $*" >&2
  exit 1
}

psql_admin() {
  docker exec "$POSTGRES_CONTAINER" psql -U postgres -v ON_ERROR_STOP=1 -q "$@"
}

app_env_file() {
  mkdir -p "$STATE_DIR"
  [ -f "$STATE_DIR/secrets.env" ] || echo "MERCURE_SECRET=$(openssl rand -hex 32)" > "$STATE_DIR/secrets.env"
  # shellcheck disable=SC1091
  source "$STATE_DIR/secrets.env"
  # Real values win over .env / .env.local - nothing of the developer's own keys reaches these containers
  cat > "$STATE_DIR/app.env" <<EOF
APP_ENV=prod
APP_DEBUG=0
PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
PHP_ZEND_ASSERTIONS=-1
APP_URL=http://$WEB:8080/
DATABASE_URL=$DATABASE_URL
LOCK_DSN=${DATABASE_URL/postgresql:/postgresql+advisory:}
MESSENGER_TRANSPORT_DSN=doctrine://default
REDIS_CACHE_DSN=redis://$REDIS:6379/1
REDIS_STATE_DSN=redis://$REDIS:6379/2
MERCURE_URL=http://$MERCURE/.well-known/mercure
MERCURE_PUBLIC_URL=http://$MERCURE/.well-known/mercure
MERCURE_JWT_SECRET=$MERCURE_SECRET
UPLOAD_SPOOL_DIR=/tmp/competition-load-spool
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

database() {
  [[ "$DB" =~ _test_load$ ]] || die "refusing database $DB"
  psql_admin -tc "SELECT 1 FROM pg_database WHERE datname = '$TEMPLATE'" | grep -q 1 \
    || die "no $TEMPLATE - run any test through run.sh $PREFIX first (the bootstrap makes it)"
  psql_admin -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)" -c "CREATE DATABASE $DB TEMPLATE $TEMPLATE"
}

setup() {
  [ -f "$STATE_DIR/app.env" ] || die "no stack - run up first"
  php_run tools/competition-load/setup.php "${ROUND_FORMAT:-pick}" > "$STATE_DIR/credentials.json"
  echo "event ready, credentials in $STATE_DIR/credentials.json"
}

warm_cache() {
  php_run bin/console cache:clear --no-warmup > /dev/null
  php_run bin/console cache:warmup > /dev/null
}

up() {
  database
  app_env_file
  # shellcheck disable=SC1091
  source "$STATE_DIR/secrets.env"

  docker run -d --name "$REDIS" --network "$NETWORK" "$REDIS_IMAGE" redis-server --save "" --appendonly no > /dev/null

  docker run -d --name "$MERCURE" --network "$NETWORK" \
    -e SERVER_NAME=:80 \
    -e MERCURE_PUBLISHER_JWT_KEY="$MERCURE_SECRET" \
    -e MERCURE_SUBSCRIBER_JWT_KEY="$MERCURE_SECRET" \
    -e MERCURE_TRUSTED_ISSUERS="https://myspeedpuzzling.com" \
    -e MERCURE_EXTRA_DIRECTIVES=$'cors_origins *\nanonymous' \
    "$MERCURE_IMAGE" > /dev/null

  warm_cache

  docker run -d --name "$WEB" --network "$NETWORK" --env-file "$STATE_DIR/app.env" \
    -e FRANKENPHP_WORKER=1 \
    -e "FRANKENPHP_CONFIG=$(printf 'worker {\n    file /app/public/index.php\n    num %s\n}\nmax_wait_time 30s\nmax_requests 500' "$WORKERS")" \
    -v "$APP_DIR:/app" -v "$VENDOR_DIR:/app/vendor:ro" -v "$BUILD_DIR:/app/public/build:ro" \
    "$PHP_IMAGE" > /dev/null

  wait_for "the web container" docker exec "$WEB" curl -fsS http://localhost:8080/-/health-check/container
  setup
  status
}

reload_app() {
  [ -f "$STATE_DIR/app.env" ] || die "no stack - run up first"
  warm_cache
  docker restart "$WEB" > /dev/null
  wait_for "the web container" docker exec "$WEB" curl -fsS http://localhost:8080/-/health-check/container
  status
}

# Samples pg_stat_activity of the load database every 100 ms in ONE psql session: how many backends wait for an
# advisory lock (CompetitionParticipantsLock - the lock store's own connections) and how many run a statement
sampler() {
  local file="$1"
  local query="SELECT to_char(clock_timestamp(), 'HH24:MI:SS.MS'), count(*) FILTER (WHERE wait_event_type = 'Lock' AND wait_event = 'advisory'), count(*) FILTER (WHERE state = 'active' AND wait_event_type IS DISTINCT FROM 'Lock'), count(*) FROM pg_stat_activity WHERE datname = '$DB' AND pid <> pg_backend_pid();"
  {
    while :; do
      echo "$query"
      echo '\! sleep 0.1'
    done
  } | docker exec -i "$POSTGRES_CONTAINER" psql -U postgres -d "$DB" -qAtF ' ' > "$file" 2> /dev/null
}

run() {
  local name="${1:-}"
  local mix="${2:-all}"
  shift 2 || shift $#
  case "$name" in
    '' | *[!a-zA-Z0-9_-]*) die "run <name> [MIX]: the name is [a-zA-Z0-9_-]+" ;;
  esac
  [ -f "$STATE_DIR/credentials.json" ] || die "no credentials - run up first"
  local out="$DIR/out/$name"
  mkdir -p "$out"
  cp "$STATE_DIR/credentials.json" "$out/credentials.json"

  sampler "$out/locks.txt" &
  local sampler_pid=$!

  set +e
  docker run --rm --name "$PREFIX-k6-$name" --network "$NETWORK" \
    -u "$(id -u):$(id -g)" \
    -v "$DIR:/scripts:ro" -v "$out:/out" \
    -e K6_NO_USAGE_REPORT=true \
    "$K6_IMAGE" run --quiet -e OUT=/out -e BASE_URL="http://$WEB:8080" -e MIX="$mix" "$@" /scripts/load.js
  local status=$?
  set -e

  pkill -P "$sampler_pid" > /dev/null 2>&1 || true
  kill "$sampler_pid" > /dev/null 2>&1 || true
  rm -f "$out/credentials.json"
  locks "$out/locks.txt" | tee -a "$out/report.md"
  return $status
}

# The sampler's summary: the share of samples with somebody waiting for the event's lock, the mean and max queue
locks() {
  awk '
    NF >= 4 { n++; w = $2 + 0; a = $3 + 0; total += w; if (w > 0) busy++; if (w > max) max = w; act += a; if (a > amax) amax = a }
    END {
      if (n == 0) { print "Lock sampler: no samples"; exit }
      printf "Lock sampler (every ~100 ms, %d samples): waiting for an advisory lock in %.1f %% of samples, mean %.2f, max %d; statements running mean %.2f, max %d\n", n, 100 * busy / n, total / n, max, act / n, amax
    }' "$1"
}

# The live entry page on a throttled phone (README "Judge page timings"): a Selenium Chromium next to the stack, the
# script in a Python container with the WebDriver client; both removed afterwards
timings() {
  [ -f "$STATE_DIR/credentials.json" ] || die "no credentials - run up first"
  local out="$DIR/out/timings-$(date +%H%M%S)"
  mkdir -p "$out"
  cp "$STATE_DIR/credentials.json" "$out/credentials.json"
  docker run -d --name "$SELENIUM" --network "$NETWORK" --shm-size 2g -e SE_START_VNC=false \
    -e SE_NODE_MAX_SESSIONS=1 "$SELENIUM_IMAGE" > /dev/null
  wait_for "selenium" docker exec "$SELENIUM" curl -fsS http://localhost:4444/status
  set +e
  docker run --rm --name "$PREFIX-timings" --network "$NETWORK" -v "$DIR:/scripts:ro" -v "$out:/out" \
    -e SITE="http://$WEB:8080" -e SELENIUM="http://$SELENIUM:4444" -e CREDENTIALS=/out/credentials.json \
    -e CPU="${CPU:-4}" -e NETWORK_PROFILE="${NETWORK_PROFILE:-slow4g}" -e SAVES="${SAVES:-20}" \
    "$PYTHON_IMAGE" sh -c 'pip install -q --root-user-action=ignore selenium==4.52.0 > /dev/null 2>&1 && python -u /scripts/judge_timings.py' \
    | tee "$out/timings.json"
  local status=${PIPESTATUS[0]}
  set -e
  rm -f "$out/credentials.json"
  docker rm -f -v "$SELENIUM" > /dev/null
  return "$status"
}

down() {
  for name in "$SELENIUM" "$WEB" "$MERCURE" "$REDIS"; do
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
  setup) setup ;;
  run) shift; run "$@" ;;
  timings) timings ;;
  reload-app) reload_app ;;
  down) down ;;
  status) status ;;
  stats) docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' "$WEB" "$MERCURE" "$REDIS" "$POSTGRES_CONTAINER" ;;
  logs) docker logs --tail "${TAIL:-200}" "$WEB" ;;
  *) die "usage: load.sh up|setup|run <name> [MIX]|timings|reload-app|down|status|stats|logs" ;;
esac
