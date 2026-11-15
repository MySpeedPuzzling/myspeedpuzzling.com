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
- Database runs in Docker on port 5432 (postgres/postgres)
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

## `custom_` is reserved for unmanaged objects
`CustomIndexFilteringSchemaManagerFactory` hides every `custom_*` index from Doctrine, so never name a table or
an index Doctrine should manage with that prefix (the custom fields tables are `player_custom_field` /
`solving_time_custom_values` for that reason - `.claude/rules/features/results.md`).
