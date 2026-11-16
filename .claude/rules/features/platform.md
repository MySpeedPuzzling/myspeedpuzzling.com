---
paths:
  - "src/**/*Sandbox*"
  - "src/**/*ApiUsage*"
  - "src/**/*ApiCaller*"
  - "src/**/*Wjpf*"
  - "src/**/*PlayerActivity*"
  - "src/**/*ActivityDay*"
  - "src/Services/Sandbox/**"
  - "src/Services/ApiUsage/**"
  - "src/Services/Wjpf/**"
  - "templates/sandbox/**"
  - ".github/**"
  - ".docker/**"
  - "compose*.yml"
  - "Dockerfile*"
  - "bin/migrate-database"
  - "docs/features/sandbox/**"
  - "docs/features/api/usage-statistics.md"
  - "docs/features/activity-analytics.md"
  - "docs/features/wjpf-pairing/**"
  - "docs/ci-pipeline.md"
  - "templates/**/*api_usage*"
  - "src/**/*HealthCheck*"
  - "src/**/Sandbox/**"
  - "src/**/Wjpf/**"
  - "src/**/*ActivityDaily*"
  - "src/**/*PlayerLocale*"
---

# Platform and operations

Loaded automatically when you read or edit files of this area (the sandbox installation, activity analytics, WJPF pairing, CI/deploy and health checks). Read on purpose when planning work here before touching code. Each entry is the as-built summary; its doc under `docs/features/` is the design of record - read it before changing behaviour, and update both when behaviour changes.

- **Sandbox (sandbox.myspeedpuzzling.com)**: `docs/features/sandbox/README.md` — a second LIVE installation (lily `/srv/msp-sandbox`, `SANDBOX=1`): made-up players + the public catalogue, everybody a member, real e-mail marked `[Sandbox]`, never indexed (welcome page with an Enter button, `SandboxGate`). **"On production"/nothing said = myspeedpuzzling.com, "on sandbox" = the sandbox - never mix hosts, tokens or servers** (internal API: `INTERNAL_API_TOKEN` vs `SANDBOX_INTERNAL_API_TOKEN` in `.env.local`, skill `internal-api`). Copying a real account there (same e-mail + password): skill `sandbox-copy-account` (`/srv/msp-sandbox/copy-account.sh <email>`, never via HTTP)
- **Sandbox - how it is built and fed**: `docs/features/sandbox/README.md` (server side: lily.srv `apps/msp-sandbox/`, D88) — a public test copy, same image as production, deployed after each production deploy (`test.yml` job `deploy-sandbox`). `SANDBOX=1` (`SandboxMode`) = banner + noindex everywhere (`SandboxNoIndexSubscriber`, meta) + a membership for every sign-in (`GrantSandboxMembership`, a `granted_until` grant - no code switch). Data: the PUBLIC catalogue copied once from production (`SandboxCatalogue::COLUMNS` = the only columns that travel; read-only export `myspeedpuzzling:sandbox:export-catalogue`), made-up players `test-user+N@myspeedpuzzling.com` (password `sandbox`) with a history logged through `AddPuzzleSolvingTime` (`myspeedpuzzling:sandbox:seed`, clock set per result) and a 10-minute activity cron (`myspeedpuzzling:sandbox:simulate-activity`). Every sandbox-only message/command calls `SandboxMode::assertOn()` - refuses on production. Sentry environment from `SENTRY_ENVIRONMENT` (unset = kernel env, `prod` on production)
- **Activity Analytics**: `docs/features/activity-analytics.md` — daily player presence (`player_activity_day`, UTC days, 24-month prune) + immortal per-locale aggregates (`activity_daily_summary`); written by `PlayerActivitySubscriber` on kernel.terminate with Redis dedup; crons `myspeedpuzzling:snapshot-activity-summary` + `myspeedpuzzling:prune-player-activity` (daily)
- **WJPF Pairing**: `docs/features/wjpf-pairing/README.md` — maps players to worldjigsawpuzzle.org accounts by e-mail (`wjpf_identity`, one row per player, `not_found` stored as a row so re-runs skip it; `wjpf_id` deliberately non-unique). Outbound `WjpfClient` → their `users_pr.php?accion=wjpf_user`; inbound `POST /api/v0/wjpf-pairing` (token from body *or* query, `idusuario`/`idjugador` both accepted). Backfill via `myspeedpuzzling:sync-wjpf-identities` — **read-only survey by default, `--claim` writes and their side stores it permanently**. Their endpoint echoes the row *before* its UPDATE, so a response never confirms its own write, and their `if (!$fila['MySpeedPuzzlingId'])` guard means a stale mapping can never be corrected remotely. Conflicts still pair locally + log a warning. Manual command, no cron

## CI / deploy pipeline
`docs/ci-pipeline.md` — one workflow (`test.yml`) from push to production: ParaTest in 3 shards (each on Postgres 18.7 and, until production's upgrade, on its 16.14), the image builds while the gates run (`sha-<commit>` only), `deploy` re-tags it `main` + fires the lily webhook once the gates pass (`deploy-fast` right after the image when a same-repo PR already verified the exact tree), out-of-order guard, carried `/build` assets via the `website:build-assets` image

Health checks: `/-/health-check/liveness` is Traefik's load balancer check and answers 503 while `/tmp/drain` exists - lily.srv's drain-first blue-green rollout drains an old container that way before stopping it. Docker's health check is `/-/health-check/container`, which ignores the drain. Never change the Traefik health check path label casually: a `traefik.*` label differing between old and new containers makes Traefik drop the service for ~11 s
