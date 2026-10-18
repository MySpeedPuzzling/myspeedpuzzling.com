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

One row (id 1): `generation` (bigint) moves on by one at the end of every 15-minute run and of every daily run;
`advanced_at` is when. `InsightsGeneration::current()` is one primary-key lookup (0 = no run yet), `advance()` one
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
**expected = c × difficulty ÷ Σᵢ (1 / baselineᵢ)** (the synergy builder of 4c divides actual by that).

- **Fit (daily):** for every pair/team result that can be compared - `puzzling_team` with ≥ 2 members, **every member a
  registered player with a baseline** (`player_baseline`) at the puzzle's piece count, a puzzle with a difficulty score
  of more than insufficient confidence, boxed, not suspicious, not a secret puzzle - the ratio
  time × Σ(1/baselineᵢ) ÷ difficulty; **c = the median ratio** per size group (2 = pair, 3, 4 = four or more) and
  first try / repeat (the result's `first_attempt` flag, set only when it is everybody's first try). One statement,
  ~0.4 s.
- **Seeds** (`Value\GroupCoordination::SEEDS`, measured 2026-10): pairs 1.21 / 1.08, teams of 3 1.35 / 1.24, 4+ 1.41 /
  1.32 (first / repeat). A bucket with fewer than 30 results keeps its seed (`fitted = false`, `sample_size` = what it had).
- **Production copy:** 1.2067 / 1.0844 (12,534 / 14,140 results), 1.3504 / 1.2489 (202 / 305), 1.4096 / 1.3200 (693 / 499).
- Baselines are today's (`player_baseline`), consistent with how the synergy builder computes "expected" today. When
  4c moves expected to the pace history (as-of baselines), refit the same way with as-of baselines.
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
- **residual** = ratio ÷ form ÷ L - 1.0 = exactly your usual, 0.9 = 10 % faster. Repeats are not "strengths" just
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
