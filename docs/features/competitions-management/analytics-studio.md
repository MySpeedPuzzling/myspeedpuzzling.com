# Analytics Studio - design of record

**Status:** design (2026-11-17), nothing built. The commentator's companion page for a competition round: every entrant
of the round against the round's puzzle(s) - solved before or not, how often, their times, rating, skill, prediction,
history on similar puzzles, a head-to-head of 2-4 entrants, the puzzle's own statistics, and the official results as
they come in. Public, no sign-in, **no membership gate**, on for every event with rounds. No feature flag (Jan,
2026-11-17: the toolkit ships to main, no flags; "everything for everyone").

Jan's request (verbatim, 2026-11-17):
> one more thing we want to build is "analytics studio" -> page focused on the puzzle and the players of the competition
> - compare to each other, see whether they solved the puzzle already, how many times and what were their time, compact
> view with all the analytics allowing to search quickly and quickly change between the players and view -> this is
> always per round, note there can be multiple puzzle per round - new unique view for the commentators during live
> streaming to have insights - they should see all statistics about the puzzle, difficulty, predictions for the players
> etc -> no membership gate here everything for everyone, i will personally propose they buy membership anyways.

Builds on: `toolkit/R-review-and-plan.md` §1.5 (`RoundPuzzlesVisibleNow`), `competitor-pages.md` (published/live rule,
anonymous stopwatch stream, refetch planner), `displays.md` (stateless firewall, viewer-less exemptions),
`round-formats.md` (pick K of N, labels), `round-results.md` (round slugs, unfinished results),
`.claude/rules/features/insights.md` (predictions, difficulty, skill, rating), `.claude/rules/queries.md` (read-side rules).

---

## 0. Decisions at a glance

| # | Decision | Why |
|---|---|---|
| D1 | One page per round: `event_analytics_studio` / `edition_analytics_studio` (`…/analytics-studio/{roundSlug}`), next to `…/results/{roundSlug}` | Same shapes as round results, standings, bracket |
| D2 | The page is a **data-free shell**; three JSON endpoints on the **stateless firewall** (`/analytics-studio/{roundId}/state`, `/analytics`, `/entrant/{entryId}`) | Viewer-independent answers can be cached in Redis per round; a stateless request has no viewer, so nothing differs per person |
| D3 | **No viewer**: the blocklist does not apply (documented exemption like displays), private players are always masked (no allow list unmasks) | A commentator's screen goes on air - it must show the same entrants to everybody; hiding a competitor because the commentator blocked them would break the broadcast. Private = maximum privacy for everyone |
| D4 | Always on for every publicly visible event with ≥ 1 slugged round; no setting | Reads only public data (+ the ungated insights below); Jan: everything for everyone |
| D5 | Insights shown **ungated** (difficulty tier, skill tier, predictions of other players) by calling the underlying queries directly, never the viewer-gated wrappers | Gated pages stay exactly as they are |
| D6 | Predictions only for entrants with a public profile who did not opt out of time predictions; rating/skill only for those who did not opt out of rankings | `player.time_predictions_opted_out` / `ranking_opted_out` exist precisely for this; the studio is the first surface showing a player's prediction to others |
| D7 | Before the public reveal: labels only, **nothing computed** for a hidden puzzle (no stats, pieces, brand, image, similar history, predictions, solved-before, sort keys) | `RoundPuzzlesVisibleNow` is the only source of what a puzzle "is" |
| D8 | Live results follow the competitor page rule exactly: published → `OfficialEntrantsView` (rank); not published → confirmed results only while `competitor_pages AND competitor_pages_live_results`, never a place | Same rule = never a disagreement between surfaces |
| D9 | Freshness: anonymous public `/round-stopwatch/{roundId}` stream + refetch at `nextRevealAtMs`, polling 10 s while the round is live | No new Mercure topic, no change to any write path |
| D10 | Round-wide predictions computed **asynchronously** (one message per round and visible puzzle set) into Redis; the selected entrant's are computed on request | 300 entrants × the per-player prediction pass ≈ 1,200 statements - never on a request |
| D11 | "Solved before" = solves whose moment is before the round's original stopwatch start (else `starts_at`), never this round's own logged times | The commentator asks "has she done it before today?" |
| D12 | A pair/team row = the exact line-up's history (`puzzling_team` by composition key, only when every member is linked and public) + each member's own history; members' **solo** predictions labelled solo; no pair/team prediction | The pair/team model is "never shown" (prediction-history.md) - not the studio's call to change |
| D13 | `noindex, nofollow` everywhere, links `rel="nofollow"`, not in the sitemap; per-IP rate limits on the JSON | Scraping cost bounded by the caches and limits |

## 0a. Review amendments (binding - they override the sections below where they differ)

Independent design review 2026-11-17, decisions by the orchestrator:

- **R1 Secret puzzles by exclusion (was a blocker).** Every exclusion list and every "round puzzle" set in a query is
  built from the **visible** slots only - never from all round puzzle ids (`NOT IN (:roundPuzzleIds)` of a hidden
  puzzle would leak by subtraction). Every list of §3.3 (attempts, recent similar solves, recent form) applies the
  history cut-off and the embargo (`hide_until`/`hide_image_until` > now excluded). The secret test is a **swap
  test**: the same round with hidden puzzle X vs hidden puzzle Y (fixture entrants have solved X at the same pieces and
  brand as a visible puzzle, and logged a time on X after the cut-off) - `state`, `analytics` and `entrant` answers
  byte-identical (ignoring `serverNowMs`); after the reveal X's data appears.
- **R2 State cache.** The server-side state cache is keyed by `RoundClock::stateHash()` + `visibleHash` + a published
  /results fingerprint is NOT needed: key = round id + stopwatch state hash + visible hash, TTL 2 s capped at
  `nextRevealAtMs`; `serverNowMs` is stamped per response outside the cache. A push refetch therefore never gets a
  pre-push answer for a stopwatch change; "Reveal now" and new results arrive at the next poll (≤ 10 s) - accepted.
- **R3 No async prediction warm (cut).** Predictions are computed **synchronously only for the entrants on screen**:
  the selected entrant (`entrant` endpoint) and the compare set (a `predictions?entries=a,b,c,d` endpoint, ≤ 4
  entries, each member through `GetPlayerPredictions::forPuzzles()`), cached per player × visible puzzle set in
  `analytics_studio_cache` 15 min via the pool's `get()` callback (stampede lock). No prediction column/sort in the
  list (v2 in `docs/TODO.md`). No `WarmAnalyticsStudioPredictions`, no LockFactory, no messenger routing.
- **R4 "As of now" numbers.** Predictions, rating, skill, solved total and best 500 are the player's current values
  (labelled "now"); only the per-puzzle and similar-puzzle history is "before this round" (cut-off).
- **R5 Date precision.** A history row with a month/year-only date counts as "before" only when its whole period ends
  before the cut-off; such dates are shown at their precision (month / year), never as a day.
  `DatePrecisionCoverageTest` classifies the new readers.
- **R6 Rate limits.** No limiter on `state` (micro-cached; venues share one NAT). `analytics_studio_heavy` 600/min per IP
  for analytics + entrant + predictions.
- **R7 Opt-out wording.** The studio shows other players' predictions publicly (Jan: "predictions for the players"):
  the opt-out help text (`messages.*.yml` key used at `en:1638` area) gains "…and on event analytics studios" in all
  6 locales in S1 (S1 makes the data public).
- **R8 Live results are not a leaderboard.** Unpublished confirmed results (organiser opted in) appear per entrant as
  a cell/detail value labelled "confirmed by the judge, not official"; the list is never sorted by them and no
  ordered live list is drawn. Published rounds show the official ranking (sortable by rank). Open question to Jan.
- **R9 Guard tests** in S1's list in addition: `PrivateProfileQueryCoverageTest`, `DatePrecisionCoverageTest`,
  `UnfinishedResultsAreInvisibleTest`, `RobotsTxtTest`, `CustomValuesPrivacyCanaryTest`, `DraftVisibilityCoverageTest`.
- **R10 Cuts for v1:** density toggle, `cmp=` in the URL (kept: `puzzle`, `e`, `q`, `sort`), 4-wide compare on phones
  (2 max there), the JSON fixture contract test, keymap trimmed to `/`, Esc, ↑/↓ (j/k), Enter, `c`, `x`, `[` `]`,
  `?`. The bundle budget test stays (cheap).
- **R11 Budgets** are pinned at the real numbers the implementation measures (no `tiersOf()` - `forPuzzleList()`
  already returns the tier).
- **R12** Line-up keys from lowercase player ids (`TeamComposition` member keys). **R13** rating place via
  `GetPlayerRatingRanking::sqlRankOf()` (the header's number), not the snapshot. **R14** no separate
  `IsSeriesPubliclyVisible` (covered by `SQL_CONDITION`).
- **R15 Naming:** every class/test/key carries `AnalyticsStudio` (e.g. `AnalyticsStudioStateController`,
  `src/Results/AnalyticsStudio*`, `AnalyticsStudioQueryBudgetTest`), link labels say "Analytics studio".
- **R16** `OfficialEntrantsView::entrant()` objects are never serialized - the JSON is built field by field; the
  private canary asserts a private entrant's player id/code is absent from all answers.
- **R17** Cache computations through `CacheInterface::get()` callbacks (one computation at a reveal).
- **R18** S4 adds its own `analyticsStudioUrl` to `RoundsTimelineBuilder` rows (not dependent on results).
- **R19 Earlier rounds at this event**: the entrant detail shows the entrant's published results in the event's
  other rounds (`OfficialEntrantsView::forRounds()`, published only, +1 statement on `entrant`).
- **R20 Blocklist exemption** (Q5) is broader than displays (a public URL, not an organiser token) - stated in the
  doc and to Jan; accepted for on-air consistency.

---

## 1. Routes, controllers, HTTP

### 1.1 Page (shell)

| Route | Paths | Controller |
|---|---|---|
| `event_analytics_studio` | cs `/eventy/{slug}/analytics-studio/{roundSlug}`, en `/en/events/{slug}/analytics-studio/{roundSlug}`, es `/es/eventos/{slug}/analytics-studio/{roundSlug}`, ja `/ja/イベント/{slug}/analytics-studio/{roundSlug}`, fr `/fr/evenements/{slug}/analytics-studio/{roundSlug}`, de `/de/veranstaltungen/{slug}/analytics-studio/{roundSlug}` | `src/Controller/AnalyticsStudio/EventAnalyticsStudioController.php` |
| `edition_analytics_studio` | `/{cs: serie, other: series}/{seriesSlug}/{editionSlug}/analytics-studio/{roundSlug}` with the locale prefixes of `edition_standing` | `src/Controller/AnalyticsStudio/EditionAnalyticsStudioController.php` |

- The path segment `analytics-studio` is the same in every locale (a product name, like "Insights"). **Naming:** the online competitions already have a "studio" (the LiveKit video studio - `StudioInvite`, `GetStudioInvites`, `StudioLiveUpdates`, `console_studio.js`), so every class, route, key, asset and cache name of this feature carries the full `AnalyticsStudio` / `analytics_studio` / `analytics-studio` prefix - never a bare `Studio`.
- Resolution exactly as `EventStandingController` / `EditionStandingController`: `MapEntity` by slug (a standalone
  event wins), an edition's event route 301s to `edition_analytics_studio`, a draft throws `DraftNotVisible` for anybody off its
  team; then `AnalyticsStudioPageBuilder::build(competitionId, roundSlug, seriesName)`.
- **The team of a draft gets 404 too** (like the round pages: "results of an event that is not public are never shown").
  The JSON is stateless and cannot know a team anyway.
- 404 (`AnalyticsStudioNotFound extends NotFoundHttpException`): event not publicly visible (`IsCompetitionPubliclyVisible`),
  no round with that slug, a round without a slug. A round without puzzles, entrants or start works (empty states).
  Online events work like in-person ones (their entries are the same tables).
- The shell renders `templates/analytics_studio/page.html.twig` on its own lean layout `templates/analytics_studio/layout.html.twig`
  (no site header/footer, no Turbo, no `app.js`): title, event + round names, the round switcher (the event's slugged
  rounds - links to their studios), `data-analytics-studio-*` values (state/analytics/entrant URL templates with the round id,
  labels JSON, locale, `serverNowMs`). **No entrant, result or puzzle data** in the HTML - it fills from the JSON.
- Headers: `Cache-Control: no-cache, private`, `X-Robots-Tag: noindex, nofollow`, `<meta name="robots"
  content="noindex, nofollow">`, no canonical/hreflang, `Referrer-Policy: same-origin`.
- Guest statements: event (MapEntity, 1) + its series when an edition (lazy, 1) + `GetAnalyticsStudioRound::bySlug()` (1 - the
  round, the event's slugged rounds for the switcher and the visibility condition in the same statement). **Budget: 2
  (event), 3 (edition), pinned.** A signed-in viewer adds only what every signed-in request loads (the layout reads no
  unread counts - it has no header).

### 1.2 JSON (stateless firewall)

Add `|/analytics-studio/[0-9a-f-]{36}/(state|analytics|entrant/[0-9a-f-]{36})$` to the `stateless` firewall pattern
(`config/packages/security.php`): no session, no cookie, no viewer - `HiddenPlayers` and `PrivateProfileAccess` resolve
to "nobody hidden, nobody revealed" by construction.

| Route | Path | Controller | Answer |
|---|---|---|---|
| `analytics_studio_state` | `GET /analytics-studio/{roundId}/state` | `AnalyticsStudio/AnalyticsStudioStateController` | §3.1, `private, no-store`, micro-cached 2 s server-side |
| `analytics_studio_analytics` | `GET /analytics-studio/{roundId}/analytics?v={visibleHash}` | `AnalyticsStudio/AnalyticsStudioAnalyticsController` | §3.2, `private, max-age=30` |
| `analytics_studio_entrant` | `GET /analytics-studio/{roundId}/entrant/{entryId}?v={visibleHash}` | `AnalyticsStudio/AnalyticsStudioEntrantController` | §3.3, `private, max-age=30` |

- All three: `X-Robots-Tag: noindex, nofollow`; 404 (empty JSON body `{}`) when the round's event is not publicly
  visible, the round has no slug or does not exist, or the entry is not of this round.
- `v` is only a cache-buster: the server **always** computes visibility itself; a reveal changes the hash the state
  hands out, so the client asks a new URL and no browser cache can serve pre-reveal analytics after the reveal (and a
  pre-reveal answer contains nothing secret, whatever URL it sits under).
- Rate limits (framework limiters, `config/packages/rate_limiter.php`, sliding window per client IP, 429 +
  `Retry-After`): `analytics_studio_state` 240/min (a commentator polls every 10 s with several tabs), `analytics_studio_heavy` 120/min shared
  by analytics + entrant. Tests use the framework limiters as everywhere else.
- `robots.txt`: add `Disallow: /analytics-studio/` (the JSON paths). The shell is reachable for people only via `nofollow` links.

### 1.3 Why not the main firewall for the JSON

The main firewall would load a signed-in viewer's profile, turn on their blocklist and allow list inside every
`src/Query` statement and make every answer per viewer - uncacheable, and different on two commentators' screens. The
stateless firewall makes the "no viewer" decision structural rather than a convention each query must remember.

---

## 2. Data

### 2.1 Overview - statements per answer

| Answer | Always | On cache miss | Pinned in `AnalyticsAnalyticsStudioQueryBudgetTest` |
|---|---|---|---|
| Shell (event / edition, guest) | 2 / 3 | - | exact |
| `state` | 3 (round context, visible puzzles, entrants with results) + 1 when published (`OfficialEntrantsView`) | - (whole answer cached 2 s; capped at `nextRevealAtMs`) | 3 / 4, and 0 for a second call within 2 s |
| `analytics` | 3 (same three as state - visibility and entrants are needed for the cache key) | + ≤ 6: puzzle stats (2, per puzzle cached 10 min), entrant history (1), player facts (1), similar puzzles (1), exact line-ups (1, pair/team rounds) | 3 warm / ≤ 9 cold |
| `entrant` | 3 | + 3 (attempt list, similar list, recent form) + per member without cached predictions ≤ 4 | 3 warm / ≤ 10 cold solo |

Predictions add **0 statements** to any answer except the entrant's own cold path (§2.8).

### 2.2 Round context - `src/Query/GetAnalyticsStudioRound.php` (new)

`bySlug(competitionId, roundSlug)` and `byId(roundId)`, one statement each, returning `AnalyticsStudioRound`
(`src/Results/AnalyticsStudioRound.php`): round id, name, slug, category, `starts_at`, timezone, `minutes_limit`, stopwatch
columns (`stopwatch_status`, `stopwatch_started_at`, `stopwatch_first_started_at`, `stopwatch_stopped_at`),
`results_published_at IS NOT NULL`, `puzzle_format`, `puzzles_to_solve`, the event's name/slug/series slug,
`(c.competitor_pages AND c.competitor_pages_live_results) AS live_results`, and (for `bySlug`) the list of the
event's slugged rounds `{name, slug, starts_at}` as JSON. The WHERE embeds `IsCompetitionPubliclyVisible::SQL_CONDITION`
(and `IsSeriesPubliclyVisible` for editions) - no row = 404. Draft guard: `DraftVisibilityCoverageTest` sees the
condition.

"History cut-off" (D11): `AnalyticsStudioRound::historyCutOff()` = `stopwatch_first_started_at ?? stopwatch_started_at ??
starts_at` (the entity's `stopwatchOriginalStartAt()` rule, mirrored on the result object).

### 2.3 Visible puzzles - reuse `RoundPuzzlesVisibleNow::forRound()` (1 statement)

The only source of what a slot shows. A slot with `puzzle === null` is a **label only**. `visibleHash` =
`substr(sha1(implode(',', [roundPuzzleId:puzzleId:imageShown for each visible slot in slot order])), 0, 16)`
(`AnalyticsStudioVisibility::hash(RoundPuzzlesNow)`, pure, unit-tested) - it also changes when an image-only puzzle's picture
comes out.

### 2.4 Entrants - `src/Query/GetAnalyticsStudioEntrants.php` (new, 1 statement)

One statement, shaped like `GetCompetitorPage::entries()` but for one round:

```sql
-- solo round
SELECT 'participant_round' AS kind, cpr.id, cpr.table_number,
       cpr.result_seconds, cpr.result_pieces_placed, cpr.result_did_not_start, cpr.result_puzzles::text,
       NULL::varchar AS team_name, json_build_array({member})::text AS members
FROM competition_participant_round cpr
JOIN competition_participant cp ON cp.id = cpr.participant_id AND {CompetitionParticipantGoing::sql('cp')}
LEFT JOIN player ON player.id = cp.player_id
WHERE cpr.round_id = :roundId
-- pair/team round: competition_team ct of the round + members via competition_participant_round m.team_id (as there)
```

`{member}` = `participant_id, name, country, player_id, player_code, player_country, player_avatar,
{PrivateProfileAccess::sqlIsPrivate('player')} AS is_private, player.time_predictions_opted_out,
player.ranking_opted_out`. Hydrated to `AnalyticsStudioEntrant` (ref, isTeam, teamName, table, result columns,
`list<AnalyticsStudioMember>`). Names through `OfficialEntrantsView::entrant()` (viewer null → private = organiser's name only).

A member is **analysable** (`AnalyticsStudioMember::analysable()`) when `player_id !== null && is_private === false`. Only
analysable members' player ids ever go into any history/insights statement - a private or unlinked member contributes
nothing, and the JSON says the same thing for both ("no public MySpeedPuzzling history"), so a private profile is not
even distinguishable from an unlinked one. Unpaired participants of a pair/team round are not entrants (no entry).

### 2.5 Puzzle statistics - `src/Query/GetAnalyticsStudioPuzzleStats.php` (new) + `AnalyticsStudioPuzzleStatsCache` (service)

Per visible puzzle, viewer-independent, cached per puzzle id in the pool `analytics_studio_cache` (Redis, `cache.app`), key
`puzzle.{puzzleId}.{type}`, TTL 10 min. Two statements for all uncached visible puzzles of the request together:

1. **Facts + distribution** (measured 63 ms on the three most-solved puzzles, 15.6k times, local, warm):
   ```sql
   WITH best AS (
     SELECT pst.puzzle_id, pst.player_id,
            min(pst.seconds_to_solve) AS best,
            min(pst.seconds_to_solve) FILTER (WHERE pst.first_attempt) AS first_try
     FROM puzzle_solving_time pst
     WHERE pst.puzzle_id IN (:puzzleIds) AND pst.puzzling_type = 'solo'
       AND pst.suspicious = false AND pst.seconds_to_solve > 0          -- custom_pst_solo_board
     GROUP BY 1, 2)
   SELECT puzzle_id, count(*) AS solvers, array_agg(best ORDER BY best) AS bests,
          count(first_try) AS first_tries,
          percentile_cont(0.5) WITHIN GROUP (ORDER BY first_try) AS first_try_median,
          min(first_try) AS fastest_first_try
   FROM best GROUP BY puzzle_id
   ```
   plus the same for the round's category when it is a pair/team round (best per `puzzling_team_id`,
   `puzzling_type = :type`). The sorted `bests` array (≤ 6k ints) is cached: percentiles (p10/p25/p50/p75/p90), a
   20-bucket histogram and **any entrant's place on the puzzle** ("#14 of 5,623") are computed from it in PHP
   (`AnalyticsStudioDistribution`, pure, unit-tested). Counts of times / relax / unfinished from `puzzle_statistics`
   (`GetPuzzleStatistics::forPuzzleList()`, reused, folded into the same cache entry - so this is the 2nd statement),
   difficulty from `GetPuzzleDifficulty::forPuzzleList()` + `tiersOf()` - **reused, called directly, not through
   `ResolveDifficultyTiers::forViewer()`** (that wrapper is the members gate). Folding: the puzzle_statistics and
   puzzle_difficulty columns are LEFT JOINed into statement 2 below instead of separate calls if that keeps the pinned
   count; the implementation picks one and pins it.
2. **Top 10 per puzzle** (measured 53 ms, same puzzles): best per player, `row_number() OVER (PARTITION BY puzzle_id
   ORDER BY best, player_id) <= 10`, joined to `player` for name/code/country/avatar and
   `PrivateProfileAccess::sqlIsPrivate('player')` - a private record holder is "Private puzzler" without code/link,
   exactly like `PuzzleTimes`. The studio marks rows whose player is an analysable member of this round ("in this
   round").

Unfinished results are not solves (`CountsAsSolve` for counts, `seconds_to_solve` for times); suspicious times are
excluded in every statement; tracker-less imported results are group results (`player_id` NULL) and count in the pair/
team distribution through `puzzling_team_id`. Puzzle facts shown: image (only `VisibleRoundPuzzle::$puzzleImage`),
name, brand, pieces, difficulty score + tier + confidence, solvers, times logged, median, percentiles, histogram,
first tries (count, median, fastest), top 10.

Invalidation: none needed beyond TTL - the numbers are community aggregates; 10 min is the cadence of the insights
batch (15 min). A puzzle merge changes ids → new keys.

### 2.6 Entrant history on the round's puzzles - `src/Query/GetAnalyticsStudioEntrantHistory.php` (new, 1 statement)

Every attempt of the analysable members on the visible puzzles, solo and as a pair/team member (including tracker-less
imported group results, which carry `puzzling_team_id`), before the cut-off:

```sql
SELECT pst.puzzle_id, pst.player_id AS member_id, NULL::uuid AS puzzling_team_id, 'solo' AS kind,
       pst.seconds_to_solve, pst.pieces_placed, pst.first_attempt, pst.unboxed,
       COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
FROM puzzle_solving_time pst
WHERE pst.puzzle_id IN (:puzzleIds) AND pst.player_id IN (:memberIds)
  AND pst.puzzling_type = 'solo' AND pst.suspicious = false
  AND COALESCE(pst.finished_at, pst.tracked_at) < :cutOff
  AND pst.competition_round_id IS DISTINCT FROM :roundId
UNION ALL
SELECT pst.puzzle_id, ptm.player_id, pst.puzzling_team_id, pst.puzzling_type, …
FROM puzzle_solving_time pst
JOIN puzzling_team_member ptm ON ptm.team_id = pst.puzzling_team_id AND ptm.player_id IN (:memberIds)
WHERE pst.puzzle_id IN (:puzzleIds) AND pst.puzzling_team_id IS NOT NULL AND pst.suspicious = false
  AND COALESCE(pst.finished_at, pst.tracked_at) < :cutOff
  AND pst.competition_round_id IS DISTINCT FROM :roundId
```

**Measured** (local, worst case on purpose: the 300 most active solo players × the 3 most-solved puzzles): 20.5 ms warm,
4,311 rows; the first version joining from `puzzling_team_member` outward took 435 ms - the puzzle filter must drive the
group branch (as above). Real rounds return a few hundred rows. PHP folds the rows into `AnalyticsStudioAttempts` per member ×
puzzle: solves (count, `CountsAsSolve` rule: a time), first time (first by moment), best, last, first-try time,
unfinished count (pieces placed shown, never as a time), solo vs group split. `pst.team` JSON is never read.

### 2.7 Player facts + similar puzzles + exact line-ups

**Player facts** - `src/Query/GetAnalyticsStudioPlayerFacts.php` (new, 1 statement), per analysable member:
`community_player_stats` (solved_total, best500_seconds, last_solved_at), MSP Rating
`CASE WHEN p.ranking_opted_out THEN NULL ELSE elo.elo_rating END` (`player_elo` at 500 - the ladder's), place on the
ladder only via `GetPlayerRatingRanking`'s stored `elo_rank` of the latest snapshot (public ladder; private players are
never analysable anyway), skill tier at **each visible puzzle's piece count** (`player_skill`, opted out → NULL) -
before any reveal the skill at 500 only (no piece count is implied). Shape of `GetPlayerCard`'s statement, for a list.

**Similar puzzles** - `src/Query/GetAnalyticsStudioSimilarHistory.php` (new, 1 statement, grouped by player, FILTER aggregates
generated per visible puzzle *i*):

```sql
SELECT pst.player_id,
  count(*)                                         FILTER (WHERE p.pieces_count = :pc_i) AS size_i_count,
  min(pst.seconds_to_solve)                        FILTER (WHERE p.pieces_count = :pc_i) AS size_i_best,
  percentile_cont(0.5) WITHIN GROUP (ORDER BY pst.seconds_to_solve) FILTER (WHERE p.pieces_count = :pc_i) AS size_i_median,
  count(*) / min / median                          FILTER (WHERE p.pieces_count = :pc_i AND p.manufacturer_id = :m_i) AS brand_i_…
FROM puzzle_solving_time pst JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.player_id IN (:memberIds) AND pst.puzzling_type = 'solo' AND pst.suspicious = false
  AND pst.seconds_to_solve IS NOT NULL AND p.pieces_count IN (:pieceCounts)
  AND pst.puzzle_id NOT IN (:roundPuzzleIds)                -- the round's own are §2.6
  AND (p.hide_until IS NULL OR p.hide_until <= :now)
  AND COALESCE(pst.finished_at, pst.tracked_at) < :cutOff
GROUP BY pst.player_id
```

**Measured** (local): 300 realistic players (≥ 10 solo solves, random) 54 ms warm / 155 ms cold; the 300 most active
players 190-282 ms (141k rows, a sort spills 7 MB to disk). Acceptable because the result is cached with the analytics
(§2.9). Only public raw times feed it - `player_baseline` / pace history (Insights data, self-only) are **not** used.

**Exact line-ups** (pair/team rounds only) - `src/Query/GetAnalyticsStudioLineupHistory.php` (new, 1 statement): for each team
whose members are all analysable, `TeamComposition::keyFromMemberKeys(playerIds)` → `puzzling_team.composition_key IN
(:keys)` joined to its times on the visible puzzles before the cut-off (suspicious excluded, tracker-less included).
A team with an unlinked/private member has no line-up history ("—"), only its members' own.

### 2.8 Predictions - `src/Services/AnalyticsStudio/AnalyticsStudioPredictions.php` (new) + message `WarmAnalyticsStudioPredictions`

Reuse, never re-implement: **`GetPlayerPredictions::forPuzzles($playerId, $puzzleIds)`** - it already honours
`PREDICTION_SHOWN_MODEL` (`ShownPredictionModel`: v7 candidate when fitted, else v3) and is the same math as the puzzle
page (`GetPlayerPrediction`). It is per player: ~4 statements each (personal pass, ratios, global ratios, statistical /
candidate rows). Measured parts: the personal pass for all 300 realistic players together returns 32.8k rows (213 ms
as one statement); the heaviest single player 2.3k rows / 38 ms. Estimate for a 300-entrant round: **~1,200 statements,
3-6 s** - so:

- `WarmAnalyticsStudioPredictions(roundId, list<puzzleId>)` routed to `async` (`config/packages/messenger.php`). Handler
  `WarmAnalyticsStudioPredictionsHandler` (read-only; writes only Redis): for each analysable member with
  `time_predictions_opted_out = false`, `forPuzzles()`, then `GetPlayerPredictions::reset()` every 25 players (memory).
  Stores `array<playerId, array<puzzleId, {seconds, low50, high50, low80, high80, method, attempt}>>` under
  `analytics_studio_cache` key `predictions.{roundId}.{sha1(sorted puzzleIds)}.{shownModel}.{MODEL_VERSION}`, TTL 15 min.
  The message implements `HandledOutsideTransaction` (it only reads; seconds long).
- Dispatch: `AnalyticsStudioPredictions::forRound()` on a cache miss acquires a non-blocking lock
  `analytics-studio-predictions-{roundId}` (`LockFactory`, TTL 120 s) and dispatches once; the analytics answer then carries
  `predictions: null, predictionsPending: true` and the client asks again in 5 s (§5). **Only visible puzzle ids are
  ever passed** - nothing is computed for a hidden puzzle, so neither the cache nor timing can tell anything about it.
- The entrant endpoint reads the same cache entry; on a miss it computes **that entrant's members only** with the same
  call (≤ 4 statements per member) - the selected entrant always has predictions at once.
- No pair/team prediction (D12). Members' solo predictions appear on a pair/team row labelled "solo prediction".
- `AnalyticsStudioPredictions` implements `ResetInterface` (per-request memo of the cache entry).

### 2.9 Analytics cache

`AnalyticsStudioAnalyticsBuilder` caches its whole computed payload minus live bits in `analytics_studio_cache` under
`analytics.{roundId}.{visibleHash}.{entrantsHash}` (TTL 10 min), `entrantsHash` = sha1 of the sorted analysable member
ids per entry + opt-out flags (from the entrants statement that runs anyway). Predictions are merged in from their own
cache at answer time (0 statements). A new entrant or a reveal = new key; everything else is history before the
cut-off, which changes only by back-dated logging - 10 min stale is fine for commentary.

Pool: `analytics_studio_cache` in `config/packages/cache.php` (`adapters: ['cache.app']`, Redis), `default_lifetime: 600`.

### 2.10 What is membership-gated today, and how the studio shows it ungated

| Datum | Gate today | Studio |
|---|---|---|
| Puzzle difficulty tier/score | members (`puzzle_detail` "Insights" panel, `ResolveDifficultyTiers::forViewer()`, `_difficulty_corner`) | `GetPuzzleDifficulty::forPuzzleList()` / `tiersOf()` called directly |
| A player's time prediction | the player themself, members, not opted out (`PuzzleDetailController`, `ResolvePersonalEstimates`) | `GetPlayerPredictions::forPuzzles()` directly; opt-out honoured; private never |
| Skill tier of another player | members see others' tiers (`PlayerHeader::tierChip()`, lock for others) | `player_skill` read directly; `ranking_opted_out` honoured |
| MSP Rating | public ladder (private never) | `player_elo` directly; opt-out honoured |
| Compare line-ups | free capped (Solo you + 1), members 10 | the studio's own compare of 2-4 entrants, no line-up persistence, no `comparison_subject` writes |
| Charts, first-try filters on compare / leaderboard | members | studio's own first-try numbers from §2.5/§2.6 |
| Median histogram, raw stats | free | same |

No gated page changes: the studio calls the queries, not the gated wrappers, and touches no template of theirs.

---

## 3. JSON contracts

All times in seconds, moments in ms since epoch. No player ids, participant ids or codes except a public player's
`profile` path. Entry keys are the entry ids (`participant_round` / `team` ids - already public in competitor-page `e`
tags).

### 3.1 `state`

```
{serverNowMs, round: {id, name, category, startsAtMs, minutesLimit, stopwatch: RoundStopwatchState, published,
  liveResults (bool: the event shows confirmed results), puzzleFormat?: {kind: "pick", toSolve}},
 slots: [{label, revealsAtMs|null, puzzle: null | {key (= round puzzle id), name, brand, pieces, imageUrl|null}}],
 visibleHash, nextRevealAtMs|null,
 entrants: [{key, isTeam, teamName, table, members: [{name, country, profile|null, analysable}]}],
 results: [{key, state: "official"|"live", seconds|null, piecesPlaced|null, piecesCount|null, rank|null,
            qualified, puzzles: [{label, seconds}]?}],
 mercure: {hubUrl, topics: ["/round-stopwatch/{roundId}"]}}
```

- `results` per D8 (`AnalyticsStudioLiveResults::of()` - see §6). "Did not start" never appears (public rule, H1).
- A slot's `puzzle` is copied from `RoundPuzzlesVisibleNow`'s `VisibleRoundPuzzle` only.

### 3.2 `analytics`

```
{visibleHash, cutOffMs,
 puzzles: {<slotKey>: {solvers, timesLogged, medianSeconds, percentiles: {p10,p25,p50,p75,p90}, histogram: [{fromSeconds, count}],
   firstTries: {count, medianSeconds, fastestSeconds}, difficulty: {score, tier, confidence}|null,
   top: [{place, name, country, profile|null, seconds, inRound: key|null}],
   group?: {solvers, medianSeconds, bests…} (pair/team rounds)}},
 entrants: {<entryKey>: {
   members: [{rating|null, ratingPlace|null, solvedTotal, best500|null, skill: {<slotKey>|"500": tier}|null,
     perPuzzle: {<slotKey>: {solves, firstSeconds, bestSeconds, lastSeconds, firstTrySeconds|null, unfinished,
                             lastSolvedAtMs, placeOnPuzzle|null, asGroup: {solves, bestSeconds}|null}},
     similar: {<slotKey>: {sizeCount, sizeMedian, sizeBest, brandCount, brandMedian, brandBest}},
     prediction: {<slotKey>: {seconds, low50, high50, low80, high80, method}}|null}],
   lineup?: {perPuzzle: {<slotKey>: {solves, bestSeconds, firstSeconds}}}|null}},
 predictionsPending: bool}
```

Only slots with a visible puzzle appear under `puzzles` / `perPuzzle` / `similar` / `prediction`; before any reveal
`puzzles` is `{}` and `perPuzzle`, `similar`, `prediction` are absent - **the key set does not depend on hidden slots**
(pinned by `AnalyticsStudioSecretPuzzleTest`). A non-analysable member is `{}` except the name in `state`.

### 3.3 `entrant`

The analytics row of one entry plus: every attempt on each visible puzzle (`{solvedAtMs, seconds|null, piecesPlaced,
firstAttempt, kind: solo|pair|team, unboxed}`, newest first), the 8 most recent similar solves per visible puzzle
(`{puzzleName, brand, pieces, seconds, solvedAtMs}` - puzzles under an embargo excluded), recent form = the last 10 solo
times at each visible piece count (median + list). Cached per `entrant.{roundId}.{entryId}.{visibleHash}` 5 min.

---

## 4. Read-side guarantees, datum by datum

| Datum | Rule | Where enforced |
|---|---|---|
| Event visibility | drafts, unapproved events, editions of draft series → 404 for everyone | `GetAnalyticsStudioRound` WHERE `IsCompetitionPubliclyVisible::SQL_CONDITION` (+ series) |
| Entrants | going participants only (`CompetitionParticipantGoing`), organiser's names, `OfficialEntrantsView::entrant()` masking | `GetAnalyticsStudioEntrants` |
| Blocklist | **documented exemption** (D3): no viewer on the stateless firewall; `HiddenPlayers` resolves to nobody | `BlocklistCanaryTest::testTheAnalyticsStudioHasNoViewerAndSoNoBlocklist` + the studio's statements listed in `BlocklistQueryCoverageTest`'s exemptions with the reason |
| Private players | never analysable: no history, no rating, no prediction, no profile link; "Private puzzler" in top lists; indistinguishable from unlinked | `AnalyticsStudioMember::analysable()`; `PrivateProfileCanaryTest` gets a real case (a private entrant + a private record holder) |
| Suspicious times | excluded from every count, time, distribution, record, similar, prediction (predictions already exclude) | each statement `suspicious = false`; `SuspiciousTimeQueryCoverageTest` scans the new files |
| Tracker-less results | counted as pair/team results via `puzzling_team_id` (line-up + members' group history + group distribution) | `TrackerlessTimeQueryCoverageTest` classification; `TrackerlessTimeCanaryTest` gets a case (an imported team result appears in the line-up history) |
| Unfinished results | never a solve or a time; shown as "unfinished (412/500)" in the attempt list; counts use `CountsAsSolve` | `SolvingTimeReadersAreClassifiedTest` classifies the new readers |
| Series picks | n/a - the studio reads by puzzle and player, never by series | - |
| Custom field values | never read | `CustomValuesCoverageTest` unchanged |
| Opt-outs | `time_predictions_opted_out` → no prediction; `ranking_opted_out` → no rating/skill | `GetAnalyticsStudioEntrants` columns, `AnalyticsStudioAnalyticsBuilder` |
| Secret puzzles | §5 | `RoundPuzzlesVisibleNow` |
| Live results | §6 | `AnalyticsStudioLiveResults` |

`DraftCanaryTest` gets a real case: the shell and the three JSON answers 404 for a draft event and an edition of a draft
series.

## 5. Secret puzzles

**Rule:** the studio knows a puzzle only as a `VisibleRoundPuzzle` of `RoundPuzzlesVisibleNow::forRound($roundId,
$now)`. Everything about a puzzle - name, image, brand, pieces, difficulty, any statistic, any member's history on it,
similar-puzzle history (brand/pieces), skill at its piece count, predictions, sort keys, place on its leaderboard - is
computed **only from the visible list**, in every builder. A hidden slot is `{label, revealsAtMs, puzzle: null}`.
Image-only hiding (`PuzzleHideMode::ImageOnly`): name visible → stats shown, `imageUrl` null until the picture is out.
The site-wide `hide_until` / `hide_image_until` embargo is part of the same rule.

Indirect leaks closed:
- no aggregate over "all round puzzles" (counts, "solved any of this round's puzzles", totals) while one is hidden;
- skill shown at 500 before any reveal (no piece count implied); `minutesLimit` is the organiser's public data;
- the analytics key set and every cache key depend only on visible slots (`visibleHash`), predictions are never warmed
  for hidden puzzles (no Redis entry, no timing);
- similar-history excludes puzzles under an embargo (`hide_until > now`);
- the entrant list's sort options requiring a puzzle are disabled until it is visible;
- the shell HTML carries no slot data at all.

**How the client learns of the reveal** (no new topic, D9): (1) `nextRevealAtMs` from the state → refetch at that
moment + 300 ms + 0-1 s jitter (scheduled reveals, image embargo ends); (2) the anonymous public stream
`/round-stopwatch/{roundId}` (`official_results_events.js` public subscription, as the competitor page) → refetch on
every push within 0-1 s - a stopwatch start with the reveal tick reveals at that moment; (3) polling every 10 s while the
round counts down / runs - covers "Reveal now". When the new state's `visibleHash` differs, the client fetches
`analytics?v=` and the open entrant again and re-renders in place - no reload. The server micro-cache of `state` never
outlives `nextRevealAtMs` (TTL = min(2 s, next reveal − now)).

Tests: `tests/Controller/AnalyticsStudio/AnalyticsStudioSecretPuzzleTest.php` - a round with a secret puzzle (scheduled reveal, manual,
stopwatch tick, image-only, pick round with one of three hidden): at `reveal − 1 s` the shell, `state`, `analytics` and
`entrant` answers contain none of the puzzle's id, name, EAN, image path, brand name, piece count (as a JSON number
next to a slot), and the analytics JSON is byte-identical to the same round without that puzzle; at `reveal + 1 s`
everything is there. Plus one method in `SecretPuzzleRoutesTest`
(`testTheAnalyticsStudioNeverCarriesTheSecretBeforeTheReveal`).

## 6. Live official results

`AnalyticsStudioLiveResults::of(AnalyticsStudioRound, list<AnalyticsStudioEntrant>, RoundPuzzlesNow, ?OfficialRoundEntrants)` - pure, the
competitor page's rule (`GetCompetitorPage::entry()`, lines ~303-330, and `CompetitorPageSettings`):

- **Published round** (`results_published_at` set): `OfficialEntrantsView::forRound($roundId, $category, null)` - the
  round page's rows, official ranks and qualified marks, never re-ranked here; a pick entry's chips via
  `PublishedEntryPuzzle::listOf()`.
- **Not published and `competitor_pages AND competitor_pages_live_results`** (the setting does nothing while pages are
  off - the competitor page 404s then, so the studio requires both): each entry's confirmed result (`state: "live"`),
  no rank, no qualified mark, never "Did not start". The list is ordered like a ranking would be
  (`OfficialResultsRanking` order) but **no place number is drawn** - labelled "Confirmed by the judge - not official
  yet".
- Otherwise: no results at all (a round in progress shows only who is in it).
- `piecesCount` of an unfinished result only when the competitor page would show it (`GetCompetitorPage::piecesCount()`
  rule - extract it to a shared static `UnfinishedPiecesCount::of()` in package S1, the competitor page calling it, its
  tests unchanged).

Freshness: state polling 10 s ± 20 % while the round runs, counts down, or stopped but not published; 60 s otherwise;
a stopwatch push refetches at once (0-1 s). Statements per refresh: **3** (unpublished) / **4** (published), and **0**
for any viewer within the 2 s micro-cache window - 50 commentator tabs cost one computation per 2 s.

## 7. UI

### 7.1 Laptop 1440 × 900 (primary)

```
┌ top bar 44px ─────────────────────────────────────────────────────────────────────────────────────────┐
│ MSP · WJPC 2026 ▸ Individual D ▾   [A] [B] [C]   ● Running 0:41:12 · 1:18:48 left   Live: 23 confirmed │
├ entrants 400px ───────────────┬ detail / compare (flex) ───────────────────┬ puzzle 360px ───────────────┤
│ [/ search name, country, table]│ Anna K. 🇨🇿 #ANNA  table 12   Rating 1834 (#41)│ [image]  London Postcard   │
│ sort: prediction ▾  filter:    │ ┌ Puzzle A ───────────────────────────────┐ │ Trefl · 500 pcs           │
│  ○ all ○ solved A ○ never      │ │ Solved before 3×  first 52:10  best 41:07│ │ Difficulty: Hard (0.94)   │
│ ───────────────────────────    │ │ last 44:30 (12 Sep)  #14 of 5,623        │ │ 5,623 solvers · median 58:│
│ ▸ Anna K. 🇨🇿 3×  41:07  ≈43:00 │ │ Prediction ≈ 43:00 (40-47)  skill ★★★★   │ │ ▁▃▆█▆▃▂▁ histogram        │
│   Ben T.  🇬🇧 —      —   ≈55:20 │ │ Same brand 500: 12× med 48:10 best 40:55│ │ first tries 4,102 · 1:02: │
│   Pairs "Sharks" 2 members …   │ │ All 500: 412× med 51:30                  │ │ Top 10 (★ = in this round)│
│ …                              │ └──────────────────────────────────────────┘ │ ─ Live results ──────────│
│                                │ attempts ▾  recent form ▾                    │ 1 Anna K. 41:55 (official)│
└────────────────────────────────┴──────────────────────────────────────────────┴───────────────────────────┘
```

- **Entrant list**: one dense row per entry (28 px): name (+ flag), table, solved-before mark for the active puzzle
  (`3×` / `—`), best time on it, prediction, rating, live result. Pair/team rows: team name, members as a second muted
  line, "line-up 2×" + "members: 1 of 2 solved". Sort: name, table, prediction, best on puzzle, rating, live result;
  filter chips: all / solved this puzzle / never solved / no MSP history. Search filters instantly (diacritics-folded,
  name, team name, country code, table number) - client-side over ≤ 300 rows.
- **Detail** (one entrant) or **Compare** (2-4 entrants as columns, the same rows aligned - solved before, best, first,
  last, first try, prediction, similar brand/size, rating, skill, live result; the best value per row bold).
- **Puzzle panel** for the active slot; a hidden slot shows its label and "Revealed at the start" (+ countdown when
  `revealsAtMs` is known). **Live results** under it (§6).
- Density toggle (compact/comfortable), dark by default on the lean layout (`prefers-color-scheme` respected).

### 7.2 Phone

Single column with a sticky bottom tab bar: Entrants · Puzzle · Live. Tapping an entrant opens the detail as a full
screen sheet (back closes). Compare = up to 2 entrants side by side (4 on ≥ 768 px). Search sticky at the top.

### 7.3 Keyboard

| Key | Action |
|---|---|
| `/` | focus search (Esc clears, a second Esc blurs) |
| `↑` `↓` / `j` `k` | move the selection in the (filtered) list; the detail follows |
| `Enter` | open the selected entrant (phone: the sheet) |
| `c` | add/remove the selected entrant to compare (max 4) · `x` clear compare |
| `1`-`9` / `[` `]` | switch puzzle slot (A, B, C…) |
| `s` / `S` | next / previous sort |
| `p` / `l` | focus puzzle panel / live results |
| `d` | density |
| `?` | shortcut help overlay |

Shortcuts are ignored while typing in the search box except `Esc`, `↑`, `↓`, `Enter`.

### 7.4 URL

`?puzzle=B&e=<entryId>&cmp=<id>,<id>&sort=prediction&q=…` kept with `history.replaceState` (deep-linkable, a
commentator shares a prepared view with the co-commentator). The puzzle is addressed by **label**, never by an id.
Unknown ids are ignored.

### 7.5 Empty states

- No MSP history (unlinked or private member): "No public MySpeedPuzzling history" - the same words for both.
- Never solved this puzzle (analysable): "First time on this puzzle" + similar-puzzle history.
- Puzzle never solved on MSP: "Nobody has logged this puzzle yet" + brand/pieces + difficulty "not rated yet";
  predictions still shown where the model gives one.
- Round without entrants: "No entrants yet"; without puzzles: "No puzzle in this round yet".
- Predictions pending: a small spinner in the column header, filled in place.
- Opted out: prediction / rating cells "—" with a tooltip "Not shown at the player's wish".

### 7.6 Front-end build

- Encore entry `analytics_studio` (`assets/analytics_studio.js`): own chunk, excluded from the shared split like `display`
  (`webpack.config.js` `splitChunks.chunks`), added to the service worker's `PRECACHE_SKIP_ENTRIES` (never precached
  for every visitor; `ServiceWorkerInstallTest` gets `testTheStudioEntryIsNeverPrecached`). Stimulus is started inside
  the entry with one controller, `assets/controllers/analytics_studio_controller.js` (DOM adapter only).
- Pure ES modules under `assets/analytics-studio/` (no DOM except `render.js`):
  - `model.js` - merges state + analytics into rows; sort, filter, search (diacritics folding), compare set (max 4),
    best-value marking;
  - `url.js` - encode/decode `?puzzle=&e=&cmp=&sort=&q=`;
  - `keys.js` - the keymap (§7.3) → actions;
  - `refresh.js` - the refetch planner (pushes, `nextRevealAtMs`, polling cadence, backoff doubling to 5 min on
    failures, `visibleHash` change → analytics refetch, `predictionsPending` → 5 s) reusing `createServerClock()` and
    `official_results_events.js` public subscription, and `round_stopwatch_clock.js` `stopwatchView()` for the clock;
  - `format.js` - durations (`h:mm:ss`), deltas ("+2:13 vs best");
  - `render.js` - builds DOM with `textContent` only, never `innerHTML` (XSS corpus test like `render.js` of displays).
- Node harness `tests/analytics-studio-harness.mjs` (model, url, keys, refresh, format, render XSS) → PHPUnit wrapper
  `tests/AnalyticsStudioScriptTest.php`; bundle budget `tests/AnalyticsStudioBundleTest.php` (≤ 70 KB min / 22 KB gzip, measured and
  pinned at build).
- Labels: `analytics_studio.*` keys passed as `data-analytics-studio-labels-value` JSON (like the competitor page).

## 8. Links (0 statements)

Every link is `rel="nofollow"` and uses only data the page already has.

- **Organiser surfaces** (in S3, the package that completes the page): `competition_toolkit/displays.html.twig`
  (a "Studio" button per round row - rounds already loaded), `manage_round_stopwatch` page and
  `online_control_room` (next to the existing "Displays" link) - each "Open the commentator studio". Their tests assert
  the link and unchanged statement counts.
- **Public round page** (`round_results.html.twig`, S4): one line under the round header "Commentator studio →"
  built from `round_route_params` + `round.slug` (0 statements). `OfficialRoundResultsGoldenTest` stays green
  **without regenerating the golden**: the test strips the single `[data-analytics-studio-link]` element before comparing, and a
  new assertion checks the link is the only difference. `DetailPagesQueryBudgetTest` numbers unchanged.
- **Event / edition page** rounds list (S4): a "Studio" link next to "Results" for slugged rounds (the rounds are
  already loaded; `DetailPagesQueryBudgetTest` unchanged).
- Not linked from: competitor pages (competitors, not commentators), standings, bracket, sitemap.

## 9. Abuse and cost

- All heavy work is per round, not per viewer: analytics ≤ 1 computation per round × visible set × entrant set per
  10 min, predictions ≤ 1 per 15 min, state ≤ 1 per 2 s, puzzle stats ≤ 1 per puzzle per 10 min.
- A scraper walking every past round of every event pays ≤ 9 statements per new round (≤ ~350 ms worst case measured)
  and is capped at 120 heavy requests / min / IP; bots are kept out of the index (noindex, nofollow links, robots.txt
  `Disallow: /analytics-studio/`) and the production bot-blocker stays in front.
- Studios of past rounds work (history is "before that round"), from the same caches.
- What is exposed beyond today's public pages: other players' predictions, skill tiers and puzzle difficulty tiers
  without membership - Jan's explicit decision; opt-outs and private profiles are honoured.

## 10. Packages

Each package ships to production on its own, complete and tested; nothing user-facing is reachable before S3.
At most two run in parallel: **S1 ∥ S2**, then **S3**, then **S4**. Every package ends with the CLAUDE.md gates
(phpstan, cs-fix, paratest, schema:validate, cache:warmup) and the as-built summary in
`.claude/rules/features/competition-toolkit.md` + this doc.

### S1 - Data + JSON endpoints (back end only, no UI, no links)

Files: `src/Query/GetAnalyticsStudioRound.php`, `GetAnalyticsStudioEntrants.php`, `GetAnalyticsStudioPuzzleStats.php`,
`GetAnalyticsStudioEntrantHistory.php`, `GetAnalyticsStudioPlayerFacts.php`, `GetAnalyticsStudioSimilarHistory.php`,
`GetAnalyticsStudioLineupHistory.php`; `src/Results/Studio*` (`AnalyticsStudioRound`, `AnalyticsStudioEntrant`, `AnalyticsStudioMember`, `AnalyticsStudioAttempts`,
…); `src/Services/AnalyticsStudio/` (`AnalyticsStudioVisibility`, `AnalyticsStudioDistribution`, `AnalyticsStudioLiveResults`, `AnalyticsStudioPredictions`,
`AnalyticsStudioPuzzleStatsCache`, `AnalyticsStudioStateBuilder`, `AnalyticsStudioAnalyticsBuilder`, `AnalyticsStudioEntrantBuilder`);
`src/Value/UnfinishedPiecesCount.php` (extracted from `GetCompetitorPage`); `src/Message/WarmAnalyticsStudioPredictions.php` +
handler; `src/Controller/AnalyticsStudio/Studio{State,Analytics,Entrant}Controller.php`; `src/Exceptions/AnalyticsStudioNotFound.php`;
config: security stateless pattern, `analytics_studio_cache` pool, two rate limiters, messenger routing; `robots.txt`.

Tests: `tests/Controller/AnalyticsStudio/AnalyticsStudioStateControllerTest.php` (published / live on / live off × solo, pair, pick;
404s), `AnalyticsStudioAnalyticsControllerTest.php` (history cut-off, this round's own times excluded, opt-outs, private =
unlinked, unfinished, tracker-less team result in line-up history, pair/team rows), `AnalyticsStudioEntrantControllerTest.php`,
`AnalyticsAnalyticsStudioQueryBudgetTest.php` (state 3/4/0, analytics 3 warm / pinned cold, entrant 3 warm / pinned cold),
`AnalyticsStudioSecretPuzzleTest.php` (§5), `tests/Services/AnalyticsStudio/AnalyticsStudioDistributionTest.php`, `AnalyticsStudioVisibilityTest.php`,
`AnalyticsStudioLiveResultsTest.php` (parity with the competitor page on the same fixture: same results, states, never a rank
for live), `WarmAnalyticsStudioPredictionsHandlerTest.php` (cache shape, only visible puzzles, opted-out skipped, private
skipped, equals `GetPlayerPredictions::forPuzzles()` for a fixture player); canaries: `BlocklistCanaryTest` exemption
method, `PrivateProfileCanaryTest`, `DraftCanaryTest`, `TrackerlessTimeCanaryTest` cases, `SecretPuzzleRoutesTest`
method; coverage tests' classifications; `CompetitorPageQueryBudgetTest` + `CompetitorPageControllerTest` unchanged
(after the `UnfinishedPiecesCount` extraction).

Acceptance: the three endpoints answer per §3 for the fixture cup; budgets pinned; secret test green; no existing test
changed except the canary/coverage additions.

### S2 - Front-end modules (pure JS + harness, no page, no PHP)

Runs in parallel with S1 against a fixture contract `tests/fixtures/analytics-studio-state.json` + `analytics-studio-analytics.json`
(written first in S2 from §3; S1's controller tests assert their answers validate against the same fixture's key
shape - `AnalyticsStudioJsonContractTest`, added in S1 if S2 merged first, else in S3).

Files: `assets/analytics-studio/{model,url,keys,refresh,format,render}.js`, `tests/analytics-studio-harness.mjs`,
`tests/AnalyticsStudioScriptTest.php`, the fixtures. Touches no hot file (not `webpack.config.js`, not `app.js`).

Acceptance: harness covers sort/filter/search (diacritics, table numbers), compare cap 4, URL round trip, every key of
§7.3, refresh plan (push jitter, reveal timing, polling cadences, backoff, `visibleHash` change → analytics,
`predictionsPending` → 5 s), XSS corpus through `render.js`, hidden slot rendering without puzzle data.

### S3 - The page (shell routes, layout, controller, styles, translations, organiser links)

Files: `src/Controller/AnalyticsStudio/{Event,Edition}AnalyticsStudioController.php`, `src/Services/AnalyticsStudio/AnalyticsStudioPageBuilder.php`,
`templates/analytics_studio/{layout,page}.html.twig`, `assets/analytics_studio.js`, `assets/controllers/analytics_studio_controller.js`,
`assets/styles/analytics_studio.scss`, `webpack.config.js` (entry + split exclusion), `public/service-worker.js`
(`PRECACHE_SKIP_ENTRIES` + `CACHE_VERSION` rule of `.claude/rules/service-worker.md`), `translations/messages.*.yml`
(`analytics_studio.*` in **all 6 locales**), organiser links (§8) in `competition_toolkit/displays.html.twig`, the stopwatch page,
the online control room.

Tests: `tests/Controller/AnalyticsStudio/AnalyticsStudioPageTest.php` (event + edition routes, 301 of an edition's event path, 404s, draft,
noindex headers/meta, no slot data in the HTML), shell budget in `AnalyticsAnalyticsStudioQueryBudgetTest`, `AnalyticsStudioBundleTest`,
`ServiceWorkerInstallTest::testTheStudioEntryIsNeverPrecached`, organiser link tests with unchanged budgets;
`PluralMessagesTest` / missing-translations clean. **Browser walk** before merge (1440 × 900 and 375 px): keyboard map,
search, compare 4, a scheduled reveal and a stopwatch-tick reveal arriving live without reload, live results appearing
while the event shows confirmed results.

Acceptance: a commentator can open the URL and use everything in §7; nothing public links to it yet.

### S4 - Public links

Files: `templates/round_results.html.twig` (one `data-analytics-studio-link` line), the event/edition page round rows,
`analytics_studio.link*` keys in 6 locales. Tests: `OfficialRoundResultsGoldenTest` strips `[data-analytics-studio-link]` and asserts it is
the only difference (golden files unchanged), `DetailPagesQueryBudgetTest` unchanged, `RoundResultsStateGoldenTest`
unchanged, a link test per page.

Acceptance: links visible on every slugged round's public page, 0 extra statements, goldens untouched.

## 11. Open questions for Jan (defaults in force)

1. **Others' predictions in public** - the studio is the first page showing a player's prediction to anyone but
   themselves. Default: shown, opt-out and private profiles honoured. Should we also tell players in the opt-out help
   text ("…and on event studios")? Default: yes, one sentence added in S3.
2. **Pair/team prediction** - the group model exists but is "never shown". Default: members' solo predictions only.
3. **Organiser opt-out** of the studio per event? Default: none (everything for everyone).
4. **Faster live signals** - a public data-free `/round-signal/{roundId}` push on results and "Reveal now" would replace
   the 10 s poll; it touches existing write paths. Default: v2.
5. **Blocklist exemption** (D3) - a signed-in commentator sees players they blocked. Default: accepted (on-air
   consistency), documented like displays.

---

## As built: S1 (data + JSON endpoints, 2026-11-17)

Where it differs from the sections above (§0a applied throughout):

- **Four JSON routes** (`AnalyticsStudio{State,Analytics,Entrant,Predictions}Controller`), the fourth = R3's
  `analytics_studio_predictions` (`?entries=` ≤ 4, unknown ignored). Stateless firewall pattern covers all four;
  404 = `AnalyticsStudioNotFound` bubbling (Symfony's JSON error body, not `{}`).
- **No async warm** (R3): no message, no handler, no lock, no messenger routing. `AnalyticsStudioPredictions` computes per
  member on request, cached per player × visible puzzle set × shown model × `MODEL_VERSION` (15 min).
- **Statements** (pinned in `AnalyticsStudioQueryBudgetTest`): every answer reads the round (`GetAnalyticsStudioRound`,
  event's slugged rounds as JSON) and its visible puzzles (2); analytics/entrant/predictions also the entrants (3).
  state 3 cold unpublished / 4 published / **2 within 2 s** (R2's key needs the two reads); analytics 10 cold (puzzle
  statistics are 4 - the distributions, top tens, `GetPuzzleStatistics` and `GetPuzzleDifficulty` reused, not folded) /
  12 pairs with an all-public line-up and solvers / 3 warm, + 1 on every answer whose puzzles have solvers (who the
  record holders are now - the top tens are cached by player id only, so a player turning private is nameless at once);
  entrant (reads only its entry, `GetAnalyticsStudioEntrants::forEntries()`) 17 cold solo incl. the member's prediction
  / 4 warm (the earlier published rounds are read on every answer, never cached); predictions 7 cold for 1 entry / 19
  for 4 / 3 warm. The rating place is one `RANK()` window over the stored ladder (the header's number without a viewer),
  not a count per player.
- **State cache key**: round + `stateHash()` + visible hash + published moment + live-results switch; not cached at all
  when the next reveal is < 2 s away. Extra fields beyond §3.1: `event`, `cutOffMs`, slot `key`/`position`, member `code`.
- **Puzzle statistics cache**: one entry per visible puzzle set and round category (one computation for all of them).
- **JSON shapes follow S2's fixtures** (`tests/fixtures/analytics-studio-*.json`): `profile` = the English
  `player_profile` path (`AnalyticsStudioProfile`), analytics members `rankingOptedOut`, skill/difficulty tiers as API
  tokens, `lastSolvedAtPrecision` / `solvedAtPrecision`, attempt `kind` solo|pair|team, prediction `method`
  personal|general, entrant `{visibleHash, cutOffMs, key, analytics, members[{predictionsOptedOut, prediction, attempts,
  recentSimilar, recentForm{<pieces>: {medianSeconds, seconds[]}}}], earlierRounds, lineupAttempts?}`, a member without
  public history = `{}` everywhere. Skill before any reveal = `{"500": tier}`, after = per visible slot.
- **Similar history** excludes the visible round puzzles only (R1), puzzles under `hide_until` and this round's own
  times; `recentForm` keeps every puzzle at that piece count (visible round puzzles included) before the cut-off.
- **Measured** (test DB, Docker on a Mac): cold analytics of a fixture round 16-19 ms; a 300-entrant × 3-puzzle round with
  12,000 times: first cold 214 ms (incl. process warm-up), cold recompute ~43 ms, warm ~30-40 ms, 439 KB JSON.

## As built: S3 (the page, 2026-11-17)

Where it differs from the sections above:

- **Routes** `event_analytics_studio` / `edition_analytics_studio` (§1.1 paths, 6 locales; `EventAnalyticsStudioController`
  / `EditionAnalyticsStudioController`, resolution + draft rule copied from the standing pages) → `AnalyticsStudioPageBuilder`
  (`GetAnalyticsStudioRound::bySlug()` - the switcher's rounds ride on it). Guest statements **event 2, edition 3**
  (`AnalyticsStudioQueryBudgetTest::testTheShellCost`). Headers `private, max-age=0, no-cache` (the explicit max-age keeps
  `AnonymousCacheHeadersSubscriber` from making the guest shell shared-cacheable - it carries `serverNowMs`),
  `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: same-origin`, the robots meta; no canonical/hreflang.
- **Organiser links by the round's id**: the three organiser pages know a round's id but not its event's/series' slugs, so
  they link `analytics_studio_round` (`/{locale}/analytics-studio/{roundId}`, `AnalyticsStudioRoundController`): one
  statement (`GetAnalyticsStudioRound::byId()`, the visibility condition included) and a 302 to the page; 404 like the
  page. The links (`rel="nofollow noopener"`, new tab) only for slugged rounds: displays page = an "Analytics studio"
  section with one button per slugged round (`CompetitionRoundForManagement::$slug`, a column of the statement it ran
  anyway), round stopwatch page and online control room = one button. `AnalyticsStudioLinksTest` pins equal statement
  counts with and without the link. No public link (S4).
- **Plain entry, no Stimulus**: `assets/analytics_studio.js` → `assets/analytics-studio/page.js` (the DOM adapter: fetches
  without cookies, the keymap, click delegation over the `data-as-*` attributes, `history.replaceState` of the view, the
  phone sheet as a history entry so Back closes it, the 1 s clock, `visibilitychange`). The renderer is created at the
  first state answer (before it the shell's "Loading…"). Measured 63.5 KB minified / 21.2 KB gzip
  (`AnalyticsStudioBundleTest`, budget +5 %; `tests/live-entry-bundle.mjs` takes the entry as an argument now and skips
  stylesheet imports). Own stylesheet `assets/styles/analytics_studio.scss` (no Bootstrap), entry excluded from the
  split chunks and in `PRECACHE_SKIP_ENTRIES` (`CACHE_VERSION` unchanged - nothing cached needs evicting).
- **Layout**: ≥ 1280 px three columns 440 / flexible / 340 px (440, not 400: the row's name needs the room next to
  table, solved, best, rating, result), 1024-1279 narrower without the table/rating columns, 768-1023 two columns +
  tab bar, < 768 one panel + bottom tabs, the entrant / compare as a full-screen sheet. Dark by default, light under
  `prefers-color-scheme: light`.
- **render.js fix (S2)**: the compare view got its own head (title, Clear, ×) - on a phone the compare sheet had no
  way out; harness extended.
- **Labels**: `AnalyticsStudioPageBuilder::LABEL_NAMES` = render.js `LABEL_NAMES` (pinned by `AnalyticsStudioPageTest`),
  translated server-side into `data-labels`; the English block `analytics_studio.*` (flat keys - `compare` and
  `compare.clear` are both messages) + `page.*` and `organiser.*`. No plural messages (counts are interpolated in the
  browser, `%count%×`).
- **Browser walk** (headless Chrome, test fixtures + a pick round with a scheduled secret): search with diacritics,
  ↑/↓/Enter, compare 2-4 and the fifth refused, `[` `]`, `?`, a confirmed result and a scheduled reveal arriving
  without a reload, pairs, a published round, an empty round, light mode; phone list, sheet + Back, tabs, compare 2.
  Not tried: a stopwatch push through Mercure from the browser (the stream's CORS on a non-default origin), real
  devices, a 300-entrant round on screen.

## As built: S4 (public links, 2026-11-17)

- **Event / edition page**: `TimelineRound::$analyticsStudioUrl` (R18, `RoundsTimelineBuilder::analyticsStudioUrl()`) -
  every slugged round of a public page, independent of results (an upcoming round too: commentators prepare before
  it), "Analytics studio" next to "Results" in `templates/event_parts/_round.html.twig` (`rel="nofollow"`,
  `data-analytics-studio-link`). No link on a page that is not public (draft for its team, unapproved, rejected) or for a
  round without a slug. Built from the rounds already loaded - `DetailPagesQueryBudgetTest` unchanged.
- **Round results page**: one `<p data-analytics-studio-link>` right after the header line ("Analytics studio →"), the
  URL from `round_route` + `round_route_params` (0 statements). `OfficialRoundResultsGoldenTest` asserts exactly one
  such element with the expected URL, cuts it out and compares the rest with the unchanged golden files.
- Key `analytics_studio.link` in all 6 locales. Tests `AnalyticsStudioPublicLinksTest` (event, edition, round pages,
  Czech URLs, not public, draft for its team, a round without a slug, the link answers 200),
  `RoundsTimelineBuilderTest::testTheAnalyticsStudioOfEverySluggedRoundOfAPublicPageResultsOrNot`, a `DraftCanaryTest`
  surface.
