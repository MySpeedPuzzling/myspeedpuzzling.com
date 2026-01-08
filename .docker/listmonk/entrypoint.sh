#!/bin/sh
set -e

echo "Running listmonk install (idempotent)..."
./listmonk --install --yes

echo "Starting listmonk..."
exec ./listmonk
