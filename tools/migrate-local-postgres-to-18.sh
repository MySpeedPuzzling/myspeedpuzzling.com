#!/usr/bin/env bash
#
# Copies the databases of this checkout's OLD local Postgres (the Postgres 16 data in the `postgres-data` volume) into
# the Postgres 18 volume the stack uses now (`postgres18-data`, compose.yml), and verifies the copy row by row.
#
#   tools/migrate-local-postgres-to-18.sh                 # roles + every database that is not a test database
#   tools/migrate-local-postgres-to-18.sh speedpuzzling   # roles + only the named database(s)
#   tools/migrate-local-postgres-to-18.sh --replace       # also overwrite databases that already hold tables on 18
#   tools/migrate-local-postgres-to-18.sh --include-test-databases
#
# What it does, in this order:
#   1. stops every container that has the old or the new volume open (a clean Postgres shutdown - clients that are
#      still connected are disconnected; they find no `postgres` host until the copy is done),
#   2. starts the old server on the old volume and the new server on the new volume, both under private names on the
#      project's network - neither answers to `postgres`, neither publishes a port, so nobody writes during the copy,
#   3. copies the roles, then each database (pg_dump of 18 | pg_restore, one database at a time),
#   4. counts the rows of every table on both sides and compares them,
#   5. stops both, starts the `postgres` service (18) and prints what was copied.
#
# Safe to run again: a database that already holds tables on 18 is never overwritten without --replace (the script
# stops before copying anything and says which). The old volume is only read: it is opened by the old server and shut
# down cleanly, never deleted and never written by this script - going back is checking out a commit before the
# switch and `docker compose up -d postgres`.
#
# Test databases (*_test, *_test_<anything> such as _test_3, _test_template, _test_load, and Panther's
# *_panther_<hash>) are skipped unless --include-test-databases: the test bootstrap rebuilds them (tests/bootstrap.php),
# and one built by an older checkout does not restore at all (its immutable_unaccent() is the unqualified one).
# Template databases are always skipped.
#
# A database that does not restore: named on the command line, the script stops there (and leaves `postgres` stopped).
# Otherwise what was copied of it is removed again, the others are copied, it is listed at the end and the script
# ends with an error - it is still in the old volume.
#
# While this runs, nobody may start the stack of this project (`docker compose up`): the `postgres` service would open
# the data directory the copy is being written into. Stop the stack first and wait for the summary.
#
# LOCAL ONLY. It talks to the Docker daemon on this machine (a remote daemon or context is refused), to the compose
# project of the checkout it lies in, with the development password. It takes no host, no URL and no credentials.

set -euo pipefail

# The compose project of this checkout, the way `docker compose` resolves it there (compose.override.yml included)
cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$(pwd)"
COMPOSE=(docker compose)
OLD_IMAGE="${OLD_IMAGE:-postgres:16.0}"   # what compose.yml pinned before the switch; any 16.x opens the same data
SUPERUSER=postgres
PASSWORD=postgres                         # the development password of compose.yml - nothing else is ever tried

REPLACE=0
INCLUDE_TESTS=0
ONLY=()

say() { printf '%s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

usage() { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"; }

for argument in "$@"; do
    case "$argument" in
        --replace) REPLACE=1 ;;
        --include-test-databases) INCLUDE_TESTS=1 ;;
        -h|--help) usage; exit 0 ;;
        -*) die "Unknown option $argument (see --help)" ;;
        *)
            [[ "$argument" =~ ^[A-Za-z0-9_]+$ ]] || die "\"$argument\" is not a database name. This script takes database names only - no host, no URL."
            ONLY+=("$argument")
            ;;
    esac
done

# ---- local only ----------------------------------------------------------------------------------------------------
command -v docker >/dev/null || die "docker not found"
docker_endpoint="${DOCKER_HOST:-$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null || true)}"
case "$docker_endpoint" in
    unix://*|npipe://*) ;;
    *) die "The Docker daemon is not a local one ($docker_endpoint). This script only ever migrates the database of the local development stack." ;;
esac

project="$("${COMPOSE[@]}" config 2>/dev/null | sed -n 's/^name: //p' | head -n 1)"
[ -n "$project" ] || die "Could not read the compose project name from $ROOT/compose.yml"

OLD_VOLUME="${project}_postgres-data"
NEW_VOLUME="${project}_postgres18-data"
OLD_SERVER="${project}-postgres-migrate-old"
NEW_SERVER="${project}-postgres-migrate-new"

say "Project:    $project  ($ROOT)"
say "Old volume: $OLD_VOLUME  ->  new volume: $NEW_VOLUME"

if ! docker volume inspect "$OLD_VOLUME" >/dev/null 2>&1; then
    say
    say "There is no volume $OLD_VOLUME - this project has no old Postgres data. Nothing to migrate."
    exit 0
fi

old_major="$(docker run --rm --entrypoint cat -v "$OLD_VOLUME":/old:ro "$OLD_IMAGE" /old/PG_VERSION 2>/dev/null || true)"
image_major="$(docker run --rm --entrypoint sh "$OLD_IMAGE" -c 'echo "$PG_MAJOR"')"
[ -n "$old_major" ] || die "$OLD_VOLUME holds no Postgres data directory (no PG_VERSION in it)."
[ "$old_major" = "$image_major" ] || die "$OLD_VOLUME was written by Postgres $old_major, but OLD_IMAGE is $OLD_IMAGE. Run again with OLD_IMAGE=postgres:$old_major"
[ "$old_major" -lt 18 ] || die "$OLD_VOLUME is already Postgres $old_major data."

# ---- 1. nobody else has the two data directories open -----------------------------------------------------------------
stopped=()
for volume in "$OLD_VOLUME" "$NEW_VOLUME"; do
    for container in $(docker ps --quiet --filter "volume=$volume"); do
        name="$(docker inspect --format '{{.Name}}' "$container" | sed 's#^/##')"
        say "Stopping $name (it has $volume open; connected clients are disconnected) ..."
        docker stop -t 120 "$container" >/dev/null
        stopped+=("$container")
    done
done

copy_started=0
finished=0

cleanup() {
    status=$?
    docker stop -t 120 "$OLD_SERVER" >/dev/null 2>&1 || true
    docker stop -t 120 "$NEW_SERVER" >/dev/null 2>&1 || true

    if [ "$finished" = 1 ]; then
        return
    fi

    if [ "$copy_started" = 1 ]; then
        printf '\nThe copy did NOT finish. The `postgres` service is left stopped, because the Postgres 18 volume may hold a half-copied\ndatabase: fix the cause and run this again with --replace. The old volume is as it was.\n' >&2
    elif [ "${#stopped[@]}" -gt 0 ]; then
        # Nothing was changed, so everything is put back the way it was found
        docker start "${stopped[@]}" >/dev/null 2>&1 || true
        printf '\nWhat was running before is running again.\n' >&2
    fi

    exit "$status"
}
trap cleanup EXIT

# ---- 2. both servers, reachable by nobody but this script ---------------------------------------------------------------
for leftover in "$OLD_SERVER" "$NEW_SERVER"; do
    # Only ever this script's own two containers, by their exact names: an interrupted run may have left them behind
    if docker inspect "$leftover" >/dev/null 2>&1; then
        docker stop -t 120 "$leftover" >/dev/null 2>&1 || true
        docker container rm "$leftover" >/dev/null 2>&1 || true
    fi
done
say "Starting the old server ($OLD_IMAGE) and the new one (the compose service's image) ..."
# A one-off container of the service: its image, its settings and its volume, but without the service's network
# alias and without its published port. It also creates the volume and the network if this is the first start.
startlog="$(mktemp)"
COMPOSE_IGNORE_ORPHANS=1 "${COMPOSE[@]}" run --detach --rm --no-deps --name "$NEW_SERVER" postgres >/dev/null 2>"$startlog" \
    || { cat "$startlog" >&2; die "Could not start the Postgres 18 server of the compose project."; }
network="$(docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}' "$NEW_SERVER")"
docker run --detach --rm --name "$OLD_SERVER" --network "$network" -v "$OLD_VOLUME":/var/lib/postgresql/data "$OLD_IMAGE" >/dev/null

wait_for() {
    for _ in $(seq 1 120); do
        # Over TCP on purpose: while the image initialises a new data directory its temporary server has no TCP port
        if docker exec "$1" pg_isready --quiet --host 127.0.0.1 --username "$SUPERUSER" 2>/dev/null; then
            return 0
        fi
        docker inspect "$1" >/dev/null 2>&1 || die "$1 stopped while starting. Run it by hand to see why: docker run --rm -v <volume> <image>"
        sleep 1
    done
    die "$1 did not accept connections within 2 minutes"
}
wait_for "$NEW_SERVER"
wait_for "$OLD_SERVER"

# Every client below is the new server's (Postgres 18 tools read an older server; the reverse is not supported)
old_sql() { docker exec -e PGPASSWORD="$PASSWORD" "$NEW_SERVER" psql --host "$OLD_SERVER" --username "$SUPERUSER" --no-psqlrc --tuples-only --no-align --set ON_ERROR_STOP=1 --dbname "$1" --command "$2"; }
new_sql() { docker exec "$NEW_SERVER" psql --username "$SUPERUSER" --no-psqlrc --tuples-only --no-align --set ON_ERROR_STOP=1 --dbname "$1" --command "$2"; }

old_sql postgres 'select 1' >/dev/null || die "The old server refuses user \"$SUPERUSER\" with the development password. This script only knows compose.yml's own."
say "Old server: $(old_sql postgres 'show server_version')   new server: $(new_sql postgres 'show server_version')"
[ "$(new_sql postgres "select current_setting('server_version_num')::int / 10000")" -gt "$old_major" ] \
    || die "The compose service's Postgres is not newer than the old data ($old_major) - is this checkout on the Postgres 18 compose.yml?"

# ---- which databases ------------------------------------------------------------------------------------------------
TEST_DATABASE='(_test(_[A-Za-z0-9_]+)?|_panther_[0-9a-f]+)$'
is_test_database() { [[ "$1" =~ $TEST_DATABASE ]]; }

selected=()
skipped=()
while IFS= read -r database; do
    [ -n "$database" ] || continue

    if [ "${#ONLY[@]}" -gt 0 ]; then
        wanted=0
        for name in "${ONLY[@]}"; do [ "$name" = "$database" ] && wanted=1; done
        if [ "$wanted" = 1 ]; then selected+=("$database"); else skipped+=("$database"); fi
    elif is_test_database "$database" && [ "$INCLUDE_TESTS" = 0 ]; then
        skipped+=("$database")
    else
        selected+=("$database")
    fi
done < <(old_sql postgres "select datname from pg_database where not datistemplate and datname <> 'postgres' order by (datname ~ '$TEST_DATABASE'), datname")

if [ "${#ONLY[@]}" -gt 0 ]; then
    for name in "${ONLY[@]}"; do
        found=0
        for database in ${selected[@]+"${selected[@]}"}; do [ "$name" = "$database" ] && found=1; done
        [ "$found" = 1 ] || die "The old server has no database \"$name\" (template databases are never copied)."
    done
fi

[ "${#selected[@]}" -gt 0 ] || die "The old server has no database to copy."

# ---- never over a database that already holds something ---------------------------------------------------------------
TABLES="select count(*) from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind in ('r', 'p') and n.nspname not in ('pg_catalog', 'information_schema')"
exists_on_new() { [ "$(new_sql postgres "select count(*) from pg_database where datname = '$1'")" = 1 ]; }

occupied=()
for database in "${selected[@]}"; do
    if exists_on_new "$database" && [ "$(new_sql "$database" "$TABLES")" != 0 ]; then
        occupied+=("$database")
    fi
done

if [ "${#occupied[@]}" -gt 0 ] && [ "$REPLACE" = 0 ]; then
    say
    say "These databases already hold tables on Postgres 18 and are NOT overwritten: ${occupied[*]}"
    say "If the copy on 18 may be thrown away (for example the stack was started once and only ran its migrations),"
    say "run this again with --replace. Nothing was copied."
    exit 1
fi

# ---- 3. roles, then the databases ---------------------------------------------------------------------------------------
copy_started=1
say
say "Roles ..."
workdir="$(mktemp -d)"
docker exec -e PGPASSWORD="$PASSWORD" "$NEW_SERVER" pg_dumpall --host "$OLD_SERVER" --username "$SUPERUSER" --roles-only > "$workdir/roles.sql" \
    || die "Reading the roles of the old server failed."
# The superuser exists on both; a role that exists from an earlier run keeps its name and gets its settings again
role_errors="$(grep -v -E "^CREATE ROLE $SUPERUSER;" "$workdir/roles.sql" \
    | docker exec --interactive "$NEW_SERVER" psql --username "$SUPERUSER" --no-psqlrc --quiet --dbname postgres 2>&1 >/dev/null \
    | grep -E 'ERROR|FATAL' | grep -v 'already exists' || true)"
[ -z "$role_errors" ] || die "Copying the roles failed: $role_errors"
roles="$(old_sql postgres "select coalesce(string_agg(rolname, ', ' order by rolname), '-') from pg_roles where rolname !~ '^pg_'")"

# One line per table: schema.table <tab> rows
COUNTS="select format('%I.%I', n.nspname, c.relname) || E'\\t' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', n.nspname, c.relname), false, true, '')))[1]::text from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind = 'r' and n.nspname not in ('pg_catalog', 'information_schema') order by 1"

summary="$workdir/summary"
failed_tests=()
failed=()

for database in "${selected[@]}"; do
    started=$SECONDS
    say "Database $database ..."

    if exists_on_new "$database"; then
        new_sql postgres "drop database \"$database\"" >/dev/null
    fi

    if ! docker exec -e PGPASSWORD="$PASSWORD" -e DATABASE="$database" -e OLD="$OLD_SERVER" -e SUPERUSER="$SUPERUSER" "$NEW_SERVER" \
        bash -o pipefail -c 'pg_dump --host "$OLD" --username "$SUPERUSER" --format custom "$DATABASE" | pg_restore --username "$SUPERUSER" --dbname postgres --create --exit-on-error' 2>"$workdir/error"; then
        if [ "${#ONLY[@]}" -gt 0 ]; then
            cat "$workdir/error" >&2
            die "Copying $database failed."
        fi

        # Not asked for by name: the others still get copied. What is left of this one on 18 would only be in the way
        exists_on_new "$database" && new_sql postgres "drop database \"$database\"" >/dev/null

        if is_test_database "$database"; then
            failed_tests+=("$database")
            say "  could not be restored (a test database - the test bootstrap rebuilds it): $(head -n 1 "$workdir/error")"
        else
            failed+=("$database")
            say "  COULD NOT BE RESTORED - it stays in the old volume only: $(head -n 1 "$workdir/error")"
        fi

        continue
    fi

    new_sql "$database" 'analyze' >/dev/null
    old_sql "$database" "$COUNTS" > "$workdir/old"
    new_sql "$database" "$COUNTS" > "$workdir/new"

    if ! diff "$workdir/old" "$workdir/new" > "$workdir/diff"; then
        say "  row counts differ (< old, > new):"
        cat "$workdir/diff"
        die "The copy of $database is not the same as the original."
    fi

    printf '%s\t%s\t%s\t%s\t%s\n' "$database" \
        "$(wc -l < "$workdir/new" | tr -d ' ')" \
        "$(awk -F '\t' '{ rows += $2 } END { print rows + 0 }' "$workdir/new")" \
        "$(new_sql postgres "select pg_size_pretty(pg_database_size('$database'))")" \
        "$((SECONDS - started)) s" >> "$summary"
done

# ---- 5. hand over to the service ----------------------------------------------------------------------------------------
docker stop -t 120 "$OLD_SERVER" >/dev/null
docker stop -t 120 "$NEW_SERVER" >/dev/null
finished=1
COMPOSE_IGNORE_ORPHANS=1 "${COMPOSE[@]}" up --detach --no-deps postgres >/dev/null 2>"$startlog" \
    || { cat "$startlog" >&2; die "Everything was copied, but the \`postgres\` service did not start - start it with: docker compose up -d postgres"; }

say
say "Copied from Postgres $old_major ($OLD_VOLUME) to Postgres 18 ($NEW_VOLUME), every table's row count compared:"
say
{ printf 'DATABASE\tTABLES\tROWS\tSIZE ON 18\tTIME\n'; cat "$summary" 2>/dev/null || true; } | awk -F '\t' '{ printf "  %-34s %7s %12s %12s %7s\n", $1, $2, $3, $4, $5 }'
say
say "Roles: $roles"
[ "${#skipped[@]}" = 0 ] || say "Not copied (still in the old volume): ${skipped[*]}"
[ "${#failed_tests[@]}" = 0 ] || say "Test databases that did not restore (the test bootstrap rebuilds them): ${failed_tests[*]}"
say
say "The \`postgres\` service runs on Postgres 18 now. Start the rest with: docker compose up -d"
say "The old volume $OLD_VOLUME was left as it was."
rm -rf "$workdir"

if [ "${#failed[@]}" -gt 0 ]; then
    printf '\nERROR: NOT copied, because they did not restore (see above): %s\nThey are still in the old volume. Run this again with their names to see the full error.\n' "${failed[*]}" >&2
    exit 1
fi
