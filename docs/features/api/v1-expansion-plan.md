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
| N5 | **Security defaults:** all `/api/v1/*` endpoints require a valid token (no anonymous API); UUID-shaped input is validated before any query; `hide_until` (secret competition puzzles) ⇒ **never returned** by any puzzle endpoint (404 / excluded — stricter than the web's by-id page, same as the competition API's "critical" reveal rule); **puzzle image embargo (`hide_image_until`) is respected on every endpoint that returns an image** — search, detail, results, collection items (competitions already do) — `image: null` until the embargo ends, asserted with a seeded embargoed puzzle in each new/changed endpoint's tests; no personal data in catalog responses. | Security checklist per PR (§7) |

Conventions (match the existing `src/Api/V1`): single-action providers, `final class XResponse` DTOs with snake_case public props, `shortName` + `openapi: new OpenApiOperation(tags:, summary:, description:)`, `security:` expression on the operation **and** an `access_control` rule, ids as UUID strings, seconds as ints, dates as ISO-8601 strings (`format('c')`), enums as lowercase tokens (`very_easy`, `medium`, `solo`). Members-only blocks are **nullable objects**: `null` ⇔ "not available to this token" (not a member / machine token / missing scope / opted out); when available, the object is always present and carries `null` *inside* for "not enough data". An app tells the reasons apart from `GET /me` (`has_active_membership`, opt-out flags — PR 0). Decision (Jan, 2026-08-19): **no `unavailable_reason` field** — it would be derivable and would multiply across endpoints.

**Everything the API has is always included — no `include=`/`fields=` switches** (decision, Jan 2026-08-19: opt-in parameters are a code smell and a bad consumer experience). Every endpoint returns every insight object it can for the calling token; what the token is not entitled to is `null` (not a member / machine token / missing scope / opted out). The cost of this is kept **fixed per request** by batch queries: a list of 500 items costs the same number of queries as a list of 5 (difficulty via `forPuzzleList`, predictions via `GetPlayerPredictions::forPuzzles`, solves via `GetPlayerPuzzleSolves::forPuzzles` — one query each). `prediction` objects appear only on `/me/*` lists and `/puzzles` (self-only, N1); on `/players/{id}` collection items `solves` are the *collection owner's* solves and are `null` unless the token also has `results:read`.

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
- `PuzzleStatisticsResponse { solved_times:int, solo: PuzzleStatisticsGroupResponse, duo: …, team: … }` with `PuzzleStatisticsGroupResponse { count:int, fastest_seconds:?int, average_seconds:?int, slowest_seconds:?int, median_seconds:?int }` (`median_seconds` since PR 5a; average and median are both over each player's best time in the discipline) — **always split by discipline**; public data; read from the precomputed `puzzle_statistics` table (event-driven recompute in `RecalculatePuzzleStatisticsOnSolvingTimeChange`, backfill `myspeedpuzzling:recalculate-puzzle-statistics`) through a new batch query `GetPuzzleStatistics::forPuzzleList(ids)` — **one** query per list, reused for cards, collection items, result items; puzzles without a row ⇒ counts 0, nulls
- `PuzzleDifficultyResponse { score:?float, level:?string (very_easy…very_hard), confidence:string (insufficient|low|medium|high), sample_size:int }` — built from `PuzzleDifficultyResult`; **for a member with no row, synthesise `{null, null, 'insufficient', 0}`** so that `difficulty: null` means exactly "members only" (N1 semantics)
- `TimePredictionResponse { predicted_seconds:?int, range_low_seconds:?int, range_high_seconds:?int, is_personalized:bool, personal_solve_count:?int, predicted_attempt_number:?int, last_time_seconds:?int }` — built from `TimePredictionResult`; all-null fields + `is_personalized:false` when `GetPlayerPrediction` returns null
- `PlayerSolvesResponse { solo: SolvesGroupResponse, duo: SolvesGroupResponse, team: SolvesGroupResponse }` with `SolvesGroupResponse { count:int, best_time_seconds:?int, last_time_seconds:?int, first_solved_at:?string, last_solved_at:?string }` — the player's own history on a puzzle, **always split by discipline exactly like `/me/statistics` (`solo`/`duo`/`team` are different disciplines and are never merged)**; unboxed and suspicious-flagged times are included (it is the player's own data, the same set as `/me/results?type=…`). Built by a new batch query `GetPlayerPuzzleSolves::forPuzzles(playerId, puzzleIds)` — **one** query `GROUP BY puzzle_id, puzzling_type` with `COUNT(*)`, `MIN(seconds_to_solve)`, `(array_agg(seconds_to_solve ORDER BY solved_at DESC))[1]`, `MIN/MAX(COALESCE(finished_at, tracked_at))`, pivoted in PHP; a puzzle/discipline with no rows ⇒ `count 0` and nulls (the object is always present for an entitled token)
- **Bulk predictions are already solved**: `GetPlayerPredictions::forPuzzles($playerId, $puzzleIds)` (Puzzle Picker, on `main` since 2026-08-19) = 4 queries for any N (all of the player's solo attempts once, player ratios once, global ratios once, baseline×difficulty for the ids not personally solved once), memoised per request, `ResetInterface`, same `TimePredictionCalculator` as the single query. **Never call `GetPlayerPrediction::forPuzzle` in a loop** — lists use `forPuzzles`
- `PlayerRatingResponse { pieces_count:int, points:int, rank:int, total_players:int }` — MSP Rating as shown on the profile page (`points = round(elo_rating × 1000)`; from `GetPlayerRatingRanking::allForPlayer` — 1 query)
- `PlayerSkillResponse { pieces_count:int, tier:string (enthusiast…legend, `SkillTier::toApiValue()`), percentile:float, confidence:string, qualifying_puzzles_count:int }` — from `GetPlayerSkill::byPlayerId` (1 query)
- `badges: list<string>` — badge enum values from `GetBadges::forPlayer` (1 query)
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
| `GET /puzzles` (PR 1) | ✓ | any scope | ✓ | per item `difficulty` (members), `prediction` + `solves` (player + `results:read`/PAT); `sort=easiest\|hardest`, `difficulty=` filter ⇒ 403 for non-members |
| `GET /puzzles/{id}` (PR 2) | ✓ | any scope (`prediction`/`solves` only with `results:read`) | ✓ (insights null) | `difficulty`, `prediction`, `solves` |
| `GET /me/results`, `/me/collections/{id}/items` (PR 3) | ✓ | as today | — | `difficulty` (results); `difficulty`, `prediction`, `solves` (collection items) |
| `GET /players/{id}/results`, `/players/{id}/collections/{cid}/items` (PR 3) | — | as today (`solves` needs `results:read`) | ✓ (difficulty null) | `difficulty`; collection items also `solves` (owner's, public profiles only); never `prediction` |
| `GET /me/library`, `/me/wishlist`, `/me/unsolved-puzzles`, `/me/lend-borrow`, `/me/sell-swap` + `/players/{id}/…` (PR 5) | ✓ | `collections:read` | ✓ on `/players/{id}/…` (insights null) | per-item `statistics`, `difficulty`, `prediction` (`/me` only), `solves`; owner visibility settings as on the web |
| `POST /me/solving-times` (PR 4) | ✓ | `solving-times:write` | — | `prediction` (the one that applied before this solve) in the response |
| `GET /me` (PR 6) | ✓ | `profile:read` | — | `rating` (opt-out aware), `skill` (members), `badges` |
| `GET /players/{id}` (PR 6, new) | — | `profile:read` | ✓ | public profile + `rating` (private/opt-out ⇒ null) + `skill` (token owner member) + `badges`; masked when private |

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
| `limit` | int, `Range(1, 100)`, default 20 | max 100 (decision, Jan 2026-08-19) |

No filter at all is allowed (same as `/en/puzzle`) — the catalog is public; scraping is bounded by the page/limit caps (≤ 100 items per page, ≤ 500 pages) and the 60/min limiter.

**Response** `PuzzleListResponse { count:int (on this page), total:int, page:int, limit:int, has_more:bool, puzzles: list<PuzzleResponse> }`. Every item always carries `difficulty` (members, else `null`), `prediction` (member + not opted out + PAT/`results:read`, else `null`) and `solves` (token owner's own solves; `null` for machine tokens or without PAT/`results:read`). Machine tokens therefore get three `null`s per item — accepted, never an error, so one client code path works for every token.

**Errors:** 401 no token · 403 premium sort/filter without membership · 422 invalid/contradicting params · 429 limiter.

**Query budget (assert in test):** machine token: auth (≤2) + count 1 + search 1 = **≤ 4**; non-member player token: + owner profile 1 + solves 1 = ≤ 6; member: + difficulty batch 1 + predictions 4 = **≤ 11** — and the same number at `limit=100` as at `limit=5` (assert both). EAN path: same rules, no count query.

**Tests** (`tests/Controller/Api/V1/PuzzleSearchEndpointTest.php`): 401 anonymous; non-member ⇒ items with `difficulty:null`, `prediction:null`, `solves` present; member with seeded history ⇒ `prediction.is_personalized:true` on that item; machine token ⇒ all three `null`; PAT / auth-code (`profile:read` only) / cc all 200; `query=Puzzle 1` hits, accent-insensitive hit, `ean` exact hit + zero-tolerance, `ean`+`query` ⇒ 422, each constraint violation ⇒ 422 with `violations[].propertyPath`; `pieces_min>pieces_max` ⇒ 422; `manufacturer` filter; `sort=easiest` non-member ⇒ 403, member ⇒ 200 sorted; `difficulty=hard` non-member ⇒ 403; pagination `total/has_more/page` over `limit=2`; `limit=101` ⇒ 422; seeded `hide_until` puzzle absent from `query`, `ean` and default listing; seeded `hide_image_until` ⇒ `image:null`; member sees `difficulty` object (seeded + synthesised insufficient), non-member `null`; `solves` counts/best/last for a puzzle with fixture solves; `assertQueryCountAtMost` for machine token, non-member, and member at two page sizes; 429 after the limit with `Retry-After`; OpenAPI lists all 9 parameters with enum/description.

**Docs:** README — new "Puzzle Endpoints" table + a "Puzzles" section (params, exclusivity, members-only sort/filter, pagination caps, rate limit); `for-developers.html.twig` — new section row `for_developers.table_section_puzzles` (English key in `translations/messages.en.yml` only) + endpoint rows (PAT ✓ / Auth Code ✓ / Client Credentials ✓); OpenAPI from attributes (tag `Puzzles`).

## 5. PR 2 — `GET /api/v1/puzzles/{puzzleId}` (detail with insights)

**Route:** tag `Puzzles`, `security: IS_AUTHENTICATED_FULLY` (covered by the PR 1 access rule).
**Response** `PuzzleDetailResponse` = all `PuzzleResponse` fields (same names, same order) + `prediction: ?TimePredictionResponse` + `solves: ?PlayerSolvesResponse` (token owner's solves; `null` for machine tokens or without `results:read`/PAT — one query via `GetPlayerPuzzleSolves`). Object names are `prediction` and `solves` on every endpoint (never `predicted_time`/`my_solves`).
**Gates:** `difficulty` ⇒ owner member (cc ⇒ null). `prediction` ⇒ owner present **and** `canReadResults()` (PAT or `results:read` — it exposes `last_time_seconds`/`personal_solve_count`, which are `results:read` data on the standalone endpoint) **and** member **and** not `timePredictionsOptedOut`; else `null`. Unknown/malformed id, or `hide_until` in the future ⇒ 404 (document; follow-up idea: 301 to the merge survivor via `GetPuzzleRedirect`). `hide_image_until` ⇒ `image:null`.
**Refactor:** extract the prediction/difficulty assembly used by `MyPredictedTimeResponseProvider` into `PuzzleInsightsAssembler` (`src/Services/Api/`) and have both providers use it — the standalone endpoint's **output must not change** (its #186 tests are the guard). *(Landed as: `PuzzleResponseFactory` from PR 1 is that assembler — the detail is `card()` of the overview; the standalone provider gates through `ApiTokenOwner` and flattens the same `TimePredictionResponse` / `PuzzleDifficultyResponse` via `PredictedTimeResponse::fromInsights()`, see §12.)*
**Budget:** auth (≤2) + overview 1 + owner profile 1 + difficulty 1 + prediction ≤5 + solves 1 ⇒ **≤ 11**; cc token ≤ 4.
**Tests:** gating matrix — cc (difficulty/prediction/solves null) · auth-code `profile:read` member (difficulty object, prediction **and** solves `null` because of scope) · auth-code `results:read` member (all three) · PAT member (all three) · non-member (difficulty/prediction null, solves present) · opted-out member (difficulty + solves, prediction null) · member without history & baseline (prediction object with null fields, `is_personalized:false`; solves zeros); hidden image; `hide_until` ⇒ 404; bad id ⇒ 404; query budgets; standalone `/me/.../predicted-time` tests still green unchanged.

## 6. PR 3 — insights & solves on the list endpoints (collections first)

The motivating case: a collection with 500+ puzzles where the app wants, per item, "how hard is it, how would I do, how often have I solved it" — always present, at a fixed query count.

| Endpoint | per-item objects (always present; `null` when not entitled) |
|---|---|
| `GET /me/collections/{id}/items` | `statistics` (public, always) · `difficulty` (owner member) · `prediction` (owner member + not opted out + PAT/`results:read`) · `solves` (own, PAT/`results:read` - the same gate as on `/puzzles`, §0) |
| `GET /players/{id}/collections/{cid}/items` | `statistics` · `difficulty` (**token owner** member) · `solves` (the **collection owner's** solves — only with `results:read` on the token, and only for public profiles; the zeroed private response stays zeroed with no queries) · never `prediction` |
| `GET /me/results`, `GET /players/{id}/results` | `statistics` · `difficulty` (token owner member) |

- `CollectionItemResponse` and `PlayerResultResponse` gain trailing `statistics: PuzzleStatisticsResponse` (public, always — `GetPuzzleStatistics::forPuzzleList`, +1 query) and `difficulty: ?PuzzleDifficultyResponse = null`; `CollectionItemResponse` also `prediction: ?TimePredictionResponse = null` and `solves: ?PlayerSolvesResponse = null`. (Medians arrive inside `statistics` with PR 5a — nothing to change on these endpoints then.)
- Providers: collect `puzzleIds` once; then at most one call each to `PuzzleResponseFactory`/`GetPuzzleDifficulty::forPuzzleList`, `GetPlayerPredictions::forPuzzles`, `GetPlayerPuzzleSolves::forPuzzles`, each guarded by its gate (no query when the result would be `null` for every item). Private-profile short-circuits stay first. Empty list ⇒ no batch queries.
- `GetPlayerPuzzleSolves` (new, `src/Query/`): one `GROUP BY` query as in §1.2; `readonly`, no memo needed.
- Budget (assert with two collection sizes — fixture `COLLECTION_PUBLIC` plus items seeded in the test — the count must not grow with the item count): machine token on `/players/...`: today + statistics 1; non-member player token: today + statistics 1 + profile 1 + solves 1; member: today + statistics 1 + profile 1 + difficulty 1 + predictions 4 + solves 1 ≈ **≤ 12 total** on collection items; results lists: today + ≤ 3.
- Tests per endpoint: member vs non-member vs machine token per object; opted-out ⇒ `prediction:null` but `difficulty` present; `solves` numbers for `PLAYER_REGULAR` × `PUZZLE_500_02` (3 solo solves: count 3, best 1700, last 1700 — verify against `.claude/fixtures.md`); `solves` on `/players/{id}/...` without `results:read` ⇒ `null`; private profile ⇒ zeroed as today; existing field values byte-identical (compare the pre-change JSON minus the new keys); query budgets at two collection sizes.
- Docs: README "Members-Exclusive Data" + per-endpoint notes; for-developers table unchanged (same endpoints); OpenAPI from the DTOs.
- Follow-ups (not in this PR): optional `page/limit` on collection items with **default unchanged (= all)**; `ranking` per item (rank/total players via `GetRanking::allForPlayer` — heavier, needs its own budget).

## 7. PR 4 — `prediction` in the `POST /me/solving-times` response

After dispatch, when the time is **solo** (`group_players` empty/null), parsed time is non-null, owner is a member and not opted out, and the token may read results (PAT or `results:read` - §2's rule, the write scope alone does not read insights; corrected when PR 4 shipped): `prediction = TimePredictionResponse` from `GetPlayerPrediction::forPuzzle($playerId, $puzzleId, excludeTimeId: $timeId)` (the prediction *before* this solve — what the added-time recap shows); else `null`. Also fill `time_seconds` using the same parser the handler uses (today it is always `null` in the create response — filling it is additive). `SolvingTimeResponse` gains trailing `prediction: ?TimePredictionResponse = null` (the PUT response keeps `null`). Budget: + profile 1 + prediction ≤5, only for eligible requests. Tests: member solo with history (seeded) ⇒ object, `personal_solve_count` equals the count *before* this time; non-member ⇒ null; group time ⇒ null; opted-out ⇒ null; `time_seconds` filled; existing create tests unchanged.

## 7b. PR 5 — the puzzle library: wishlist, lend/borrow, unsolved, sell/swap (read-only) + library summary

The website's puzzle library (`PuzzleLibraryController`) has, besides collections: **unsolved puzzles** (system collection not yet solved + borrowed unsolved), **wishlist**, **lend/borrow list**, **sell/swap list** (always public), **solved puzzles** (already `/results`), each with an owner-set visibility (`CollectionVisibility` public|private) and always visible to the owner. The API mirrors it 1:1, read-only (write endpoints are a follow-up; today only collections have writes).

| Endpoint | Auth | Source | Visibility (as web) |
|---|---|---|---|
| `GET /me/library`, `GET /players/{id}/library` | PAT or `collections:read` / `collections:read` | `GetPlayerCollectionsWithCounts`, the count queries the web page uses | summary: `collections[]` (as `/me/collections`), `unsolved {count, visibility}`, `wishlist {count, visibility}`, `lend_borrow {lent_count, borrowed_count, visibility}`, `sell_swap {count}`, `solved {count, visibility}`; for others, private sections show `count: 0` and their `visibility` |
| `GET /me/wishlist`, `GET /players/{id}/wishlist` | PAT or `collections:read` / `collections:read` | `GetWishListItems::byPlayerId` | `wishListVisibility` public or own, else zeroed |
| `GET /me/unsolved-puzzles`, `GET /players/{id}/unsolved-puzzles` | same | `GetUnsolvedPuzzles::byPlayerId` + `GetBorrowedPuzzles::unsolvedByHolderId` (like the web) | `unsolvedPuzzlesVisibility` |
| `GET /me/lend-borrow`, `GET /players/{id}/lend-borrow` | same | `GetLentPuzzles::byOwnerId` + `GetBorrowedPuzzles::byHolderId` | `lendBorrowListVisibility` |
| `GET /me/sell-swap`, `GET /players/{id}/sell-swap` | same | `GetSellSwapListItems::byPlayerId` | always public (as web) |

Item shapes follow `CollectionItemResponse` (flat puzzle fields: `puzzle_id, puzzle_name, manufacturer_name, pieces_count, image`) + the per-item objects from PR 3 (`statistics`, `difficulty`, `prediction` on `/me/*` only, `solves`), plus list-specific fields: wishlist `wishlist_item_id, added_at`; unsolved `added_at, is_borrowed`; lend/borrow `lent_puzzle_id, direction: lent|borrowed, counterparty { player_id:?string, name:string }, lent_at, notes`; sell/swap `item_id, listing_type, price…` (take the public fields from `SellSwapListItemOverview`; nothing about the counterparties beyond what the web shows publicly). Whole-profile `is_private` ⇒ every `/players/{id}/…` list zeroed, as today. Budgets: base + statistics 1 + difficulty 1 + predictions 4 + solves 1 (same batch rules, asserted at two sizes). Tests per endpoint: own/other/public/private/cc, embargoed image `null`, budgets. Docs: README "Puzzle Library Endpoints" table + section, for-developers rows, OpenAPI; `collections:read` is documented as "read the puzzle library".

## 7c. PR 5a — per-puzzle medians in `puzzle_statistics` → `statistics.*.median_seconds`

Medians are not stored today (only brand/pieces hubs compute `percentile_cont` ad hoc); computing them per request for 500 puzzles is not acceptable. Add `median_time`, `median_time_solo`, `median_time_duo`, `median_time_team` (nullable int) to the `PuzzleStatistics` entity (**migration generated with `doctrine:migrations:diff`, never hand-written**), compute them in `RecalculatePuzzleStatisticsOnSolvingTimeChange` with `percentile_cont(0.5) WITHIN GROUP (ORDER BY seconds_to_solve)` per discipline (same row filters the averages use), backfill with the existing `myspeedpuzzling:recalculate-puzzle-statistics` (run once after deploy — ops note; note the command does **not** go through the handler — it calls `PuzzleStatisticsCalculator` and upserts with its own raw SQL, so its column lists carry the medians too), then add `median_seconds:?int` to `PuzzleStatisticsGroupResponse` (BC-safe new key, flows to every endpoint at once). Tests: handler computes the median for an odd and an even count; API shows it. Ops: after the release, run the backfill on the box.

## 8. PR 6 — profile insights on `GET /me` and a new `GET /players/{id}` (what the profile page shows)

**`GET /me`** gains (all from the token owner's own data):
- `rating: ?list<PlayerRatingResponse>` — `null` when the owner `rankingOptedOut` (the web shows the explanation instead of numbers); otherwise the list (empty when not ranked yet)
- `skill: ?list<PlayerSkillResponse>` — members only (`null` for non-members; the web shows the locked "Player insights" button), also `null` when `rankingOptedOut`
- `badges: list<string>`
- budget: + 3 queries (rating, skill only when member, badges)

**`GET /players/{id}`** (new) — tag `Players`, `security: is_granted(OAuth2Scope::ProfileRead->role())` (+ `access_control` `^/api/v1/players/[^/]+$` ⇒ `ROLE_OAUTH2_PROFILE:READ`; cc tokens allowed — public profile data), provider `PlayerProfileResponseProvider`, DTO `PlayerProfileResponse`:
- public profile: `id, name, code, avatar, country, city, bio, facebook, instagram, is_private, has_active_membership` (same names as `GET /me`, no `email`)
- `rating: ?list<PlayerRatingResponse>` — `null` when target is private or `rankingOptedOut`
- `skill: ?list<PlayerSkillResponse>` — **token owner** must be a member (viewer gate, as on the web) **and** target not private / not `rankingOptedOut`; else `null`. (Target's own skill is puzzle-intelligence data about the target, shown on the web to any member viewer — not the proxy problem, because it is not a per-viewer personal feature; it mirrors the profile page 1:1)
- `badges: list<string>` (empty for private)
- **masked private profile** (private target, not the token owner): the API returns `name: null, avatar: null, country: null, city: null, bio: null, facebook: null, instagram: null, is_private: true` and keeps `id`, `code`, `has_active_membership`; insights `null`, `badges: []` — the web's "Secret puzzler #CODE" label is presentation, clients render it from `is_private` + `code`. When the token owner *is* the target (auth-code token on own id) the response is the full one.
- unknown/malformed id ⇒ 404
- budget: auth + profile 1 + rating 1 + skill 1 (members) + badges 1 + owner profile 1 ⇒ ≤ 7

Tests: own/other/private/opted-out/non-member-viewer/cc matrix for both endpoints; `points = round(elo × 1000)` against a seeded `player_elo` row; `skill` null for non-member viewer; masked profile shape; existing `/me` assertions unchanged.
Docs: README (`/me` row, new `/players/{id}` row + "Profile insights" section), for-developers table row (PAT — / Auth Code ✓ / Client Credentials ✓), OpenAPI.

---

## 9. Rollout, per PR

Waves (dependencies): **wave 1** PR 0 ∥ PR 1 (independent) → **wave 2** PR 2 ∥ PR 3 ∥ PR 6 ∥ PR 5a (all need PR 1's foundation; disjoint files, README hunks merged by the orchestrator) → **wave 3** PR 4 ∥ PR 5 (PR 5 needs PR 3's item objects).

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
- [x] PR 0 `GET /me` flags
- [x] PR 1 `GET /puzzles` + foundation (§1) (2026-08-19; `statistics` shipped split by discipline - `{ solved_times, solo, duo, team }` from `puzzle_statistics` via `GetPuzzleStatistics::forPuzzleList`, as §1.2/§13 now say; measured budgets: OAuth2 auth costs 3 queries, not 2, so auth-code tokens sit one above the §4 ceilings - cc 3-4, non-member 6 PAT / 8 OAuth2, member 10-11 PAT / 12-13 OAuth2)
- [x] PR 2 `GET /puzzles/{id}` (2026-08-19; the detail is `PuzzleResponseFactory::card()` of the overview - no separate `PuzzleInsightsAssembler`, the factory from PR 1 already is that assembler; `MyPredictedTimeResponseProvider` now gates through `ApiTokenOwner` and flattens `TimePredictionResponse`/`PuzzleDifficultyResponse` via `PredictedTimeResponse::fromInsights()`, output byte-identical; `PuzzleOverview::hideUntil` added (loaded by `GetPuzzleOverview::byId` only) for the N5 404; measured: cc 2-3, non-member 5 PAT / 7 OAuth2, member 9-10 PAT / 11-12 OAuth2 - ceilings 4 / 6 / 8 / 11 / 13)
- [x] PR 3 insights & solves on lists (2026-08-19; `PuzzleResponseFactory::insightsFor()` + `PuzzleInsightsBatch` shared by cards, collection items and result lists; the `POST /me/collections/{id}/items` item answers in the same shape; measured: collection items cc 4-5 / non-member 5-6 PAT, 7-8 OAuth2 / member 9-11 PAT, 13 OAuth2 on `/me`, 9 on `/players`; result lists today + ≤ 3; `solves` on `/me/*` needs PAT or `results:read` like everywhere else - §6 table corrected from "always")
- [x] PR 3b `/players/{id}/collections/{cid}/items`: collection visibility as on the web (private profile / private custom collection / private system collection ⇒ zeroed for everyone but the owner; +1 query for custom collections) and `prediction` = the token owner's own forecast there, like the website's collection page (2026-08-19)
- [x] PR 4 `prediction` on `POST /me/solving-times` (2026-08-19; `time_seconds` filled from `SolvingTime::fromUserInput` - the handler's parser; gate = solo + time + `ApiTokenOwner::isMember()` + not opted out **+ PAT / `results:read`** (§2's rule - the write scope alone does not read insights, so an auth-code token with only `solving-times:write` gets `null`); measured request-only counts, PAT: the create's own write path is 28-35 (solo, data-dependent: the `PuzzleSolved` event runs the statistics/intelligence recalculations, wishlist removal and notifications synchronously) / 21 (duo), the feature adds profile 1 + prediction 4 (personal) or 2 (statistical) - ceilings pinned per scenario as write path + 1 + 5 / + 1 / + 0 in `CreateSolvingTimePredictionEndpointTest`; one plan wrinkle: the synchronous recalculation rewrites the posted puzzle's `puzzle_difficulty` row inside the request, so a seeded difficulty does not survive the POST - the statistical-prediction test seeds four other players' first attempts instead so the puzzle is scored from real data)
- [x] PR 5 puzzle library lists (2026-08-19; `GET /me|players/{id}/library`, `/wishlist`, `/unsolved-puzzles`, `/lend-borrow`, `/sell-swap` - one resource per list with both operations, `PuzzleLibraryVisibility` (owner always / private profile hides all / the section's setting) + `PuzzleLibraryItemsFactory` (one `insightsFor()` batch per list, prediction = token owner's own, solves = list owner's, as PR 3b) + `PuzzleLibrarySummaryFactory` (one count query per visible section); the summary's `collections[]` carry `item_count` (the library card's number) as `LibraryCollectionResponse` - the four `/me/collections` fields + the count, the system collection "default" with its real visibility; `sell_swap` is `{count}` only; measured (request only): wishlist `/me` 5 non-member PAT / 10 member PAT / 12 member OAuth2, `/players` 4-5 cc / 8 non-member / 13 member; unsolved and lend-borrow +1 (two item queries): 6 / 11 / 13 and 5-6 / 9 / 14; sell-swap 3 (empty) / 10 / 12 and 4-5 / 8 / 13; library summary 11 PAT / 13 OAuth2 own, 11 cc / 14 OAuth2 for a complete public library, 8 for a stranger with default settings; private profile 5 / 2 everywhere. Deviation from §7b: the website's library pages do not hide a private profile's lists (only the header is masked) - the API keeps the `/players/{id}` privacy rule and zeroes them)
- [x] PR 5a medians in `puzzle_statistics` — code (2026-08-19: `median_time(_solo/_duo/_team)` columns, computed with `percentile_cont(0.5)` over the same per-player-best population as the averages, `median_seconds` on every `statistics` group); **backfill on the box still to do after deploy: `php bin/console myspeedpuzzling:recalculate-puzzle-statistics` once — until then `median_seconds` is `null`**
- [x] PR 6 profile insights on `/me` + `GET /players/{id}` (2026-08-19; measured budgets: `/me` member 7 OAuth2 / 5 PAT - the §8 "+3" on top of auth 3 + profile; `/players/{id}` member viewer 8, non-member 7, cc 4, masked 5 (auth-code) / 2 (cc). The owner's own private profile on `/players/{id}` and on `/me` returns the rating (plan §8, "the full one"), while the web's own-private-profile view replaces both blocks with an explanation - the API follows the plan)

Follow-ups deliberately out of scope: write endpoints for wishlist/lend-borrow (add/remove/return), `first_attempt_average_seconds` in statistics (exists for solo/duo only), tags on puzzle card / tag filter, related puzzles, marketplace offer counts, 301 to merge-survivor on `/puzzles/{id}`, puzzle-level solvers/ranking list, exact `pieces=` shorthand, per-scope rate limits beyond search, optional pagination on collection items (default must stay = all; if added, `limit` max 100 like search), per-item `ranking`.

---

## 13. Appendix — reference payloads (agreed 2026-08-19; subagents match these field-for-field)

`difficulty` (puzzle-level, members-only; `null` ⇔ not a member / machine token):
```json
"difficulty": { "score": 1.18, "level": "challenging", "confidence": "medium", "sample_size": 14 }
```
member, puzzle not scored yet: `{ "score": null, "level": null, "confidence": "insufficient", "sample_size": 0 }`

`prediction` (personal, members-only, self-only; `null` ⇔ non-member / opted out / machine token / no `results:read`):
```json
"prediction": { "predicted_seconds": 1890, "range_low_seconds": 1607, "range_high_seconds": 2174,
                "is_personalized": true, "personal_solve_count": 3, "predicted_attempt_number": 4, "last_time_seconds": 2100 }
```
statistical: `is_personalized:false`, the three `personal_*`/`last_*` fields `null`; not enough data: every field `null`, `is_personalized:false`.

`solves` (own history, not members-only; `null` ⇔ machine token / no `results:read`; always split by discipline):
```json
"solves": {
  "solo": { "count": 3, "best_time_seconds": 1700, "last_time_seconds": 1700, "first_solved_at": "2026-07-30T10:12:45+00:00", "last_solved_at": "2026-08-09T14:30:00+00:00" },
  "duo":  { "count": 1, "best_time_seconds": 1180, "last_time_seconds": 1180, "first_solved_at": "2026-08-02T18:00:00+00:00", "last_solved_at": "2026-08-02T18:00:00+00:00" },
  "team": { "count": 0, "best_time_seconds": null, "last_time_seconds": null, "first_solved_at": null, "last_solved_at": null }
}
```

Collection item (`GET /me/collections/{id}/items`, member) = today's item + the three objects appended:
```json
{ "collection_item_id": "018f…", "puzzle_id": "018d0003-0000-0000-0000-000000000002", "puzzle_name": "Puzzle 2",
  "manufacturer_name": "Ravensburger", "pieces_count": 500, "image": "puzzles/…/box.jpg", "comment": null,
  "added_at": "2026-06-01T09:00:00+00:00",
  "statistics": { … }, "difficulty": { … }, "prediction": { … }, "solves": { … } }
```

`GET /puzzles/{id}` (member, PAT):
```json
{ "id": "018d0003-0000-0000-0000-000000000002", "name": "Puzzle 2", "alternative_name": null,
  "manufacturer": { "id": "018d0002-0000-0000-0000-000000000001", "name": "Ravensburger" },
  "pieces_count": 500, "image": "puzzles/…/box.jpg", "ean": "4005556123456", "identification_number": "12345",
  "is_available": true, "is_approved": true,
  "statistics": { "solved_times": 41,
                  "solo": { "count": 30, "fastest_seconds": 1500, "average_seconds": 2160, "slowest_seconds": 3900, "median_seconds": 2040 },
                  "duo":  { "count": 8,  "fastest_seconds": 1180, "average_seconds": 1320, "slowest_seconds": 1700, "median_seconds": 1290 },
                  "team": { "count": 3,  "fastest_seconds": null, "average_seconds": null, "slowest_seconds": null, "median_seconds": null } },
  "difficulty": { … }, "prediction": { … }, "solves": { … } }
```
`statistics` is public and always split by discipline; `median_seconds` (PR 5a) is, like `average_seconds`, over each player's best time in the discipline, rounded to whole seconds; `null` until the row has been recomputed (event-driven on the next solve change, or the backfill command). `GET /puzzles` items are the same object; the list wrapper is `{ "count", "total", "page", "limit", "has_more", "puzzles": [ … ] }` with `limit` ≤ 100.

`POST /me/solving-times` response = today's fields (`time_seconds` now filled) + `"prediction": { … }` — the prediction that applied **before** this solve; `null` for group times / non-members / opted out.

Profile insights (`GET /me`, and `GET /players/{id}` for public, non-opted-out targets):
```json
"rating": [ { "pieces_count": 500, "points": 1532, "rank": 87, "total_players": 1204 },
            { "pieces_count": 1000, "points": 1498, "rank": 120, "total_players": 863 } ],
"skill":  [ { "pieces_count": 500, "tier": "proficient", "percentile": 62.5, "confidence": "medium", "qualifying_puzzles_count": 14 } ],
"badges": [ "wjpc_2025_participant", "early_adopter" ]
```
`rating: null` ⇔ ranking opted out (or private target); `skill: null` ⇔ token owner not a member (or target private / opted out); `badges` always a list.

`GET /players/{id}` = `GET /me` shape without `email`/flags/`membership_ends_at`, plus the three blocks above; masked private profile ⇒ `id`, `code`, `is_private: true`, `has_active_membership`, everything else `null`/`[]`.
