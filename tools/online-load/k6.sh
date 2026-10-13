#!/usr/bin/env bash
# Runs participants.js in the grafana/k6 image with the k6 binary build-k6.sh made (k6 + the SSE extension).
#
# Usage: tools/online-load/k6.sh <run name> -e BASE_URL=… [-e N=25 -e DURATION=8m …]   (README.md)
#   input/  is mounted at /input (links.csv, photo.jpg, burst.bin, burst.json - gitignored)
#   out/<run name>/ receives report.md and summary.json (gitignored)
#   NETWORK: the Docker network to run on (the local stack's: speedpuzzlingcz_default; a public site: bridge)
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
NAME="${1:-}"
shift || true

case "$NAME" in
  '' | *[!a-zA-Z0-9_-]*) echo "k6.sh: the first argument is the run's name ([a-zA-Z0-9_-]+)" >&2; exit 2 ;;
esac

NETWORK="${NETWORK:-speedpuzzlingcz_default}"
K6_IMAGE="${K6_IMAGE:-grafana/k6:2.3.0}"
NAME_PREFIX="${NAME_PREFIX:-online-load}"

[ -x "$DIR/.bin/k6" ] || { echo "k6.sh: no $DIR/.bin/k6 - run build-k6.sh first" >&2; exit 2; }
[ -f "$DIR/input/links.csv" ] || [[ " $* " == *" LINKS="* ]] || { echo "k6.sh: no input/links.csv (README.md: the links page's CSV)" >&2; exit 2; }

mkdir -p "$DIR/out/$NAME"

# k6 keeps a resolved name for 5 minutes; K6_DNS="ttl=0" resolves every request (a stopped store then fails at once)
DNS=()
[ -n "${K6_DNS:-}" ] && DNS=(-e "K6_DNS=$K6_DNS")

# No usage report, no extension download: the binary has what the script imports
exec docker run --rm --name "$NAME_PREFIX-k6-$NAME" --network "$NETWORK" \
  -u "$(id -u):$(id -g)" \
  -v "$DIR:/scripts:ro" \
  -v "$DIR/input:/input:ro" \
  -v "$DIR/out/$NAME:/out" \
  -v "$DIR/.bin/k6:/usr/local/bin/k6-sse:ro" \
  -e K6_AUTO_EXTENSION_RESOLUTION=false \
  -e K6_NO_USAGE_REPORT=true \
  ${DNS[@]+"${DNS[@]}"} \
  --entrypoint /usr/local/bin/k6-sse \
  "$K6_IMAGE" run --quiet -e OUT=/out "$@" /scripts/participants.js
