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
| Shelf picks | `ShelfPicks::build(playerId, isMember, pieces)` → `ShelfPicksResult` | ≤ 7 (1 for free players) | 47 ms |
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
expected time of every rated one (`GetPlayerPredictions::forPuzzles()`, statistical = pace × difficulty), and
`fasterThanShare` against the puzzle's public first-attempt times - the rating's stored lists where a puzzle has one,
the rest aggregated from the results (`firstAttemptTimes()`, one statement for the rest only) - from 5 solvers on.
Best place first (higher share), then the shorter expected time; 12 shown, `withExpectedTime` counted.
**Phase 5d seam:** `ShelfPicks::estimates()` is where the picture-only estimate joins for shelf puzzles without a
statistical prediction (`Value\ShelfPickSource` gets `image`, marked estimated).

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
