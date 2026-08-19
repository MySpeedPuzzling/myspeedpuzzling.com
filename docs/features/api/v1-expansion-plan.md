# API V1 expansion plan — puzzles catalog + Puzzle Insights (Aug 2026)

Status: **plan, direction agreed** (Jan, 2026-08-19). Implemented one PR at a time, each by a
subagent working from this document, orchestrated by the session that wrote it. Tick the
checklist at the bottom as PRs land.

Context: PR #186 (2026-08-18) shipped the first Puzzle-Insights surface,
`GET /api/v1/me/puzzles/{puzzleId}/predicted-time`. There is still **no puzzle catalog in V1** —
an app cannot even obtain a `puzzle_id` to `POST /me/solving-times` except from the user's own
results/collections. This plan adds the catalog and folds Insights into the places apps already
read, under five non-negotiables.

---

## 0. Non-negotiables (every PR, every review)

| # | Rule | How it is enforced |
|---|------|--------------------|
| N1 | **Members-exclusive data behaves exactly as on the website.** Difficulty, predictions, skill tiers are visible only when the *token owner* (PAT owner, or the player behind an authorization-code token) has an active membership. A `client_credentials` token has no player ⇒ never a member. Predictions are **self-only**: there is no `/players/{id}` route carrying personal insights (see #186 — one member's token must never proxy a members-only feature to a third-party app's non-member users). | One gate, `ApiTokenOwner` (§1.1), used by every provider; cc-token + non-member + opted-out cases in every test class |
| N2 | **No BC breaks.** Only additive fields/endpoints. Never rename, retype, re-null or reorder existing fields. The flat `PredictedTime` shape from #186 stays as is. | Diff review: existing `*Response` constructors only gain trailing nullable/defaulted params; existing tests untouched and green |
| N3 | **Performance: fixed, small query count per request; no N+1.** Every list builds `puzzleIds` and makes **one** batch call (`GetPuzzleDifficulty::forPuzzleList`). Deep pagination capped. Search is rate-limited. | Each endpoint has a query budget (table per PR) **asserted in a test** via the profiler (`QueryCountAssertions`, §1.3) |
| N4 | **Every query parameter is validated and documented from one declaration.** Use API Platform `QueryParameter(key, schema, description, constraints, required, castToNativeType/nativeType)` on the operation: invalid input ⇒ `422 application/problem+json` with `violations`, never a 500 or a raw SQL error; the same declaration renders in Swagger/OpenAPI. Cross-parameter rules and membership-gated values are enforced in the provider (422 / 403 with a message). | Test per invalid value; one test that reads `/api/docs.jsonopenapi` and asserts the parameter names, enums and descriptions are present |
| N5 | **Security defaults:** all `/api/v1/*` endpoints require a valid token (no anonymous API); UUID-shaped input is validated before any query; `hide_until` (secret competition puzzles) ⇒ **never returned** by any puzzle endpoint (404 / excluded — stricter than the web's by-id page, same as the competition API's "critical" reveal rule); `hide_image_until` ⇒ `image: null`; no personal data in catalog responses. | Security checklist per PR (§7) |

Conventions (match the existing `src/Api/V1`): single-action providers, `final class XResponse` DTOs with snake_case public props, `shortName` + `openapi: new OpenApiOperation(tags:, summary:, description:)`, `security:` expression on the operation **and** an `access_control` rule, ids as UUID strings, seconds as ints, dates as ISO-8601 strings (`format('c')`), enums as lowercase tokens (`very_easy`, `medium`, `solo`). Members-only blocks are **nullable objects**: `null` ⇔ "not available to this token" (not a member / machine token / missing scope / opted out); when available, the object is always present and carries `null` *inside* for "not enough data". An app tells the reasons apart from `GET /me` (`has_active_membership`, opt-out flags — PR 0). Decision (Jan, 2026-08-19): **no `unavailable_reason` field** — it would be derivable and would multiply across endpoints.

---

## 1. Shared foundation (lands with PR 1, reused by all later PRs)

### 1.1 `ApiTokenOwner` — the single membership/scope gate
`src/Services/Api/ApiTokenOwner.php` (auto-registered by the `Services` glob; no `services.php` edit).

```php
final class ApiTokenOwner implements ResetInterface          // worker mode: memoised per request
{
    public function __construct(private Security $security, private GetPlayerProfile $getPlayerProfile) {}

    /** The player behind the token, or null for ClientCredentialsUser / anonymous. One query, memoised. */
    public function profile(): null|PlayerProfile;
    public function isMember(): bool;                         // profile()?->activeMembership === true
    public function canReadResults(): bool;                   // is_granted(PatUser::ROLE) || is_granted(OAuth2Scope::ResultsRead->role())
    public function canReadStatistics(): bool;                // PAT || statistics:read
    public function reset(): void;                            // clear memo
}
```
Rules: never `assert($user instanceof ApiUser)` outside `/me/*` providers — use `profile()` and handle `null`. `profile()` must not throw for machine tokens.

### 1.2 Response building blocks (`src/Api/V1/`)
- `PuzzleManufacturerResponse { id, name }`
- `PuzzleStatisticsResponse { solved_times:int, average_time_solo:?int, fastest_time_solo:?int, average_time_duo:?int, fastest_time_duo:?int, average_time_team:?int, fastest_time_team:?int }` (averages rounded to int seconds — the overview query yields numeric strings)
- `PuzzleDifficultyResponse { score:?float, level:?string (very_easy…very_hard), confidence:string (insufficient|low|medium|high), sample_size:int }` — built from `PuzzleDifficultyResult`; **for a member with no row, synthesise `{null, null, 'insufficient', 0}`** so that `difficulty: null` means exactly "members only" (N1 semantics)
- `TimePredictionResponse { predicted_seconds:?int, range_low_seconds:?int, range_high_seconds:?int, is_personalized:bool, personal_solve_count:?int, last_time_seconds:?int, predicted_attempt_number:?int }` — built from `TimePredictionResult`; all-null fields + `is_personalized:false` when `GetPlayerPrediction` returns null
- `PuzzleResponse` (the catalog card, list item and detail base):
  `id, name, alternative_name, manufacturer, pieces_count, image (null while hidden), ean, identification_number, is_available, is_approved, statistics, difficulty (?PuzzleDifficultyResponse)`
- `PuzzleResponseFactory` (`src/Services/Api/`): `card(PuzzleOverview $o, null|PuzzleDifficultyResult $d, bool $ownerIsMember): PuzzleResponse` and `cards(list<PuzzleOverview>, bool $ownerIsMember): list<PuzzleResponse>` (does the **one** `forPuzzleList` call when member, none otherwise).
- `DifficultyTier::fromApiValue(string): ?self` (inverse of `toApiValue()`), `SkillTier::toApiValue()` (PR 6).

### 1.3 Test helpers (`tests/`)
- `QueryCountAssertions` trait: `assertQueryCountAtMost(KernelBrowser $browser, int $max, string $why)` using `$browser->enableProfiler()` + the `db` collector (proven to work in this suite). Count **only the request** — create tokens before `enableProfiler()`.
- `PuzzleTestSeeder` (or methods on the test class): `seedDifficulty($puzzleId, float $score, MetricConfidence $c)` (the #186 pattern: find-or-create `PuzzleDifficulty`, `updateDifficulty`, flush, clear — DAMA rolls back), `hidePuzzleUntil($puzzleId, DateTimeImmutable $until)`, `hideImageUntil(...)`, `optOutOfTimePredictions($playerId)`.
- `OpenApiAssertions`: `assertOpenApiHasParameters(string $path, array $expectedNames)` reading `/api/docs.jsonopenapi`.

### 1.4 Rate limiting (search only, PR 1)
`config/packages/rate_limiter.php`: `api_puzzle_search` sliding window **60 / 1 minute**, keyed by the token's user identifier (player id for PAT/auth-code, client id for cc). On rejection throw `TooManyRequestsHttpException($retryAfter)` ⇒ 429 + `Retry-After`; declare `429` in the operation's OpenAPI responses. Test-env trap: limiter storage is Redis (shared, not rolled back) — give the limiter its own pool that is `cache.adapter.array` in `test` only (or `reset()` it in `setUp()`); look at how the login throttling tests stay deterministic first. Production value stays 60/min; document it on `/for-developers` next to the Fair Use Policy.

---

## 2. Auth matrix after the plan (target state)

| Endpoint | PAT | Auth-code (scope) | client_credentials | Members-only parts |
|---|---|---|---|---|
| `GET /me` (+flags, PR 0) | ✓ | `profile:read` | — (403) | — |
| `GET /me/puzzles/{id}/predicted-time` (#186) | ✓ | `results:read` | — (403) | all fields (null for non-members) |
| `GET /puzzles` (PR 1) | ✓ | any scope | ✓ | `difficulty` per item; `sort=easiest\|hardest`, `difficulty=` filter ⇒ 403 for non-members |
| `GET /puzzles/{id}` (PR 2) | ✓ | any scope (`predicted_time` only with `results:read`) | ✓ (insights null) | `difficulty`, `predicted_time` |
| `GET /me/results`, `/me/collections/{id}/items` (PR 3) | ✓ | as today | — | `difficulty` per item |
| `GET /players/{id}/results`, `/players/{id}/collections/{cid}/items` (PR 3) | — | as today | ✓ (difficulty null) | `difficulty` per item (token owner's membership) |
| `POST /me/solving-times` (PR 4) | ✓ | `solving-times:write` | — | `predicted_time` in the response |
| `GET /me/statistics` (PR 6) | ✓ | `statistics:read` | — | `skill` (members), `rating` (public, opt-out aware) |
| `GET /players/{id}/statistics` (PR 6) | — | `statistics:read` | ✓ | `rating` only (public ladder), never `skill` |

---

## 3. PR 0 — `GET /me` flags (XS, independent, ship first)

**Why first:** lets apps explain `null` members-only blocks ("upgrade" vs "you opted out") without an extra field anywhere else.

- `CurrentUserResponse` gains trailing props: `membership_ends_at: ?string` (ISO-8601), `time_predictions_opted_out: bool`, `ranking_opted_out: bool`, `streak_opted_out: bool`. All from the `PlayerProfile` already loaded — **0 extra queries**.
- Docs: README `/me` row + a "Members-Exclusive Data" paragraph pointing at these flags; OpenAPI via the DTO.
- Tests: `CurrentUserEndpointTest` — member (`PLAYER_WITH_STRIPE`: `membership_ends_at` non-null, flags false), non-member (`membership_ends_at: null`), opted-out player (seed flag) ⇒ true; PAT and auth-code both; existing assertions untouched.
- Budget: unchanged (assert ≤ current count).

## 4. PR 1 — `GET /api/v1/puzzles` (search / barcode lookup) + foundation (§1)

**Route:** `GET /api/v1/puzzles` · tag `Puzzles` · `security: is_granted('IS_AUTHENTICATED_FULLY')` · `access_control`: `^/api/v1/puzzles` ⇒ `IS_AUTHENTICATED_FULLY` (before the `^/` PUBLIC_ACCESS catch-all).
**Data:** `SearchPuzzle::byUserInput` / `countByUserInput` (already filters `hide_until`, already joins statistics + manufacturer). Brand/sort/difficulty go through `PuzzleSearchCriteria::fromUserInput(..., isMember)` so the web and the API can never drift on what is members-only (the API then turns a downgraded premium value into a 403 instead of the web's silent fallback); pieces go through the new `PiecesRange`; EAN path via `SearchPuzzle::allByEan` **plus an explicit `hide_until` filter** (N5 — `allByEan` does not filter it today; add the condition to the query, the web scanner benefits too).

**Query parameters** (declared with `QueryParameter`; all optional):

| key | type / constraints | meaning |
|---|---|---|
| `query` | string, trimmed, `Length(min:2,max:100)` | name / alternative name (accent-insensitive), identification number, EAN substring — same as the web search box |
| `ean` | string, `Regex('/^\d{8,14}$/')` | exact barcode lookup (`allByEan`: leading/trailing zeros tolerated). **Mutually exclusive** with `query`, `manufacturer`, `pieces_*`, `sort`, `difficulty` ⇒ 422 "ean cannot be combined with …" |
| `manufacturer` | `Uuid` | brand filter (unknown uuid ⇒ empty result, not 404) |
| `pieces_min`, `pieces_max` | int, `Range(1, 50000)`, `pieces_min ≤ pieces_max` (provider, 422) | piece-count range; exact count = both equal. Requires extending `SearchPuzzle::byUserInput/countByUserInput` to accept a `PiecesRange` value object (`PiecesRange::fromFilter(PiecesFilter)`, `::between(min,max)`); web callers pass `fromFilter` — behaviour unchanged, existing tests prove it |
| `sort` | `Choice(most-solved\|least-solved\|a-z\|z-a\|easiest\|hardest)`, default `most-solved` | `easiest`/`hardest` are members-only ⇒ **403** `AccessDeniedHttpException('sort=easiest requires an active membership')` for non-members and machine tokens (the web silently falls back; the API is explicit) |
| `difficulty` | comma list of `very_easy…very_hard` (`castToNativeType` to array, each `Choice`) | members-only tier filter ⇒ 403 as above; mapped with `DifficultyTier::fromApiValue()` |
| `page` | int, `Range(1, 500)`, default 1 | offset = (page−1)·limit |
| `limit` | int, `Range(1, 50)`, default 20 | |

No filter at all is allowed (same as `/en/puzzle`) — the catalog is public; scraping is bounded by page/limit caps and the 60/min limiter.

**Response** `PuzzleListResponse { count:int (on this page), total:int, page:int, limit:int, has_more:bool, puzzles: list<PuzzleResponse> }`. `difficulty` per item only for members (one batch query), `null` otherwise.

**Errors:** 401 no token · 403 premium sort/filter without membership · 422 invalid/contradicting params · 429 limiter.

**Query budget (assert in test):** non-member/cc: auth (≤2) + count 1 + search 1 = **≤ 4**; member: + owner profile 1 + difficulty batch 1 = **≤ 6**. EAN path: ≤ 5.

**Tests** (`tests/Controller/Api/V1/PuzzleSearchEndpointTest.php`): 401 anonymous; PAT / auth-code (`profile:read` only) / cc all 200; `query=Puzzle 1` hits, accent-insensitive hit, `ean` exact hit + zero-tolerance, `ean`+`query` ⇒ 422, each constraint violation ⇒ 422 with `violations[].propertyPath`; `pieces_min>pieces_max` ⇒ 422; `manufacturer` filter; `sort=easiest` non-member ⇒ 403, member ⇒ 200 sorted; `difficulty=hard` non-member ⇒ 403; pagination `total/has_more/page` over `limit=2`; seeded `hide_until` puzzle absent from `query`, `ean` and default listing; seeded `hide_image_until` ⇒ `image:null`; member sees `difficulty` object (seeded + synthesised insufficient), non-member `null`; `assertQueryCountAtMost` for member and cc; 429 after the limit with `Retry-After`; OpenAPI lists all 9 parameters with enum/description.

**Docs:** README — new "Puzzle Endpoints" table + a "Puzzles" section (params, exclusivity, members-only sort/filter, pagination caps, rate limit); `for-developers.html.twig` — new section row `for_developers.table_section_puzzles` (English key in `translations/messages.en.yml` only) + endpoint rows (PAT ✓ / Auth Code ✓ / Client Credentials ✓); OpenAPI from attributes (tag `Puzzles`).

## 5. PR 2 — `GET /api/v1/puzzles/{puzzleId}` (detail with insights)

**Route:** tag `Puzzles`, `security: IS_AUTHENTICATED_FULLY` (covered by the PR 1 access rule).
**Response** `PuzzleDetailResponse` = all `PuzzleResponse` fields (same names, same order) + `predicted_time: ?TimePredictionResponse`.
**Gates:** `difficulty` ⇒ owner member (cc ⇒ null). `predicted_time` ⇒ owner present **and** `canReadResults()` (PAT or `results:read` — it exposes `last_time_seconds`/`personal_solve_count`, which are `results:read` data on the standalone endpoint) **and** member **and** not `timePredictionsOptedOut`; else `null`. Unknown/malformed id, or `hide_until` in the future ⇒ 404 (document; follow-up idea: 301 to the merge survivor via `GetPuzzleRedirect`). `hide_image_until` ⇒ `image:null`.
**Refactor:** extract the prediction/difficulty assembly used by `MyPredictedTimeResponseProvider` into `PuzzleInsightsAssembler` (`src/Services/Api/`) and have both providers use it — the standalone endpoint's **output must not change** (its #186 tests are the guard).
**Budget:** auth (≤2) + overview 1 + owner profile 1 + difficulty 1 + prediction ≤5 ⇒ **≤ 10**; cc token ≤ 4.
**Tests:** gating matrix — cc (difficulty null, predicted null) · auth-code `profile:read` member (difficulty object, predicted **null** because of scope) · auth-code `results:read` member (both) · PAT member (both) · non-member (both null) · opted-out member (difficulty only) · member without history & baseline (predicted object with null fields, `is_personalized:false`); hidden image; `hide_until` ⇒ 404; bad id ⇒ 404; query budgets; standalone `/me/.../predicted-time` tests still green unchanged.

## 6. PR 3 — `difficulty` on result and collection-item lists

`PlayerResultResponse` and `CollectionItemResponse` gain a trailing `difficulty: ?PuzzleDifficultyResponse` (default `null`). Providers (`MyResults`, `PlayerResults`, `MyCollectionItems`, `PlayerCollectionItems`) collect `puzzleIds` and call `PuzzleResponseFactory`/`forPuzzleList` **once** when `ApiTokenOwner::isMember()`; nothing extra otherwise. Private-profile short-circuits stay first (no queries for zeroed responses). Budget: existing + 2 for members, + 0 for non-members — assert both; assert an empty list triggers no difficulty query. Tests per endpoint: member sees object (seeded), non-member null, cc on `/players/...` null, query budget. Docs: README "Members-Exclusive Data" lists the four endpoints.

## 7. PR 4 — `predicted_time` in the `POST /me/solving-times` response

After dispatch, when the time is **solo** (`group_players` empty/null), parsed time is non-null, owner is a member and not opted out: `predicted_time = TimePredictionResponse` from `GetPlayerPrediction::forPuzzle($playerId, $puzzleId, excludeTimeId: $timeId)` (the prediction *before* this solve — what the added-time recap shows); else `null`. Also fill `time_seconds` using the same parser the handler uses (today it is always `null` in the create response — filling it is additive). `SolvingTimeResponse` gains trailing `predicted_time: ?TimePredictionResponse = null` (the PUT response keeps `null`). Budget: + profile 1 + prediction ≤5, only for eligible requests. Tests: member solo with history (seeded) ⇒ object, `personal_solve_count` equals the count *before* this time; non-member ⇒ null; group time ⇒ null; opted-out ⇒ null; `time_seconds` filled; existing create tests unchanged.

## 8. PR 6 — statistics insights

- `MyStatisticsResponse` gains `skill: ?list<PlayerSkillResponse>` (members only; `PlayerSkillResponse { pieces_count, tier (enthusiast…legend via SkillTier::toApiValue), score, percentile, confidence, qualifying_puzzles_count }`, from `GetPlayerSkill::byPlayerId` — 1 query) and `rating: ?list<PlayerRatingResponse>` (`{ pieces_count, rating, rank, total }` from `GetPlayerRatingRanking::allForPlayer` — 1 query; **public** on the web, but `null` when the owner `rankingOptedOut`).
- `PlayerStatisticsResponse` gains `rating` only (same rules + `null` for private targets), **never** `skill` (skill is viewer-membership-gated on the web ⇒ would reintroduce the proxy problem).
- Budget: +2 / +1. Tests: member vs non-member, opted-out, private target, cc token on `/players/{id}/statistics` gets `rating` (public) but the response stays zeroed for private profiles.

---

## 9. Rollout, per PR

1. Orchestrator creates an isolated git worktree on a branch `api/<topic>` (own `DATABASE_URL` — `tests/bootstrap.php` drops and recreates the database it is pointed at — and the main tree's `vendor` bind-mounted read-only when running inside the base image); the subagent implements there from this plan and never touches the shared checkout.
2. Gates in the worktree: `composer run phpstan`, `composer run cs-fix`, `vendor/bin/phpunit --testsuite "Project Test Suite"`, `bin/console doctrine:schema:validate`, `bin/console cache:warmup`, `bin/console api:openapi:export` (must render; the parameter test covers content), profiler query budgets.
3. Orchestrator reviews the diff against §0, pushes, opens the PR with the checklist below, waits for CI, squash-merges (repo is squash-only), watches Tests → Release → deploy, then verifies in production (`/api/docs.jsonopenapi` lists the new path/params; unauthenticated probe returns 401).
4. Update this file's checklist and `docs/features/api/README.md` in the same PR.

## 10. Subagent brief (paste at the top of each task)

> Read, in this order: `docs/features/api/v1-expansion-plan.md` (this file — §0 and your PR section are binding), `docs/features/api/README.md`, `CLAUDE.md` (API & Authentication, state-changing ops, controllers), `src/Api/V1/MyPredictedTimeResponseProvider.php` + `PredictedTimeResponse.php` + `tests/Controller/Api/V1/PredictedTimeEndpointTest.php` (the reference for gating + seeded tests), `src/Api/V1/CompetitionListResponse.php` (OpenAPI parameter style), `src/Value/PuzzleSearchCriteria.php`, `src/Query/SearchPuzzle.php`, `tests/OAuth2TestHelper.php`, `tests/PatTestHelper.php`, `.claude/fixtures.md`.
> Deliver: code + tests + README/for-developers/OpenAPI docs in one commit on the given branch. Do **not**: touch existing response field names/types/values, add `/players/{id}` personal-insight routes, write migrations, add translations beyond English, call `assert($user instanceof ApiUser)` outside `/me/*`, skip the query-budget assertion, or widen scope beyond the PR section.
> Report back: `git diff --stat`, the per-path query counts you measured, the test list, and anything in this plan that turned out to be wrong (say so explicitly rather than working around it).

## 11. PR review checklist (orchestrator)

- [ ] N1: every members-only value flows through `ApiTokenOwner`; cc-token + non-member + opted-out tests exist
- [ ] N2: only trailing, defaulted/nullable additions to existing DTOs; existing tests unchanged
- [ ] N3: query budget asserted; one batch call per list; no per-item queries
- [ ] N4: parameters declared once (`QueryParameter`), 422 tests, OpenAPI parameter test
- [ ] N5: `hide_until` excluded/404, `hide_image_until` nulls image, UUIDs validated, access_control rule present
- [ ] Docs: README tables + section, for-developers rows, OpenAPI summary/description, this file's checklist

## 12. Progress

- [x] #186 `GET /me/puzzles/{id}/predicted-time` (2026-08-18)
- [ ] PR 0 `GET /me` flags
- [ ] PR 1 `GET /puzzles` + foundation (§1)
- [ ] PR 2 `GET /puzzles/{id}`
- [ ] PR 3 `difficulty` on lists
- [ ] PR 4 `predicted_time` on `POST /me/solving-times`
- [ ] PR 6 statistics insights

Follow-ups deliberately out of scope: tags on puzzle card / tag filter, related puzzles, marketplace offer counts, 301 to merge-survivor on `/puzzles/{id}`, puzzle-level solvers/ranking list, exact `pieces=` shorthand, per-scope rate limits beyond search.
