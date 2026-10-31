# Puzzle Insights System

## Overview

The Puzzle Insights System answers two core questions:

1. **How difficult is this puzzle?** — A normalized 6-tier difficulty score based on how players perform relative to their own pace at the time of the solve.
2. **How skilled is this player?** — A difficulty-adjusted 7-tier skill rating that accounts for which puzzles a player solves.

Additionally, the system provides:
- **MSP-ELO Ladder** — Competitive ranking (monthly + all-time)
- **Time Prediction** — Personalized estimated solving time per puzzle
- **Puzzle Personality** — Derived metrics: memorability, skill sensitivity, predictability, box dependence
- **Skill Improvement Charts** — Historical baseline tracking

## Data Filtering Rules

All calculations use the following filters:

| Rule | Details |
|------|---------|
| Solo only | `puzzling_type = 'solo'` — duo/team excluded |
| Non-suspicious | `suspicious = false` |
| Completed | `seconds_to_solve IS NOT NULL` |
| Same piece count | Comparisons only within exact piece count matches |
| Outlier ceiling | Difficulty index > 5.0 excluded from calculations |

### First Attempt Resolution

A "first attempt" for a player-puzzle pair is determined as:
1. If any solve has `first_attempt = true`, use the **earliest** one marked true
2. Otherwise, use the **oldest** solve as proxy first attempt
3. Only one first attempt per player-puzzle pair ever counts

"Earliest" / "oldest" is the insights order: the solve moment `COALESCE(finished_at, tracked_at)`, then `tracked_at`
(`finished_at` is a date, so same-day solves are ordered by when they were logged), then the id for an exact tie.
The rule lives in one place, `PlayerBaselineCalculator::qualifyingFirstAttemptsQuery()` - solo, with a time, not
suspicious, not unboxed, one per player and puzzle - used by the baselines, the pace history and the difficulty
indices alike. (Before 2026-10 the batch ordered by the solve moment only, so two same-day solves of one puzzle were
picked in no defined order.)

---

## Core Algorithm

The computation is sequential and non-circular:

```
Step 1: Player Baselines       (independent — no puzzle data needed)
        + Pace history          (the baseline after every qualifying first attempt — same rule)
Step 2: Difficulty Indices      (time ÷ the solver's pace-history row just before the solve)
Step 3: Puzzle Difficulty       (depends on Step 2)
Step 4: Derived Metrics         (depends on Step 3)
Step 5: Player Skill            (depends on Steps 1 + 3)
Step 6: MSP-ELO                 (independent ranking system)
Step 7: Improvement Ratios      (independent — uses raw solve times)
Step 8: Time Prediction         (uses Steps 1, 3, 7 at query time)
Step 9: Skill History           (snapshot of current state)
```

---

## Step 1: Player Baseline

The player baseline is the **weighted median** of their first-attempt solo times for a given piece count. Recent solves are weighted more heavily using exponential decay.

### Formula

```
effective_age = max(0, age_in_months - 3)
weight(solve) = exp(-effective_age / 13.5)

Decay curve (3-month plateau):
  Age  0-3 months: weight 1.00  (plateau)
  Age  6 months:   weight 0.80
  Age 12 months:   weight 0.51
  Age 18 months:   weight 0.33
  Age 24 months:   weight 0.21
```

### Weighted Median Calculation

1. Collect all first-attempt solo solves for the player + piece count
2. Assign each solve a weight based on its age
3. Sort solves by time (ascending)
4. Accumulate weights from the fastest solve upward
5. The weighted median is the solve where cumulative weight crosses 50% of total weight

### Minimum Threshold

At least **5 distinct puzzles** must have qualifying first-attempt solo solves. The weighting only affects the median computation, not the eligibility check.

If fewer than 5 qualifying solves exist, no baseline is computed.

### Example

Player has 7 first-attempt 500pc solves:

| Puzzle | Time | Age (months) | Effective Age | Weight |
|--------|------|-------------|--------------|--------|
| A | 45:00 | 2 | 0 | 1.000 |
| B | 48:30 | 4 | 1 | 0.929 |
| C | 50:15 | 6 | 3 | 0.801 |
| D | 52:00 | 8 | 5 | 0.690 |
| E | 47:20 | 10 | 7 | 0.595 |
| F | 55:00 | 14 | 11 | 0.442 |
| G | 62:00 | 20 | 17 | 0.283 |

Total weight: 4.740 — baseline computed (5 qualifying solves required, 7 present).

---

## Pace history (`player_pace_history`)

The baseline above is the player's pace **today**. Dividing a three-year-old time by today's (faster) pace makes
the puzzle look harder than it was: the difficulty indices were inflated by +1.8 % (solves < 6 months old) up to
+24 % (3+ years), and statistical first-try predictions ran 9.9 % slow (`docs/features/insights/prediction-research.md`
§2 Q1, §4). The pace history keeps the pace **as it was** after every solve that moved it.

### Rows

One row per player × piece count × **qualifying first attempt, from the 5th on** (the first one with a direct
baseline). No collapsing per day: a row per solve keeps the lookup exact for same-day solves (`finished_at` is a
date), costs ~20 % more rows than one per day (production copy: 232k rows vs ~179k), and ties every row to the solve
that moved it.

| Column | Meaning |
|---|---|
| `player_id`, `pieces_count` | the pair |
| `valid_from`, `valid_from_tracked_at` | the solve moment `COALESCE(finished_at, tracked_at)` and its `tracked_at` - the row holds from this solve on |
| `time_id` | the solve that moved the pace (FK, `ON DELETE CASCADE`, see below) |
| `qualifying_count` | the solve's position among the pair's qualifying first attempts (5 = first row); unique per pair |
| `baseline_seconds` | `PlayerBaselineCalculator::weightedBaselineSeconds()` over solves 1..k, **ages measured to the k-th solve** - the one baseline rule, never re-implemented |
| `step` | `ln(baseline_k / baseline_{k-1})`, rounded to 6 decimals; NULL on the pair's first row and when either baseline is 0 (0-second times). Log, so steps add up and ±3 % reads the same both ways ("your biggest jumps") |
| `drift` | the improvement drift as of this row (prediction model v3): the mean residual `ln(actual / predicted without drift)` of the pair's last 10 qualifying first attempts up to and including this row - each against its own pace row before it and the puzzle's difficulty as of it (the model's: median, shrunk or prior, below) - divided by (n + 5), so few solves shrink it toward 0, each residual clipped at ±0.5 like the spread's (one typo time moves the next first tries by ~3 %, not ~14 %); a 0-second time is no residual (no log - it leaves the drift as it was, like the pace history and the difficulty skip it) (`TimePredictionCalculator::drift()`). 0.0 on the first row. Written by the batch's prediction-model step on every run (only rows whose value changed); NULL on a row written since the last run |
| `spread` | the player's consistency at this piece count as of this row: the root mean square of the last 20 residuals around the drift-corrected prediction, each clipped at ±0.5, shrunk toward the global 0.13 with 5 pseudo-residuals (`TimePredictionCalculator::playerSpread()`) - what the window width rests on. Written with the drift |
| `inputs_checksum` | running sum of the hashes of the pair's qualifying solves up to this one (below) |

Indexes: `(player_id, pieces_count, valid_from, valid_from_tracked_at)` for the as-of lookup (the plan's
`(player_id, pieces_count, valid_from)` plus the tie-break, so "the row just before (solved, tracked)" is one index
step), unique `(player_id, pieces_count, qualifying_count)` (the writer's key; two writers can never interleave
silently), `time_id` for the cascade.

**Only direct baselines are stored** - no interpolated/extrapolated rows. A change at 500 pieces would otherwise need
a row for every other piece count the player ever solved (2,900 players have ~3.5 gap sizes each), and those rows would
still be stale between events. The lookup derives them exactly from the direct rows instead (below), with the same
`gapBaseline()` rule as the baselines' second pass. (The research prototype stored direct baselines only, too.)

Between two rows of a pair only time decay changes the weighted median; the row keeps the value as of its own solve
(ages measured to `valid_from`) - the research's "ignorable" option. Live and reconstructed use the same rows, so
they agree.

### The as-of lookup (`PaceTimeline::paceAt()`)

The pace of a solver at a solve `(solved, tracked)` of piece count S:

1. the row of S valid **strictly before** the solve: the last row with `(valid_from, valid_from_tracked_at) < (solved, tracked)`
   (two rows of the very same moment - solves logged in the same second: the later `qualifying_count`). The row a solve
   wrote itself is never its own pace;
2. else interpolated/extrapolated from the solver's rows valid strictly before the solve at their other piece counts
   (`gapBaseline()` with the batch's scaling exponent) - e.g. a 1000-piece solve of a 500-piece regular;
3. else - the solve came **before the solver had any pace at all** (one of their very first first attempts) - their
   **first pace**: 1 and 2 at the moment of their earliest row, provided that row is known at the moment the
   difficulty is computed for (the batch: always; the reconstructor: only rows before the predicted solve);
4. else no index.

Step 3 is a deliberate choice against simply dropping those indices (the research prototype dropped them): measured on
the production copy, 36k of 261k direct-baseline indices (13.8 %) are among their solver's first five solves at that
piece count, and dropping them would leave only ~5,500 of today's ~6,300 rated puzzles rated - mostly the lightly
solved ones that newcomers solve. The first pace is the solver's pace closest in time to those solves (formed within
their next few solves), so it carries no age bias; today's code divided them by today's pace, the most biased choice.

`PaceTimelineLoader` builds the timeline: every row (the batch: 232k rows, ~10 MB in packed arrays, one query), every
row of some players (`PredictionReconstructor`: the solvers of the puzzles it predicts statistically), or just what a
few lookups need (one puzzle - `RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange` on every added time:
`forPuzzleFirstAttempts()`, **one statement** with the puzzle's qualifying first attempts, the marker below, one index
step per solver at the puzzle's piece count and, aggregated, every row only of solvers without such a row - as many
statements as the old baseline join, so the add path's query budgets are unchanged).

### Writing it: the 15-minute batch, change detection

`PlayerPaceHistoryWriter::sync()` runs as a step of `recalculate-puzzle-intelligence` (right after the baselines). The
watermark is the data itself, not a timestamp - a time watermark misses deletions, back-dated adds, edits and
suspicious flags set by SQL:

- every row stores `inputs_checksum` = the sum of `md5`-based 48-bit hashes of the pair's qualifying solves up to it
  (`PlayerPaceHistoryCalculator::solveHash()`: id, seconds, solve moment, tracked_at, the rule `VERSION`);
- one aggregate statement sums the same hash in SQL (`sqlSolveHash()`, pinned equal to the PHP one by a test) over
  every pair's qualifying solves as they are now; a pair whose count and sum equal its last stored row is unchanged
  and is neither loaded nor written;
- a changed pair is loaded; the first position whose running checksum differs from the stored row is the first
  changed solve - rows from there on are deleted and written anew; a pair below five qualifying solves (or with none
  left) loses its rows.

So an incremental run ends exactly as a rebuild from scratch, and a run over unchanged data writes nothing (both
pinned by `PlayerPaceHistoryTest`). Covered: new and back-dated adds, edits of seconds or dates, deletions,
suspicious/unboxed flags (also set by SQL), a changed `first_attempt` flag, a puzzle's piece count, merges and moved
owners. Bump `PlayerPaceHistoryCalculator::VERSION` whenever what a row holds changes for the same inputs - every pair
is rewritten by the next run (~25 s once).

**Deleted times:** the `time_id` FK cascades - the row goes with its time at once, and the next run rewrites the pair
from that position (the checksums no longer match). Nothing ever points at a missing time. A merged puzzle's times keep
their ids (moved to the survivor), so nothing cascades; a deleted player takes their rows with them.

Between two runs a new solve has no row yet; the lookup for it does not need one (its pace is the row *before* it).
Only a solve back-dated before other solves logged in the same 15 minutes is briefly looked up against one row too few.

Batch and backfill take turns on an advisory lock (`PlayerPaceHistoryWriter::LOCK_KEY`).

### Backfill

`myspeedpuzzling:backfill-pace-history [--player=<uuid>]` dispatches `BackfillPaceHistory`; the handler walks every
player with a qualifying solve or stored rows in keyset batches of 300 (uuid order) through the same `sync()` - one
transaction, holding the writer's lock until commit. Idempotent: run again, nothing is written. Measured on the
production copy (523k times): 232,119 rows over 7,191 pairs in 22 s, 63 MB peak; a second run 2.1 s, nothing written;
the table is 73 MB with its indexes (33 MB heap).

**Run it right after the deploy** (`docker compose exec -T web php bin/console myspeedpuzzling:backfill-pace-history`):
the migration creates the table empty, and until the first complete sync - this backfill or the next full batch run,
whichever finishes first - the as-of difficulty is switched off, so the sooner it runs the shorter that window.

### Before the history is built (the rollout guard)

The difficulty must never be computed from an empty or half-built history: a puzzle would get no indices, the stored
difficulty would become NULL and the next add of the puzzle would be stored as "not predictable" - for good. So
`PaceHistoryReadiness` keeps a marker row (`pace_history_build`), written at the end of a complete sync (a full batch
run's `sync()` or the backfill's walk over every player) and never removed. Until it exists:

- `RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange` (sync on every added/edited time) updates the
  baseline and the ratios as before but **keeps the stored difficulty** - so live predictions keep using it;
- a `--player` batch run skips the difficulty step (a full run builds the history first, in the same run, so it never
  has to);
- `PredictionReconstructor` leaves statistical targets **pending** (NULL, not `false`): the self-healing prediction
  backfill cron picks them up once the history is there.

Pinned by `PaceHistoryRolloutTest` (mutation-checked). The marker also holds the baselines' scaling exponent of the
last full sync, which the incremental path and the reconstructor interpolate with (the batch's own value, not
recomputed). On the add path the marker costs nothing extra: it is read in the same statement as the puzzle's solves.

## Step 2: Difficulty Index (Per Solve)

For each qualifying first attempt of a puzzle:

```
difficulty_index = seconds_to_solve / solver's pace at the solve (PaceTimeline::paceAt(), above)
```

- Index < 1.0: puzzle was easier than average for this player
- Index = 1.0: exactly average
- Index > 1.0: puzzle was harder than average

**Qualification criteria:**
- The solve is a first attempt (per the resolution rules above)
- The solve is solo, non-suspicious, not unboxed, with `seconds_to_solve IS NOT NULL`
- The solver has a pace for it (rules 1-3 of the lookup)
- The resulting difficulty_index is <= 5.0 (outlier ceiling) and above 0 (a 0-second time measures nothing)

Until 2026-10 the index divided by the solver's **current** `player_baseline`. Expected effect of the switch
(research on the production copy): rated scores drop ~8 % at the median, ~46 % of well-rated puzzles move one tier
easier (Jan: fine), the rank order barely changes (Spearman 0.97); the public hardest/easiest lists change
accordingly, and statistical predictions lose their ~10 % slow bias. Measured on the copy of the production data
after the switch: puzzles with ≥ 10 indices -7.2 % at the median, 42.8 % one tier (or more) easier, 0.3 % harder;
6,284 puzzles rated instead of 6,285 (the first-pace rule keeps the newcomers' solves); tiers 1-6 went from
71 / 892 / 3,032 / 1,281 / 652 / 357 to 238 / 1,763 / 2,832 / 861 / 371 / 219.

The other per-solve indices of the batch - skill sensitivity, predictability, box dependence (`DerivedMetricsCalculator`) -
divide by the same as-of pace since prediction model v3 (Insights stream 3b): every solo solve (repeats and unboxed ones
too, as before) by its solver's pace at that solve (`PaceTimeline::paceAt()`, the batch hands in the timeline it loaded
for the difficulty; the incremental path loads the puzzle's solvers' rows). They measure how a puzzle spreads players
of a given pace apart, the same purpose as the difficulty index, so today's pace inflated them for old solves the same
way. Before the pace history is built they fall back to today's baseline (the rollout guard below).

---

## Step 3: Puzzle Difficulty

```
puzzle_difficulty = median(all qualifying difficulty indices for that puzzle)
```

Stored next to it for a shrunk difficulty on read (prediction model v2): `sample_size` = k, the number of indices, and
`sum_log_indices` = the sum of their natural logs (sorted before summing, so the same indices always give the same
number; 0.0 for k = 0, NULL until the batch has written the row with as-of indices). Both are stored for every puzzle
with a solve, also with fewer than 5 indices. (The plan named the count `index_count`; it is `sample_size`, which has
always been exactly that count - one column, not two that could drift.)

### Minimum Threshold

At least **5 qualifying difficulty indices** from different players are needed. Below this, no difficulty score is assigned.

### Difficulty Tiers

Six tiers (`DifficultyTier::fromScore()`):

| Tier | Score Range | Label |
|------|------------|-------|
| 1 | < 0.75 | Very easy |
| 2 | 0.75 - 0.90 | Easy |
| 3 | 0.90 - 1.10 | Average |
| 4 | 1.10 - 1.25 | Challenging |
| 5 | 1.25 - 1.45 | Hard |
| 6 | ≥ 1.45 | Very hard |

### Confidence Levels

Based on the number of qualifying indices (sample size):

| Sample Size | Confidence |
|-------------|-----------|
| 0-4 | Insufficient (not displayed) |
| 5-9 | Low |
| 10-19 | Medium |
| 20+ | High |

### Example

Puzzle X (500pc) has 6 qualifying indices: 0.87, 0.92, 1.05, 1.12, 1.18, 1.25

Median of [0.87, 0.92, 1.05, 1.12, 1.18, 1.25] = (1.05 + 1.12) / 2 = 1.085

Difficulty score: **1.085** — Tier 3 (Average), Confidence: Low (6 indices)

---

## Step 4: Derived Metrics (Puzzle Personality)

These provide richer context about what makes a puzzle difficult. Each metric requires its own minimum data threshold.

### Memorability

How much easier does a puzzle get on repeat solves?

```
memorability = median(first_try_difficulty_indices) / median(repeat_difficulty_indices)
```

- Requires >= 5 players with repeat solves
- High value (1.5+): Puzzle rewards familiarity — much easier the second time
- Low value (~1.0): Puzzle is equally challenging every time
- **User explanation:** "How much easier does this puzzle get once you've solved it before?"

### Skill Sensitivity

How much does player skill matter for this puzzle?

```
skill_sensitivity = percentile_75_difficulty_index / percentile_25_difficulty_index
```

- Requires >= 10 qualifying indices
- High value (2.0+): Big gap between fast and slow solvers — technique matters
- Low value (~1.2): Most people finish in similar time — an equalizer puzzle
- **User explanation:** "Does skill make a big difference on this puzzle?"

### Predictability

How consistent is the puzzle's difficulty across different players?

```
predictability = 1 / coefficient_of_variation(difficulty_indices)
coefficient_of_variation = standard_deviation / mean
```

- Requires >= 10 qualifying indices
- High value: People's experience is very consistent
- Low value: Wildly different experiences for different players
- **User explanation:** "How predictable is this puzzle's difficulty?"

### Box Dependence

How much harder is the puzzle without the box image?

```
box_dependence = median(unboxed_difficulty_indices) / median(boxed_difficulty_indices)
```

- Requires >= 5 unboxed solvers with baselines
- High value (1.5+): Box image is very helpful
- Low value (~1.0): Having the box makes almost no difference
- **User explanation:** "How much harder is this puzzle without seeing the box?"

---

## Step 5: Player Skill

Player skill measures how well a player performs **relative to other solvers on each puzzle**, weighted by difficulty and recency. This prevents gaming by solving only easy puzzles.

### Formula

For each puzzle the player solved (first-attempt, solo):

```
percentile = (slower_count + tied_count / 2) / (total_solvers - 1)
  0.0 = slowest, 1.0 = fastest

confidence = min(1.0, sample_size / 50)
blend = 0.5 × confidence
difficulty_weight = (1 - blend) + blend × puzzle_difficulty

weighted_percentile = percentile × difficulty_weight
```

### Time Decay (Weighted Median)

Skill uses a **weighted median** where recent solves have more influence. The time decay has a 6-month plateau (full weight) followed by gentle exponential decline:

```
effective_age = max(0, age_in_months - 6)
age_weight = exp(-effective_age / 24)

Decay curve:
  0-6 months:  1.00  (plateau)
  12 months:   0.78
  18 months:   0.61
  24 months:   0.47
  36 months:   0.29

skill_score = weighted_median(weighted_percentiles, age_weights)
```

### Minimum Threshold

Player needs >= **10 qualifying puzzles** (puzzles that have a difficulty score and at least 20 first-attempt solvers) for a skill tier to be computed.

### Skill Tiers

Based on percentile rank among all players with valid skill scores for the same piece count:

| Tier | Percentile | Label |
|------|-----------|-------|
| 1 | Bottom 25% | Enthusiast |
| 2 | Top 75% | Apprentice |
| 3 | Top 50% | Proficient |
| 4 | Top 30% | Advanced |
| 5 | Top 15% | Expert |
| 6 | Top 5% | Master |
| 7 | Top 1% | Legend |

### Separate Skill Contexts

Skills are computed independently for each piece count where enough data exists. A player can have different tiers for different piece counts:

```
500pc:  Expert (Top 12%)
1000pc: Advanced (Top 28%)
1500pc: Not enough data (3/5 baseline solves needed)
```

---

## Step 6: MSP-ELO Ladder

A competitive portfolio-based ranking system separate from skill tiers. "MSP-ELO" = MySpeedPuzzling ELO.

### Entry Requirements (Per Piece Count)

- At least **20 first-attempt solves** within 24-month window
- At least **50 total qualifying puzzle results** within 24-month window
- Public profile (private players excluded)

### Algorithm

Portfolio-based snapshot calculation combining first-attempt and best-time performance with difficulty weighting and time decay:

1. Collect all puzzles solved within 24-month window (20+ public solvers each)
2. Compute first-attempt percentile and best-time percentile for each puzzle
3. Apply difficulty weight (confidence-scaled) and time decay
4. Build two portfolios (top 100 entries each), compute means
5. Blend: 75% first-attempt + 25% best-time

### Time Decay

Portfolio entries are gently decayed by age with a 3-month plateau:

```
effective_age = max(0, age_in_months - 3)
decay = exp(-effective_age / 30)

Decay curve:
  0-3 months:  1.00  (plateau)
  6 months:    0.90
  12 months:   0.74
  18 months:   0.61
  24 months:   0.50  → hard cutoff

decayed_points = percentile × difficulty_weight × decay
```

First-attempt entries decay by the first-attempt date. Best-time entries decay by the most recent solve date on that puzzle.

### Rating Formula

```
rating = 0.75 × mean(top 100 FA_decayed_points) + 0.25 × mean(top 100 BT_decayed_points)
Display: rating × 1000 (e.g., 0.85 → 850)
```

---

## Step 7: Improvement Ratios (Pre-computed)

Data-driven improvement ratios capture how much faster players get between consecutive attempts.

### Global Ratios

Computed per `(pieces_count, from_attempt, gap_bucket)`:

```
ratio(N→N+1) = median(time_attempt_N+1 / time_attempt_N)
```

- Transitions capped at 4 (4 = "4+")
- Gap buckets: lt30d, 1_3m, 3_12m, gt12m, all
- Outlier filter: ratios between 0.1 and 5.0
- Stored in `global_improvement_ratio` table

### Player Ratios

Computed per `(player_id, from_attempt)` — cross-piece-count for data density:

- Minimum 3 puzzles with that transition
- Stored in `player_improvement_ratio` table
- No gap bucketing (too sparse per player)

### Gap Correction

Player ratios are adjusted at prediction time using global gap data:

```
gap_correction = global_ratio[transition][actual_gap_bucket] / global_ratio[transition][all]
effective_ratio = player_ratio × gap_correction
```

---

## Step 8: Time Prediction

Personalized estimate for how long a puzzle will take a specific player, with **calibrated windows**: the range is the
50 % "likely" window (half of solves land inside it), next to it the 80 % range. Model version 3 (Insights stream 3b,
`TimePredictionCalculator::MODEL_VERSION`); the research behind every number: `docs/features/insights/prediction-research.md`
(§2 Q1/Q2, piece-count groups, §12 windows).

Storing the prediction on every solving time (history, backfill, the side-by-side table): [`prediction-history.md`](prediction-history.md).

### The shown model switch (`PREDICTION_SHOWN_MODEL`, v3 → v7, 2026-10-31)

**Decision (Jan, 2026-10-31):** the prediction players see switches from this model (v3, everything in Step 8 up to
"The candidate model") to **candidate v7** ("The candidate model" … "Candidate v7" below): on 3,254 results predicted
live before they happened (first tries and repeats, paired) its error is 0.0923 → 0.0852 (−7.7 %, CI ±0.0019), first
tries −11 %, k = 1-4 −20 %, 1000 pieces −17 %, the windows as honest or more (49.2 / 79.2 % vs 48.9 / 78.7 %); no group
significantly worse (k = 0 +1 % within noise - and k = 0 is not shown). Insights itself stays hidden (`INSIGHTS_PUBLIC`).

**One switch, an instant rollback.** Env `PREDICTION_SHOWN_MODEL` = `v3` (default) | `v7` (`Value\ShownPredictionModel`,
docs/features/feature_flags.md). Both models are computed side by side whatever it says (the side-by-side rows of
`solving_time_prediction`, the fits, the evidence), so flipping it back makes every number read live v3's again at once,
with no data migration. With `v7`:

| Surface | What shows the candidate's numbers |
|---|---|
| Puzzle page, API v1 `me/predicted-time`, the recap while a time is pending | `GetPlayerPrediction::forPuzzle()`: a first try = `GetCandidatePrediction`'s one-puzzle statement + `firstTryOf()` (in place of the statistical statement - 2 statements as before), a repeat = the statistical centre with `repeatOf()` (the candidate's fit rides on the ratio statement - 4 as before) |
| Collections (display mode), the Picker's cards, API v1 puzzles, Insights readers | `GetPlayerPredictions`: the same two calculations, the player's candidate columns once on the ratio statement, the puzzles' candidate columns on the puzzles' statement |
| The Picker's filters and gap order (pre-LIMIT) | `CandidateCentreSql` - the candidate's centre in SQL with the calculator's operations (README of the Picker §6.4); the pace table by piece count from PHP (`GetPlayerPredictions::candidatePaces()`, one statement more on such a draw); the pictures' taste projections stored by the candidate's jobs (`candidate_picture_projection`, read only while current) |
| Personal estimates (Insights, hidden) | `GetPersonalEstimates` - the same rule, the candidate's numbers (estimates.md "Which number") |
| Stored shown prediction of an added time | `SolvingTimePredictor`: the candidate's live prediction (`forDisplay()`), `prediction_model_version` 7; its candidate row is written whatever `PREDICTION_CANDIDATE_ROWS` says |
| Reconstructed shown predictions | `BackfillSolvingTimePredictionsHandler`: the candidate's reconstruction; `--recompute-reconstructed` replaces every reconstructed shown prediction made by the other model or before the shown model's fit (prediction-history.md "The shown model switch") |
| Calibration report | `GetPredictionCalibration`: the candidate's live rows (model 7; repeats are its `w|` buckets) |

Kept as they are: **k = 0 prior-only predictions stay unshown** (a candidate first try without any index - its k - is
null on every surface; the personal estimates show the picture's k = 0 prediction only where its gate passed, as before),
the members-only gates, opted-out players, the tiers (the puzzle's own, from the statistical model's indices). Before the
candidate's first fit the statistical model stands in (and its rows say version 3).

**What the time verification reads.** Nothing of it follows the switch: the form check asks `forPuzzle(model: V3)`, the
scan reads, for a time whose shown prediction is a candidate one, the statistical side row written next to it
(`ShownPredictionModel::statisticalVersionSql()`). Likewise every reader of a stored repeat's centre as a model input -
the repeats' residuals (the windows' spread), `RepeatHistory` (both fits), `PredictionReconstructor` - reads the
statistical model's centre through `RepeatResiduals::storedPersonalSql()`: on a row with a statistical shown prediction
the column itself (their SQL results unchanged), on a v7 one the statistical side row - so the inputs of both models do
not depend on what was shown, and a rollback is exact.

The proof: `ShownModelV7Test` (every surface = the candidate's side-by-side calculation, the Picker's SQL = its cards to
the second, mutation-checked; the verification and the residuals on the statistical model; the backfill and the
recompute in both directions; the statement counts), `ShownModelParityTest` and every other test at the default `v3`
(the v3 path unchanged); on a copy of production "Measured (the switch)" below.

#### Measured (the switch, 2026-10-31)

On a local copy of production (times of 2026-10-26; `puzzle_difficulty`, the estimates 1.3.0, the shown columns and
the statistical v3 / candidate v7 side rows copied read-only from production on 2026-10-31; the candidate refitted on
the copy - 90.6 s, peak 314 MB resident, 43,352 picture projections written; the 15-minute refresh after it 7.7 s,
273 MB, 0 projections rewritten; PostgreSQL with `jit = off` like production):

**What players would see change** (400 random players × their solved puzzles + 150 random unsolved ones each, the bulk
reader `GetPlayerPredictions` v3 vs v7 on the same rows; change = v7 ÷ v3):

| group | shown by both | only v3 | only v7 | mean change | median abs | p90 abs | > 10 % | 50 % window width v3 / v7 |
|---|---|---|---|---|---|---|---|---|
| all | 39,278 | 54 | 8,905 | +1.7 % | 1.2 % | 15.7 % | 19.4 % | 16.9 / 17.0 % |
| repeats | 17,710 | 0 | 0 | +0.7 % | 0.5 % | 1.1 % | 0.0 % | 12.1 / 15.1 % |
| first tries | 21,568 | 54 | 8,905 | +2.6 % | 7.0 % | 20.7 % | 35.4 % | 22.8 / 20.4 % |
| first tries k 1-4 / 5-19 / 20+ | 16,676 / 3,405 / 1,487 | | | +3.2 / +0.2 / +0.5 % | 7.8 / 5.4 / 3.8 % | | 39 / 25 / 13 % | 23.7→21.4 / 17.9→16.9 / 15.6→14.4 % |
| first tries 1000 pieces | 4,622 | 35 | 787 | +3.7 % | 8.2 % | 22.5 % | 41.8 % | 28.6 / 21.6 % |
| first tries, v3 pace 500-scaled | 12,035 | 54 | 0 | +2.0 % | 8.0 % | 22.4 % | 40.5 % | 24.6 / 22.3 % |

v7 shows a first try where v3 had none for 8,905 pairs (the size curve paces a size from the player's other sizes and
their 1-4 first tries there: 7,814 of them `pooled_few`); 54 the other way (v3 500-scaled at 1000 pieces where the
candidate's evidence on the copy is older than the copied `puzzle_difficulty` - an artefact of the mixed copy). Repeats
move by the gap offset only, their windows widen where the gap is long (the candidate's gap-aware window); first-try
windows get narrower, most at 1000 pieces. The accuracy behind it is the live paired comparison above and the time
splits of "Candidate v7".

**Cost** (laptop, warm, second runs):

| reader | v3 | v7 |
|---|---|---|
| puzzle page (`forPuzzle()`, a repeat + a first try, 600 calls) | 1.3-1.7 ms | 2.7-3.6 ms a call (same statement count) |
| bulk, ~195 puzzles a player (400 players) | 5.7 ms | 18.9 ms |
| a 1,350-puzzle list: bulk predictions / personal estimates | 51 / 84 ms | 166 / 157 ms |
| Picker "any puzzle, fits in 60 min", the 10 players with most solo times | 97-162 ms | 188-310 ms |

The Picker's centre: the per-puzzle part once for the catalogue in a materialised CTE (hash joins; a join per candidate
in the order-keeping plan cost 530-940 ms), the taste projections stored (with them computed in SQL each request:
+~60 ms more), the pace table 37-46 ms of it. The bulk statement over 200 puzzles: 23 ms (v3 4 ms) after reading the
own first tries in one CTE and the estimate by its key (73 ms as a subquery per puzzle and a hash over every estimate).

**Time verification** (`var`-script on the same copy, the scan's input of every result it lists - its checks deleted in
a rolled-back transaction - with production's shown columns, then with every **reconstructed** shown prediction replaced
by its candidate v7 row as `--recompute-reconstructed` will): 547,123 results, 345,646 with a prediction the scan reads;
449,710 shown predictions replaced (401,800 with another number than v3's) - **the scan's input differs for 4 results**:
production's 5 reconstructed shown predictions that already differ from their own statistical side row (2026-10-19
rows; the scan reads the side row after the switch), at 0.79-0.99× of their prediction, nowhere near a rule (fast from
2×, slow from 3×) - **no case opens or closes**. Replacing the live shown predictions too (which the switch never does)
would change 9,797 inputs: live v1/v2 predictions from before the side rows existed, whose side rows are later
reconstructions - left as they are. The classifier reads nothing else of a prediction, so the switch changes nobody's
marks; `SuspiciousTimeClassifier::VERSION` stays.

### Prediction Hierarchy

| Prior Solves (N) | Method |
|---|---|
| 0 | Statistical: `pace × difficulty × drift` (below) |
| 1 | Ratio-based: `last_time × ratio(1→2, gap)` |
| 2-5 | Blend: ratio + Holt's (Holt's weight = (N-1)/5) |
| 6+ | Pure Holt's damped trend |

### Ratio Sources (priority order)

1. Player-specific ratio for transition N→N+1
2. Global ratio for transition N→N+1 with gap bucket
3. Default: 0.90

### Blending Formula

```
ratio_prediction = last_time × improvement_ratio
holts_prediction = Holt's damped trend (α=0.5, β=0.4, φ=0.8)

holts_weight = min(1.0, (N - 1) / 5)
predicted = holts_weight × holts + (1 - holts_weight) × ratio_prediction
```

Floor: `best_time × 0.70` (max 30% improvement over personal best).

### Statistical Prediction (N=0, a first try)

```
centre = pace × exp(log_difficulty + drift)
```

- **Pace** - the player's baseline at the piece count (`player_baseline`: direct from 5+ first attempts, else
  interpolated/extrapolated), or, for a **thin size**, their direct 500-piece pace × (pieces / 500)^α of the size group,
  while their own direct baseline rests on fewer first attempts than the group's fitted threshold (no own baseline at
  all counts as 0). Groups (`PiecesGroup`): ≤300 / 500 / 1000 / 1500+ / odd sizes. The daily fit decides per group:
  α from the players with direct paces at both sizes, the threshold from {1, 10, 20, 50, always} by the error on the
  last 12 months' first attempts, and **no rule where the 500-scaled pace never beats the own baseline**. Measured on
  the copy of production (fit on the year before 2026-04-11): 1000 pc always (0.136 vs 0.148), ≤300 below 20 own first
  attempts (0.106 vs 0.114), odd sizes and 1500+ no rule. What the pace was is recorded with the prediction
  (`TimePredictionResult::$paceSource`, `solving_time_prediction.pace_source`: `own` / `interpolated` /
  `scaled_500`) - the time verification leaves the 500-scaled one out.
- **Difficulty** - `log_difficulty` by the puzzle's index count k (`TimePredictionCalculator::logDifficulty()`):
  - k ≥ 5: ln(difficulty score), the median shown as the tier - source `solves`;
  - k = 1..4: **shrunk** `(Σ ln index + τ × prior) / (k + τ)` (`puzzle_difficulty.sum_log_indices`, `sample_size`),
    toward the prior, toward the average puzzle (0) without one - source `shrunk`. The tier shown stays "not rated yet"
    below 5 indices: only predictions use it;
  - k = 0: the prior alone, only where it is asked for (`allowPriorOnly`: the live and reconstructed side-by-side rows),
    never on a surface (`SolvingTimePrediction::forDisplay()`, `GetPlayerPrediction` default) - the personal estimates
    of Insights Phase 6c show the *image* prediction at k = 0 only where its gate passed, never the prior-only
    statistical one (`docs/features/insights/estimates.md`).
  τ is fitted daily from {0.25, 0.5, 0.75, 1, 1.5, 2, 3} on the last 12 months' attempts at puzzles with 1-4 indices
  (the copy of production: 0.5 on everything, 0.75 when fitted before 2026-04-11 or 2026-07-01).
- **Prior** - behind the interface `Prior\DifficultyPrior`, a chain (`Prior\DifficultyPriors`, tagged services by
  priority: the first that knows the puzzle answers): `ImageEstimatePrior` (priority 10) in front of `BrandPiecesPrior`
  (0).
  - `BrandPiecesPrior`: the mean log index of the puzzle's brand at its piece count, shrunk toward 0 with 20
    pseudo-indices, **the puzzle's own indices left out** (the group's sums in `difficulty_prior_group`, every puzzle's
    share in them in `difficulty_prior_puzzle`, both fitted daily; fewer than 5 other indices = no prior; no brand = no
    prior).
  - `ImageEstimatePrior` (Insights 5d): the estimate of the puzzle's **current picture** (`puzzle_difficulty_estimate`,
    the image pipeline's ridge model - its fingerprint must match) as the log difficulty, else it falls through to
    brand × pieces. It answers only when the `PriorContext` carries an estimate, and **only the side-by-side `image` /
    `blend` predictors' contexts do** (`PriorContext::withImageEstimate()`): the statistical predictor - the shown one,
    on every surface - keeps shrinking toward brand × pieces until the estimates have passed their gates
    (`docs/features/insights/image-estimate-gates.md`). It fades like any prior: weight τ ÷ (k + τ), with τ ≈ 0.5 about
    14 % at k = 3, nothing from 5 indices on (the median). A prediction resting on it alone (k = 0) has the difficulty
    source `image` and its own window (`TimePredictionCalculator::imageWindow()`, since 2026-10-27): each end at
    centre × exp(± √((z × σ_player)² + s × e²)) - the player's part at the normal quantile z (spread and size terms of
    the first-try model, no puzzle term), the picture's part at the estimate's own asymmetric quantile offset e (p10 / p90
    for the 80 % ends, p25 / p75 for the 50 % ends - `ImagePriorInput::quantileOffsets()`), no brand-prior quantile
    table. The scales s, one per window (`imageWindowScales` = [s50, s80]), are fitted daily (`PredictionModelFitter`
    step 6) on the first tries of the window at puzzles with 0-4 indices, recency-weighted (half-life 45 days), so the
    picture-only windows hold 50 / 80 % - per size group × image kind × k = 0 / 1-4 and coarser keys from 600
    effective first tries (`imageWindowKeys()`). The interval is built against well measured puzzles, a first try at a
    new one scatters more - the first gate record (2026-10-23) had 42 / 71 % with the interval as is. Measurements:
    `docs/features/insights/image-estimate-gates.md`, "The picture-only window". (Before it, and for a stored fit
    without `image_window_scales`: s × σ_img² as the symmetric puzzle term, σ_img² = ((p90 − p10) ÷ 2.563)², inside the
    k = 0 quantile table - `ImagePriorInput::variance()`, `imageVarianceScale(s)`.)
  - The fit stores every puzzle's finished priors as the chain gives them then: `difficulty_prior_puzzle.prior_log` =
    the statistical predictor's (no estimate in the context: brand × pieces) and `blend_prior_log` = the blend
    predictor's (with the estimate of the current picture, any - in-sample too, like a live prediction; equal to
    `prior_log` without one) - a row for every puzzle with a share or a prior, 0 indices included, written in the same
    transaction as the sums (38k rows, ~0.35 s of the fit; ~0.6 s with an estimate for every pictured puzzle) - and a
    copy of both on the puzzle's difficulty row (`puzzle_difficulty.prior_log` / `blend_prior_log`,
    `PredictionModelRecalculator::syncDifficultyPriors()`: in the fit's transaction and after the batch's difficulty
    step, ~20 ms when nothing changed; the incremental handler fills them on insert). The Puzzle Picker reads
    `prior_log` from the copy - the difficulty row it joins anyway, no lookup of its own - while the other
    current-state readers and `PredictionReconstructor` build the prior from the sums and the estimate
    (`PriorContext`); equal to the last bit (`BrandPiecesPrior::fromSums()` on the same doubles, the estimate's own
    double), pinned by the Picker's parity test, `PredictionModelRecalculatorTest` and
    `ImagePredictionsSideBySideTest`. `blend_prior_log` has no reader yet: it is there for the Picker and the bulk
    readers once estimates are shown (Insights Phase 6), at no extra cost.
  - **Secret puzzles** (`hide_until` / `hide_image_until` in the future) have no prior of either kind, no estimate
    reaches them, and they are no training row of a brand prior (`StatisticalInputsSql::NOT_SECRET`, also in
    `imageEstimateJoins()`; `ImageEstimateSecretPuzzleCanaryTest`).
- **Drift** - the `drift` of the pace-history row the pace came from (own pair, or the 500-piece pair for a thin size;
  an interpolated/extrapolated baseline has none): the player's recent first tries ran this much faster or slower than
  their pace says. Research: the error of later solves 0.160 → 0.137.

### Windows (both kinds)

```
first try: sigma² = a × spread² + b / (k + 1) + size_term(group)
repeat:    sigma² = a_r × repeat_spread² + b_r
bounds     = centre × exp(q × sigma)      q = quantiles 10 / 25 / 75 / 90 % of the bucket
```

- `spread` = the pair's `spread` (first tries; none = the global 0.13), `repeat_spread` = the same formula over the
  residuals `ln(actual / predicted)` of the player's last 20 repeats with a stored personal prediction before the solve
  (`RepeatResiduals`; a repeat's centre - Holt - is the same in every model version, so all stored ones count).
- The bucket (`WindowBucket`): first try / repeat × consistency quartile of the spread × k bucket (0, 1-4, 5-19, 20+;
  first tries) × size group; a bucket with fewer than 200 samples falls back to the less specific ones (without the
  size group, the k bucket, the kind alone).
- a, b, the size terms, a_r, b_r (least squares on the clipped squared residuals, never negative), the quartile edges
  and the quantile table are fitted daily on the last 12 months; `PredictionModelParameters::defaults()` (the
  research's numbers) stand in before the first fit and for any part with too few samples - a size group's own term
  needs 200 first tries (`PredictionModelFitter::MINIMUM_SIZE_TERM_SAMPLES`, like a calibration bucket: a variance
  from n residuals is off by ~√(2/n) of itself, 10 % at 200), below that it keeps the default term and its samples
  count for a and b with that term taken off. 0-second times are no sample of any part. sigma ≥ 0.045.
- Asymmetric: the slow tail is longer (q90 ≈ +1.2 vs q10 ≈ −1.2, but q25 ≈ −0.6 vs q75 ≈ +0.6 around a centre that
  already carries the drift). The inner window is never wider than the outer one; bounds ≥ 1 s.
- Stored: `predicted_range_low/high_seconds` = the 50 % window, `predicted_range80_low/high_seconds` = the 80 % range.
  Rounding and wording are the display's (Insights stream 3c, "How windows are shown" below), never outward.

### The model's batch step and its daily fit (`PredictionModelRecalculator`)

The batch step runs in `recalculate-puzzle-intelligence` right after the difficulty, full runs only (not `--player` /
`--puzzle`); the fit is its own daily command, kept out of the 15-minute batch. Both replay the statistical model over every qualifying first attempt **as of its own moment** (`Model\PredictionEngine`
over `Model\FirstAttemptSet`, the in-memory first attempts the difficulty step loads anyway): the puzzle's difficulty
from the indices known strictly before the attempt (an index is known from its solve on; a solver's "first pace"
index only from that pace's row), the pace rows before it, the drift and spread of the attempts before it.

- Every batch run: drift and spread of every pace-history row with the stored fit (defaults and no priors before the
  first fit); only changed rows are written (`UPDATE ... FROM unnest`), compared with the values loaded with the pace
  timeline (`PaceTimelineLoader::all(withStoredStates: true)` - no second read of the table). The writes take the pace
  history writer's lock (`PlayerPaceHistoryWriter::underLock()`; the daily fit holds it until its commit), so the batch
  and the fit never UPDATE the same rows in two orders.
- Daily, `myspeedpuzzling:fit-prediction-model` (`FitPredictionModel`): the prior groups and shares (one transaction),
  then `Model\PredictionModelFitter` - τ, drift with that τ, the thin-size rules, the first-try and repeat windows -
  into `prediction_model_fit` (one JSON row per model version), then the drift and spread with the new fit. Read on
  the site through `PredictionModelParametersProvider::SQL` as a scalar subquery of a statement that runs anyway:
  **the upgrade costs no query** on any page. Run it once right after the deploy (until then: defaults).

Measured on a copy of the dev data (523k times, laptop, no debug, 512 MB container, steady state, two runs each,
2026-10-19): the step takes ~0.9 s; the whole batch 14.8 / 14.7 s against 13.5 / 13.4 s for main on the same copy
(+1.3 s: this step, the as-of pace of the derived metrics +0.4 s, the difficulty step +0.2 s with the prior copy's
sync), peak memory 362 MB (the run frees the allocator's caches between steps, `gc_mem_caches()`). The first run after
the deploy writes every row (~6.6 s). The daily fit takes ~4.4 s within 512 MB (~7-10 s the first time, when it
rewrites every row).

#### Memory of the daily fit (2026-10-27)

On production the fit runs as `docker compose run --rm --no-deps messenger-consumer php -d memory_limit=1G bin/console
myspeedpuzzling:fit-prediction-model` in a container killed at 512 MB resident, and the data grows every day. What it
holds is cut without changing a single output (the levers of the candidate's fit, below):

- every large statement through a server-side cursor (`CursorRows`, 5,000 rows a fetch): the pace history
  (`PaceTimelineLoader::all()`), the qualifying first attempts (`PlayerBaselineCalculator::streamQualifyingFirstAttemptsInPaceOrder()`,
  the batch's difficulty step too), the repeats (`RepeatHistory::load()`), the picture estimates - pdo_pgsql otherwise
  holds a whole result in the client library next to what is built from it; 20,000 rows a fetch left ~20 MB more
  resident (the freed rows fragment PHP's allocator);
- the attempts' ids as 16 bytes each in one string (`FirstAttemptSet::idAt()`, ~31 → ~5 MB), the stored drift and
  spread as packed records per pair (`PaceTimeline::storedStates()`, ~30 → ~7 MB);
- released once written or read: the finished priors after their write, the repeats after their window step (fitted
  first - it reads nothing else, PredictionModelFitter step 5), the priors and estimates before the drift's write;
  `gc_mem_caches()` after the attempts' load and the priors' write;
- each window calibration's errors kept once with their bucket (`Model\SampleBuckets`) instead of once per fallback
  key (four lists per first try).

PHP lists keep their power-of-two slack, but the slack is never written, so it is not resident: fixed-size arrays
(`SplFixedArray`) for the set's and the engine's columns took PHP's own peak from 290 to 217 MB and left the resident
peak where it was (265 vs 264 MB) - not done.

Peak resident memory on the production copy (2026-10-26, 306,796 qualifying first attempts, 249,322 pace rows),
`APP_ENV=prod`, `APP_DEBUG=0`, `memory_limit=1G`, no swap (`--memory-swap` = `--memory`), the same frozen clock;
VmHWM and the peak of RssAnon sampled every 50 ms (the part a cgroup cannot reclaim):

| job | before: VmHWM / RssAnon | after: VmHWM / RssAnon | time |
|---|---|---|---|
| daily fit, first run (29,969 drift rows written) | 348 / 314 MB | 267 / 232 MB | 15.4 / 15.6 s |
| daily fit, steady state (2 runs each, interleaved) | 386 / 351-352 MB | 265-268 / 231 MB | ~11 s both |
| batch: difficulty + prediction_model steps (VmHWM after them, 3 runs each) | 290-307 MB | 228-234 MB | 4.6-6.0 / 5.1-5.5 s (steady state) |

Times are a laptop shared with other work - within its noise; the large statements read through the cursor as fast as
buffered (qualifying first attempts 1.31-1.32 s vs 1.36-2.26 s, pace history 1.08-1.17 s vs 1.04-1.33 s).

Every output is the same to the bit: on the production copy (with 10 % of the stored drift moved and some prior rows
dropped / added first, so the fit writes) `prediction_model_fit`, `difficulty_prior_group`, `difficulty_prior_puzzle`,
`player_pace_history` and the batch's `puzzle_difficulty` compared by md5 of their full dumps between main and this
code; in the suite `ShownModelParityTest` (synthetic players, puzzles, estimates and repeats with every fitted part
fitted: the batch, the fit, the batch with that fit, and five players' predictions of every puzzle, against what main
recorded - `ShownModelParity.json`).

#### Memory of the 15-minute batch (2026-10-27)

`recalculate-puzzle-intelligence` runs every 15 minutes the same way (`docker compose run --rm --no-deps
messenger-consumer ...`, killed at 512 MB resident). Five of its steps held a whole large statement twice - in the
client library (pdo_pgsql buffers every result) and as one PHP array per row: the improvement ratios, derived metrics
and skills peaked at 407-429 MB, the ratings at ~350, the baselines at ~310 - far above the prediction model's ~230 MB,
and 80-100 MB from the kill. Cut without changing a single output, with the same levers as the daily fit's:

- every large statement through a server-side cursor (`CursorRows`): the baselines' qualifying first attempts, the
  improvement ratios' solves, the derived metrics' solves, the skills' two first-attempt statements, the ratings'
  first-attempt, best-time and per-player statements;
- computed while the rows stream by instead of kept: the derived metrics of a puzzle once its solves are complete
  (`DerivedMetricsCalculator` holds one puzzle's solves, then its five numbers), a player's improvement ratios once
  the stream is past the player and the global ratios' groups directly (`ImprovementRatioCalculator`, no transition
  arrays), the ratings' first-attempt pool deduplicated by the row order (no map per player);
- packed what is kept for a later loop: a player's qualifying first attempts per size (`PlayerBaselineCalculator`, 23
  bytes an attempt), a player's first attempts on the skill's comparison puzzles only (`PlayerSkillCalculator`, 28
  bytes), a player's 24-month solves of the rating (`MspRatingCalculator`, 83 bytes) - unpacked per player, in the
  same order;
- where the order is part of the result (a weighted median sums its weights in the given order), the statement says
  the order it always had: the window of the baselines' and the skills' first attempts sorts by player and puzzle,
  `ORDER BY player_id, puzzle_id` now pins it.

Each step's own resident peak is printed with `-v` (`PeakMemory::resetResidentPeak()` before each step: "5" to
`/proc/self/clear_refs` starts VmHWM over, the step's peak is VmHWM after it).

Each step's peak resident memory on the production copy (2026-10-26: 565,741 times, 6,662 puzzles with a difficulty,
314,879 rating entries; the copy has no player rows, so 12,048 were added with production's `is_private` /
`ranking_opted_out`), `APP_ENV=prod`, `APP_DEBUG=0`, `memory_limit=1G`, a 512 MB container without swap, the same
frozen clock; VmRSS sampled every 50 ms between step markers, four runs each (the first run writing everything, one
after the same moved / dropped rows and 300 new solves, two steady), old and new interleaved:

| step | before (main) | after |
|---|---|---|
| baselines | 297-321 MB | 99-101 MB |
| pace history | 94-95 MB | 92 MB |
| improvement ratios | 407-429 MB | 95-98 MB |
| difficulty | 231-232 MB | 228-229 MB |
| prediction model | 222-224 MB | 220-223 MB |
| derived metrics | 416-424 MB | 142 MB |
| skills | 417-423 MB | 134-137 MB |
| ratings | 343-352 MB | 145-155 MB |
| **whole batch (VmHWM)** | **428-429 MB** (RssAnon 391-399) | **228-229 MB** (RssAnon 198-199) |
| time (first / after moves / steady) | 30.2 / 22.7 / 19.7-20.7 s | 28.1 / 23.1 / 20.8-21.3 s |

The batch's peak is now the difficulty step's (the pace timeline and the first attempts, "Memory of the daily fit");
the in-process `-v` column (the true per-step VmHWM) is 0-6 MB above the 50 ms sampling. Times are within the
laptop's noise; the ratings
step reads its three statements through the cursor ~0.5 s slower in a steady run (4.5-5.6 vs 3.6-4.8 s).

Every output is the same to the bit: on two copies of the production copy (main's code on one, this code on the
other) every table the batch writes - `player_baseline`, `player_pace_history`, both improvement ratio tables,
`puzzle_difficulty` with its derived metrics, `player_skill`, `player_elo`, `player_rating_entry`,
`puzzle_rating_times`, `player_skill_history`, `player_rating_snapshot`, the prior tables, `prediction_model_fit` and
the insights generation - compared by md5 of its full dump (without the generated ids) after each of the four runs;
in the suite `InsightsBatchParityTest` (synthetic data with many solvers per puzzle, so every derived metric, skill and
rating is computed: two runs, the second after moved and dropped rows, against what main recorded -
`InsightsBatchParity.json`).

### Shared by every read path

`StatisticalInputsSql` is the one place of the live tables' inputs (`COLUMNS` + `JOINS`, hydrated into
`StatisticalPredictionInputs`): `GetPlayerPrediction` (one puzzle, latest pace rows by LATERAL), `GetPlayerPredictions`
(many, one DISTINCT ON relation) and the Puzzle Picker, whose pre-LIMIT filter mirrors the calculator's centre in SQL
(`centreSql()` - the same IEEE operations; pinned equal by `GetPuzzlePickerSuggestionsTest`, thin sizes and shrunk
difficulty included). `PredictionReconstructor` builds the same inputs as of the solve. The time verification
(`SingleTimeSuspicionCheck`, the scan) keeps using only predictions v2 would have made - on a rated puzzle and the
player's own pace (or a personal one): a shrunk, prior-only or 500-scaled first-try prediction is no expectation
there (`suspicious-time-review.md`).

### Accuracy (measured, the acceptance of Insights plan §3.6)

`myspeedpuzzling:evaluate-predictions --fit-before=YYYY-MM-DD [--until=] [--json]` fits everything on what was solved
before the date and measures every first attempt and repeat after it (read only, a few seconds and ~1 GB on the copy
of production). On the copy (fit before 2026-04-11, 83,981 first attempts and 47,985 repeats after it):

| | n | coverage | error (MAE ln) | median bias | 50 % hit / width | 80 % hit / width |
|---|---|---|---|---|---|---|
| first tries shown (k ≥ 1) | 70,900 | 84.4 % (v2 rule: 73 %) | 0.087 | −0.2 % | 50.8 % / 13.8 % | 80.2 % / 26.9 % |
| rated puzzles (k ≥ 5) | 62,445 | 74.4 % | 0.082 | −0.2 % | 51.0 % / 13.1 % | 80.3 % / 25.2 % |
| puzzles with 1-4 indices | 8,455 | 10.1 % | 0.123 | +0.1 % | 49.9 % / 19.0 % | 79.8 % / 38.0 % |
| prior only (k = 0, not shown) | 4,141 | 4.9 % | 0.186 | +1.5 % | 49.8 % / 30.9 % | 79.7 % / 62.1 % |
| repeats | 47,985 | - | 0.077 | +0.4 % | 50.2 % / 11.9 % | 79.7 % / 23.1 % |

Per consistency quartile (first tries shown) the 50 % window holds 50.8 / 51.1 / 50.9 / 50.6 % at a median width of
10.5 / 12.5 / 15.1 / 16.8 %. A later cut (fit before 2026-07-01, 36,522 first tries shown, 25,304 repeats): MAE 0.087,
bias −0.1 %, 50 % window 50.5 % / 13.7 % (quartiles 51.4 / 49.7 / 50.5 / 50.0 %), 80 % window 80.0 % / 26.9 %;
repeats 50.5 % / 11.8 %. Measured 2026-10-19 with the drift clipped at ±0.5. Thin sizes: 1000
pieces from the 500 pace always (α ≈ 1.43), ≤ 300 below 20 own first attempts (α ≈ 1.34); thin groups stay the
weakest (1000: MAE 0.129, bias −3 %; 1500+: 29 attempts). Before (v1/v2 as stored): first tries 29 % inside a 16 % range, repeats 76 % inside 30 %.

### Calibration monitor

`myspeedpuzzling:report-prediction-calibration [--days=90] [--min-samples=200]` (daily cron): per bucket of the stored
**live** side-by-side rows of the current model (`GetPredictionCalibration`: solo, not suspicious, not unboxed), how
often the actual time landed inside the 50 % and 80 % windows; a bucket (or the first-try / repeat total) with enough
samples more than 3 points from its target is a **warning** (Sentry): the nightly fit no longer matches what players do.

### Image and blend predictions (side by side, Insights 5d)

Every first try also gets two predictions from the puzzle's picture estimate, stored next to the statistical one and
**never shown** (`solving_time_prediction`, `PredictorKind`; `TimePredictionCalculator::sideBySide()`):

| Predictor | Centre | Window |
|---|---|---|
| `statistical` (shown) | pace × drift × difficulty shrunk toward brand × pieces | as above |
| `image` | pace × drift × exp(estimate) - the picture and the pieces alone, whatever the solves say | k = 0 bucket, puzzle term = the estimate's interval |
| `blend` | pace × drift × difficulty shrunk toward the estimate (`ImageEstimatePrior` first): the estimate alone at k = 0, fading with k, the solves' median from 5 indices on | like `statistical`; at k = 0 like `image` |

Only for a first try on a puzzle with an estimate of its current picture (repeats are personal predictions, which do
not use the difficulty); live from any current estimate, reconstructed **from out-of-fold estimates only**. Storage,
backfill and the as-of rule: [prediction-history.md](prediction-history.md) "Image and blend predictions". They feed
the estimates' evaluation and gates (`myspeedpuzzling:evaluate-image-estimates`, `ImageEstimateGate` -
[`docs/features/insights/image-estimate-gates.md`](../insights/image-estimate-gates.md)); Insights Phase 6 shows
estimates only where a gate passes.

Measured on a copy of the dev data (523k times, laptop, no debug, 2026-10-19) with a synthetic estimate for every
pictured puzzle (41,266): the daily fit 5.6 → 6.0 s (the estimates' read and the blend prior per puzzle), the 15-minute
batch unchanged (18.6 s - only the copy's sync compares one more column). The add-time path costs no statement: the
estimate rides on the statistical read (two LEFT JOINs) and the three side rows go in one INSERT.

### The candidate model (v4, v5 with the taste factor, v6, v7) - side by side, never shown

A second model computed next to the shown one (v3), to be launched as one `MODEL_VERSION` bump once Jan decides
(prediction model review 2026-10-26, proposals P1-P8 and data-mining F6/F8). **Nothing a player sees changes**: the
columns on `puzzle_solving_time`, `GetPlayerPrediction(s)`, the Picker, the recap and API v1 stay on v3;
`CandidateModelTest::testTheShownModelIgnoresEveryCandidateInput` and
`CandidatePredictionsSideBySideTest::testTheShownPredictionsAreTheSameWithAndWithoutTheCandidate` guard it. Its rows
are the `candidate` predictor of `solving_time_prediction`, model version `CandidatePredictionCalculator::MODEL_VERSION`
(7 since "Candidate v7", 6 since "Candidate v6", 5 with the taste factor, 4 before - "The taste factor (v5)") - first tries and repeats; storage and backfill: [prediction-history.md](prediction-history.md) "The candidate
model (v4)".

**First try** (`Model\CandidatePredictionCalculator::firstTry()`): the shown model's pace × e^drift (same
`baselineFor()`, same thin-size rules) × e^(posterior log difficulty) × e^(centre offset) [× e^(unboxed offset)]:

- **Index (P6).** Every difficulty index once more: ln(time ÷ pace) minus the solver's drift before the solve, clipped
  at ±1, with its own noise variance s² = a·spread² + c ÷ q + e₀ (+ a term for a pace that was not the solver's own
  direct one) - `Model\CandidateEngine`, fitted daily by non-negative least squares of each index's squared deviation
  from the mean of the puzzle's other indices (puzzles with 10+ indices). Prod copy 2026-10-27: a 0.43, c 0.087,
  e₀ 0.0013, indirect +0.020 (a 5-10-first-try solver's index is ~1.8× as noisy as a 50+ one's).
- **One posterior for every k (P6, P1).** (Σ w l + m ÷ v) ÷ (Σ w + 1 ÷ v), w = 1 ÷ s², variance 1 ÷ (Σ w + 1 ÷ v) -
  no switch to the median at 5 indices; the prior keeps its weight. Prior m: the image estimate
  (`ImageEstimatePrior`'s estimate), else the **puzzle-weighted brand × pieces prior (P8)** (`PuzzleWeightedBrandPrior`
  - every puzzle of the group counts once, fitted pseudo-count of puzzles; the shown one averages indices, so popular
  easy puzzles dominate it), else the average puzzle. Each prior type its own variance v (grid on first tries at puzzles
  with 0-9 indices; prod copy: image 0.015 ≈ τ 1-1.5 in index units, brand / none stay at their defaults - nearly
  every puzzle has an estimate).
- **Centre offsets (P3).** The median residual per size group × pace source × k bucket (× prior type at k = 0),
  **less the fit window's overall median** (a level shared by the whole window is a period effect: a plain median offset
  left +0.8 % bias out of period), shrunk (n ÷ (n + 200)); then the same per player experience (first tries before:
  < 10 / < 30 / < 100 / more) - new players come 4-5 % slower than predicted in both models.
- **Window.** sigma² = w₁·spread² + w₂·posterior variance + size term + pace-source term (interpolated, 500-scaled - P3)
  + experience term (< 10, < 30) + w₃·dispersion (F8: the puzzle's excess disagreement - the plain variance of its
  indices less their own noise, shrunk with (k - 1) ÷ (k - 1 + 10)), × a context scale (competition, unboxed - F6),
  then standardised-residual quantiles per consistency × k bucket × size group.
- **Unboxed first tries (F6).** Left out of indices and paces like before (the qualifying rule); predicted with a fitted
  offset (+11 %) and window scale (1.75), fitted on the unboxed first tries of the window (`Model\UnboxedFirstTries`).

#### The candidate's pace: the size curve (review "Size curve", 2026-10-27)

The shown model paces a first try from the player's own baseline at the piece count, an interpolation between their
direct baselines (with the between-player exponent), or - under a thin-size rule - their 500 pace × (s ÷ 500)^α. The
candidate replaces all three with one rule (`SizeCurve::pace()`, `CandidatePredictionCalculator::pace()`; switch
`size_curve`):

- **The curve.** Log time vs 500 as a piecewise-linear function of ln pieces through the knots 48 / 100 / 150 / 200 /
  300 / **500 = 0** / 750 / 1000 / 1500 / 2000 / 3000 / 5000, plus β × (ln pace500 - ln 4200 s) × min(ln(s ÷ 500), 0) (slow
  players are relatively quicker at small puzzles). Fitted daily (`Model\SizeCurveFitter`): every first try of the window
  paired with every direct pace row of the solver at another size before it, y = ln t - log difficulty - ln baseline -
  drift, robust (Huber 0.25, IRLS) with a ridge on the knot values' second differences. Prod copy: 100 pc -2.16, 300
  -0.67, 1000 +0.98, 1500 +1.64, β 0.10 (274k pairs).
- **Pooled level.** Every direct row r at another size gives θ_r = ln baseline_r + drift_r - F(s_r) with variance
  1.57 × spread_r² ÷ min(q_r, 50) + τ² × ln(s_r ÷ 500)² + 1e-4; precision-pooled, + F(s) = the prior for the target size
  with variance V_θ + τ² × ln(s ÷ 500)² (personal deviations grow with the distance and do not carry across 500).
- **Own evidence** at the size updates it by precision: the own direct row (variance 1.57 × spread² ÷ min(q, 50)), else
  the player's 1-4 first tries there (mean of ln t - the puzzle's log difficulty, variance σ₁² ÷ n). Each source keeps
  its own drift. τ², σ₁² by grid **on held-out players**: one fold in five (`SizeCurveFitter::foldOf()`, by player id)
  is left out of a first regression and the grid is scored on it with that curve; the curve itself is then fitted on
  everybody (τ², σ₁² do not enter the regression). Prod copy: 0.005 / 0.03 on both splits (the in-sample grid had
  picked 0.003 / 0.015 - over-confident; held-out error 0.0914 June, 0.0941 April).
- **When the window cannot identify a curve** (fewer than 2,000 pairs, or a singular system - a knot or the speed term
  without pairs the ridge cannot carry: `NotIdentifiable`, never a silent zero coefficient) the fit keeps the curve the
  candidate has (its last fit's; the review's on a first fit) and says so in the summary (`size_curve_kept`).
- **What it rests on** (`pace_source`): `pooled` (other sizes only), `pooled_few` (+ own 1-4), `pooled_own` (+ own
  row), `own` (an own row and no other size - today's own pace). The window gets the pace's posterior variance
  (weight fitted with the other terms) and quantile keys per pooled kind × distance band from 500.
- **The own first tries' difficulty** is the puzzle's candidate difficulty **as of the fit's / evaluation's cut-off**
  (honest), live and in reconstructions the current one (known at prediction time; a documented approximation for
  reconstructions, like the priors' sums - the size-curve review measured as-of and current alike).

Measured (`--candidate` vs `--candidate-off=size_curve`, same rows; fit < 2026-06-01 / < 2026-04-01; τ², σ₁² on
held-out players):

| group | n (June) | shown v3 | candidate without the curve | **with the curve** |
|---|---|---|---|---|
| all first tries | 68,240 | 0.0928 | 0.0884 (−4.8 %) | **0.0872 (−6.0 %)**; April: 0.0927 → 0.0885 → **0.0871 (−6.0 %)** |
| shown pace interpolated | 564 | 0.1708 (hits 39/67) | 0.1677 | **0.1380 (−19.2 %)**, 40.6/72.2; April −22.1 % |
| shown pace 500-scaled | 11,888 | 0.1259 | 0.1179 | **0.1131 (−10.1 %)** |
| 1-2 own first tries at the size | 2,676 | 0.1330 | 0.1300 | **0.1210 (−9.0 %)** |
| 3-4 own first tries at the size | 1,898 | 0.1250 | 0.1190 | **0.1071 (−14.3 %)** |
| never solved the size | 1,788 | 0.1428 (46.5/77.9) | 0.1386 | **0.1363 (−4.6 %)**, 53.0/83.7 |
| 50+ own first tries | 36,605 | 0.0765 | 0.0728 | 0.0728 (unchanged) |
| 1000 pieces | 3,410 | 0.1429 | 0.1236 | **0.1191 (−16.7 %)** |
| 1500+ pieces | 68 | 0.2192 | 0.2003 | **0.1582 (−27.8 %)** |
| odd sizes | 390 | 0.1405 | 0.1362 | **0.1116 (−20.6 %)** |
| ≤ 300 pieces | 11,342 | 0.1160 | 0.1118 | **0.1071 (−7.7 %)** |
| k = 1-2 | 4,758 | 0.1328 | 0.1212 | **0.1166 (−12.2 %)** |

The held-out grid cost 0.0001 overall against the in-sample one it replaced (0.0871 → 0.0872 June, April unchanged)
and up to 1.4 points in the interpolated group - the price of a variance not fitted on the rows it is scored on.

Known losses (both splits): a never-solved size at |ln(s ÷ 500)| 0.75-1.2 (402 / 573 rows) +3.6 % / +3.1 % against the
candidate without the curve (still better than v3; biased −4.9 %); players new to 500 with 1-4 first tries there (214 /
296 rows) hit 35 / 67 (36 / 67) instead of 43 / 72; players with < 10 first tries hit 48 / 77 instead of 52 / 82.

Cost: the candidate fit with the curve takes 42 s on the copy (the curve ~10 s, pacing the samples with it ~3 s) at
**~360 MB resident** (358-363 over runs; without the curve 334 MB). The curve step reads the attempt set and the drift
states only: the puzzles' priors for its own first tries come from two small maps taken while sampling, so the prior
lookup and the image estimates are released before it; its pairs are three fixed-size columns, and the samples are
re-paced in place. Live, the curve's two columns ride on the candidate's own statement; the own-first-tries subquery
runs only for a player without a pace row at the puzzle's size (the only case the curve reads it) and reads at most 5.

**Repeat** (`repeatWindow()`): the shown centre (Holt / ratio) × e^(gap offset), the window with a variance term and
quantiles per gap bucket since the last solve (P4: < 30 d, 1-3 m, 3-12 m, > 12 m).

**Measured** (`evaluate-predictions --fit-before=2026-06-01 --candidate` on a read-only copy of production taken
2026-10-27; everything fitted before the cut, every first try after it predicted as of its moment, the same 68,240
rows for all three; error = mean |ln(actual ÷ predicted)|, "blend" = v3 shrunk toward the out-of-fold image estimate):

| group | n | shown v3 | blend v3 | candidate | 50 / 80 % hits shown → candidate |
|---|---|---|---|---|---|
| all first tries | 68,240 | 0.0928 | 0.0903 (−2.6 %) | **0.0884 (−4.8 %)** | 50.6 / 80.1 → 50.3 / 79.9 |
| k = 0 (not shown today) | 3,737 | 0.1845 | 0.1508 | 0.1497 (−18.9 %) | 50.4 / 80.5 → 51.9 / 83.2 |
| k = 1-2 | 4,758 | 0.1328 | 0.1259 | 0.1212 (−8.7 %) | 50.8 / 80.3 → 50.3 / 79.8 |
| k = 3-4 | 2,954 | 0.1079 | 0.1048 | 0.1017 (−5.8 %) | 48.8 / 79.5 → 48.0 / 77.7 |
| k = 5-9 | 4,874 | 0.0990 | 0.0990 | 0.0940 (−5.1 %) | 51.6 / 79.9 → 50.0 / 80.2 |
| k = 10-19 | 5,940 | 0.0890 | 0.0890 | 0.0862 (−3.1 %) | 51.0 / 79.1 → 49.3 / 78.1 |
| k = 20+ | 45,977 | 0.0801 | 0.0801 | 0.0788 (−1.6 %) | 50.5 / 80.2 → 50.4 / 79.9 |
| 1000 pieces | 3,410 | 0.1429 (bias −2.3 %) | 0.1317 | 0.1236 (−13.5 %, bias +0.9 %) | 52.0 / 81.9 → 52.1 / 80.8 |
| pace interpolated | 564 | 0.1708 | 0.1643 | 0.1677 (−1.8 %) | 39.2 / 66.8 → 47.3 / 75.9 |
| pace 500-scaled | 11,888 | 0.1259 | 0.1208 | 0.1179 (−6.4 %) | 48.7 / 78.4 → 50.0 / 80.5 |
| players with 0-9 first tries | 2,304 | 0.1395 (bias +4.8 %) | 0.1386 | 0.1317 (−5.6 %, bias +0.9 %) | 38.5 / 65.4 → 52.2 / 82.2 |
| players with 300+ first tries | 11,278 | 0.0828 | 0.0801 | 0.0774 (−6.5 %) | 54.3 / 83.6 → 52.9 / 82.3 |
| competition | 3,721 | 0.0760 | 0.0755 | 0.0723 (−4.9 %) | 53.5 / 82.6 → 51.0 / 78.7 |
| dispersion ≥ 0.02 (k ≥ 5) | 419 | 0.1412 | 0.1412 | 0.1452 (**+2.9 %**) | 34.6 / 59.3 → 53.7 / 82.1 |
| unboxed first tries | 1,401 | 0.1582 (bias +10.5 %) | - | 0.1307 (−17.4 %, bias −0.4 %) | 31.7 / 58.0 → 49.1 / 77.2 |
| repeats > 12 months | 3,370 | 0.0996 | - | 0.0995 | 39.0 / 67.8 → 49.9 / 77.8 |
| repeats 3-12 months | 9,748 | 0.0812 | - | 0.0808 | 47.1 / 76.9 → 50.7 / 80.5 |
| all repeats | 42,728 | 0.0771 | - | 0.0768 (−0.3 %) | 50.1 / 79.8 → 50.2 / 79.9 |

A second split (fit before 2026-04-01, 97,101 first tries) agrees: 0.0927 → 0.0885 (−4.6 %), k = 0 −18.1 %, 1-2 −8.2 %,
3-4 −5.3 %, 5-9 −5.0 %, 10-19 −3.4 %, 20+ −1.6 %, unboxed −21 %. The one loss: puzzles players disagree about most
(dispersion ≥ 0.02, 0.6 % of first tries) - the weighted mean follows their slow outliers where the shown median does
not; their windows go from 35 / 59 to 54 / 82.

**Each part kept only if the time split confirmed it** (`--ablation`: the candidate refitted without one part at a time,
the same rows; MAE fit < 2026-06-01 / < 2026-04-01, all parts 0.0883 / 0.0885):

| part (review) | without it | kept |
|---|---|---|
| drift-adjusted index (P6) | 0.0889 / 0.0890 | yes |
| precision weights (P6) | 0.0889 / 0.0891 | yes |
| one posterior for every k (P6) | 0.0894 / 0.0895 | yes (k = 5-9: 0.0987 → 0.0939) |
| image estimate as the prior (P1) | 0.0905 / 0.0906 | yes |
| a variance per prior type (P1) | 0.0884 / 0.0885 | yes (nearly every puzzle has an estimate - little to separate) |
| relative centre offsets + experience (P3) | 0.0888 / 0.0889 | yes |
| puzzle-weighted brand prior (P8) | k = 0: 0.1499 / 0.1528 vs 0.1496 / 0.1525 | yes |
| first-try hygiene as index weights (P5) | 0.0884 / 0.0885 | **no** - no gain on either split |
| comment as an index outlier weight (F6) | 0.0884 / 0.0885 | **no** - no gain on either split |
| prior type in the window keys (F4) | k = 0 image hits 51.7 / 82.9 vs 51.5 / 82.8 | **no** - no change |
| pace-source window terms (P3) | interpolated 42.2 / 69.0, 500-scaled 48.4 / 78.6 | yes (→ 47.3 / 75.9, 50.0 / 80.5) |
| dispersion term (F8) | top bucket 34.4 / 58.9, 0.008-0.02 41.1 / 68.8 | yes (→ 53.7 / 82.1, 51.5 / 80.6) |
| competition window scale (F6) | 52.9 / 80.6 | yes (→ 51.0 / 78.7, ~8 % narrower) |
| unboxed offset + scale (F6) | 29.6 / 54.5, MAE 0.1605 | yes (→ 49.1 / 77.2, 0.1307) |
| repeat gap (P4) | > 12 m 39.0 / 67.8 | yes (→ 49.9 / 77.8) |

`CandidateFeatures::NOT_KEPT` lists the parts left off; `--candidate-off=…` and `--ablation` still measure them. Not
built (measured elsewhere or left for later): the k = 0 dispersion from the picture's look-alike numbers (F8 - traits
are not on the prediction's statements). The taste factor (P7) and the per-player event offset (data-mining F7) came
with v5 - "The taste factor (v5)" below.

**Isolation and cost.** Nothing of the candidate runs inside the shown model's jobs: `fit-prediction-model` and the
15-minute batch are exactly main's. The candidate has two commands of its own (`CandidateModelRecalculator`), each its
own process and transaction - `myspeedpuzzling:fit-candidate-prediction-model` (daily, after the shown fit: reads its
committed drift and tau) and `myspeedpuzzling:refresh-candidate-prediction-evidence` (every 15 minutes, after the
batch) - so a failing or killed candidate costs only the candidate's own rows. The add-time row is its own statement in
its own savepoint, after the shown predictions, and only while `PREDICTION_CANDIDATE_ROWS=1` (prediction-history.md).

Peak resident memory (VmHWM) on the production copy (306,796 qualifying first attempts), production settings
(`APP_ENV=prod`, `memory_limit=1G`; the container's limit is 512 MB):

| job | peak resident | time |
|---|---|---|
| shown fit alone (`fit-prediction-model`) | 434 MB; since 2026-10-27 265-268 MB (`APP_DEBUG=0`, "Memory of the daily fit") | 18 s; ~11-16 s |
| candidate fit (`fit-candidate-prediction-model`) | 334 MB | 32 s |
| candidate fit with the size curve (below) | ~360 MB | 42 s |
| candidate fit v5 with the taste factor (below, `APP_DEBUG=0`) | 273-333 MB (at the size curve) | + 36-83 s (loaded laptop) |
| candidate evidence refresh (every 15 min) | 312 MB | 14 s |
| 15-minute batch | +0 (the candidate is not in it) | - |

The candidate fit got there (from 437 MB when it ran inside the shown fit) by releasing what no later step reads
(`CandidateModelFitter::begin()` / `sample()` / `fitNoise()` / `addEvidence()` / `finish()`; the recalculator drops
the shown as-of difficulty, the priors and the image estimates after the samples - `PredictionEngine::releaseAsOfDifficulty()`
- and the attempt set and engines after the evidence; the window, unboxed and repeat steps run on the samples alone at
~120 MB), by reading the two 300k-row statements through a cursor (`CursorRows`: pdo_pgsql otherwise holds the whole
result in the client library next to the arrays built from it) with the attempt flags merged positionally (no id
map), and by fixed-size columns for the samples and the evidence (`SplFixedArray`). The fitted parameters, the evidence
and the brand priors are identical to the single-process version (compared on the copy). The fit's summary records
`memory_*` and `resident_peak_*` per step. (The candidate's numbers above were measured before the shown fit's memory work, which the candidate shares: its
attempt ids are packed, `CursorRows` fetches 5,000 rows instead of 20,000.)

#### The taste factor (v5, 2026-10-27)

Every puzzle is different and every player's taste is different (Jan): some players are relatively quicker at photos,
others at cartoons or at 1000 pieces than their pace and the puzzle's difficulty say. The candidate's model version 5
(`CandidatePredictionCalculator::MODEL_VERSION`) is v4 plus a term per player × puzzle on the log centre (switch
`taste`; prediction model review P7, time-model report §4):

    ln t += b_i + u_i · W x_j   (+ c_i at an event, switch competition_player)

- **x_j** (`TasteModel::features()`): the puzzle's 16 picture factors (`puzzle_difficulty_estimate.picture_factors`, a
  frozen PCA of the current picture's SigLIP 2 crop vector - unsupervised, no solve went into them; zeros without them
  or from another basis version than W was fitted on), its kind (box, assembled) and its size group (≤ 300, 1000, other
  than 500). It exists for a brand-new puzzle.
- **W** (rank 4 × 21): fitted once a day in the candidate's fit (`Model\TasteFitter`, after the centre offsets) on every
  player's residual against the candidate's centre without the taste (clipped at ±1) - by alternating ridge regressions
  over per-player sufficient statistics (so an iteration costs per player, not per first try; ridge 10 on W). The
  players' data never leaves the server.
- **[b_i, u_i]** = A⁻¹ g, closed form (`Value\TasteState`): A = diag(λ_b, λ_u, …) + Σ v vᵀ, g = Σ v r over the
  player's own first tries **before** the predicted one, v = [1, W x]; λ_b = 30 (the intercept), λ_u from {30, 100, 300}
  by a held-out time split inside the fit window (W on its oldest 75 %, each λ scored on the newest 25 % as of every
  first try - how it is served; prod copy: 100 on both splits). Nothing known = every term 0; a few first tries move it
  little.
- **c_i** (data-mining F7, reliability 0.51): the player's residuals at events less their taste term, shrunk:
  Σ ÷ (n + 10).
- **Window**: + w × vᵀ A⁻¹ v (the term's own uncertainty; w fitted with the other variance terms, prod copy 0.045). The
  window's calibration reads every first try's term from a W fitted **without that player** (two folds by player): with
  the W that saw the row, the window came out ~1 point too narrow after the cut (50 % hits 48.8 → 49.4 with the
  cross-fitted terms, v4 49.7).

**As of, stored** (`candidate_player_taste`, entity `CandidatePlayerTaste`): the daily fit writes every player's state
(A, g, the event sums - jsonb) after each of their first tries of the fit window, keyed by player + the first try's
moment (solve, then tracking - the models' as-of order); the table is rewritten with every fit (W changes daily). Live
(`GetCandidatePrediction`, the candidate's own statement - `StatisticalInputsSql::CANDIDATE_TASTE_COLUMNS`): the
player's latest state (one primary-key index step) and the picture's factors - **no extra statement**, and nothing on
any shown statement (`CandidateModelTest::testTheShownModelIgnoresEveryCandidateInput` pins it). Reconstructed
(`PredictionReconstructor`, in the candidate's own try/catch pass): the latest state valid strictly before the solve -
one statement for the player's states, one for the target puzzles' factors
(`CandidatePredictionsSideBySideTest::testTheTasteMovesLiveAndReconstructedPredictionsAlike`). A first try added after
the last fit joins the player's state with the next daily fit (live lags ≤ 1 day; as-of honest). Serving cost: one
5 × 5 Cholesky and a dot product - microseconds.

**Measured** (`evaluate-predictions --fit-before=… --candidate`, the same rows; v4 = `--candidate-off=taste,competition_player`
on the same code; the evaluation moves every player's state on with each first try after the cut in time order, as the
daily fits would; read-only production copy of 2026-10-27, picture factors of 43,378 pictures):

| group | n (June) | v3 shown | v4 candidate | **v5 candidate** | v4 → v5 | 50 / 80 % hits v4 → v5 |
|---|---|---|---|---|---|---|
| all first tries | 68,240 | 0.0928 | 0.0872 | **0.0846** | **−3.0 %** | 49.7 / 79.4 → 49.4 / 79.3 |
| players with 0-9 first tries | 2,304 | 0.1395 | 0.1323 | 0.1306 | −1.3 % | 45.5 / 73.6 → 46.1 / 76.3 |
| 10-29 | 9,418 | 0.1161 | 0.1084 | 0.1063 | −1.9 % | 49.9 / 79.8 → 50.4 / 80.4 |
| 30-99 | 20,971 | 0.0952 | 0.0902 | 0.0875 | −3.0 % | 47.6 / 77.4 → 48.4 / 78.1 |
| 100-299 | 24,269 | 0.0819 | 0.0770 | 0.0743 | −3.5 % | 50.5 / 80.3 → 49.5 / 79.6 |
| 300+ | 11,278 | 0.0828 | 0.0766 | 0.0736 | −3.9 % | 52.7 / 81.9 → 50.8 / 80.4 |
| k = 0 (picture only) | 3,737 | 0.1845 | 0.1441 | 0.1420 | −1.5 % | 52.8 / 83.3 → 52.6 / 84.1 |
| k = 1-2 | 4,758 | 0.1328 | 0.1166 | 0.1143 | −2.0 % | 50.5 / 81.3 → 51.1 / 81.3 |
| k = 3-4 | 2,954 | 0.1079 | 0.0976 | 0.0966 | −1.0 % | 49.0 / 78.7 → 47.6 / 78.3 |
| k = 5-9 | 4,874 | 0.0990 | 0.0921 | 0.0905 | −1.7 % | 49.2 / 79.2 → 49.0 / 79.1 |
| k = 10-19 | 5,940 | 0.0890 | 0.0852 | 0.0823 | −3.4 % | 49.7 / 78.0 → 49.2 / 78.0 |
| k = 20+ | 45,977 | 0.0801 | 0.0786 | 0.0757 | −3.7 % | 49.4 / 79.1 → 49.1 / 78.9 |
| 500 pieces | 53,030 | 0.0841 | 0.0806 | 0.0777 | −3.6 % | 49.8 / 79.3 → 49.5 / 79.2 |
| 1000 pieces | 3,410 | 0.1429 | 0.1191 | 0.1158 | −2.8 % | 51.3 / 81.6 → 51.9 / 82.1 |
| ≤ 300 pieces | 11,342 | 0.1160 | 0.1071 | 0.1062 | −0.8 % | 48.5 / 79.1 → 47.9 / 78.8 |
| at an event | 4,111 | 0.0759 | 0.0719 | 0.0673 | −6.4 % | 50.0 / 78.5 → 51.3 / 79.6 |

The second split (fit before 2026-04-01, 97,101 first tries) agrees: 0.0871 → 0.0845 (**−3.0 %**), 0-9 first tries
−1.1 %, 10-29 −2.3 %, 30-99 −2.8 %, 100-299 −3.8 %, 300+ −3.3 %, k = 0 −1.6 %, at an event −5.2 %; hits 49.8 / 79.3 →
49.5 / 79.5. **No experience bucket, k bucket or size group got worse on either split** (the review's bar: ≥ 1.5 %
overall, ≥ 3 % for 100+ first tries). Unboxed first tries: MAE unchanged, hits 50.2 / 77.9 → 51.4 / 79.5 (June),
49.5 / 78.3 → 48.1 / 75.9 (April - their state is read from the served W, not cross-fitted; 2,628 rows).

What the gain is made of (an offline prototype on the same as-of residuals of v4, June split): the player's intercept
alone −1.0 %, the picture factors alone −2.1 %, both in one rank-4 factor −2.5 %, with the kind and size and a separate
intercept (λ_b 30) −2.6 %, the ridge λ_u = 100 instead of 30 −3.2 % (April the same: −3.2 %); rank 8 or a weaker
ridge over-fitted (−1.2 to −1.9 %), λ_u 300 or 1000 shrank the gain away.
The event offset (switch `competition_player`, `--candidate-off=competition_player`): at events 0.0693 → 0.0673
(−2.9 %) June, 0.0697 → 0.0680 (−2.4 %) April, overall −0.1 / −0.2 %, no group worse by more than 0.0001 - kept.

Cost: the taste step of the daily fit took 36-83 s on the copy (3 grid fits on the inner split, the full window warm
started, 2 cross-fit folds; 160k first tries of 4,650 players; measured on a laptop shared with other jobs, load 15-40 -
the whole fit 197 s there, v4's was 42 s on a quiet machine) and **does not raise the fit's peak** - production settings
(`APP_DEBUG=0`, `memory_limit=1G`): peak resident 273 / 333 MB in two runs, both reached at the size curve; after the
taste step 155 MB in use (+25 MB: the pictures' factors, the rows, the fit). `candidate_player_taste` holds one row per first try of the fit window
(~160k rows, ~25 MB), rewritten in chunks of 5,000 in the fit's transaction. The 15-minute evidence refresh and the
batch are untouched. (Measured with `APP_DEBUG=1` the step looked 180 MB heavier - the debug query logger keeps the
inserts' parameters; production runs without debug.)

#### Candidate v6 (2026-10-27)

Model version 6 (`CandidatePredictionCalculator::MODEL_VERSION`) = v5 + three parts, each a switch of
`CandidateFeatures` and kept only because both time splits confirmed it; one proposal measured and left out.

- **The image prior's own variance** (switch `image_interval`, time-model review F1 re-tested on the 1.2.1 estimates):
  v = v_image × (the estimate's variance from its 80 % interval ÷ the window's median one)^0.5
  (`CandidateModelParameters::imagePriorVariance()`, v_image by the existing grid, the reference stored with the fit as
  `image_interval_reference`). An estimate the pipeline is less sure about gives way to the puzzle's first indices
  sooner and widens its k = 0 window. The exponent is fixed at 0.5 (`CandidateModelFitter::IMAGE_INTERVAL_EXPONENT`):
  the one the window picks (0.75 June, 1.0 April) made 1500+ pieces 0.5-1.1 % worse on June, 0.5 improved every k, size
  and experience group on both splits. The 50 % interval (p25/p75) measured the same as the 80 % one.
- **The k = 0 picture term** (switch `picture_dispersion`, data-mining F8): how alike the picture's pieces look -
  `Value\PictureLookalikes`: `traits` pieceLevel.real pieceLookalikeShare / pieceDistinctiveness, log colorRegions,
  `piece_scale` real nnShareBelow006 / logCandidates006 of the current picture - predicts where players will disagree
  before anybody solved the puzzle. `Value\PictureDispersionModel`: a ridge regression (standardised numbers, ridge 100)
  of the window's squared error less its other fitted terms over first tries at puzzles with 0-9 indices, centred on
  the median puzzle, then its weight with the other terms (`CandidateModelFitter::fitPictureDispersion()`); a signed
  variance term of a first try at k = 0 only (from the first index the puzzle's own dispersion speaks). Offline the
  numbers rank the measured disagreement weakly (Spearman 0.17), the `traits` numbers alone gave ~90 % of the k = 0 gain of
  the five together, `piece_scale` alone ~80 %; only k = 0 (with (1 - λ_k) for k = 1-4 the k = 1-4 windows got worse). The centre never moves.
- **The unboxed window as a variance of its own** (switch `unboxed_variance`): σ² + u instead of σ² × a scale (v4/v5:
  1.5-1.75), u by a grid against the window's unboxed first tries predicted by the real path. The unboxed noise does not
  grow with the boxed window: v5 covered 93 / 88 % of the k = 0 unboxed first tries in the 80 % window and 76 / 72 % at
  k = 20+ (June / April).

Measured (`evaluate-predictions --fit-before=… --candidate` on the read-only production copy of 2026-10-27 with the
production estimates 1.2.1 and the `traits` / `piece_scale` analyses of every current picture; every first try the
candidate predicts after the cut - 74,079 June, 105,724 April -, each row v5 and v6 on the same code; IS80 = the 80 %
window's interval score (width + 10 × the miss outside it, lower = better - a sharper window at the same coverage):

| group | n (June) | MAE v5 → v6 June | April | IS80 v5 → v6 June | April |
|---|---|---|---|---|---|
| all first tries | 74,079 | 0.08951 → 0.08940 (−0.12 %) | −0.11 % | 0.4192 → 0.4170 (−0.55 %) | −0.48 % |
| k = 0 (picture only) | 5,511 | 0.15500 → 0.15501 (centre unchanged) | 0.00 % | 0.7526 → 0.7346 (**−2.4 %**) | **−2.2 %** |
| k = 1-2 | 5,148 | 0.11615 → 0.11533 (**−0.71 %**) | −0.59 % | −1.2 % | −0.9 % |
| k = 3-4 | 3,179 | −0.17 % | −0.29 % | −0.6 % | −0.6 % |
| k = 5-9 | 5,206 | −0.36 % | −0.28 % | −0.6 % | −0.6 % |
| k = 10-19 / 20+ | 6,235 / 48,800 | −0.12 / −0.02 % | −0.10 / −0.02 % | −0.1 / −0.05 % | −0.2 / −0.05 % |
| players with 0-9 / 10-29 / 30-99 / 100-299 / 300+ first tries | | −0.02 / −0.14 / −0.13 / −0.15 / −0.11 % | −0.03 / −0.13 / −0.12 / −0.13 / −0.11 % | −0.3 / −0.5 / −0.6 / −0.6 / −0.7 % | −0.3 / −0.5 / −0.5 / −0.5 / −0.6 % |
| ≤ 300 / 500 / 1000 pieces | | −0.19 / −0.04 / −0.04 % | −0.16 / −0.04 / −0.10 % | −0.8 / −0.2 / −1.4 % | −0.7 / −0.2 / −1.2 % |
| odd sizes | 989 | −2.27 % | −1.61 % | −5.4 % | −3.8 % |
| 1500+ pieces | 173 | −0.17 % | −0.67 % | **+1.4 %** | −0.2 % |
| k = 0 box / assembled / flat pictures | | | | −2.6 / −3.0 / −2.0 % | −2.3 / −2.3 / −2.1 % |

No k, size or experience group got a worse MAE on either split; the one worse window is 1500+ pieces on June (173 rows,
its windows wider - 78.0 → 82.1 % in the 80 % window). Hits stay at 49.4 / 79.3 % overall on both splits. The parts
alone (v6 without the part → v6): the image interval MAE −0.12 / −0.11 %, k = 1-2 −0.71 / −0.59 %, IS80 −0.31 /
−0.28 % (k = 0 −0.8 %); the picture term MAE ±0 (window only), IS80 −0.18 / −0.17 %, k = 0 −1.2 / −1.1 %, every group's
IS80 better on both splits. The evaluator's own table (the 68,240 / 97,101 first tries all three models predict):
0.0840 → 0.0839 June, 0.0841 → 0.0840 April.

Unboxed first tries (1,401 June / 2,628 April after the cut): MAE 0.1263 → 0.1260 / 0.1206 → 0.1204, 80 % hits
80.0 → 79.7 / 75.6 → 75.9; the mean miss of the 80 % window over the k buckets 4.6 → 2.8 / 7.6 → 5.6 points (50 %:
6.0 → 3.0 / 5.7 → 3.9) - k = 0 93.1 → 89.6 / 87.8 → 85.2, k = 20+ 76.2 → 78.5 / 71.9 → 74.2. The April split's
75.6 % (the v5 TODO) is **not** the served W: calibrating the unboxed window on cross-fitted taste states (a W fitted
without the player, like the window's first tries) picked the same grid value on both splits and changed nothing - so
it was left out. In the April window the unboxed first tries hit 80.1 % in-sample; after the cut 75.9 % - the unboxed
first tries after April 2026 (twice as many as the window's 1,320) scatter more than the window's did.

**Measured and left out - robust index weights** (the v4 known loss: puzzles players disagree about most, 0.6 % of
first tries, the weighted mean 2.9 % worse than the shown median). A Huber weight (c = 2.5 noise s.d.) on each index's
distance from the as-of posterior, iterated: overall −0.06 / −0.04 %, the high-dispersion group −2.4 / −2.1 % - but
odd sizes +0.24 % on June, and it needs a re-weighting over all the puzzle's indices at every as-of moment (O(k²) in
the fit, not a sum the stored evidence can carry). The causal variant that can be stored (each index weighed once
against the posterior before it) did worse: at best −0.03 % overall (c = 3), odd sizes +0.6-1.7 % for c = 2-3.

Cost: no statement more anywhere. Live, the look-alike numbers ride on the candidate's own statement (two unique-index
steps on `puzzle_image_analysis`, `PictureLookalikes::JOINS`); reconstructed, on the targets' picture lookup the taste
factor already makes (`PictureFactorsLookup`); the shown statements carry none of it (the guard test). The daily fit
(production settings, `APP_DEBUG=0`, `memory_limit=1G`, on main's packed attempt set - "Memory of the daily fit"):
**peak resident 307-314 MB** (337 before the packed set; v5: 273-333), reached at the taste step, 73-76 s on the copy - the
fitted v6 parameters identical with and without the packed set; the picture term ~0.1 s of the window step, the unboxed grid 1.6 s.

#### Candidate v7 (2026-10-27): the difficulty-adjusted pace and the player's difficulty slope

Model version 7 (`CandidatePredictionCalculator::MODEL_VERSION`) = v6 + two parts, each a switch of `CandidateFeatures`
and kept because both time splits confirmed it (prediction model review F7 / P9 and data-mining F7).

**Why.** The pace is the decay-weighted median of a player's **raw** seconds (Step 1), so it carries the difficulty of
the puzzles they picked: a player who picks harder puzzles gets a slower pace and, multiplied by the puzzle's
difficulty, a prediction that is too slow - and the puzzles such players solve look easier than they are (their
indices are divided by an inflated pace). On v6 the players' bias still ran from +2.8 % (the tenth who pick the easiest
puzzles: slower than predicted) to -1.5 / -1.8 % (the tenth who pick the hardest). The shown drift had taken part of it
already: its residuals against the raw pace carry the selection, shrunk by n ÷ (n + 5) - so a pace corrected for the
selection must not keep that drift, or the selection is taken back twice.

- **The adjusted pace** (switch `adjusted_pace`, `Model\AdjustedPace`): per pace row (a pair's first try from the 5th
  on, like `player_pace_history`) the weighted median of ln seconds - the puzzle's log difficulty over the pair's first
  tries so far, with the baseline's weights (3-month plateau, 13.5-month half-life, ages to that first try), and the
  drift once more against it (`TimePredictionCalculator::drift()` over the residuals ln t - adjusted pace before -
  difficulty). One number per row, the **offset** = (ln adjusted pace + its drift) - (ln raw median + the shown drift)
  (the raw median with the same weights, so their rounding cancels), which the candidate adds wherever it reads a
  solver's pace and drift: the size curve's pace rows (`CandidateEngine::paceRowsAt()`) and every difficulty index
  (`CandidateEngine::solverInputs()` - an index from a pace of the solver's other sizes or their first pace takes the
  mean offset of their rows at the moment the index is known from).
- **Jointly with the puzzles' difficulty** (`CandidateModelFitter::adjustPace()`): every puzzle's candidate posterior as
  known at the end of the window (`difficultyWithoutOwnIndex()`: its indices known before that moment **less the
  player's own index** - a player's own time must not move their own pace twice - and its prior), the paces from it,
  the indices again with the offsets: `ADJUSTED_PACE_ITERATIONS` = 2 rounds of this alternating fixed point; then the
  window's evidence once more from the adjusted indices. The puzzle side of the fixed point measured nothing on its own
  (1 / 2 / 3 rounds -3.92 / -3.93 / -3.81 % June; 2 the best on April, -3.74 vs -3.64 %) - the gain is the players' side.
- **The player's difficulty slope** (switch `player_slope`, `TasteState::slopeTerm()`): γ (d - the player's mean d),
  γ = Σ (d - mean) s ÷ (Σ (d - mean)² + λ), s = the player's residual after their taste term, d = the puzzle's posterior
  log difficulty; λ = 2 (`TasteModel::SLOPE_LAMBDA`, in Σ (d - mean)² units - ~90 first tries of an average spread),
  from 5 first tries with a difficulty. Five more sums on the taste state (as of every first try, stored with it in
  `candidate_player_taste`; absent = 0 for states written before v7); the window's calibration reads the term with the
  taste's (cross-fitted by player). Split-half reliability of a player's slope on the v7 residuals 0.32 / 0.34 (players
  with 40+ first tries after the cut; data-mining F7: 0.22 on v3); the common slope is ~0 (+0.02), so it is personal.
  λ from a causal probe on both splits (each first try's slope from the player's earlier ones): 1 / 2 / 4 / 8 gave
  -0.26 / -0.29 / -0.24 / -0.17 % June, the same order on April.
- **Measured and left out**: centring the offsets on 0 (their mean is +0.035; the centre offsets absorb the level):
  -3.97 / -3.78 % instead of -3.93 / -3.74 %, but 1000 pieces 0.2-0.3 points worse. The offset without the drift
  recomputed (the shown drift kept): -1.90 % June (it takes the selection back twice); the drift recomputed against the
  raw pace alone (no selection): -0.25 %; the mean difficulty instead of the adjusted median: -0.98 %. Indices from
  other sizes' paces without an offset: -3.53 % instead of -3.93 %.

**Storage and serving** (no statement more anywhere; the shown model reads none of it -
`CandidatePredictionsSideBySideTest::testTheShownPredictionsAreTheSameWithAndWithoutTheCandidate` stores an offset and a
slope state and compares): the daily fit writes every pace row's offset to `candidate_pace_offset` (entity
`CandidatePaceOffset`, rewritten, a row only where the pair's offset moved: offsets are rounded to 4 decimals and kept
while they move less than `AdjustedPace::STEP` = 0.001 - **the fit computes with exactly the stored values**, so the
15-minute refresh right after it writes nothing, `testTheEvidenceRefreshRewritesWhatIsMissing`). Live: the latest
offset of each of the player's piece counts rides on their pace row on the candidate's own statement (one primary-key
step per row, `StatisticalInputsSql::candidatePaceColumns()`); a pace row written since the fit carries the pair's last
stored offset. The 15-minute refresh reads every stored offset (`CandidatePaceOffsets`) for the indices of the evidence
it writes. Reconstructed (`PredictionReconstructor`): one statement for the offsets of the player and of every solver
of the targets' puzzles, the rows valid strictly before each solve - live == reconstructed as of now
(`testTheAdjustedPaceMovesLiveAndReconstructedPredictionsAlike`). Like the priors' sums and the taste states,
reconstructions read offsets computed with today's knowledge of the puzzles (in-sample; the time split is the proof).

**Measured** (`evaluate-predictions --fit-before=… --candidate` vs `--candidate-off=adjusted_pace,player_slope` = v6 on
the same code; the read-only production copy of 2026-10-27 with the 1.2.1 estimates; the same 68,240 June / 97,101
April first tries, each v6 and v7 on the same row; error = mean |ln(actual ÷ predicted)|, IS80 = the 80 % window's
interval score; in brackets a 90 % player-cluster bootstrap interval of the June change):

| group | n (June) | v6 → v7 June | April | IS80 June / April |
|---|---|---|---|---|
| all first tries | 68,240 | 0.08394 → 0.08032 (**-4.32 %** [-4.62, -4.01]) | 0.08399 → 0.08054 (**-4.10 %**) | -3.75 / -3.67 % |
| k = 0 | 3,737 | -1.07 % [-1.86, -0.28] | -2.03 % | -0.81 / -1.47 % |
| k = 1-4 | 7,712 | -4.39 % | -4.35 % | -3.64 / -3.87 % |
| k = 5+ | 56,791 | -4.69 % | -4.30 % | -4.12 / -3.90 % |
| ≤ 300 pieces | 11,342 | -3.31 % | -3.96 % | -2.59 / -2.95 % |
| 500 pieces | 53,030 | -4.51 % | -3.99 % | -3.98 / -3.71 % |
| 1000 pieces | 3,410 | **-5.47 %** (bias +1.2 → +0.2 %) | -5.93 % | -4.85 / -5.59 % |
| 1000 · k = 0 / 1-4 / 5+ | 868 / 1,157 / 1,385 | -1.26 / -7.01 / -7.86 % | -2.16 / -6.80 / -8.70 % | |
| 1500+ pieces | 68 | -2.76 % [-9.4, +5.6] | -2.79 % (94) | +1.9 / -1.6 % |
| 1500+ · k = 0 / 1-4 / 5+ | 37 / 24 / 7 | +2.56 / -6.60 / -15.5 % | -2.43 / -1.29 / -9.8 % (54 / 30 / 10) | |
| odd sizes | 390 | -3.74 % | -2.91 % | -6.56 / -3.72 % |
| players with 0-9 first tries | 2,304 | **-14.6 %** (bias +2.8 → -0.4 %) | -15.6 % | -12.0 / -13.8 % |
| 10-29 / 30-99 | 9,418 / 20,971 | -7.54 / -3.69 % | -7.54 / -3.25 % | |
| 100-299 / 300+ | 24,269 / 11,278 | -2.29 / -2.50 % | -1.88 / -2.02 % | |
| own first tries at the size 0 / 1-4 | 1,788 / 4,574 | -4.85 / -3.87 % | -4.81 / -4.21 % | |
| 5-19 / 20-49 / 50+ | 12,905 / 12,368 / 36,605 | -8.35 / -4.48 / -2.13 % | -8.83 / -4.21 / -1.33 % | |
| selection decile 1 (easiest picks) | 6,188 | **-9.84 %**, bias +2.85 → +0.70 % | -10.15 %, +2.84 → +0.57 % | -8.65 / -9.91 % |
| deciles 2-9 | 6,188 each | -2.07 … -4.43 % | -1.42 … -3.93 % | |
| decile 10 (hardest picks) | 6,188 | **-5.78 %**, bias -1.48 → -0.45 % | -6.96 %, -1.82 → -0.57 % | -4.55 / -6.00 % |
| no pace at the size yet | 6,362 | -4.18 % | -4.40 % | |

The selection deciles: what the player picked at the size before the predicted first try (the decay-weighted mean log
difficulty of their puzzles, from the raw indices - the same deciles for v6 and v7; the evaluator's `selection decile`
groups). Hits stay at 49.3 / 79.1 % (June; v6 49.4 / 79.3) and 49.0 / 78.9 % (April; 49.5 / 79.3). The shown model's
predictions are identical in both runs.

**Every k, size, experience, own-count and selection group is better on both splits.** Not better: two cells under
200 rows whose intervals hold 0 - 1500+ pieces at k = 0 (+2.6 % June on 37 rows, -2.4 % April on 54; its bias moved
from -2.7 % to +2.2 %: the k = 0 centre offset of so few first tries falls back to the size group's) and odd sizes at
k = 0 (+1.5 / +1.0 %, 133 / 173 rows). Where it comes from: the slope alone -0.46 / -0.36 %, the adjusted pace alone
-3.93 / -3.74 %; the gain is largest where the pace rests on few first tries (players with 0-9: -15 %, own 5-19 at the
size: -8.5 %) - a median of raw seconds over a handful of first tries carries their puzzles' difficulty as noise, the
adjusted median does not.

Cost (production settings, `APP_DEBUG=0`, `memory_limit=1G`, a copy of the production copy, a laptop at load 45-60):
the daily fit's **peak resident 309-310 MB** (v6: 307-314), reached at the adjusted pace step (28 s, + 16 s for the
evidence once more); 222,963 offset rows written (31 MB with the index); the 15-minute refresh 272 MB, 25 s, 0 rows
right after the fit. No statement more on the live, the reconstructed or any shown path.

### Pair and team predictions - side by side, never shown (2026-10-27)

Pair/team results get no shown prediction (`predictable = false`; the shown model has only GroupCoordinationFitter's
global constants, used by the Insights synergy page). A model of their own is computed next to everything and **never
shown**: predictors `group` and `group_baseline` of `solving_time_prediction` (storage, the daily command, the switch:
[prediction-history.md](prediction-history.md) "Pair and team predictions"; the pair/team = the exact set of people,
[pairs-and-teams/README.md](../pairs-and-teams/README.md)). `Group\GroupPredictionModel` (pure), version 1:

**ln t̂ = ln c(size, first try / repeat, unknown members) + ln D + ln 1/R + s + o**

- **R - the members' paces as of the solve.** Σ 1 / (paceᵢ × e^driftᵢ) of every registered member with a pace at the
  piece count **before** the solve (`PaceTimeline::paceBefore()` - rules 1 and 2; never the first pace that a player's
  later rows give, which GroupExpectation's `paceAt()` uses) with the drift of their own row (an interpolated pace has
  none). **Unknown members** - guests, and players without a pace there - count with the known members' mean rate
  (R × members ÷ known); the coordination constant has a bucket per unknown count (0 / 1 / 2+) and the synergy carries
  the rest (their pace is a constant of the pair/team). A result with nobody known, unboxed, or on a secret puzzle is
  not predicted (~15 % of pair/team results: 11.4k of 74k on the copy have no member with a pace).
- **D - the shown difficulty as of the solve** (`Group\GroupDifficultyTimeline`, pinned equal to
  `PredictionEngine::difficultyAt()`): the median index from 5 indices on, 1-4 indices shrunk toward the picture estimate
  with the shown fit's τ, none: the estimate alone (`image`), else the average puzzle (`none`). *Why not the candidate
  v4's difficulty:* v4 gains ~0.003-0.005 of error on solo first tries at k ≥ 5, while a pair's/team's own error is
  ~0.11-0.18 (synergy, unknown members); the v4 posterior needs the whole first-attempt set, the noise model and its
  evidence in memory (334-360 MB) - the shown rule as of any moment costs ~10 MB (the puzzles with a pair/team result
  only, streamed a puzzle at a time). Worth revisiting when v4 is shown.
- **c** = the median of t ÷ (D ÷ R) per size (2 / 3 / 4+) × first try / repeat × unknown members on the fit window
  (GroupExpectation's rule; ≥ 30 results, else the bucket without unknown members, else the seed). Copy, fit < 2026-06:
  pairs 1.185 first / 1.074 repeat, with one guest 1.361 / 1.243; teams of 4+ 1.390 / 1.324, with 2+ unknown 1.641.
- **s - the pair's/team's synergy, as of the result:** its earlier residuals (ln t - the rule), fading with a half-life
  of 180 days, shrunk toward its **members' offsets** with a pseudo-count κ: (Σ fade·r + κ·m) ÷ (Σ fade + κ). m = the mean
  over its registered members of their residual sums ÷ (count + 5) - like with like: over their other all-known
  pairs/teams for an all-known one, over all their pairs/teams for one with an unknown member (the guests they bring are
  part of it). κ by a grid on the fit window's last 91 days of first tries, one for all-known pairs/teams and one for
  those with an unknown member: copy 12 / 1 (fit < 2026-06), 50 / 1 (fit < 2026-04) - for all-known ones the members'
  pooled offset (which includes this pair's own results, shrunk with 5) is the stronger evidence, so the pair-specific
  part is shrunk hard; with an unknown member it is the pair/team itself that has to learn the guest's pace.
- **o - centre offsets** (median residual after s, shrunk n ÷ (n + 200)): per the pair's/team's experience (earlier
  results 0 / 1-2 / 3-9 / 10+: a new pair/team +1.9 %), the difficulty's source (image +1.3 %, shrunk +1.1 %) and whether
  it was at an event (-2.5 %).
- **A repeat after the same pair's/team's own earlier time on the puzzle** = that time × 0.90 (fitted, 3.4k samples).
- **Windows:** the residual's scale per factor level, fitted by raking (kind, difficulty source - no difficulty at all
  counts as picture-only -, unknown members, experience, size: a new pair/team ×1.36, 2+ unknown ×1.24, rated puzzle
  ×0.93), the 10/25/75/90 % quantiles of
  the scaled residual per kind (first try / repeat / own earlier time) - all on the fit window's sequential, as-of
  residuals.

The reference `group_baseline` is **the existing global-constant rule** (GroupExpectation: c(size, first / repeat) ×
difficulty ÷ Σ 1/pace, no drift) as of the solve - paces before it, constants fitted on the fit window - only where it
can predict (every member registered with a pace, a rated puzzle: 33 % of first tries); its window = quantiles of its
residual per size.

**Measured - `myspeedpuzzling:evaluate-group-predictions --fit-before=` on a read-only copy of production (2026-10-27):**
fitted on everything solved before the cut, every result after it predicted as of its moment. Error = mean |ln(actual ÷
predicted)|; "rule" = the model's own coordination rule alone (c × D ÷ R with its buckets - no synergy, no offsets);
"existing rule" = the reference on the results it can predict, with the model's error on the same results.

| group (fit < 2026-06-01) | n | rule | **model** | 50 / 80 % hits | 50 % width | existing rule vs model (n) |
|---|---|---|---|---|---|---|
| first tries | 7,951 | 0.1523 | **0.1275 (−16.3 %)** | 49.8 / 79.4 | 20 % | 0.1037 vs **0.0885** (2,597) |
| pairs | 5,781 | 0.1396 | 0.1181 | 50.1 / 79.9 | 18 % | 0.1015 vs 0.0866 |
| teams of 3 | 302 | 0.1890 | 0.1646 | 49.3 / 77.1 | 27 % | 0.1200 vs 0.0976 (41) |
| teams of 4+ | 1,868 | 0.1857 | 0.1505 | 48.8 / 78.3 | 25 % | 0.1242 vs 0.1063 |
| experience 0 (a new pair/team) | 1,307 | 0.1979 | 0.1882 (−4.9 %) | 49.3 / 79.1 | 31 % | 0.1141 vs 0.1040 |
| experience 1-2 | 1,188 | 0.1815 | 0.1395 (−23 %) | 47.9 / 79.8 | 22 % | 0.1143 vs 0.1038 |
| experience 3-9 | 1,591 | 0.1583 | 0.1214 (−23 %) | 48.4 / 79.3 | 19 % | 0.1080 vs 0.0901 |
| experience 10+ | 3,865 | 0.1255 | 0.1057 (−16 %) | 51.0 / 79.5 | 18 % | 0.1006 vs 0.0849 |
| every member known | 3,973 | 0.1151 | 0.1080 | 50.0 / 79.2 | 16 % | 0.1037 vs 0.0885 |
| one unknown member | 2,974 | 0.1763 | 0.1356 (−23 %) | 49.8 / 80.0 | 21 % | - |
| 2+ unknown members | 1,004 | 0.2286 | 0.1805 (−21 %) | 48.4 / 78.8 | 31 % | - |
| difficulty from 5+ solves | 4,953 | 0.1318 | 0.1081 | 51.5 / 81.3 | 18 % | |
| 1-4 solves (shrunk) | 1,548 | 0.1710 | 0.1425 | 50.5 / 78.5 | 24 % | |
| the picture alone | 1,327 | 0.1970 | 0.1735 (bias +2.1 %) | 44.2 / 74.7 | 26 % | |
| no difficulty at all | 123 | 0.2629 | 0.2220 | 31.7 / 65.8 | 30 % | |
| at an event | 1,585 | 0.1610 | 0.1366 | 47.8 / 77.2 | 22 % | 0.0966 vs 0.0860 |
| repeats | 6,602 | 0.1361 | **0.1056 (−22 %)** | 50.8 / 80.5 | 16 % | 0.1118 vs 0.0920 (3,608) |
| after the pair's/team's own time | 1,143 | 0.1481 | 0.0901 | 47.2 / 77.5 | 13 % | 0.1358 vs 0.0890 |

A second split (fit < 2026-04-01, 11,726 first tries, 9,518 repeats) agrees: first tries rule 0.1500 → **0.1261
(−15.9 %)**, hits 50.3 / 80.2; existing rule 0.1018 → 0.0871 on its 3,830; new pairs/teams 0.1944 → 0.1849; one
unknown member 0.1737 → 0.1342; events 48.9 / 78.8; repeats 0.1350 → 0.1056, hits 51.2 / 80.2. Known misses (both
splits): the picture alone is biased (+2 %) and a bit narrow (44-46 / 75-76), no difficulty at all 31-32 / 64-66
(123-128 results, scaled like picture-only puzzles - too few for a level of their own).

**Every part kept only if both splits confirmed it** (`--off=<part>`, first tries; the model 0.1275 / 0.1261):

| part | without it | kept |
|---|---|---|
| synergy | 0.1516 / 0.1493 | yes - the whole gain |
| members' drift on their paces | 0.1282 / 0.1268 | yes |
| members' offsets as the synergy's prior | 0.1286 / 0.1272; new pairs/teams 0.1955 / 0.1920 (vs 0.1882 / 0.1849) | yes |
| fading (half-life 180 days) | 0.1285 / 0.1274 | yes |
| centre offsets (experience, difficulty source) | 0.1280 / 0.1266 | yes |
| event offset | 0.1280 / 0.1266; events 0.1390 / 0.1353 (bias −3.2 % → −0.7 %) | yes |
| event window scale | events' 80 % hits 76.8 → 74.1 / 78.1 → 75.4 (narrower where it was narrow already) | **no** |
| a repeat from the own earlier time | repeats 0.1108 / 0.1107 (vs 0.1056 / 0.1056) | yes |

(Before the "like with like" member prior the offsets came from every result alike: overall the same, all-known
pairs/teams 0.1094 / 0.1066 vs 0.1080 / 0.1052; members' offsets from all-known results only: new pairs/teams 0.1915.)

**The synergy, as of each solve** (F7 of the data-mining review measured it against current baselines: split-half 0.84,
SD of pair means 0.14): split-half correlation of each pair's/team's odd vs even results' mean residual against the as-of
rule, pairs/teams with 8+ results, whole history:

| results | pairs/teams | split-half | Spearman-Brown | SD of the means |
|---|---|---|---|---|
| pairs, 500 pieces, first tries, every member known (F7's subset) | 325 | 0.69 | 0.82 | 0.066 |
| every member known | 743 | 0.78 | 0.87 | 0.082 |
| with unknown members | 880 | 0.89 | 0.94 | 0.169 |
| all | 1,547 | 0.87 | 0.93 | 0.134 |

So with as-of paces the pair effect of all-known pairs is about half F7's spread (0.066 vs 0.14 - a stale member pace
inflated it) and still reliable (0.82); where a guest is in, the synergy also holds the guest's pace - twice the spread.

**Isolation and cost.** Nothing of it runs in the shown model's jobs or on the add-time / edit path: an added pair/team
time costs no statement whatever the switch says. One command of its own, `myspeedpuzzling:fit-group-prediction-model`
(daily, its own process and transaction): loads every pair/team result (`Group\GroupResultSetLoader` - the pace
history with its rows' drift through a cursor, the qualifying first attempts of the puzzles with a pair/team result a
puzzle at a time, ~74k results as plain columns), predicts what has no row yet with yesterday's fit, refits, writes its
own tables. Reads the shown fit's τ; writes nothing the shown model reads (`GroupPredictionRowsTest` snapshots the
shown predictions, every other predictor's rows, the coordination constants, the fits, the difficulty and the pace
history before and after). Peak resident (prod settings, production copy): the evaluation (one fit, three replays)
251-296 MB, 37 s loading + 10 s fitting; the daily job's first fill 293 MB, 68 s (74,354 times, both predictors) -
under the ~350 MB the 512 MB container leaves.

### Requirements

- Personal: At least 1 prior solo solve on the same puzzle
- Statistical: a pace for the piece count (own, interpolated, or the 500-scaled one of a thin-size rule) and at least
  one difficulty index (or a prior, side by side only)

### Example (Personal, N=1)

- Player's 1st attempt: 65 minutes
- Player's personal 1→2 ratio: 0.87 (13% improvement)
- Gap: 2 weeks (bucket: lt30d)

```
Predicted time: 65 × 0.87 = 56.55 → ~57 minutes
Likely window (50 %): 57 × exp(q25 σ) … 57 × exp(q75 σ), σ from the player's repeat spread
Display: "Attempt #2 (based on 1 prior solve): ~57 minutes · Likely 54–61 min · half of solves land inside"
```

### How windows are shown

Never raw: `Value\PredictionWindow` rounds the ends (nearest minute when the window is under 20 min wide, else the
nearest 5 min under 1 h / 10 min from 1 h on; ties inward, at least one step, never outward), `PredictionWindowFormatter`
writes "57–64 min", and the wording is "Likely … · half of solves land inside"; after a solve the recap says "Inside
your likely window – only half of solves do" or the side + margin ("3 min faster than likely"). The full rule,
examples and every surface: [prediction-history.md](prediction-history.md) "How windows are shown".

---

## Skill Improvement Chart

Monthly snapshots of player baseline and tier for historical tracking.

### Computation

For each month in the player's history:
1. Compute the weighted median using data up to that month's end
2. Record baseline, tier, and percentile

### Display

Line chart showing baseline over time, with tier boundary lines overlaid. Enables users to see their progression:

```
Jan 2025: 68 min (Intermediate)
Jul 2025: 58 min (Expert)
Mar 2026: 52 min (Expert)
```

---

## Minimum Thresholds Summary

| Feature | Requirement |
|---------|------------|
| Player baseline | 5 distinct first-attempt solo solves per piece count |
| Puzzle difficulty | 5 qualifying indices from different players |
| Player skill tier | 10 qualifying puzzles with 20+ first-attempt solvers each |
| MSP-ELO entry | 20 first attempts + 50 total solves within 24-month window |
| Memorability | 8 players with 3+ attempts |
| Skill sensitivity | 20 qualifying indices |
| Predictability | 20 qualifying indices |
| Box dependence | 10 unboxed + 5 boxed solvers |
| Player improvement ratio | 3 puzzles with that transition (cross-piece-count) |
| Personal prediction | 1 prior solo solve on the same puzzle |
| Statistical prediction | a pace for the piece count + 1 difficulty index (shrunk below 5; a prior alone side by side only) |
| Brand × pieces prior | 5 indices of other puzzles of the brand at the piece count |
| Window bucket of its own | 200 samples in the fit window (else a less specific bucket) |
| Improvement ceiling | 20 solvers |

---

## Configuration Constants

### Player Baseline
| Constant | Value |
|----------|-------|
| Min solves | 5 |
| Decay plateau | 3 months |
| Decay rate | 13.5 |

### Player Skill
| Constant | Value |
|----------|-------|
| Min qualifying puzzles | 10 |
| Min solvers per puzzle | 20 |
| Difficulty blend | 0.5 |
| Diff confidence threshold | 50 |
| Decay plateau | 6 months |
| Decay rate | 24 |

### MSP-ELO
| Constant | Value |
|----------|-------|
| Portfolio cap | 100 |
| Window | 24 months |
| Decay plateau | 3 months |
| Decay rate | 30 |
| Min first attempts | 20 |
| Min total solves | 50 |
| Min solvers per puzzle | 20 |
| First-attempt weight | 0.75 |
| Difficulty blend | 0.5 |
| Diff confidence threshold | 50 |

### Improvement Ratios
| Constant | Value |
|----------|-------|
| Max transition | 4 (4 = "4+") |
| Player min samples | 3 |
| Ratio outlier bounds | 0.1–5.0 |
| Gap buckets | lt30d, 1_3m, 3_12m, gt12m, all |
| Default ratio | 0.90 |

### Time Prediction
| Constant | Value |
|----------|-------|
| Safety floor | best_time × 0.70 |
| Holt's blend cutoff | N ≥ 6 (pure Holt's) |
| Drift | last 10 residuals, each clipped at ±0.5, ÷ (n + 5) |
| Spread | last 20 residuals clipped at ±0.5, + 5 × 0.13² pseudo-residuals |
| Prior pseudo-count | 20 indices (BrandPiecesPrior), τ fitted daily (default 1.0) |
| Minimum sigma | 0.045 |
| Fit | daily (`fit-prediction-model`), on the last 12 months |
| Model version | 3 (`TimePredictionCalculator::MODEL_VERSION`) |

---

## Visibility Rules

| Data | Public | Members Only |
|------|--------|-------------|
| Median time | Yes | -- |
| Solver count | Yes | -- |
| MSP-ELO ladder | Yes | -- |
| Methodology page | Yes | -- |
| Difficulty tier + score | -- | Yes |
| Puzzle personality metrics | -- | Yes |
| Time prediction | -- | Yes |
| Player skill tier + percentile | -- | Yes |
| Bell curve position chart | -- | Yes |
| Improvement chart | -- | Yes |
| Solve analysis recap | -- | Yes |
| Difficulty filter on puzzle list | -- | Yes |
| Hardest / easiest lists: the first 25 names | Yes | -- |
| Hardest / easiest lists: difficulty per puzzle, ranking up to 100 | -- | Yes |

Non-members see a blurred/locked CTA overlay triggering the membership modal (`#membersExclusiveModal`).

### Public hardest / easiest lists

`/en/puzzle/{pieces}-pieces/hardest|easiest` (hub piece counts with ≥ 50 rated puzzles) and
`/en/puzzle/brand/{slug}/hardest|easiest` (approved brands with ≥ 40), all 6 locales, else 404.
Rated = approved, not hidden, difficulty score of **medium or high** confidence; ordered by score,
then sample size, then name. Everybody sees the ranked names (image, brand on a piece-count list /
piece count on a brand list, solo solves, raw median solo time) with a locked difficulty button per
row; members get the tier badge + "x% harder than average" and up to 100 puzzles. The public HTML
never carries a score or tier name, not even hidden: the service hands non-members entries with
difficulty stripped (`DifficultyRanking::forPublic()`). Usability first (Jan, 2026-09-30): a
one-sentence lead, then the list; the explanation, the members call to action and the other lists
come after it (a sidebar on wide screens).
Code: `PuzzleDifficultyRankings` (1 h cache in the `difficulty_rankings_cache` pool), `GetDifficultyRankings`,
`PiecesDifficultyRankingController`, `BrandDifficultyRankingController`, `sitemap-difficulty.xml`.

---

## Batch Computation

All metrics are computed via a console command, not in real-time:

```
php bin/console myspeedpuzzling:recalculate-puzzle-intelligence
```

### Schedule

Cron (every 15 minutes):
```
*/15 * * * * docker compose exec web php bin/console myspeedpuzzling:recalculate-puzzle-intelligence
```

Daily (Insights: coordination constants + profile cards, ~2.5 s):
```
40 4 * * * docker compose exec web php bin/console myspeedpuzzling:recalculate-insights-daily
```

### Execution Order

1. Recompute all player baselines
2. Bring the pace history up to date (only the pairs whose solves changed)
3. Recompute improvement ratios (global + player)
4. Recompute all puzzle difficulty scores (as-of indices)
5. Prediction model (full runs): drift + spread on the pace history (the fit: daily `fit-prediction-model`)
6. Recompute derived metrics (as-of indices)
7. Recompute all player skill scores + percentiles
8. Recompute MSP-ELO ratings - and store, for Insights, every entry behind them (`player_rating_entry`) and the
   sorted times of every rated and nearly rated puzzle (`puzzle_rating_times`)
9. Record skill history and rating snapshots
10. Move the insights generation on (`insights_generation`; caches of batch data key on it)

Steps 8 (the Insights part) and 10 and the daily `myspeedpuzzling:recalculate-insights-daily` (pair/team coordination
constants, the puzzler profile cards): [`../insights/computation.md`](../insights/computation.md).

With `-v` the command prints the seconds of every step. Measured 2026-10-18 on a copy of the production data (solves
to 2026-10-11) on a laptop, where a steady-state run takes ~13 s (production ~11 s): pace history 0.7 s when nothing
changed (one aggregate statement + one read of the pairs' last rows), the difficulty preload 1.25 s instead of 0.5 s
(the solves plus the whole pace history and 290k lookups in PHP instead of one join) - about +1.5 s for the as-of
indices in all; peak memory of the run ~390 MB.

### Options

- `--player=UUID` — Recompute only for specific player (pace history: only that player's pairs)
- `--puzzle=UUID` — Recompute only for specific puzzle

### First-Time Setup

After initial migration, run the command to populate all data:
```
docker compose exec web php bin/console myspeedpuzzling:recalculate-puzzle-intelligence
```

### Writes: only what changed (2026-09-30)

Every run still recomputes everything, but writes only the rows whose values differ from what is stored. Until
2026-09-30 every run rewrote every row: ~105k row versions and ~175-270 MB of WAL per run, ~13 GB a day, 43 % of the
database's WAL - disk wear, not page latency.

- **Upserts** (`PuzzleIntelligenceRecalculator::writeChangedRows()`, the derived-metrics update, the history and
  snapshot statements) join the stored row in the `SELECT` and pass on only new rows and rows where a value
  `IS DISTINCT FROM` the stored one. An unchanged row never reaches `ON CONFLICT`: not written, not even locked
  (`ON CONFLICT ... DO UPDATE ... WHERE` would still lock, and so log, every row). The fresh values are cast to the
  column types before the comparison, so "equal" means a rewrite would have stored exactly the same value.
- **`computed_at`** is the moment the row's values last changed, no longer the last run. On `puzzle_difficulty` it
  moves with the difficulty columns only - the derived metrics never set it (neither before). Nothing reads it but
  the cleanups below; checked 2026-09-30: queries, templates, API, the incremental handler and the tests (one test
  uses it as a "was this row touched" marker). Should a "last recalculated" time ever be needed, it belongs in one
  place, not in every row.
- **Stale rows** are deleted by key: a row this run did not produce, unless its `computed_at` is not older than the
  run's start. Exactly what `computed_at < :now` removed while every produced row was rewritten with `:now`; a row
  written after the start (the incremental handler stamps its own clock) stays, as it always did. The cleanups of
  piece counts without skills/ratings and of private players' ratings are unchanged; `puzzle_difficulty`, the skill
  history and the snapshots are never deleted. `--player` produces one player's rows only, so - as before - it
  removes every other player's baselines, skills, ratings and improvement ratios until the next full run.
- **History and snapshots** keep one row per player, piece count and month (`player_skill_history`) or day
  (`player_rating_snapshot`), holding the latest values of that period. The first run of the period inserts the rows,
  later runs update the changed ones: the same rows with the same values as before. A snapshot's `computed_at` is when
  its values were last written, not the last run of the day. The snapshots are read by the weekly digest
  (`PlayerPeriodActivityReader`: rank and tier before and in the period) and stay as they are - Jan, 2026-10-18: they
  become the "your rating over time" chart of Insights. The history's only reader (`GetPlayerSkillHistory`) is not
  wired into any page at the moment.
- **Concurrency** is as before: every statement commits on its own at the same point of the run, readers see the same
  values meanwhile (a rewrite with equal values was invisible to them), and far fewer rows are locked. The one
  difference is a millisecond race: when the incremental handler updates a row between a batch statement's snapshot
  and its write, and the batch's value equals the one it saw, the handler's newer value now stays until the next run
  instead of being overwritten.

Measured 2026-09-30 on the production copy (data to 2026-09-25 16:29), old and new code on identical copies with the
same frozen clock, Postgres 16 with production's WAL settings (lz4 compression, full-page writes) and a checkpoint
before every run, as production checkpoints every 5 minutes and the cron runs every 15:

| Run | Old: WAL / rows written | New: WAL / rows written |
|-----|------|------|
| Any run of the old code (19 measured) | 173-185 MB / ~104,600 | |
| Next cron slot after the copy's last production run (13 minutes of production changes) | 185 MB / 104,623 | 81 MB / 14,901 |
| Nothing changed since the previous run | 175 MB / 104,623 | 0 MB / 0 |
| 13 replayed 15-minute windows of real solves (2026-09-25 13:00-16:15) | 174-176 MB / ~104,620 each | 27-56 MB / 5,500-13,100 each |
| First run of a day 7 hours after the previous run (inserts the day's 17,479 snapshots) / of a month (+ 17,479 history rows) | 173 / 174 MB | 151 / 161 MB |
| A replayed day: 96 runs over the copy's last 24 hours of solves | ~16.4 GB / ~10 M (96 x 175 MB, every run is alike) | 2.2 GB / 0.82 M (median run 21 MB, largest 82 MB: 00:02, the day's first) |

Run time is unchanged, ~11 s in the steady state: history 0.25 s -> 0.02 s, snapshots 2.4 s -> 1.7 s, the difficulty
upsert 1.2 s -> 1.6 s (the join with the stored rows). Table contents after every compared run were identical, byte
for byte, apart from `id` and `computed_at` - with parallel query off, because `ImprovementRatioCalculator` orders
solves of one player and puzzle with equal timestamps as they come, so with parallel plans a few "4+" ratios differ
between any two runs, old code against old code too (`docs/TODO.md`). What still costs WAL are real changes: ratings
decay a little whenever a solve in a portfolio passes its time of day and move with every new solve on a popular
puzzle, skill percentiles shift with them, and every changed snapshot is an update into four indexes of a 2.7M-row
table - most of the remaining WAL.
