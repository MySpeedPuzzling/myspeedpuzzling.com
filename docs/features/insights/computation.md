# Insights - computation

How every number the Insights page shows is computed and stored. Design of record: [`README.md`](./README.md);
delivery: [`implementation-plan.md`](./implementation-plan.md) §5 (Phase 4). This file holds the exact definitions:
§1-6 are the batch additions (stream 4a), the read models and builders (4b, 4c) add their sections below.

All of it is **internal data**: nothing here is shown as is. Readers apply the visibility rules (self-only page,
`HiddenPlayers`, `PrivateProfileAccess`, secret puzzles) - see §7.

## 1. Where it runs

| What | When | Code |
|---|---|---|
| Rating entries, puzzle rating times, generation | every 15 min, inside `myspeedpuzzling:recalculate-puzzle-intelligence` (step 7 "ratings" and the last step) | `PuzzleIntelligenceRecalculator::computeRatings()`, `RatingInsightsWriter`, `RatingEntriesSync`, `InsightsGeneration` |
| Pair/team coordination constants, profile cards, generation | daily, `myspeedpuzzling:recalculate-insights-daily` → `RecalculateInsightsDaily` | `GroupCoordinationFitter`, `InsightsProfileRecalculator`, `InsightsProfileCalculator` |

Cron row (lily.srv): `40 4 * * * docker compose exec -T web php bin/console myspeedpuzzling:recalculate-insights-daily`.

**Writes: only what changed.** Every table here is synced by `ChangedRowsSync`: the stored rows of a scope are read,
compared with the fresh ones in PHP value by value (a double read back from Postgres is the very same double; doubles
are written with 17 significant digits), and only new and changed rows are upserted, rows the run no longer produces
deleted. A run over unchanged data writes nothing and costs no WAL (no temporary tables either - their catalog rows
cost ~1 MB of WAL per run). `computed_at` is the moment a row's values last changed.

## 2. Generation (`insights_generation`)

One row (id 1): `generation` (bigint) moves on by one at the end of every 15-minute run, and at the end of a daily run
that changed a coordination constant or a profile card (those two tables are written by the daily run only, so "nothing
written" reliably means "nothing changed"); `advanced_at` is when.

The 15-minute run moves it on **every time**, even when it wrote nothing: the page also reads data other writers change
between runs - `puzzle_statistics` (medians, on every solve), `puzzle_difficulty` / `player_baseline` /
`player_improvement_ratio` (the incremental handler on a solving-time change, so the next batch run may find nothing
left to write), derived metrics, stored predictions. Knowing reliably that none of them changed would need every one of
those writers to mark it; a bump every 15 minutes bounds their staleness in the cache to one run, as the plan assumed
(a recompute costs ~60-80 ms). `InsightsGeneration::current()` is one primary-key lookup (0 = no run yet), `advance()` one
upsert. The Insights page cache keys on it (player + generation + a fingerprint of the player's results - §4.4 of the
plan), so nothing cached outlives the batch data it was built from. It survives restarts (a table row, not Redis).

## 3. Rating entries (`player_rating_entry`)

What `MspRatingCalculator` scores in memory, kept: one row per **player × piece count × puzzle × portfolio**
(`first_attempt` / `best_time`; key = primary key, in that order).

| Column | Meaning |
|---|---|
| `seconds` | first attempt: `MIN(seconds)` of the player's first-attempt rows; best time: `MIN(seconds)` of all their solo results on the puzzle |
| `solved_at` | what the decay counts from: the first attempt's moment / the latest solve of the puzzle (`COALESCE(finished_at, tracked_at)`) |
| `percentile` | `MspRatingFormula::percentileInSortedTimes()` of `seconds` in the puzzle's sorted times (§4): (slower + tied others / 2) ÷ (n − 1), 0.5 when n ≤ 1 |
| `difficulty_weight` | `MspRatingFormula::difficultyWeight(difficulty_score, sample_size)` = 1 − b + b · difficulty, b = 0.5 · min(1, sample / 50) |
| `decay` | `MspRatingFormula::decay(solved_at, now)` = exp(−max(0, months − 3) / 30), months = y·12 + m + d/30 of the calendar difference |
| `points` | `percentile × difficulty_weight × decay` (this order - the same double as in memory) |
| `in_top` | among the portfolio's best `CAP` = 100 entries by points; ties by puzzle id, so which of equal entries counts never flips between runs |

- **Who:** every public player with a solo result at a rated piece count (`RATING_PIECES_COUNTS` = 500) in the last
  24 months on a qualifying puzzle - **also players not on the ladder yet** (rating 0.0: fewer than 20 first attempts
  or 50 solves in the window), so "how far am I from the ladder" can be answered. Private players have no rating and no
  entries (deleted when a player turns private, like `player_elo`). Suspicious times are left out like everywhere.
- **Qualifying puzzle:** ≥ 20 public first-attempt solvers and a difficulty score of more than `insufficient`
  confidence (unchanged rule).
- **Parity:** the rating is computed *from* these entries (`MspRatingCalculator::calculatePortfolio()`;
  `calculateForPlayer()` = its rating), so `player_elo.elo_rating` = 0.75 × mean(points of the counted first-attempt
  entries) + 0.25 × mean(points of the counted best-time entries) = `MspRatingFormula::rating()`. Pinned by
  `RatingInsightsBatchTest` (stored entries = in-memory entries, field by field; stored entries reproduce the rating).
- **Secret puzzles** count in ratings like any puzzle (unchanged), so their entries exist; readers never show a
  secret puzzle (§7).
- **Rebuilding a rating on read:** take the player's entries, recompute `decay` for "now" if needed
  (`MspRatingFormula::decay()`), sort each portfolio by points (ties by puzzle id), take 100, `MspRatingFormula::rating()`.

**Size and churn (production copy, data to 2026-10-11):** 291k rows (221k of the 1,100 rated players, 70k of 4,649
players not on the ladder yet), 57 MB heap + indexes ~80 MB. Rows change for two reasons:
- **The daily decay roll:** most `solved_at` are midnight (dates), so 244k decays move once a day, in the day's first
  run: +1.7 s and ~100 MB of WAL for that run. The table has `fillfactor = 70` (migration, by hand) so these updates are
  HOT (no index writes): 206 MB → ~100 MB of WAL per roll, ~99 % HOT once settled.
- **New solves:** a new solver on a puzzle moves every entry of that puzzle (everybody's percentile): ~550 rows and
  ~1.5 MB of WAL for 3 new solves on popular puzzles (a typical 15 minutes).
Steady-state runs with no new solves write nothing. Together ~+250 MB of WAL a day (today's batch: ~2.2 GB a day).
A leaner alternative if that matters: store only `seconds` / `solved_at` / the key and compute percentile, decay,
points and `in_top` on read from §4 (~0 WAL; the what-ifs need §4 anyway) - not done, it changes the 4c contract.

## 4. Puzzle rating times (`puzzle_rating_times`)

The times the rating ranks a puzzle's solvers by, for rating what-ifs (binary search, README §4: ~15-25 ms instead of
270-490 ms on read). One row per puzzle of a rated piece count with ≥ `MspRatingCalculator::NEAR_QUALIFYING_MIN_SOLVERS`
(9) public first-attempt solvers: 2,560 rows at 500 pieces (1,504 qualifying + 1,056 near), 2.8 MB.

| Column | Meaning |
|---|---|
| `first_attempt_times` | jsonb list, ascending: one first-attempt time per public player (their earliest first-attempt row), all time |
| `best_times` | jsonb list, ascending: one best time per public player, all time |
| `solver_count` | = length of `first_attempt_times` (public first-attempt solvers; 20 = qualifies) |
| `qualifies` | counts in ratings now: ≥ 20 solvers and a difficulty of more than insufficient confidence |
| `difficulty_weight` | the weight the rating used (null without a difficulty score) |

Left out: suspicious times, private players' times, **secret puzzles entirely** (`hide_until` / `hide_image_until` in
the future - no row at all). The lists are exactly `MspRatingCalculator::puzzleRankings()`.

**What-ifs** (all with `MspRatingFormula`, so a what-if that changes nothing gives the stored entry back):
- *Re-solve puzzle P in T seconds* (best-time portfolio): new best b′ = min(b, T). In `best_times` replace the player's
  own b (present when the player is public) with b′ - remove at `lowerBound(b)`, insert at `lowerBound(b′)` - and take
  `percentileInSortedTimes(b′, …)`; points = percentile × `difficulty_weight` × decay(now) (= 1.0 for a fresh solve).
  A re-solve never changes the first-attempt portfolio.
- *First try of an unsolved puzzle in T seconds:* insert T into both lists (n + 1) and score both portfolios. A puzzle
  with `qualifies = false` but `solver_count + 1 ≥ 20` and a `difficulty_weight` would start counting ("pending
  qualification").
- Then rebuild the portfolio (replace or add the entry, sort, top 100, `rating()`); eligibility (20 first attempts and
  50 solves in 24 months) from `MspRatingCalculator::getProgress()`.
- A private player's own times are not in the lists: insert instead of replace. Other players' times are as of the last
  batch run.

## 5. Pair/team coordination constants (`group_coordination_constant`)

c(size, first try / repeat) = how much longer a group takes than its members' solo paces combined like rates:
**expected = c × difficulty ÷ Σᵢ (1 / paceᵢ)**, every pace **as of the solve** (the synergy of §4c.1 divides actual
by that; `Services\Insights\GroupExpectation` is the one rule both use).

- **Fit (daily):** for every pair/team result that can be compared - `puzzling_team` with ≥ 2 members, **every member a
  registered player with a pace at the solve** (`PaceTimeline::paceAt()` - the as-of rule of the difficulty: the pace
  row valid just before the solve, else interpolated from the member's other piece counts, else their first pace), a
  puzzle with a difficulty score of more than insufficient confidence, boxed, not suspicious, not a secret puzzle - the
  ratio time × Σ(1/paceᵢ) ÷ difficulty; **c = the median ratio** per size group (2 = pair, 3, 4 = four or more) and
  first try / repeat (the result's `first_attempt` flag, set only when it is everybody's first try). Two statements
  (the results, the whole pace history into a `PaceTimeline`, ~10 MB of packed arrays); the fit 0.7 s, the daily
  command 3.7 s on the production copy (3.85 s with the former one-statement fit on the same machine). Before the
  pace history is built (`pace_history_build`) every bucket keeps its seed.
- **Seeds** (`Value\GroupCoordination::SEEDS`, measured 2026-10): pairs 1.21 / 1.08, teams of 3 1.35 / 1.24, 4+ 1.41 /
  1.32 (first / repeat). A bucket with fewer than 30 results keeps its seed (`fitted = false`, `sample_size` = what it had).
- **Production copy (2026-10-11 data, as-of paces, stream 4c):** pairs 1.1976 / 1.0901 (13,388 / 14,452 results),
  teams of 3 1.3165 / 1.2518 (348 / 356), 4+ 1.3684 / 1.3259 (1,557 / 822) - back at the seeds. With today's baselines
  but the as-of difficulty of 3a the same fit gave 1.2944 / 1.1704, 1.4533 / 1.3593, 1.4915 / 1.4165: old results
  divided by today's (faster) paces against an unbiased difficulty - the age bias again, which is why the fit moved to
  as-of paces together with the synergy (stream 4a measured 1.2067 / 1.0844 … before 3a, both sides biased alike).
  Slightly more results can be compared (13,388 vs 12,534 pair first tries): the as-of lookup finds a pace wherever
  the difficulty's does.
- Read: `GetGroupCoordinationConstants::all()` (seed for any bucket not stored).
- The pair/team prediction model (never shown - puzzle-intelligence/README.md "Pair and team predictions") fits its own
  constants with this rule as of the solve (paces strictly before it, no first pace from later rows) and stores the
  rule's predictions as its reference `group_baseline`; nothing here reads them.

## 6. Puzzler profile cards (`player_insights_profile`)

Seven axes (`Value\InsightsProfileAxis`), the prototype's `renderProfile()` on real results; per axis the raw `*_score`,
`*_sample_size` (what it rests on) and `*_percentile` (0-100, "better than X % of puzzlers"). Score and percentile are
null while an axis has too few results. Daily (~2.1 s on the production copy); one row per player with at least one
score (3,589 on the copy).

**Input:** the player's solo results - not suspicious, timed, **boxed** (unboxed is a different discipline), on a puzzle
that is **not secret** and has a median solo time from **≥ 5 solo times** (`puzzle_statistics`). Per result:
- `ratio` = time ÷ the puzzle's median solo time;
- `attempt` = its number among the player's solo results on that puzzle (by solve moment, then `tracked_at`), counted
  before unboxed results are dropped;
- **form** = median `ratio` of the player's **first tries** (`first_attempt = true`) within ±75 days of the result
  ("your usual" then; null when there is none);
- **learning factor** L(pieces, attempt) = the community's cumulative improvement to that attempt: 1.0 for attempt 1,
  then the product of `global_improvement_ratio` medians (gap bucket `all`, ≥ 30 transitions) of the transitions before
  it, attempts beyond 4 as 4; a piece count without enough transitions uses the 500-piece ratios, then 1.0;
- **residual** = ratio ÷ form ÷ L (not minus 1: 1.0 = exactly your usual, 0.9 = 10 % faster). Repeats are not "strengths" just
  because everybody is faster on a second go.

| Axis | Raw score | Results used | Min | Better |
|---|---|---|---|---|
| Speed | median ratio | last 365 days, every attempt | 5 | lower |
| Improvement | 1 − median(form, last 45 days) ÷ median(form, 335-395 days ago) | results with a form in each window | 3 in each | higher |
| Consistency | q75 ÷ q25 of residuals (linear interpolation) | last 365 days | 10 | lower |
| Learning | 1 − median over puzzles of (t₂ ÷ t₁) × (form₁ ÷ form₂) | puzzles with attempts 1 and 2 (both boxed, both with a form), all time | 3 puzzles | higher |
| Hard puzzles | median residual | last 365 days, difficulty tier Hard / Very hard (≥ 5, current tier) | 5 | lower |
| Big puzzles | median residual | all time, 1000+ pieces | 5 | lower |
| Events | median residual | all time, linked to an event (`competition_id` or `competition_series_id`) | 5 | lower |

**Percentile** per axis against every puzzler with a score on that axis (private players included - the card is
self-only and a rank names nobody): scores rounded to 6 decimals, oriented so higher = better, then
100 × (players with a worse score + half of the others with the same score) ÷ (players − 1); 50 when only one player
has a score. Speed, consistency and the three pace axes count lower raw scores as better.

**Production copy (medians):** speed 1.155 (p10 0.838, p90 1.708), improvement 0.060, consistency 1.168, learning
0.102, hard 1.012, big 0.999, events 1.009. Scores: speed 3,187 players, consistency 2,529, learning 2,680, hard 920,
improvement 692, events 628, big 552.

## 7. Rules for readers

- `player_insights_profile` is **self-only**: only the player sees their own row (private players included).
- `player_rating_entry`, `puzzle_rating_times`: never list other players; a puzzle is shown only when it is not secret
  (`hide_until` / `hide_image_until`), its image only when `hide_image_until` has passed. Entries exist for secret
  puzzles (they count in the rating); a what-if needs §4, which never has them.
- Coordination constants are aggregates; they name nobody.

## 8. Not stored: distinct solvers per puzzle

"#12 of 340" is the player's rank on the puzzle's leaderboard: `GetRanking::allForPlayer()` / `forPuzzles()` already
return `rank` and `total_players` (89 ms for the heaviest profile, a few ms per page of rows). The count depends on the
viewer (blocked players and the private-profile allow list), so a batch-stored count would differ from the leaderboard
the player opens. `puzzle_rating_times.solver_count` is the rating's count (public first attempts) - not this one.

## 9. Batch cost (production copy, solves to 2026-10-11, laptop)

| | Before 4a | With 4a |
|---|---|---|
| 15-min run, steady (nothing changed) | ~12.1 s, ratings step 1.76 s | ~13.3 s, ratings step 3.04 s (+1.3 s: scoring the 4,649 players not on the ladder too +0.2 s, reading and comparing 291k stored entries +1.1 s, times +0.04 s) |
| First run of a day (decay roll) | - | +1.7 s more (245k entry updates, ~100 MB WAL) |
| First run ever | - | ratings step 5.9 s (inserts 291k entries + 2,560 time rows) |
| Daily command | - | ~2.4 s (coordination 0.4 s, profiles 2.0 s, peak 133 MB) |

Measured by alternating runs of `origin/main` and this branch on the same copy, `APP_ENV=prod`. Production runs ~10 %
faster than the laptop. If the 15-min budget gets tight: a per-player digest of the entries (compare 5k digests instead
of 291k rows) would save ~1 s of the steady state.

## Rating, pairs, improvement, shelf, cache (stream 4c)

The lazy parts of the page (plan §4.3 / §4.4): each is one builder in `src/Services/Insights/`, computed on read from
the batch tables above and the player's own data, pure PHP after a fixed number of statements, cached by
`InsightsCache` (§4c.8). Nothing here is stored and there is no migration. Members-only gating is the page's job
(Phase 6), except the shelf picks, which carry their own lock (README: "Shelf picks are members only").

| Part | Builder → result | Statements | Production copy, worst of the 7 heaviest players |
|---|---|---|---|
| Pairs & teams | `PairsTeamsSynergy::build(playerId, from, to, pieces)` → `PairsTeamsSynergyResult` | 4 | 20 ms |
| Rating opportunities | `RatingOpportunities::build(playerId, piecesCount = 500)` → `RatingOpportunitiesResult` | ≤ 8 (1 private) | 55 ms |
| Rating forecast | `RatingForecast::build(playerId, piecesCount = 500)` → `RatingForecastResult` | ≤ 10 | 62 ms |
| Both in one part | `RatingModelLoader::load()` once, `RatingOpportunities::fromModel()` + `RatingForecast::forModel()` | ≤ 10 | 63 ms |
| Re-solve radar | `ResolveRadar::build(playerId, pieces)` → `ResolveRadarResult` | 4 | 27 ms |
| Improvement curve | `ImprovementCurve::build(playerId)` → `ImprovementCurveResult` | 1 | 15 ms |
| Rating over time | `RatingOverTime::build(playerId, from, to)` → `RatingOverTimeResult` | 1 | 3 ms |
| Shelf picks | `ShelfPicks::build(playerId, isMember, pieces)` → `ShelfPicksResult` | ≤ 5 (1 for free players; was ≤ 7 before 6c) | 47 ms (before 6c) |
| Cache key | `InsightsCache::keyFor(playerId)` | 2 | 1.5-5.6 ms (fingerprint ~1-3 ms) |

Budgets are pinned by a statement-count test per part; times in §4c.11.

**Seam for 4b's `InsightsCriteria` (Phase 6 wires it):** the builders take plain typed inputs - the player id, a
period as `from` (inclusive) / `to` (exclusive) `DateTimeImmutable`, a `Value\PiecesRange`, `isMember` - so the
criteria feed them as `->build($playerId, $criteria->from(), $criteria->to(), $criteria->pieces())` (names as 4b
defines them). The rating parts take no period: the rating is defined over its own 24-month window. Members-only
filters stay stripped by the criteria.

### 4c.1 Pairs & teams synergy

"Who do I solve best with, and how?" - `GetInsightsGroupResults` (one statement) reads every pair/team result of a
`puzzling_team` the player is a member of (`puzzling_team_member`, so results someone else tracked count), not
suspicious, timed, with all members and the puzzle's difficulty (more than insufficient confidence, else none). Then
the members' pace history (`PaceTimelineLoader::forPlayers()`, one statement) and the constants (§5).

- **Per result:** expected = c(size, first try / repeat) × difficulty ÷ Σ 1/paceᵢ with every pace **as of the solve**
  (`GroupExpectation`, the rule of the fit in §5); ratio = actual ÷ expected. Scored only when nobody is a guest, every
  member has a pace then, the puzzle is rated, boxed and not secret.
- **Per pair/team** (one `puzzling_team` = the exact set of people): `Value\ShrunkEffect` of the ratios - the median of
  the log ratios shrunk by n / (n + 8), its 95 % range from the order statistics of the median (never a bootstrap),
  shrunk alike - and a verdict (`Value\SynergyVerdict`): `great_match` when the whole range is beyond 1.5 % faster,
  `faster_apart` beyond 1.5 % slower, `as_expected` otherwise, `not_sure_yet` below 5 scored results, `no_solo_pace`
  with a guest (listed with its results count, never a score). `speedVsAlone` = median of (the player's pace ×
  difficulty) ÷ the time together ("together 1.8× as fast as you alone").
- **Like with like:** `pairs` (size 2) and `teams` (3+) are separate lists, best effect first, unscored ones last by
  results; `bestPairResults` / `bestTeamResults` = the 5 results most under expected. Solo results never enter.
- **People:** a private member the viewer may not see (`PrivateProfileAccess`) is `hidden` - player id, name and code
  null, shown as a hidden puzzler (the id stays inside the query result, `InsightsGroupResults::$memberIdsByTime`, for
  the pace lookup only); a pair/team with a member **the viewer blocks** is left out of everything here (the player's
  own history keeps such results - HiddenPlayers - but a "who you solve best with" list never names a blocked person).

### 4c.2 Rating opportunities

"Where can I gain MSP rating?" at a rated piece count. `RatingModelLoader` reads (≤ 8 statements): the player's solved
puzzles of the piece count, all time, aggregated the way the rating takes them, with their stored entries (§3) and
`puzzle_rating_times` flags (`GetInsightsRatingInputs::solvedPuzzles()`); the unsolved shelf (§4c.7); the time lists
(§4) of the rated solved puzzles (best times only) and of the shelf and the pending ones (both lists); the predictions
(`GetPlayerPredictions::forPuzzles()` - personal for solved puzzles, statistical for the shelf, list scale); the
ladder's other ratings (the pool of `GetPlayerRatingRanking`: public, not opted out, hidden players closed up).
`Model\RatingModel::build()` is pure arithmetic over that:

- **Current rating** = the stored entries' points, best 100 per portfolio, `MspRatingFormula::rating()` - equal to
  `player_elo` (pinned). On the ladder = ≥ 20 first-attempt entries, ≥ 20 first attempts and ≥ 50 results at the piece
  count in the window (`MspRatingCalculator` rules). The privacy and opt-out flags come from the player's row (the
  solves are LEFT JOINed to it), so they are known without any solve: a private player has no rating
  (`standing = private`, nothing else, one statement); a player **opted out of rankings** (`ranking_opted_out`,
  docs/features/opt-out.md) gets `standing = opted_out` - the rating, its entries and moves stay, but no place, no
  `placeAfterBestTen`, no path onto the ladder, no ladder milestone in the forecast and no "on the ladder" in its points
  (and the ladder's ratings are not even read).
- **Re-solve** (`resolve`): every rated solved puzzle, expected time T = the personal prediction; new best b′ = min(b,
  T), percentile with the own b replaced by b′ in the best times (`Model\RatingWhatIf::percentileReplacing()`, binary
  search; an own time missing from the list - solved after the last batch run - is inserted instead), points =
  percentile × weight × 1.0 (a fresh solve: the decay starts over - a re-solve is worth something even at the same
  time). **Defend** (`defend`) = a re-solve of a best-time entry leaving the window within 60 days (`daysUntilLeaving`).
- **First try from the shelf** (`first_try`): every unsolved shelf puzzle that counts (qualifying list), T = the
  statistical prediction, inserted into both lists (`percentileInserting()`), a new entry in both portfolios;
  `fasterThanShare` = its first-attempt percentile ("faster than 84 % of its solvers"). Also a puzzle **one solver short
  of counting** (19 public first-attempt solvers and a difficulty, `qualifies = false`): the player's first try makes
  it count (`makesPuzzleCount`), and the gain is that of the newly counting puzzle (pinned: the stored entry afterwards
  has exactly the move's percentile).
- Each gain = rating after the one change − now, in ladder points (× 1000), the mean after one change in O(1)
  (`Model\PortfolioScore::meanWith()`, equal to a rebuild - pinned); moves below 0.05 points are dropped.
  `gainPerHour` = gain ÷ expected hours. The result keeps the 30 best by gain and the 30 best by gain per hour
  (`movesCount` = all found).
- **Best 5 / 10 together** (`bestFiveGain`, `bestTenGain`): those moves applied at once and the portfolios rebuilt -
  not the sum of the gains (they share the best 100). `placeAfterBestTen` = that rating's place on today's ladder.
- **Pending qualification:** solved puzzles with 9-19 public first-attempt solvers and a difficulty: the gain once it
  counts at today's percentile and decay, `solversNeeded` = 20 − solvers.
- **Path onto the ladder** (`ladderPath`) only when within reach: not on the ladder and at most 10 new results away
  (`standing = near_ladder`; first tries on counting puzzles count for both thresholds), with what the entries are
  worth today and that place. Further away (`off_ladder`) the moves are still computed, no path.
- **Portfolio strips:** every entry with its points, `counts` (in the best 100) and `leavesAt`; a secret puzzle's entry
  is listed with `puzzle = null` (it counts, it is not shown). `entriesLeavingWithin60Days` counts both portfolios.
- **Exactness, pinned by `RatingOpportunitiesTest`:** a re-solve's gain equals what the batch computes once that solve
  is really there (1e-9); a first try's percentile equals the stored entry's afterwards (its weight moves with the
  puzzle's new difficulty, so the gain is a close estimate there).

### 4c.3 Rating forecast

"Where is my rating going?" - the model of §4c.2 plus the player's pace points at the piece count (one index range)
and activity (`GetInsightsActivity`: all results, the last 182 days, the last 91 days' solo boxed times; one
statement). Months 0-6, the rating at "now + k months" in three scenarios (`Value\RatingForecastScenario`):

- `stop`: today's entries, each decayed to that moment (`MspRatingFormula::decay()`, memoised per day and month by
  `Model\DecayTable`; "now + k months" is the same day k months on, the month's last day where it has none - never
  "+1 month" drifting 31 Jan into March), dropped once out of the window;
- `keep_pace`: plus the last 6 months' rated first tries again, a sixth of them per month (placed mid-month; each a new
  first-attempt and best-time entry at its raw points) - the prototype's projection;
- `best_moves`: keep pace plus the 10 best moves of §4c.2 done in the first month (re-solves replace their best-time
  entry, first tries add both).

Month 0 is today's rating in all three. Every point says whether the player would be on the ladder then (counts in the
window, with the replayed results). Milestones:
- `ladder`: the next milestone place above today's (`LeaderboardPosition::RANK_MILESTONES`), the rating it takes today
  (the rating of the player at that place) and the gap, and the first scenario whose month-6 rating reaches it - against
  today's ladder (everybody else ages too; said in the page);
- `pace`: the pace at the year's end (31 Dec; next year's within 31 days of it) if the last 12 months' log trend of the
  pace history goes on (`changePerYear`);
- `results`: the next round results count (steps 50 / 100 / 250 / 500 by size) and when at the last 6 months' rate;
- `targetTime`: the next 5-minute time below the pace and the share of the last 91 days' results under it, now and in 6
  months if the times follow the pace trend.

### 4c.4 Re-solve radar

Puzzles not done for ≥ 12 months - "last solved" counts **any** result of the player on the puzzle (solo or a
pair/team through `puzzling_team_member`, boxed or not, timed or not, suspicious included: when they last did it) - with
the best time and attempts from the results the personal predictions are built on (solo, not suspicious, timed, boxed;
one statement with the on-shelf flag and the puzzle's solo count), whose personal
prediction for the next attempt (`GetPlayerPredictions::forAllSolvedPuzzles()`, three statements, shared with every
other part of a request) is at least 5 % under the best: `likelyGain` = 1 − predicted ÷ best, with the prediction's
range, months since, on shelf. Biggest gain first; `onShelfCount`. Stream 3b's model improvements arrive through
`GetPlayerPredictions` unchanged.

### 4c.5 Improvement curve

From `player_pace_history` (one statement, with the solve and puzzle of every row), per piece count, most first tries
first:
- `points`: the pace after each qualifying first attempt, one per day (the day's last), x = date or first tries;
- `biggestJumps`: rows whose step moved the pace ≥ 3 % faster at once (`step ≤ ln 0.97`), biggest 5 with the solve, its
  time, the puzzle (null when secret), pace before / after; `jumpsCount` of `stepsCount`;
- `plateau`: the pace within ±2 % of today's for the last 20+ first tries spanning 60+ days (`since`), else null;
- at 500 pieces the typical newcomer's curve from the same first pace (`ImprovementCurve::TYPICAL_500`: −4.5 / −7.6 /
  −12.2 / −15.0 / −21.0 % after 25 / 50 / 100 / 150 / 300 first tries, prediction-research.md §4; log-linear between,
  nothing beyond 300) and `typicalChange` at the player's count next to `changeSinceFirst`.

### 4c.6 Rating over time

`player_rating_snapshot` as it is (README decision: kept), the player's rated piece counts, rows with a rating (one
statement over `(player_id, snapshot_date)`): per day the rating (× 1000), the stored place, the change against the
previous stored day and the days between (snapshots skip days), the change over 30 / 90 days (against the last day at
least that far back), the biggest rise and drop. Optional period.

### 4c.7 Shelf picks

`GetInsightsShelf::unsolved()` (one statement): puzzles in any of the player's collections (the system one included)
and not lent out, or borrowed (the Picker's "mine", `sqlOnShelf()`), with **no result of the player of any kind** (solo
or in a pair/team, suspicious or untimed included), never secret. Free players get `locked` + the count. Members: the
expected time of every rated one (statistical = pace × difficulty; since 6c from `GetPersonalEstimates`, one statement,
the same number as every other surface - estimates.md), and
`fasterThanShare` against the puzzle's public first-attempt times - the rating's stored lists where a puzzle has one,
the rest aggregated from the results (`firstAttemptTimes()`, one statement for the rest only) - from 5 solvers on.
Best place first (higher share), then the shorter expected time; 12 shown, `withExpectedTime` counted.
**Picture-only estimates (6c):** `ShelfPicks::estimates()` gives a shelf puzzle nobody has solved (k = 0) its
picture-only estimate where `ImageEstimateGate` passed for its size group × image kind (`ShelfPickSource::Image`,
`isEstimated()`); puzzles with 1-4 indices stay out as before ([`estimates.md`](./estimates.md)).

### 4c.8 Cache

Pool `insights_cache` (`config/packages/cache.php`) on the **cache Redis** (`cache.app`, `REDIS_CACHE_DSN`): the page's
parts are disposable - a miss is one recompute - so never redis-state. Tests use an array adapter. `InsightsCache`:

- `keyFor(playerId)` → `Value\InsightsCacheKey` (2 statements): the generation (`InsightsGeneration::current()`), the
  player's fingerprint (`GetInsightsFingerprint`: one statement summing `hashtext()` of every own result - solo, and
  pair/team results through `puzzling_team_member`, whoever tracked them - over id, puzzle, seconds, dates, suspicious,
  unboxed, first try, type, team, event links; their pairs/teams as shown - the team name and every member's key, id,
  name, code and viewer-aware private flag, so a teammate turning private (or public) or a rename is never served from
  the cache; their collection items and lending; any add, delete, edit or flag set by SQL changes it), and the viewer (a hash of their blocks and private-profile allow list, "all" for nearly
  everybody).
- `get(key, part, context, compute)`: key `insights.<part>.<player>.<generation>.<fingerprint>.<viewer>.<context hash>`;
  the context carries the part's own inputs (filters, membership). TTL 45 min: the 15-minute batch moves the generation,
  so an older entry is simply never asked for again.
- `getForGeneration(part, playerId, context, compute)`: generation + viewer only (1 statement) - for parts that depend
  on the player's data only through the batch (4b's close calls, "cached per generation").
- No state in the service (FrankenPHP worker mode). Pinned by `InsightsCacheTest` (an edit, a suspicious flag, a pair
  result tracked by someone else, a collection item, the generation and the viewer each make a new entry; another
  player's change does not).

### 4c.9 Visibility

Self-only: every builder reads the player's own data. Other players appear only as aggregates (time lists, the ladder's
ratings for "which place", solvers' shares) or as the player's own teammates (named per `PrivateProfileAccess`,
blocked ones left out). Secret puzzles (`hide_until` / `hide_image_until` in the future) are never suggested or named:
`InsightsPuzzleRef::fromRow()` answers null for them, moves / picks / radar / pending / best group results skip them,
jumps and portfolio entries show the number without the puzzle; they still count in the rating (§3).

### 4c.10 DTO shapes for Phase 6

All `readonly` in `src/Results/Insights/`; rating numbers in ladder points (× 1000), shares 0-1, changes as fractions
(−0.05 = 5 % faster), times in seconds, puzzles as `InsightsPuzzleRef` (id, name, image, brand name, pieces).
- `PairsTeamsSynergyResult`: `pairResultsCount`, `teamResultsCount`, `pairs` / `teams` (`GroupSynergy`: team id + name,
  size, `partners` (`GroupResultMember`: player id / guest name / name / code / `hidden` / `blocked`), results and
  scored counts, `effect` (`Value\ShrunkEffect`: n, effect, low, high), `verdict` (great_match / as_expected /
  faster_apart / not_sure_yet / no_solo_pace = a guest in it), `speedVsAlone`), `bestPairResults` /
  `bestTeamResults` (`GroupResultScore`: the `InsightsGroupResult`, expected seconds, ratio).
- `RatingOpportunitiesResult`: piece count, `standing` (`Value\RatingStanding`: on_ladder / near_ladder / off_ladder /
  opted_out / no_entries / private), rating, place, ladder size, the two
  averages, entries leaving within 60 days, `movesCount`, `moves` / `movesByGainPerHour` (`RatingMove`: kind, puzzle,
  expected seconds, current best, gain, gain per hour, days until leaving, solvers, faster-than share, on shelf, makes
  the puzzle count),
  `pending` (`PendingQualification`), best 5 / 10 gains, place after the best 10, `ladderPath` (`LadderPath`),
  `firstAttemptEntries` / `bestTimeEntries` (`RatingEntryView`: puzzle or null, seconds, solved, points, counts,
  leaves at).
- `RatingForecastResult`: piece count, standing, first tries a month, `lines` (`RatingForecastLine`: scenario, `points`
  = `RatingForecastPoint` (months ahead, date, rating, on ladder), place at the end), entries leaving, milestones
  `ladder` / `pace` / `results` / `targetTime` (each nullable).
- `ResolveRadarResult`: `candidates` (`ResolveCandidate`: puzzle, best, last solved, months since, attempts, predicted
  + range, likely gain, on shelf, solo results count), `onShelfCount`.
- `ImprovementCurveResult`: `sizes` (`ImprovementCurveSize`: piece count, `points` (`PacePoint`), first pace, pace,
  first tries, change since first, typical change, `typicalCurve` (`TypicalPacePoint`), `biggestJumps` (`PaceJump`),
  jumps / steps counts, `plateau` (`PacePlateau`)).
- `RatingOverTimeResult`: `series` (`RatingOverTimeSeries`: piece count, `days` (`RatingDay`: date, rating, place,
  change, days since previous), change over 30 / 90 days, biggest rise / drop).
- `ShelfPicksResult`: `locked`, unsolved on shelf, with expected time, `picks` (`ShelfPick`: puzzle, expected + range,
  source, faster-than share, solvers, tier, median solo).

### 4c.11 Measurements (production copy, solves to 2026-10-11, laptop)

On a scratch copy of the dev database with 3a + 4a run on it, `APP_ENV=test` without debug, the 7 heaviest players
(most solo results 2,327 and 2,036; most rating entries 1,131; most pair/team results 717 ×2; biggest collections
1,722 and 1,351 items, 855 unsolved on a shelf), each part from cold PHP state (services reset, the request-scoped
prediction memo empty), median of three warm runs:

| Part | Range over the 7 players | Worst player |
|---|---|---|
| Rating opportunities | 24-52 ms | most solo (488 moves, 137 pending) |
| Forecast | 34-62 ms | most solo |
| Rating + forecast in one | 32-63 ms | 2nd collection |
| Re-solve radar | 1-25 ms | most solo (1,297 candidates) |
| Pairs & teams | 3-18 ms | most rating entries (27 pairs, 18 teams) |
| Improvement curve | 2-11 ms | most solo (10 piece counts) |
| Rating over time | < 1 ms | - |
| Shelf picks | 1-39 ms | 855 unsolved shelf puzzles, 610 with an expected time |
| Cache key (generation + fingerprint) | 1.5-2.5 ms | - |

The first build of a request with a cold Postgres buffer cache reached ~100 ms for the rating; the machine shared its
CPU with other streams' batch runs (earlier measurements under that load were up to 2× slower). Two fixes came out of
measuring: the shelf's "nothing done with it yet" check is one materialized set (a per-puzzle team lookup took 2-6 s
for an 855-puzzle shelf), and re-solves read only the best-time lists (half the JSON to decode).

## Core sections (stream 4b: read models and builders)

The first response of the Insights page: four statements whatever the player's size, then pure PHP
(`InsightsPageLoader::load()` → `InsightsBuilder::build()` → `InsightsReport`, + the hydrate → `InsightsPage`). Nothing
here is wording: sections return numbers, sample sizes, codes and a `notSureYet` flag; Phase 6 turns them into sentences
(neutral wording, README §6). Measurements: §11.

### 1. Filters (`Services\Insights\InsightsCriteria`)

`InsightsCriteria::fromUserInput(array $query, bool $isMember, bool $hasTimezone = false)` - the URL is the state, like
Compare's `ComparisonCriteria`: invalid values fall back to the default, members-only values are dropped silently and
named in `strippedForMembers` (the page shows the lock). `toQueryParameters()` gives only what differs from the defaults
(the URL), `cacheKey($timezone)` a short stable key (membership and the player's time zone included - solve days,
weekdays and the time of day depend on it). Within one multi-value filter the values are
alternatives, different filters all apply.

| Key | Values | Free |
|---|---|---|
| `period` | `3m`, `6m` (free) · `12m`, `24m` (members' default), `year` (this year), `past_year` + `year=YYYY`, `all`, `custom` + `from` / `to` (`Y-m-d`, swapped when reversed) | 3 / 6 months, default 6 |
| `pieces` | `PiecesRange` grammar (`500`, `300-1000`, `1000-`) | yes |
| `results` | `all` / `first` / `repeat` | - |
| `brands[]` | manufacturer ids (≤ 20) | - |
| `difficulty[]` | tiers 1-6, `0` = not rated yet | - |
| `events`, `unboxed`, `shelf` | `any` / `only` / `exclude` (at an event / without the box / the puzzle is in one of your collections) | - |
| `weekdays[]` | ISO 1-7 of the solve day | - |
| `personality[]` | `rewards_skill` (skill sensitivity ≥ 1.8), `equaliser` (< 1.3), `box_helps` (box dependence ≥ 1.4), `memorable` (memorability ≥ 1.4) - the puzzle page's own "high" / "low" levels | - |
| `traits[]` | `InsightsImageTrait`: the `PuzzleTagGroup::ImageTraits` keys of `puzzle_tag` (5a), photo styles = `photo`, drawn styles = `drawn` | - |
| `timeOfDay[]` | `morning` 5-12, `afternoon` 12-17, `evening` 17-22, `night` - only with a time zone (dropped without one), "when you logged" | - |

Period bounds are midnight UTC (solve days are stored as UTC dates), relative periods start at midnight - the page is
the same all day - on the same day N months back, clamped to that month's last day (31 Aug − 6 months = 28/29 Feb,
never 3 Mar). `matchesPuzzle()` = the puzzle filters (pieces, brand, difficulty, shelf, personality, traits);
`matchesResult()` = those + results, events, unboxed, weekday, time of day; `inPeriod()` the period.

### 2. Solo results (`GetInsightsSoloResults::forPlayer()`)

One statement: the player's solo results + puzzle + `puzzle_statistics` (median, solo count) + `puzzle_difficulty` (tier,
score, confidence, sample size, `sum_log_indices`, the five derived metrics) + the stored prediction (method, seconds,
range, attempt, model version) + on-shelf (`collection_item`, any collection, a MATERIALIZED CTE) + the image-trait tag
keys (`puzzle_tag`, a correlated `json_agg` limited to the registry's keys) + the manufacturer name. Oldest first
(`COALESCE(finished_at, tracked_at)`, `tracked_at`, id). Served by `custom_pst_player_puzzle_type` (player first).

- **Left out:** suspicious results (Insights is statistics, and no statistic counts them - suspicious-times.md; the
  player's own results page still lists them), results without a time (`seconds_to_solve` 0 / null), secret puzzles
  (`hide_until` / `hide_image_until` in the future - no aggregate for anything secret, README §6; the profile card's
  rule). A player cannot record a time on a competition-secret puzzle before its reveal anyway (`SecretPuzzleAccess`);
  this covers the hand-hidden placeholders and a picture hidden later.
- `attempt` = 1-based number among what is left, by solve moment then logging moment (the profile card's numbering).
- **Self-only:** the owner's own rows, private players included; no other player is read.
- `InsightsSoloResult::ratio()` = time ÷ the puzzle's median solo time, null while the median rests on < 5 solo times.

### 3. Close calls (`GetCloseCalls::forPlayer()`)

One statement, per puzzle the player has a solo time on: their best, their place and the times of the places above.
It keeps **only the times faster than the player's best**: a place is 1 + the players whose best is faster (ties share),
and the time of place N is the N-th fastest of their bests. Per puzzle a LATERAL over `custom_pst_solo_board` (index
only): `seconds_to_solve` is an INCLUDE column, not a key, so the probe still reads every index entry of the puzzle -
what shrinks is the grouping, numbering and sorting, which only the players ahead go through. Then `COUNT(*)`, `MAX` =
the next place, `MAX … FILTER (WHERE position = N)` for N in `LeaderboardPosition::RANK_MILESTONES` (1, 3, 10, 25, 50, 100, 250, 500,
1000, 2500, 5000).

- **The pool is GetRanking's** (the puzzle page's): best non-suspicious solo time > 0 per player, the viewer's hidden
  players left out (`HiddenPlayers`), private players only when the viewer may see them (`PrivateProfileAccess`) or the
  player themselves. The players outside the pool are one **MATERIALIZED CTE** (`left_out` = NOT (public-or-self AND
  not hidden)) probed by `NOT IN` - inlined into every lateral probe the plan crossed the JIT threshold (+290 ms).
- **Parity:** the places equal `GetRanking::allForPlayer()` for every fixture player, without a viewer, as themselves,
  with a block and with a private profile revealed by its allow list (`GetCloseCallsTest`).
- Secret puzzles are left out. Close calls name places, never people.

`CloseCallsBuilder`: per ranked puzzle passing the **puzzle filters only** (a place is about the puzzle, not about when
you solved it), target = the nearest milestone place above yours, `targetGapSeconds` = best − its time (tie it and you
reach it), `targetGapShare` = that ÷ your best, `nextPlaceGapSeconds` = best − the next place's time ("#12, 4 s from
#11"). Sorted by share; the closest 8; `closeCount` = under 3 %; first places, podium (≤ 3), top 10, puzzles.

### 4. Context and hydrate

- `GetInsightsContext::forPlayer()` - one statement: `insights_generation` (0 = none), the player's
  `player_insights_profile` row (7 axes: score, sample size, percentile), `player_improvement_ratio`, every
  `global_improvement_ratio` row of gap bucket `all` (small) and the 500-piece `player_elo` rating.
- `GetInsightsPuzzles::byIds()` - one statement for `InsightsReport::$puzzleIdsShown` (≤ 50): name, other names
  (`PuzzleNames`, for `puzzle/_name.html.twig`), brand, pieces, image + ratio; secret puzzles left out.

### 5. Prepared rows (`InsightsRowsPreparer`)

Once per build, over **all** results (filters come after - your usual at a moment is your usual whatever the page
filters), the same definitions as the profile card (§6):
- `ratio` = time ÷ puzzle median; `form` ("your usual") = the median ratio of your **boxed first tries** within ±75 days
  (`InsightsMath::rollingMedian()`, one sorted window sliding along both lists - O(n·w); the profile card now uses the
  same function); `residual` = ratio ÷ form ÷ the community learning factor (`LearningFactors`, the profile card's rule,
  now shared too). 1.0 = exactly your usual, 0.9 = 10 % faster.
- Solve day = the date you gave (`finished_at`), else the logging day in your zone (UTC without one); weekday of it;
  `daysSincePrevious` (to the latest earlier solve day, solo results only); `sameDayIndex`.
- Time of day = `tracked_at` in `player.timezone` - only with a zone and only when the logging day is the solve day.
- Situations ("why" chips, skill map): first try / repeat, at an event, weekend, after a break (> 14 days), second of the
  day, unboxed.

### 6. Splits (`SegmentEffect`) - skill map, habits, pairs later

For a group of residuals: the median of the log residuals **shrunk by n / (n + 8)**, with the **order-statistic range**
of that median (distribution free: the sorted logs at the 1-based ranks floor((n − 1.96·√n) / 2) and
ceil(1 + (n + 1.96·√n) / 2) - symmetric around the median, n = 10: 1 … 10, n = 25: 7 … 19 - shrunk the same way;
never a bootstrap: same answer every time, O(n log n)). `SegmentEffect` is 4c's `Value\ShrunkEffect` plus the verdict. Shown from 5 results; a verdict only from **10**: strength when
the whole range lies below −1.5 %, weak spot above +1.5 %, else about usual; under 10 "not sure yet".

**Skill map** (`SkillMapBuilder`): groups brand (the 9 with most results; label = brand name), piece count (only with ≥ 2
classes in the selection), difficulty (tier, 0 = not rated yet), situation, personality (§1) and image traits (§1). Each
segment carries its 10 fastest results against your usual (`drillDownTimeIds` - hydrate them when the drill-down opens).
Strengths / weak spots: every segment with that verdict, clearest first. **Image traits are a preview group** until
the picture backfill is complete (`SkillMapBuilder::IMAGE_TRAITS_ARE_PREVIEW`): shown, never a strength, weak spot or
finding.

### 7. Expected vs actual and prediction accuracy

- **Expected** (`ExpectedVsActualBuilder`): every result of the selection with a stored prediction. Predictions of the
  calibrated model (`prediction_model_version` ≥ `InsightsAccuracySection::CALIBRATED_MODEL_VERSION` = 3, stream 3b)
  are taken as they are. Older ones ran ~10 % slow on first tries (the age bias), so expected = prediction × your usual
  ratio to them: the median actual ÷ predicted of that method's older predictions in the selection (from 5 on, else
  1.0) - "your margin" built in (`firstTryMargin` = 1 − the statistical factor). Better / worse = beyond ±10 %; the 5
  biggest each with their situations.
- **Prediction accuracy** (`PredictionAccuracyBuilder`): the hit rate of the likely windows, **only predictions of model
  version ≥ 3** (their stored range is the calibrated 50 % window; older ranges promised nothing) - "not enough yet" under
  10. Also faster / slower than the window, the median margin, first tries vs repeats, and how many older predictions
  were not counted. When 3b adds `predicted_range80_*`, the 80 % hit rate is one more counter here.

### 8. The other sections

| Section | Builder | Rows | Definition | Not sure yet |
|---|---|---|---|---|
| Summary tiles | `SummaryBuilder` | selection; trend: without period | speed = median ratio; trend = median ratio of the last 182 days ÷ 365-547 days ago − 1; consistency = q75 ÷ q25 of residuals | speed < 5, trend < 5 in either window, consistency < 10 |
| Form | `FormBuilder` | selection (the period's own results) | months of the period, from your first result in the selection and never past today (≥ 4, ≤ 60): median, q25, q75, best result (≥ 3 results), your usual = the median of the month's results' own `InsightsRow::form` (the baseline every residual divides by); change = last median − first | < 2 months with a median |
| Histogram + outliers | `HistogramBuilder` | selection | 24 bars of 5 % faster/slower than the median solver, ±60 % (end bars hold the rest); outliers = residual > 1.3 or < 1 ÷ 1.3, 4 each | < 5 |
| Learning curve | `LearningCurveBuilder` | puzzle filters, all time, boxed | you = cumulative `player_improvement_ratio`; community = `LearningFactors` for the selection's piece class (500 for several); in selection = median of t_N ÷ t_1 × usual₁ ÷ usual_N per attempt (≥ 3); the 6 puzzles you improved on most | neither your attempt 2 nor the selection's |
| Habits | `HabitsBuilder` | selection | per weekday, per break bucket (0, 1, 2-7, 8-14, 15+ days), per time of day (with a zone, else null): count + `SegmentEffect` | no results |
| Consistency | `ConsistencyBuilder` | selection | spread = q75 ÷ q25 of residuals, ± = (spread − 1) ÷ 2, last 182 days vs before, the card's percentile | < 10 |
| Profile card | `ProfileCardBuilder` | the stored card (§6), unfiltered | 7 axes, strongest = highest percentile, room to grow = lowest (≥ 2 known axes) | no percentile |

### 9. Findings and the puzzles shown

`FindingsBuilder`, ≤ 8, in page order: `speed` (share faster than the median solver), `form_trend`, `strength` and
`weak_spot` (the clearest segment), `close_call` (the closest: puzzle, target place, seconds), `best_result` (the result
furthest under your usual, from 10 residuals on), `surprises` (members: results > 10 % better than expected + the
biggest). Free players get every finding but `surprises` (a lock with its count). Rating moves and shelf picks come from
stream 4c's builders. `puzzleIdsShown`: findings, close calls, outliers, surprises, learned most, the months' best -
deduplicated, ≤ 50, most prominent first.

Free vs members (README §3, `InsightsSection::isFree()`): the builder computes **everything for everybody** so a lock
can show real counts; a template must not render a members-only section's content for a free player. The criteria
already restrict a free player's selection to 3 / 6 months and pieces.

### 10. Result shapes for Phase 6 and 4c

All `readonly` plain data in `src/Results/Insights/` (serializable - the 4c page cache stores the report):

- `InsightsPage { report: InsightsReport, puzzles: array<puzzleId, InsightsPuzzle> }`
- `InsightsReport { playerId, isMember, criteria (applied query params), builtAt, generation,
  hasTimezone, summary, findings[], profileCard, form, skillMap, expectedVsActual, histogram, closeCalls, learningCurve,
  habits, consistency, predictionAccuracy, puzzleIdsShown[] }`
- `InsightsSummary { resultCount, ratioCount, speedRatio?, trendChange?, trendRecentCount, trendOlderCount,
  consistencySpread?, consistencySampleSize }`
- `InsightsFinding { code: InsightsFindingCode, section: InsightsSection, membersOnly, value?, count?, puzzleId?,
  timeId?, segment?: InsightsSkillSegment, rank?, seconds? }`
- `InsightsProfileCardSection { axes[]: InsightsProfileAxisScore{axis, score?, sampleSize, percentile?}, strongest?,
  roomToGrow?, computedAt?, notSureYet }`
- `InsightsFormSection { months[]: InsightsFormMonth{month 'Y-m', count, medianRatio?, q25Ratio?, q75Ratio?, usual?,
  bestTimeId?, bestPuzzleId?, bestRatio?}, firstMedianRatio?, lastMedianRatio?, change?, sampleSize, notSureYet }`
- `InsightsSkillMapSection { groups[]: InsightsSkillGroupResult{group, segments[], preview}, strengths[], weakSpots[],
  sampleSize, notSureYet }`; `InsightsSkillSegment { group: InsightsSkillGroup, key, label?, stats:
  InsightsSegmentStats{sampleSize, effect?, low?, high?, verdict: InsightsVerdict}, drillDownTimeIds[] }`
- `InsightsHistogramSection { bins[]: {fromPercent, toPercent, count}, usualPercent?, slowerThanMedianCount, sampleSize,
  slowOutlierCount, fastOutlierCount, slowOutliers[], fastOutliers[], notSureYet }`
- `InsightsExpectedSection { points[]: {timeId, expectedSeconds, actualSeconds, deviation, method?}, betterCount,
  worseCount, withinCount, sampleSize, calibratedCount, calibration[method => factor], firstTryMargin?, better[],
  worse[], notSureYet }`
- `InsightsCloseCallsSection { items[]: InsightsCloseCall{puzzleId, rank, bestSeconds, targetRank?, targetGapSeconds?,
  targetGapShare?, nextPlaceGapSeconds?}, closeCount, candidateCount, firstPlaceCount, podiumCount, topTenCount,
  puzzleCount, notSureYet }`
- `InsightsLearningSection { you[], community[], communityPiecesCount, inSelection[]: InsightsLearningPoint{attempt,
  factor?, sampleSize}, learnedMost[]: {puzzleId, attempts, firstSeconds, bestSeconds, gain}, repeatedPuzzleCount,
  notSureYet }`
- `InsightsHabitsSection { weekdays[] (Monday first), busiestWeekday?, breaks[], timesOfDay[]? : InsightsHabitBucket{key,
  count, stats}, sampleSize, notSureYet }`
- `InsightsConsistencySection { spread?, plusMinus?, sampleSize, recentSpread?, recentSampleSize, earlierSpread?,
  earlierSampleSize, percentile?, notSureYet }`
- `InsightsAccuracySection { sampleSize, hits, hitRate?, fasterThanWindow, slowerThanWindow, medianMargin?,
  firstTrySampleSize, firstTryHits, repeatSampleSize, repeatHits, olderPredictionCount, notSureYet }`
- Lists of results: `InsightsResultItem { timeId, puzzleId, seconds, solvedAt, attempt, firstAttempt, piecesCount,
  manufacturerName?, puzzleMedianSeconds?, ratio?, residual?, expectedSeconds?, daysSincePrevious?, situations[],
  imageTraits[] }`.

For 4c: `GetInsightsSoloResults` rows (`InsightsSoloResult`) and the prepared rows (`InsightsRowsPreparer`, `InsightsRow`
- ratio, form, residual) are the shared inputs; `SegmentEffect` is the split rule (pairs & teams synergy uses it on
actual ÷ expected); `InsightsCriteria::matchesPuzzle()` / `matchesResult()` / `inPeriod()` the filters.

### 11. Measured (production copy)

Scratch copy of the dev DB (production data to 2026-10-11, 523k results), migrated to this branch, production planner
settings (`jit = off`, `random_page_cost = 1.1`, `work_mem = 32MB`), laptop under load (load average 6-10 - the research's
own solo statement took 14-30 ms in the same run, 7-9 ms when it was measured quietly). Median of 7 runs, PHP timings
including hydration of the result objects; "total" = the four statements + the member build, i.e. the first response:

| Player (solo results / ranked puzzles) | solo | close calls | context | build (member / free) | hydrate | total |
|---|---|---|---|---|---|---|
| heaviest (2,292 / 2,129) | 33.0 | 32.0 | 1.5 | 34.7 / 28.6 | 0.9 | **99.8** |
| 1,661 / 966 | 23.3 | 23.3 | 1.3 | 28.6 / 16.6 | 0.7 | 77.2 |
| 1,614 / 860 | 22.2 | 40.9 | 1.2 | 26.8 / 18.4 | 0.7 | 93.7 |
| 1,561 / 295 | 20.1 | 25.6 | 1.2 | 19.7 / 11.6 | 0.6 | 68.9 |
| 1,361 / 868 | 18.5 | 27.2 | 1.2 | 11.2 / 8.2 | 0.8 | 60.5 |
| 630 / 335 (the research's worst close calls) | 10.9 | 54.7 | 1.1 | 9.7 / 6.8 | 0.5 | 77.1 |
| 305 / 108 (puzzles with the most solvers) | 4.6 | 8.1 | 1.0 | 3.9 / 2.1 | 0.5 | 18.4 |
| 160 / 140 | 3.1 | 21.4 | 1.2 | 2.4 / 1.4 | 0.6 | 28.4 |

All first responses **< 150 ms** (the plan's target; the page's hard maximum is 1 s). The solo statement alone
(`EXPLAIN ANALYZE`, warm) is 14-17 ms for the heaviest player in the same conditions - the rest of "solo" is building
2,292 result objects. The image-tag subquery costs ~1-2 ms there (production has few tags yet; it is one primary-key
probe per result). The places equal `GetRanking::allForPlayer()` on every puzzle of every measured player (0
mismatches). Peak memory of the build process 175 MB (the whole kernel; the report itself is a few hundred kB).

### 12. Monthly snapshot (`player_insights_snapshot`)

`myspeedpuzzling:snapshot-player-insights [--month=Y-m]` → `SnapshotPlayerInsights` (handled **outside** a transaction:
it only reads, one report per player - minutes - and a transaction held that long would keep the locks of everything it
read) → per player with ≥ 10 solo results in the last 12 months: the report with the members' 12-month view, no filters
and no close calls → `InsightsSnapshotFactory` JSON → chunks of 200 via `StorePlayerInsightsSnapshots` (each its own
transaction, `PlayerInsightsSnapshotWriter`: one set-based upsert) → `PrunePlayerInsightsSnapshots`: the month's rows of
every player the run did not write are deleted (one bulk DELETE) - a re-run replaces the month. A snapshot is today's
data, so `--month` accepts only the current month, or the previous one in a month's first 3 days (a late run);
anything else is refused (`InsightsSnapshotCriteria::month()`, the command and the handler).

Row: `player_id` (cascade), `month` (first day), `data` jsonb, `computed_at`; unique (player, month). `data` (version 1):
`period`, `results`, `speed`, `trend`, `form` (your usual in the latest month with results), `consistency`, `strengths` /
`weak_spots` (≤ 5 each: group, key, label, effect, low, high, n), `rating_500`, `profile` (axis → percentile). Self-only;
nothing reads it yet ("what changed since last month" later).

Cron row (lily.srv): `15 5 1 * * docker compose exec -T web php bin/console myspeedpuzzling:snapshot-player-insights`.
