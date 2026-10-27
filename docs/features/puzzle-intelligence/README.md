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

### The candidate model (v4) - side by side, never shown

A second model computed next to the shown one (v3), to be launched as one `MODEL_VERSION` bump once Jan decides
(prediction model review 2026-10-26, proposals P1-P8 and data-mining F6/F8). **Nothing a player sees changes**: the
columns on `puzzle_solving_time`, `GetPlayerPrediction(s)`, the Picker, the recap and API v1 stay on v3;
`CandidateModelTest::testTheShownModelIgnoresEveryCandidateInput` and
`CandidatePredictionsSideBySideTest::testTheShownPredictionsAreTheSameWithAndWithoutTheCandidate` guard it. Its rows
are the `candidate` predictor of `solving_time_prediction`, model version `CandidatePredictionCalculator::MODEL_VERSION`
(4) - first tries and repeats; storage and backfill: [prediction-history.md](prediction-history.md) "The candidate
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
built (measured elsewhere or left for later): the per-player competition offset (F6 - only the competition window
scale is in), the k = 0 dispersion from the picture's look-alike numbers (F8 - traits are not on the prediction's
statements) and the taste factor (P7).

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
| shown fit alone (`fit-prediction-model`, unchanged) | 434 MB | 18 s |
| candidate fit (`fit-candidate-prediction-model`) | 334 MB | 32 s |
| candidate fit with the size curve (below) | ~360 MB | 42 s |
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
`memory_*` and `resident_peak_*` per step.

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
