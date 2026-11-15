---
paths:
  - "src/Controller/Api/**"
  - "src/Controller/OAuth2/**"
  - "src/Services/Api/**"
  - "src/Services/ApiUsage/**"
  - "src/Api/**"
  - "src/**/*OAuth2*"
  - "src/**/*PersonalAccessToken*"
  - "src/**/*PatAuthenticator*"
  - "src/**/*PatUser*"
  - "src/**/*ApiUser*"
  - "src/**/*ApiUsage*"
  - "src/**/*ApiCaller*"
  - "src/**/*FairUse*"
  - "templates/oauth2/**"
  - "templates/for-developers.html.twig"
  - "templates/**/*api*"
  - "config/packages/league_oauth2_server.*"
  - "config/packages/api_platform.*"
  - "docs/features/api/**"
  - "tests/**/Api/**"
---

# Public API (v1) and OAuth2

Loaded automatically when you read or edit files of this area (personal access tokens, OAuth2, scopes, endpoints and API usage statistics). Read on purpose when planning work here before touching code. Each entry is the as-built summary; its doc under `docs/features/` is the design of record - read it before changing behaviour, and update both when behaviour changes.

- **API & OAuth2**: `docs/features/api/` — Public REST API (V1), OAuth2 server, Swagger docs, internal APIs, deprecated V0
- **API usage statistics**: `docs/features/api/usage-statistics.md` — every authenticated `/api/v1` request counted per caller (`Value\ApiCaller`: PAT / OAuth2 app for a player / `client_credentials`), request type (method + URI template, `ApiUsageOperation`) and status class (429 its own). Only Redis sees a request: `ApiUsageSubscriber` runs one Lua script on `kernel.terminate` (after the response in FrankenPHP worker mode - catch `\Throwable`, nothing but the Redis call, no state) into the dedicated **`redis-state`** instance (`REDIS_STATE_DSN`, noeviction + AOF - never the cache Redis); cron `myspeedpuzzling:flush-api-usage` (every 5 min) copies totals into `api_usage_day` / `api_caller_day` with `GREATEST` (idempotent, never lowers) and stamps PAT/consent `last_used_at`; `myspeedpuzzling:prune-api-usage` keeps 24 months. Players: `/en/account/api-usage` (own tokens, connected apps, own apps summed over users), admins `/admin/api-usage`. Tests use `InMemoryApiUsageCounter`; `RedisApiUsageCounterTest` needs `redis-state` (compose + CI service). Rate limiting later reuses `redis-state` + `ApiCaller` + the stored peak per minute (design in the doc)

## API & Authentication
- **Two auth methods:** Personal Access Tokens (PAT) for own data, OAuth2 for third-party apps
- **PAT:** `msp_pat_*` tokens, hashed in DB, `PatAuthenticator` on `api` firewall, `ROLE_PAT`, own data only (`/api/v1/me/*`)
- **OAuth2:** `league/oauth2-server-bundle`, JWT Bearer tokens, scope-based roles
- **Scopes:** `profile:read` (default), `results:read`, `statistics:read`, `collections:read`, `solving-times:write`, `collections:write` — single source of truth is the `OAuth2Scope` enum (feeds the bundle config). Bundle role = `strtoupper('ROLE_OAUTH2_' . scope)` **with punctuation kept** (`ROLE_OAUTH2_SOLVING-TIMES:WRITE`) — use `OAuth2Scope::role()`, don't retype. Write scopes are stripped from `client_credentials` tokens (`OAuth2ClientCredentialsScopeSubscriber`); token responses carry the granted `scope` (`ScopeAwareBearerTokenResponse`); `^/api/v1/me` requires `ROLE_PAT`/`ROLE_OAUTH2_USER`, so a client-credentials token gets 403 there, not a 500
- **Grants:** `authorization_code` (read+write), `client_credentials` (read-only), `refresh_token`
- **"Me" endpoints:** `/api/v1/me/*` — PAT or OAuth2 with user context
- **Player endpoints:** `/api/v1/players/{id}/*` — OAuth2 only
- **Write endpoints:** `POST/PUT /api/v1/me/solving-times`, collection CRUD
- **Collections:** Membership gating — system collection (`default`) accessible to all, custom collections members-only
- **OAuth2 client registration:** Web form → admin approval → credential claim link (one-time display)
- **Audit:** `last_used_at` tracked for both PAT and OAuth2 tokens
- **`ApiUser` interface:** Shared by `PatUser` and `OAuth2User`, used by all providers
- **Fair Use Policy:** Required acceptance for PAT generation and OAuth2 client registration
- **Adding/removing an endpoint:** also update the endpoints table on the "For developers" page (`templates/for-developers.html.twig`, `endpoint_sections`, one row per method + path) - `ForDevelopersEndpointsTableTest` fails until every `/api/v1` route is listed there
- **Deleting + sync:** `DELETE /api/v1/me/solving-times/{id}`, `POST …/lookup` (≤ 500 ids), `GET …/deleted` (tombstones) - `docs/features/api/solving-time-sync.md`. **Every solving time is deleted through `PuzzleSolvingTimeRepository::delete($time, $via)`**: it buries the whole time in `solving_time_graveyard` (disaster recovery, never read, 24 months, wiped on account deletion - which deletes times without a grave) and writes a `solving_time_tombstone` per registered member; `SolvingTimeDeletionCoverageTest` guards it
- **Full docs:** `docs/features/api/README.md`
