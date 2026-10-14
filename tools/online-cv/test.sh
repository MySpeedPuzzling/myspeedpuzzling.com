#!/usr/bin/env bash
# The CV worker's tests (tests/test_server.py) in a throwaway container - nothing is installed on the machine.
# tools/ is mounted read-only: the tests take their synthetic puzzles from the bench (tools/online-bench/completeness).
# Usage (from anywhere):  tools/online-cv/test.sh [unittest arguments, e.g. -k queue]
set -euo pipefail
TOOLS="$(cd "$(dirname "$0")/.." && pwd)"
exec docker run --rm \
  -v "$TOOLS":/tools:ro \
  -w /tools/online-cv \
  -e PYTHONDONTWRITEBYTECODE=1 \
  python:3.12.15-slim \
  sh -c 'pip install -q --root-user-action=ignore --disable-pip-version-check -r requirements.txt && python -m unittest discover -s tests -v "$@"' sh "$@"
