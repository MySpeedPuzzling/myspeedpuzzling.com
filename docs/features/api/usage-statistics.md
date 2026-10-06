# API usage statistics

Status: **implemented** (2026-10-07). Counts every authenticated request to the public API (`/api/v1/*`)
per caller, per request type and per UTC day. Players see the usage of their own tokens, of the apps they
connected and of the apps they registered; admins see everything at `/admin/api-usage`. Excluded: the
internal API, the legacy `/api/v0`, unauthenticated requests (mostly scanners probing `/api/v1/config`
and the like - Traefik and Prometheus count those) and the OAuth2 token endpoint (issued tokens are rows in
`oauth2_access_token` already).

## Why this shape

Measured before building (2026-10-06): 25-446 `/api/v1` requests a day, 72 personal access tokens, 9
OAuth2 clients. Tiny today, but the design must not care whether it is 100 or 10 million requests a day:

- **Only Redis sees each request.** One Lua script call after the response is sent; Postgres is not
  touched on the request path at all.
- **Postgres stores daily aggregates**, copied from Redis every 5 minutes. The table grows with
  *callers × request types × days*, never with the number of requests: 1000 active tokens using 5
  request types are ~10k rows a day, ~7M rows in the 24-month window.
- Rejected: **Prometheus** (one series per token = unbounded label cardinality, 30-day retention,
  not readable by the app for players' own charts), **Loki** (30-day retention, aggregations over big
  streams are what Loki does worst, no per-player GDPR delete; it stays the place for request
  *forensics* - see the request-logging proposal), **Tempo** (no PHP spans, no identity, 14 days),
  **a Postgres upsert per request** (a write per request, hot rows, WAL + vacuum growing with traffic),
  **ClickHouse/TimescaleDB** (only worth it if per-request analytics ever becomes a product).

## Callers

`Value\ApiCaller` is the one definition of "who made the request":

| Kind | Key | Player | Shown to |
|---|---|---|---|
| personal access token | `pat:{tokenId}:{playerId}` | the token's owner | the owner, admins |
| OAuth2 authorization-code token | `oauth:{clientId}:{playerId}` | the player who connected the app | that player, the app's owner (summed over players), admins |
| OAuth2 `client_credentials` token | `client:{clientId}` | none | the app's owner, admins |

`PatUser` carries the token id for this (the authenticator has it). Client identifiers are URL-encoded in
the key, so a `:` or `|` can never break it.

**Request type** (`operation`) = HTTP method + API Platform URI template, e.g. `GET /api/v1/players/{playerId}/results`
(`ApiUsageOperation`): ~40 values, bounded by the code, never by the URL. A request that matched no API
Platform operation (404 on an unknown path) is `GET (unknown)` etc.

**Status class**: `2xx`, `3xx`, `4xx`, `429`, `5xx` - 429 has its own class so a future rate limiter's
rejections are visible per caller.

## Write path (request time)

`ApiUsageSubscriber`, active only on the main request of the `api` firewall path `/api/v1/`:

1. `kernel.request` (priority 4096) stamps `hrtime()` into a request attribute.
2. `kernel.response` (priority -4096) stores the duration in ms - app time, not network time.
3. `kernel.terminate` builds the record (caller from the security token, operation, status class,
   duration) and calls `ApiUsageCounter::record()`.

**FrankenPHP worker mode** (verified in `vendor/symfony/runtime/Runner/FrankenPhpWorkerRunner.php`): the
runner sends the response inside `frankenphp_handle_request()` and calls `terminate()` only after it
returns, so the client never waits for the counting. Rules this code follows:

- **Catch `\Throwable`** in the subscriber - an exception escaping `terminate()` leaves the runner loop
  and the worker script exits (FrankenPHP restarts it, a fresh boot of 100-300 ms).
- **One Redis round-trip, nothing else** in terminate - the PHP thread is busy until it finishes. No
  Doctrine, no Messenger.
- **Stateless subscriber** - everything comes from the `TerminateEvent`'s request and response, nothing
  is kept in properties. The security token is still set at terminate (services are reset when the next
  request starts) - the same thing `PlayerActivitySubscriber` relies on.
- **No `$_SERVER['REQUEST_TIME_FLOAT']`** - `$_SERVER` is assembled per request by the runner; the
  duration comes from the request attribute.
- **The Redis connection outlives requests** - short timeouts (0.5 s), and a connection broken by a
  Redis restart is retried by phpredis on the next command; the failed record is caught and logged at
  warning.

`RedisApiUsageCounter` runs one Lua script (`EVALSHA`, falling back to `EVAL` after a Redis restart
dropped the script cache), atomically:

| Key | Type | Content |
|---|---|---|
| `api_usage:{Ymd}:requests` | hash | `{callerKey}\|{operation}\|{statusClass}` → requests |
| `api_usage:{Ymd}:duration_ms` | hash | same field → summed duration in ms |
| `api_usage:{Ymd}:peak_per_minute` | hash | `{callerKey}` → highest requests in one minute that day |
| `api_usage:{Ymd}:last_request_at` | hash | `{callerKey}` → unix time of the latest request |
| `api_usage:minute:{YmdHi}:{callerKey}` | counter | requests of that caller in that minute, TTL 120 s |

Day keys expire 3 days after their last write, so the store holds at most three days and needs no
cleanup. Days and minutes are UTC.

## Redis instance: `redis-state`

The counters live in their own Redis, not in the cache one:

| | cache `redis` | `redis-state` |
|---|---|---|
| eviction | `allkeys-lru` (right for a cache) | `noeviction` - counters are never dropped |
| persistence | none | AOF, `appendfsync everysec` - a restart loses ≤ 1 s |
| memory | 384 MB | 64 MB (every key has a TTL; a few hundred KB in practice) |

A separate *database number* in the cache instance would not do: eviction policy, memory limit and
persistence are per instance. With `noeviction` a full instance refuses writes - caught by the code,
and watched by the `redis_exporter` sidecar + alert in lily.srv. AOF everysec costs a buffered
`write()` per command; the fsync runs in a background thread once a second.

Env: `REDIS_STATE_DSN` (dev `redis://redis-state:6379`, prod set in lily.srv's compose). Test env uses
`InMemoryApiUsageCounter` (CI has no Redis for functional tests); `RedisApiUsageCounterTest` runs the real
Lua script against `REDIS_STATE_DSN` with a per-test key prefix.

## Copy into Postgres (every 5 minutes)

Cron `myspeedpuzzling:flush-api-usage` → `FlushApiUsage` → `FlushApiUsageHandler`:

- reads today, yesterday and the day before from Redis (so a cron outage of up to two days loses nothing),
- upserts `api_usage_day` and `api_caller_day` with **absolute values and `GREATEST`**: a re-run, two
  overlapping runs or a run after a Redis restart can never lower a stored number (a lost Redis counts
  under-, never double-counts),
- skips rows whose player or token has been deleted meanwhile (no FK violation, nothing left behind),
- stamps `personal_access_token.last_used_at` and `oauth2_user_consent.last_used_at` (never moving them
  back).

The upserts are native SQL in the repository - a documented bulk exception to the "PHP objects only" rule:
hundreds of counter rows per run, merged with `GREATEST`, with a unique key that overlapping runs would
race on through the ORM.

Daily cron `myspeedpuzzling:prune-api-usage` deletes rows older than 24 months.

### Tables

`api_usage_day` - unique `(day, caller_key, operation, status_class)`:
`day`, `caller_key`, `player_id` (FK, cascade), `personal_access_token_id` (FK, cascade),
`oauth2_client_identifier`, `operation`, `status_class`, `requests`, `duration_ms_total`.

`api_caller_day` - unique `(day, caller_key)`: same caller columns + `peak_requests_per_minute`,
`last_request_at`.

Deleting a player deletes their rows (GDPR); `client_credentials` rows have no player and stay.

## Where it shows

- **Edit profile**: each personal access token, connected app and own app shows "N requests in the
  last 30 days" and links to the usage page (one query for the whole page).
- **`/{_locale}/api-usage`** (`api_usage`, signed in): month picker, chips for every token / connected
  app / own app the player has, daily chart (stacked by status), totals (requests, failed share, peak per
  minute, last request) and the breakdown per request type. An own app's numbers are summed over all of
  its users, never per user.
- **`/admin/api-usage`** (`admin_api_usage`): every caller of the month, filter by caller and by request
  type, daily chart stacked by the busiest callers, tables per caller (requests, failed, 429, average ms,
  peak per minute, last request), per request type and per status.

## Later: rate limiting

The counters above are not a rate limiter (they count after the response, per day); a limiter checks
before the controller, in a window of seconds, atomically. What it reuses: the `redis-state` instance,
`ApiCaller` keys, `ApiUsageOperation`, and the data in `api_caller_day.peak_requests_per_minute` to
choose limits nobody legitimate hits. Design when it is needed:

- one Lua script (GCRA or sliding window) on `kernel.request` after authentication - one atomic
  round-trip, **not** Symfony's RateLimiter: our config takes a Postgres advisory lock per consume
  (`lock.default.factory` with `LOCK_DSN=postgresql+advisory`) plus a Redis GET and SET - fine for the
  one `api_puzzle_search` endpoint, too heavy in front of every request,
- limits per caller kind and membership, optional weights per request type,
- `RateLimit-*` headers, `429` + `Retry-After`, fail open when Redis is down,
- `api_puzzle_search` folds into it.

Tracked in `docs/TODO.md`.
