#!/bin/sh
set -e

echo "Waiting for PostgreSQL..."
while ! nc -z postgres 5432; do
    sleep 1
done
echo "PostgreSQL is ready"

echo "Creating database if not exists..."
PGPASSWORD=postgres psql -h postgres -U postgres -tc \
    "SELECT 1 FROM pg_database WHERE datname = 'listmonk'" | grep -q 1 || \
    PGPASSWORD=postgres psql -h postgres -U postgres -c "CREATE DATABASE listmonk"

echo "Running listmonk install (idempotent)..."
./listmonk --install --yes

echo "Starting listmonk..."
exec ./listmonk
