# API

## Overview

MySpeedPuzzling exposes a REST API (`/api/v1/`) for third-party integrations and personal use. The API supports two authentication methods:

- **Personal Access Tokens (PAT)** — self-service tokens for accessing your own data
- **OAuth2** — for third-party applications accessing user data with consent

The API is built with API Platform and documented via Swagger UI at `/api/docs`.

## Authentication

### Personal Access Tokens (PAT)

PATs are long-lived tokens for accessing your own data only. Any logged-in user can create multiple named PATs from their profile settings.

- **Format:** `msp_pat_` + 48 hex characters
- **Header:** `Authorization: Token msp_pat_...`
- **Access:** Own data only (`/api/v1/me/*` endpoints)
- **Cannot** access other players' data
- **No scopes** — full read/write access to own data
- **Fair Use Policy** must be accepted before generating
- **Management:** Create, revoke, and view usage in profile settings
- **Audit:** `last_used_at` tracked on every request

### OAuth2

Built on `league/oauth2-server-bundle`. Supports two flows:

**Authorization Code** (user-facing apps) — users authorize third-party apps to access their data with consent. Read and write access per granted scopes.

**Client Credentials** (service-to-service) — read-only access to any non-hidden player's public data. No user context.

### Scopes

| Scope | Description | Auth Code | Client Credentials |
|-------|-------------|-----------|-------------------|
| `profile:read` (default) | View profile info | Yes | Yes |
| `email:read` | View user email address | Yes | Yes |
| `results:read` | View puzzle solving results | Yes | Yes |
| `statistics:read` | View solving statistics | Yes | Yes |
| `collections:read` | View puzzle collections | Yes | Yes |
| `solving-times:write` | Create and edit solving times | Yes | No |
| `collections:write` | Create, edit, delete collections and items | Yes | No |

The list lives in the `OAuth2Scope` enum (`src/Value/OAuth2Scope.php`), which also feeds
`league_oauth2_server.scopes.available`. `OAuth2Scope::requiresUserContext()` marks the two write
scopes as auth-code-only, and `OAuth2ClientCredentialsScopeSubscriber` (`OAuth2Events::SCOPE_RESOLVE`)
**strips them from every `client_credentials` token** instead of failing the request — the bundle
grants *everything the client holds* when no `scope` parameter is sent, so a hard error would break
parameter-less machine-token calls from clients approved for write scopes (RFC 6749 §3.3 permits
narrowing). The token response says what was actually granted:

```json
{"token_type":"Bearer","expires_in":3600,"access_token":"…","refresh_token":"…","scope":"profile:read results:read"}
```

`scope` is added by `ScopeAwareBearerTokenResponse` (`authorization_server.response_type_class`) —
`league/oauth2-server` omits it by default. Roles are derived by the bundle as
`strtoupper('ROLE_OAUTH2_' . scope)` with no punctuation normalisation, so `solving-times:write`
grants `ROLE_OAUTH2_SOLVING-TIMES:WRITE` (hyphen kept) — `OAuth2Scope::role()` spells it right; a
hand-typed `SOLVING_TIMES` variant silently matched nothing until 2026-08 (PR #184).

### Token TTLs

- Access token: 1 hour (stateless JWT)
- Refresh token: 1 month
- Auth code: 10 minutes

## Endpoints

### "Me" Endpoints (PAT + OAuth2 with user context)

| Method | Endpoint | Required |
|--------|----------|----------|
| GET | `/api/v1/me` | PAT or `profile:read` (the `email` field is populated only for PAT or tokens granted `email:read`, otherwise `null`). Also carries `has_active_membership`, `membership_ends_at` (ISO-8601, `null` without an active membership) and the owner's opt-out flags `time_predictions_opted_out`, `ranking_opted_out`, `streak_opted_out` |
| GET | `/api/v1/me/results?type=solo\|duo\|team` | PAT or `results:read` |
| GET | `/api/v1/me/puzzles/{puzzleId}/predicted-time` | PAT or `results:read` |
| GET | `/api/v1/me/statistics` | PAT or `statistics:read` |
| POST | `/api/v1/me/solving-times` | PAT or `solving-times:write` |
| PUT | `/api/v1/me/solving-times/{timeId}` | PAT or `solving-times:write` |
| GET | `/api/v1/me/collections` | PAT or `collections:read` |
| GET | `/api/v1/me/collections/{id}/items` | PAT or `collections:read` |
| POST | `/api/v1/me/collections` | PAT or `collections:write` (members only) |
| PUT | `/api/v1/me/collections/{id}` | PAT or `collections:write` (members only) |
| DELETE | `/api/v1/me/collections/{id}` | PAT or `collections:write` (members only) |
| POST | `/api/v1/me/collections/{id}/items` | PAT or `collections:write` |
| DELETE | `/api/v1/me/collections/{id}/items/{itemId}` | PAT or `collections:write` |

### Player Endpoints (OAuth2 only)

| Method | Endpoint | Scope |
|--------|----------|-------|
| GET | `/api/v1/players/{id}/results?type=solo\|duo\|team` | `results:read` |
| GET | `/api/v1/players/{id}/statistics` | `statistics:read` |
| GET | `/api/v1/players/{id}/collections` | `collections:read` (public only) |
| GET | `/api/v1/players/{id}/collections/{cid}/items` | `collections:read` (public only) |

### Competition Endpoints (any authenticated token)

| Method | Endpoint | Auth |
|--------|----------|------|
| GET | `/api/v1/competitions?status=all\|live\|upcoming\|past&online=true&country=cz` | Any valid PAT or OAuth2 token (no specific scope) |
| GET | `/api/v1/competitions/{id}` | Any valid PAT or OAuth2 token (no specific scope) |

- **List** returns basic info for **approved, standalone** competitions only (mirrors the public website listing). Optional filters: `status` (default `all`), `online` (default `false`), `country` (ISO 3166-1 alpha-2). Response shape: `{ "count": N, "competitions": [ ... ] }`. Participants are never returned.
- **Detail** returns the competition metadata plus its `rounds`. Each round exposes `id`, `name`, `starts_at`, `minutes_limit`, `category`, and `puzzles`. **Participants are never returned.**
- **Unapproved or rejected competitions return `404`** — they must not leak through the API (the underlying `byId()` query does not filter on approval, so the provider gates on `approvedAt`).
- **Puzzle-reveal privacy (critical):** a round puzzle flagged *hide until round starts* is governed by the same single-source-of-truth rule used on the website (`GetEditionRounds`). Until `round.startsAt + 10 minutes`:
  - `hideMode = Entirely` → the puzzle is **omitted entirely** from the round's `puzzles`.
  - `hideMode = ImageOnly` → the puzzle is returned but `image` is `null` (name, pieces count, manufacturer remain visible).
  - After reveal, everything is visible. This behavior is covered by dedicated tests in `CompetitionDetailEndpointTest`.

### Puzzle Endpoints (any authenticated token)

| Method | Endpoint | Auth |
|--------|----------|------|
| GET | `/api/v1/puzzles?query=…&ean=…&manufacturer=…&pieces_min=…&pieces_max=…&sort=…&difficulty=…&page=…&limit=…` | Any valid PAT or OAuth2 token (no specific scope); `sort=easiest\|hardest` and `difficulty=` are members-only |
| GET | `/api/v1/puzzles/{puzzleId}` | Any valid PAT or OAuth2 token (no specific scope); `prediction` / `solves` only with PAT or `results:read`, `difficulty` / `prediction` members-only |

### Puzzles

`GET /api/v1/puzzles` is the catalog: the same search as the website's `/puzzle` page (`SearchPuzzle::byUserInput`, brand / sort / difficulty normalised through `PuzzleSearchCriteria` so the two can never drift on what is members-only), plus the barcode lookup of the web scanner (`SearchPuzzle::allByEan`). Provider: `PuzzleSearchResponseProvider`; the query parameters are declared once on `PuzzleListResponse` (API Platform `QueryParameter`) - the declaration validates the input and renders the Swagger documentation.

| Parameter | Type / constraints | Meaning |
|-----------|-------------------|---------|
| `query` | string, trimmed, 2-100 characters | name / alternative name (accent-insensitive), identification number, EAN substring - the same matching as the web search box |
| `ean` | `^\d{8,14}$` | exact barcode lookup, leading / trailing zeros tolerated. **Mutually exclusive** with `query`, `manufacturer`, `pieces_min`, `pieces_max`, `sort`, `difficulty` (422 "ean cannot be combined with …"). `page` / `limit` still apply |
| `manufacturer` | UUID | brand filter; an unknown id yields an empty result, not 404 |
| `pieces_min`, `pieces_max` | int 1-50000, `pieces_min ≤ pieces_max` (422 otherwise) | inclusive piece-count range; exact count = both equal (`PiecesRange`) |
| `sort` | `most-solved` (default) `least-solved` `a-z` `z-a` `easiest` `hardest` | `easiest` / `hardest` are **members-only → 403** `sort=easiest requires an active membership` (the website silently falls back, the API is explicit) |
| `difficulty` | comma list of `very_easy` `easy` `average` `challenging` `hard` `very_hard` | tier filter, **members-only → 403**; each token must be valid (422) |
| `page` | int 1-500, default 1 | |
| `limit` | int 1-100, default 20 | |

No filter at all lists the whole catalog (most solved first) - the catalog is public, scraping is bounded by the caps (≤ 100 items per page, ≤ 500 pages) and the rate limit. Invalid input is a `422 application/problem+json` with `violations[].propertyPath`, never a 500.

**Rate limit:** 60 requests per minute per token owner (`api_puzzle_search` sliding window in `config/packages/rate_limiter.php`; key = player id for PAT / authorization-code tokens, client id for `client_credentials`). Over the limit → `429` with `Retry-After` (seconds). A 422 never consumes the budget (validation runs first).

**Response** `{ count, total, page, limit, has_more, puzzles: [ … ] }` - `count` is this page, `total` the whole result. Each card:

```json
{ "id": "018d0003-0000-0000-0000-000000000002", "name": "Puzzle 2", "alternative_name": null,
  "manufacturer": { "id": "018d0002-0000-0000-0000-000000000001", "name": "Ravensburger" },
  "pieces_count": 500, "image": "puzzles/…/box.jpg", "ean": "4005556123456", "identification_number": null,
  "is_available": true, "is_approved": true,
  "statistics": { "solved_times": 41,
                  "solo": { "count": 30, "fastest_seconds": 1500, "average_seconds": 2160, "slowest_seconds": 3900 },
                  "duo":  { "count": 8,  "fastest_seconds": 1180, "average_seconds": 1320, "slowest_seconds": 1700 },
                  "team": { "count": 3,  "fastest_seconds": null, "average_seconds": null, "slowest_seconds": null } },
  "difficulty": { "score": 1.18, "level": "challenging", "confidence": "medium", "sample_size": 14 },
  "prediction": { "predicted_seconds": 1890, "range_low_seconds": 1607, "range_high_seconds": 2174,
                  "is_personalized": true, "personal_solve_count": 3, "predicted_attempt_number": 4, "last_time_seconds": 2100 },
  "solves": { "solo": { "count": 3, "best_time_seconds": 1700, "last_time_seconds": 1700, "first_solved_at": "2026-07-30T10:12:45+00:00", "last_solved_at": "2026-08-09T14:30:00+00:00" },
              "duo":  { "count": 0, "best_time_seconds": null, "last_time_seconds": null, "first_solved_at": null, "last_solved_at": null },
              "team": { "count": 0, "best_time_seconds": null, "last_time_seconds": null, "first_solved_at": null, "last_solved_at": null } } }
```

- `statistics` is public, from the precomputed `puzzle_statistics` row (`GetPuzzleStatistics::forPuzzleList`), **always split by discipline** - solo, duo and team are different disciplines and are never merged; a puzzle nobody has solved has zeros and nulls.
- The three insight objects are **always present** and `null` means exactly "not available to this token" (never an error, so one client code path works for every kind of token). When present, the object is complete and carries `null` *inside* for "not enough data":
  - `difficulty` - token owner is a member (`ApiTokenOwner::isMember()`); a member looking at a puzzle without a difficulty row gets `{ score: null, level: null, confidence: "insufficient", sample_size: 0 }`
  - `prediction` - member **and** not opted out of time predictions **and** the token may read results (PAT or `results:read`); all fields `null` + `is_personalized: false` when there is nothing to predict from
  - `solves` - the token owner's **own** history (the same row set as `/me/results?type=`, unboxed and suspicious-flagged times included), for a token with a player behind it **and** PAT / `results:read`; always split by discipline like `/me/statistics`
  - a `client_credentials` token has no player ⇒ all three are `null`
- `hide_until` (secret competition puzzles) ⇒ never returned - not in the listing, not by `query`, not by `ean`; `hide_image_until` ⇒ `image: null` until the embargo ends.
- **Fixed query cost** (asserted by `PuzzleSearchEndpointTest::testQueryBudgets`): the card builder (`PuzzleResponseFactory`) collects the puzzle ids once and makes one batch call per object - `GetPuzzleStatistics::forPuzzleList`, `GetPuzzleDifficulty::forPuzzleList`, `GetPlayerPredictions::forPuzzles` (itself ≤ 4 queries), `GetPlayerPuzzleSolves::forPuzzles` - each only when the token is entitled; a page of 100 costs the same as a page of 5. Measured: authentication 1 query (PAT) / 3 (OAuth2: access token, player, consent usage) + count + search + statistics + owner profile + solves + [member: difficulty + predictions] - `client_credentials` 3-4, non-member 6 (PAT) / 8 (OAuth2), member 10-11 (PAT) / 12-13 (OAuth2).

#### GET `/api/v1/puzzles/{puzzleId}`

The puzzle detail **is the catalog card** of that one puzzle - exactly the fields above, same names, same order, same values for the same token (`PuzzleDetailEndpointTest::testDetailIsTheCatalogCard` asserts the detail JSON equals the search card JSON). Provider: `PuzzleDetailResponseProvider` → `GetPuzzleOverview::byId` → `PuzzleResponseFactory::card()`, so the gates and the three insight objects are the one code path shared with `GET /api/v1/puzzles`; DTO `PuzzleDetailResponse` (OpenAPI `Puzzle`), built only via `fromCard()`.

- `statistics` is always present (public); `difficulty`, `prediction`, `solves` are **always present** and `null` exactly when the token is not entitled (difficulty: member; prediction: member + not opted out + PAT / `results:read`; solves: a player behind the token + PAT / `results:read`; `client_credentials` ⇒ all three `null`). A member on an unscored, never-solved puzzle gets `difficulty: { …, confidence: "insufficient", sample_size: 0 }`, a `prediction` with `null` fields and `is_personalized: false`, and `solves` with zero counts - objects, not `null`.
- **404** for an unknown or malformed id (validated before any query) **and for a secret competition puzzle** (`hide_until` in the future) - stricter than the website's puzzle page, which shows it by id; `GetPuzzleOverview::byId` loads `hide_until` for this (it does not filter it, the web needs the row). `hide_image_until` ⇒ `image: null` until the embargo ends. No 301 to a merge survivor (follow-up).
- Query cost (asserted by `PuzzleDetailEndpointTest::testQueryBudgets`): authentication (0-1 `client_credentials`, 1 PAT, 3 OAuth2) + overview 1 + statistics 1 + [player token: owner profile 1 + solves 1 with PAT / `results:read`] + [member: difficulty 1 + prediction 3-4] - measured `client_credentials` 2-3, non-member 5 (PAT) / 7 (OAuth2), member 9-10 (PAT) / 11-12 (OAuth2); ceilings pinned one above.

### Collection Membership Gating

- **System collection** (`id=default`): All users can list/add/remove items
- **Custom collections**: Only members can create, edit, delete, and manage items
- API returns 403 when non-member attempts members-only collection operation

### Members-Exclusive Data

Puzzle difficulty and player skill tiers are included in responses only if the token owner has active membership. Non-members see `null` for these fields.

An app tells the reasons for a `null` members-only block apart from `GET /api/v1/me` alone: `has_active_membership` (false ⇒ upgrade) and the opt-out flags `time_predictions_opted_out`, `ranking_opted_out`, `streak_opted_out` (true ⇒ the player switched that feature off on the website) — there is deliberately no per-endpoint `unavailable_reason` field.

Puzzle Insights are gated **exactly as on the website** - the token owner (PAT, or the player behind an authorization-code token) must be a member; a `client_credentials` token has no player and therefore no membership. There is deliberately no `/api/v1/players/{id}/…` variant of members-only data: predictions are self-only on the website, and a single member's token must never become a proxy that serves a members-only feature to a third-party app's non-member users.

One service decides this for every provider: `ApiTokenOwner` (`src/Services/Api/`) - `profile()` (the player behind the token, `null` for a machine token, memoised per request), `isMember()`, `canReadResults()` (PAT or `results:read`), `canReadStatistics()`. Providers never `assert($user instanceof ApiUser)` outside `/me/*`; they ask `ApiTokenOwner` and return `null` objects for what the token is not entitled to. Members-only blocks are **nullable objects**: `null` ⇔ "not available to this token" (not a member / machine token / missing scope / opted out); when available, the object is always present and carries `null` inside for "not enough data" (the `GET /me` flags above tell the reasons apart). Design and progress: `docs/features/api/v1-expansion-plan.md`.

### GET `/api/v1/me/puzzles/{puzzleId}/predicted-time`

The API twin of the Puzzle Insights block on the puzzle detail page (`PuzzleDetailController`), same gates. The flat shape predates the insight objects of `GET /api/v1/puzzles` and `GET /api/v1/puzzles/{puzzleId}` and stays as it is (no BC breaks); since PR 2 it is a projection of the very same objects - `MyPredictedTimeResponseProvider` gates through `ApiTokenOwner` and flattens `TimePredictionResponse` / `PuzzleDifficultyResponse` via `PredictedTimeResponse::fromInsights()` (a puzzle without a difficulty row is still `null` here, not `"insufficient"`):

- **Not a member** → every field except `puzzle_id` is `null` (`is_personalized: false`), 200.
- **Member** → `difficulty_score` / `difficulty_level` (`very_easy|easy|average|challenging|hard|very_hard`) / `difficulty_confidence` (`insufficient|low|medium|high`) whenever the puzzle has a difficulty row (`null` otherwise). The prediction (`predicted_seconds`, `range_low_seconds`, `range_high_seconds`, `is_personalized`, `personal_solve_count`, `last_time_seconds`) comes from `GetPlayerPrediction` — personalized when the owner has solo solves of the puzzle, otherwise the statistical baseline × difficulty estimate, `null` when there is nothing to predict from.
- **Member who opted out of time predictions** → prediction fields `null`, difficulty still returned (same split as `templates/puzzle/_difficulty_section.html.twig`).
- Unknown or malformed puzzle id → 404. Fixed number of queries per call (no per-item fan-out).

### POST `/api/v1/me/solving-times`

```json
{
    "puzzle_id": "uuid",
    "time": "1:23:45",
    "comment": "Optional comment",
    "finished_at": "2025-12-01T14:30:00+00:00",
    "first_attempt": true,
    "unboxed": false,
    "round_id": "uuid",
    "group_players": ["#PLAYER_CODE", "Guest Name"]
}
```

- `time` format: `HH:MM:SS` or `MM:SS`
- `group_players`: player codes prefixed with `#`, or plain names for unregistered players
- `round_id`: optional, nullable. When set, the time is linked to that competition round and automatically to its competition. An invalid or unknown `round_id` returns 404.
- Photo uploads not supported via API (use the website)

### Privacy

- `/api/v1/me/*` always returns full data for the token owner
- `/api/v1/players/{id}/*` returns empty/zeroed data for private profiles (not 403)
- Hidden players are never returned in service-to-service queries

### Error Handling

- Missing/invalid/expired token: 401
- Missing scope: 403
- `client_credentials` token on any `/api/v1/me/*` endpoint: 403 (no user context)
- Non-existent player UUID: 404
- Membership required: 403 with message
- Validation error: 422
- Error format: `application/json` and `application/problem+json` (RFC 7807)

## OAuth2 Client Registration

### Self-Service Flow

1. User accepts the Fair Use Policy at `/en/fair-use-policy` (required before accessing the form)
2. User navigates to `/en/request-api-access` (linked from `/en/for-developers` and profile settings)
3. Fills in Symfony form: app name, description, purpose, application type (confidential/public), scopes, redirect URIs
4. Admin receives email notification about the new request
5. Admin reviews at `/admin/oauth2-requests` — approve or reject with reason
6. **On approval:** User receives email with a one-time credential claim link (valid 7 days)
7. User clicks link → sees client ID + secret once → saves them securely
8. Credentials are never shown again after claiming

### Credential Management

- Users can view their applications in profile settings ("My Applications" section)
- Approved apps can reset credentials (generates new secret, revokes all tokens, sends new claim link)

### CLI Client Management

```bash
php bin/console myspeedpuzzling:oauth2:create-client "App Name" app-identifier \
    --redirect-uri=https://example.com/callback \
    --grant-type=authorization_code \
    --grant-type=refresh_token \
    --scope=profile:read
```

Add `--public` for public clients (PKCE required, no secret).

```bash
php bin/console myspeedpuzzling:oauth2:list-clients
```

## Audit Trail

- **PAT:** `last_used_at` updated on every authenticated API request (in `PatAuthenticator`)
- **OAuth2:** `last_used_at` on `oauth2_user_consent` updated on API requests (throttled to every 5 minutes, in `ApiTokenUsageSubscriber`)
- Visible in profile settings for both PATs and connected applications

## Security Architecture

Two authenticators on the `api` firewall (`^/api/v1/`):
- `PatAuthenticator` — handles `Token msp_pat_*` header (custom scheme, not Bearer), creates `PatUser` with `ROLE_PAT`
- OAuth2 authenticator (from bundle) — handles `Bearer` JWT tokens, creates `OAuth2User` with scope-based roles

PAT uses `Authorization: Token ...` (not `Bearer`) to avoid collision with the OAuth2 authenticator which intercepts all `Bearer` tokens.

Both `PatUser` and `OAuth2User` implement the `ApiUser` interface (`getPlayer(): Player`).

Access control:
- `^/api/v1/me` → `ROLE_PAT` or `ROLE_OAUTH2_USER` (`PatUser::ROLE` / `OAuth2User::ROLE`) — i.e. a token with a
  player behind it. A `client_credentials` token is authenticated too (as the bundle's `ClientCredentialsUser`, no
  roles), and under `IS_AUTHENTICATED_FULLY` it used to reach the providers, fail `assert($user instanceof ApiUser)`
  and return 500; now it gets 403
- `^/api/v1/players/.*/results` → `ROLE_OAUTH2_RESULTS:READ`
- `^/api/v1/players/.*/statistics` → `ROLE_OAUTH2_STATISTICS:READ`
- `^/api/v1/players/.*/collections` → `ROLE_OAUTH2_COLLECTIONS:READ`
- `^/api/v1/competitions` → `IS_AUTHENTICATED_FULLY` (PAT or any OAuth2 token, no specific scope)
- `^/api/v1/puzzles` → `IS_AUTHENTICATED_FULLY` (PAT or any OAuth2 token, no specific scope; members-only parts of the response are gated per token owner inside the providers)

## Fair Use Policy

Full policy page at `/en/fair-use-policy` with 10 sections: welcome, rate limits, permitted use, data ownership, API keys, monitoring, attribution, community, policy updates, and contact.

**Acceptance flow:** Users must read the full policy and click "I Accept the API Usage Policy" at the bottom. Acceptance is stored as `fairUsePolicyAcceptedAt` on the `Player` entity. Until accepted, PAT generation and OAuth2 client request forms are locked with a CTA to read and accept the policy.

**Enforced limits:** `GET /api/v1/puzzles` - 60 requests per minute per token owner (`429` + `Retry-After`, see Puzzles above). Other endpoints are not rate-limited beyond the policy (yet).

- Controller: `FairUsePolicyController` (GET), `AcceptFairUsePolicyController` (POST)
- Message: `AcceptFairUsePolicy` → `AcceptFairUsePolicyHandler`

## CORS

Configured in `config/packages/nelmio_cors.php` with `allow_origin: ['*']` globally.

## Deprecated: V0 Legacy API

> **Deprecated** — Do not develop further. Kept for backward compatibility only.

`GET /api/v0/players/{playerId}/results?token=...` — effectively unauthenticated. Lives in `src/Controller/Api/V0/`.

## Internal APIs (not for public use)

### Stopwatch API (`/api/stopwatch/`)

Session-authenticated timer management for the web app's Stimulus controller.

### Mobile Billing (`/api/android/`, `/api/ios/`)

Stub endpoints for in-app purchase verification (not implemented).

## Environment Variables

| Variable | Description |
|----------|-------------|
| `OAUTH2_PRIVATE_KEY` | RSA private key for signing tokens |
| `OAUTH2_PUBLIC_KEY` | RSA public key for verifying tokens |
| `OAUTH2_PASSPHRASE` | Private key passphrase (empty in dev) |
| `OAUTH2_ENCRYPTION_KEY` | Encryption key for refresh tokens |

## Database Tables

| Table | Description |
|-------|-------------|
| `personal_access_token` | PAT storage (hashed tokens, audit trail) |
| `oauth2_client` | Registered OAuth2 clients |
| `oauth2_client_request` | OAuth2 client registration requests (pending/approved/rejected) |
| `oauth2_access_token` | Issued access tokens |
| `oauth2_authorization_code` | Short-lived auth codes (10 min) |
| `oauth2_refresh_token` | Refresh tokens (1 month) |
| `oauth2_user_consent` | User consent per client/scope with `last_used_at` tracking |

## Key Files

| File | Purpose |
|------|---------|
| `src/Security/ApiUser.php` | Shared interface for PAT and OAuth2 users |
| `src/Security/PatUser.php` | PAT user with `ROLE_PAT` |
| `src/Security/PatAuthenticator.php` | PAT token authenticator |
| `src/Security/OAuth2User.php` | OAuth2 user (implements `ApiUser`) |
| `src/Security/OAuth2UserProvider.php` | Loads Player by UUID from JWT |
| `src/Entity/PersonalAccessToken.php` | PAT entity (hashed token, audit fields) |
| `src/Entity/OAuth2/OAuth2ClientRequest.php` | Client registration request entity |
| `src/Entity/OAuth2/OAuth2UserConsent.php` | Consent entity with `lastUsedAt` |
| `src/Api/V1/` | All API Platform resources, providers, and processors |
| `src/Api/V1/PuzzleListResponse.php` | `GET /api/v1/puzzles` resource: the single declaration of its query parameters (validation + OpenAPI) |
| `src/Api/V1/PuzzleDetailResponse.php` | `GET /api/v1/puzzles/{puzzleId}` resource - the card of one puzzle, built only via `fromCard()` (provider `PuzzleDetailResponseProvider`) |
| `src/Api/V1/PuzzleResponse.php` | The puzzle card (+ `PuzzleStatisticsResponse`, `PuzzleDifficultyResponse`, `TimePredictionResponse`, `PlayerSolvesResponse`) |
| `src/Services/Api/ApiTokenOwner.php` | The single membership / scope gate behind every provider |
| `src/Services/Api/PuzzleResponseFactory.php` | Builds puzzle cards for the calling token at a fixed query cost (one batch call per object) |
| `src/Query/GetPuzzleStatistics.php`, `GetPlayerPuzzleSolves.php` | Batch queries behind the cards' `statistics` and `solves` objects |
| `tests/QueryCountAssertions.php`, `tests/OpenApiAssertions.php` | Test traits: query budgets via the profiler, parameter documentation via `/api/docs.jsonopenapi` |
| `src/Controller/FairUsePolicyController.php` | Fair use policy page |
| `src/Controller/AcceptFairUsePolicyController.php` | Accept FUP action |
| `src/Controller/CreatePersonalAccessTokenController.php` | PAT generation |
| `src/Controller/RevokePersonalAccessTokenController.php` | PAT revocation |
| `src/Controller/OAuth2/RequestApiAccessController.php` | OAuth2 client registration form |
| `src/Controller/OAuth2/ClaimOAuth2CredentialsController.php` | One-time credential display |
| `src/Controller/Admin/OAuth2ClientRequests*.php` | Admin review pages |
| `src/FormType/RequestApiAccessFormType.php` | OAuth2 request Symfony form type |
| `src/FormData/RequestApiAccessFormData.php` | OAuth2 request form data with validation |
| `src/EventSubscriber/ApiTokenUsageSubscriber.php` | OAuth2 usage tracking |
| `src/EventSubscriber/OAuth2ClientCredentialsScopeSubscriber.php` | Strips write scopes from `client_credentials` tokens |
| `src/Security/OAuth2/ScopeAwareBearerTokenResponse.php` | Adds granted `scope` to the token response |
| `src/Value/OAuth2Scope.php` | Scope enum: available list, auth-code-only flag, role name derivation |
| `config/packages/security.php` | Firewalls and access control |
| `config/packages/league_oauth2_server.php` | OAuth2 config (scopes, grants, TTLs) |
| `config/packages/api_platform.php` | API Platform config and Swagger |
| `templates/oauth2/request-api-access.html.twig` | Client registration form |
| `templates/oauth2/claim-credentials.html.twig` | One-time credential display |

## Client secrets are stored in plaintext (follow-up, raised 2026-08-01)

`league/oauth2-server-bundle` 1.2 moved to hashed client secrets, but the hashing lives only in
the bundle's own `CreateClientCommand` — `ClientManager::save()` does **not** hash. Every place
this repo creates a client passes a raw secret straight to `save()`:

- `src/MessageHandler/ApproveOAuth2ClientRequestHandler.php`
- `src/MessageHandler/ResetOAuth2ClientCredentialsHandler.php`
- `src/ConsoleCommands/OAuth2CreateClientConsoleCommand.php`
- `tests/DataFixtures/OAuth2ClientFixture.php`

This works today only because `client.allow_plaintext_secrets` defaults to `true`, which wraps the
hasher in a `MigratingPasswordHasher`. Consequences:

1. A **deprecation fires on every container compile** (verified in `var/cache/*/…Deprecations.log`).
2. It breaks outright at bundle **2.0**, and breaks *immediately* if anyone sets
   `allow_plaintext_secrets: false` to silence the deprecation without fixing creation first.
3. Since 1.2 the token endpoint **opportunistically rehashes**: on the first successful
   confidential-client authentication, `ClientRepository` calls `setSecret(hash)` +
   `ClientManager::save()`, which does `persist()` **and `flush()`** — a mid-request flush on
   `POST /oauth2/token`, outside any Messenger handler, contrary to the project's flush rule. It is
   wrapped in `try/catch (\Throwable)` so it cannot fail authentication, and it happens once per
   existing client.

**Fix:** hash via the `league.oauth2_server.password_hasher` service before `save()`, keeping the
plaintext only in `oauth2_client_request.client_secret` for the one-time claim flow (nothing
compares the two — the claim page reads only `oauth2_client_request`). Then run
`league:oauth2-server:rehash-client-secrets` and set `allow_plaintext_secrets: false`.

**No migration needed:** `UPGRADE-1.x.md` claims the `secret` column grew 128 → 255, but the
installed 1.2.2 mapping still declares `length(128)`, which matches `Version20260131170741` and
fits bcrypt's 60 chars. `doctrine:schema:validate` stays green — verified.
