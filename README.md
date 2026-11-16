# MySpeedPuzzling.com

Website of the Czech Speed Puzzling community.

## Installation

Everything runs in Docker. To run the application, simply run:
```
docker compose up
```

The database is Postgres 18.7. A checkout that still has its data in the old Postgres 16 volume copies it over once
with `tools/migrate-local-postgres-to-18.sh` (see the head of the script; the old volume is left as it was).

- Website: `localhost:8080`
- Adminer: `localhost:8000` (driver is postgres, user `postgres`, password `postgres`)
- Minio (S3): `localhost:19000` (user `speedpuzzling` password `speedpuzzling`)
