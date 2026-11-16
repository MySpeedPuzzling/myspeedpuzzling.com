---
paths:
  - "migrations/**"
  - "tests/bootstrap.php"
  - "src/Services/Doctrine/**"
  - "src/Entity/**"
  - "docs/database-indexes.md"
  - ".claude/postgres-puzzle-query-optimization.md"
---

# Database, migrations and custom indexes

## Database
- Database runs in Docker on port 5432 (postgres/postgres): **Postgres 18.7** (`postgres:18.7`, Debian) in development
  (`compose.yml`), in CI and on production (upgraded from 16.14 on 2026-11-16, data checksums on).
- The data lives in the volume `postgres18-data`, mounted at `/var/lib/postgresql` (the 18 image refuses a mount at the
  old `/var/lib/postgresql/data`). One volume per major version: the Postgres 16 volume `postgres-data` of a checkout
  stays on disk untouched, and `tools/migrate-local-postgres-to-18.sh` copies its databases over (roles + every
  non-test database, row counts compared, never over a database that holds tables unless `--replace`; local Docker
  only). Keep the service name `postgres`, its port and the network - other tools reach it as `postgres:5432`.
- From Postgres 17 on, `CREATE INDEX` / `REINDEX` / `ANALYZE` run index-expression functions with a restricted
  `search_path`: a function used in an index must schema-qualify what it calls (`immutable_unaccent()` does -
  `public.unaccent('public.unaccent'::regdictionary, $1)`).
- Adminer available at localhost:8000
- Migrations are in `migrations/` directory

## Custom Database Indexes
Indexes that Doctrine cannot manage (e.g., GIN trigram indexes, expression indexes) are handled via a custom `SchemaManagerFactory`:
1. **Named with `custom_` prefix** - e.g., `custom_puzzle_name_trgm`
2. **Created in migrations** - add them manually to migration files
3. **Mirrored in `tests/bootstrap.php`** - The `createPostgresExtensions()` function must create any required extensions/functions
4. **Automatically ignored by Doctrine** - `CustomIndexFilteringSchemaManagerFactory` filters out `custom_*` indexes during schema introspection, so Doctrine will NOT generate `DROP INDEX` statements for them
5. **Registered in `docs/database-indexes.md`** - every custom index with the query it serves and what it measured

Example custom index (from `Version20260102200000.php`):
```sql
-- GIN trigram index for ILIKE with wildcards
CREATE INDEX custom_puzzle_name_trgm ON puzzle USING GIN (name gin_trgm_ops);
```

The `immutable_unaccent()` function is a custom wrapper around PostgreSQL's `unaccent()` that is marked IMMUTABLE (required for index expressions). Use it in queries that should leverage accent-insensitive trigram indexes.

## Ids created in SQL: `gen_uuid_v7()`
PHP creates ids with `Uuid::uuid7()`. A set-based statement that creates rows by itself (`INSERT ... SELECT`) cannot
take them from PHP - it calls **`gen_uuid_v7()`, never `gen_random_uuid()`**. The function shipped one release before
its first callers, the nine INSERTs of the insights recalculation (`PuzzleIntelligenceRecalculator`): a function must
be on production before any code calls it - a cron container runs a new image as soon as it is pulled, before the new
web container has migrated. The same goes for any later change of it.

- Why: a random version 4 id lands on a random page of the primary key index, and the first write to a page after a
  checkpoint puts the whole page into the WAL. On production (1-16 Nov 2026) the `player_rating_snapshot` upsert wrote
  ~3,500 full page images and 13 MB of WAL per 15-minute run, a third of all WAL. Version 7 ids start with the time,
  so the rows of one day sit together. Measured on a copy of that table's shape (Postgres 16.14): the day's first
  insert 171 MB → 105 MB of WAL, a later run about a quarter less. The indexes that start with `player_id` scatter as
  before - that part of the WAL is not about the id.
- What it is: a plain SQL function (`Version20261116160000`, no extension; Postgres 16 has no `uuidv7()`, 18 does) -
  48 bits of Unix milliseconds from `clock_timestamp()`, version 7, RFC variant, 74 random bits. `VOLATILE`,
  `PARALLEL SAFE`. The database's clock on purpose: the prefix orders ids and is no stored time (R2 of
  `docs/features/time-and-clock/README.md` stays - `tests/bootstrap.php` is listed in `DatabaseClockCoverageTest`
  for it).
- Not named `uuidv7()`: Postgres 18's `pg_catalog.uuidv7()` would shadow it. After that upgrade the callers can move
  to the built-in function and a migration drop this one.
- Mirrored in `tests/bootstrap.php` (`createPostgresExtensions()`), word for word - `GenUuidV7FunctionTest` compares
  the two. A change of the function is a new migration plus the mirror.

## `custom_` is reserved for unmanaged objects
`CustomIndexFilteringSchemaManagerFactory` hides every `custom_*` index from Doctrine, so never name a table or
an index Doctrine should manage with that prefix (the custom fields tables are `player_custom_field` /
`solving_time_custom_values` for that reason - `.claude/rules/features/my-data.md`).
