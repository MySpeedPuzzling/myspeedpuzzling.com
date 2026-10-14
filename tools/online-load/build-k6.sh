#!/usr/bin/env bash
# Builds the k6 binary the load script runs with: k6 + the SSE extension (xk6-sse), because k6 itself speaks no
# Server-Sent Events and Grafana's automatic extension resolution does not know `k6/x/sse` for k6 v2 (tried
# 2026-10-13: "unknown dependency : k6/x/sse"). Built locally with Grafana's own xk6 image - open source
# (k6 AGPL-3.0, xk6 Apache-2.0, xk6-sse Apache-2.0), no build service, the same binary every time.
#
# Output: tools/online-load/.bin/k6 (gitignored), for the platform Docker runs on - run.sh mounts it into the
# grafana/k6 image. On another machine (a load generator VM), run this script there.
set -euo pipefail

K6_VERSION="${K6_VERSION:-v2.3.0}"
XK6_IMAGE="${XK6_IMAGE:-grafana/xk6:1.4.14}"
SSE_EXTENSION="${SSE_EXTENSION:-github.com/phymbert/xk6-sse@v0.2.0}"
# Throwaway containers are named <prefix>-…
NAME_PREFIX="${NAME_PREFIX:-online-load}"

DIR="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$DIR/.bin"

# Static binary (the grafana/k6 image is Alpine), Go's caches inside the throwaway container
docker run --rm --name "$NAME_PREFIX-xk6-$$" \
  -u "$(id -u):$(id -g)" \
  -v "$DIR/.bin:/out" \
  -e CGO_ENABLED=0 \
  -e HOME=/tmp \
  -e GOPATH=/tmp/go \
  -e GOCACHE=/tmp/go-cache \
  "$XK6_IMAGE" build "$K6_VERSION" --with "$SSE_EXTENSION" --output /out/k6

"$DIR/.bin/k6" version 2>/dev/null || echo "Built $DIR/.bin/k6 (a Linux binary - run it through run.sh)"
