# Puzzle Insights System

## Overview

The Puzzle Insights System answers two core questions:

1. **How difficult is this puzzle?** — A normalized 7-tier difficulty score based on how players perform relative to their own baseline.
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
2. Otherwise, use the **oldest** solve (by `tracked_at`) as proxy first attempt
3. Only one first attempt per player-puzzle pair ever counts

---

## Core Algorithm

The computation is sequential and non-circular:

```
Step 1: Player Baselines       (independent — no puzzle data needed)
Step 2: Difficulty Indices      (depends on Step 1)
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

## Step 2: Difficulty Index (Per Solve)

For each qualifying solve of a puzzle:

```
difficulty_index = seconds_to_solve / player_baseline
```

- Index < 1.0: puzzle was easier than average for this player
- Index = 1.0: exactly average
- Index > 1.0: puzzle was harder than average

**Qualification criteria:**
- The solve is a first attempt (per the resolution rules above)
- The solve is solo, non-suspicious, with `seconds_to_solve IS NOT NULL`
- The solver has a valid baseline for this piece count
- The resulting difficulty_index is <= 5.0 (outlier ceiling)

---

## Step 3: Puzzle Difficulty

```
puzzle_difficulty = median(all qualifying difficulty indices for that puzzle)
```

### Minimum Threshold

At least **5 qualifying difficulty indices** from different players are needed. Below this, no difficulty score is assigned.

### Difficulty Tiers

| Tier | Score Range | Label |
|------|------------|-------|
| 1 | < 0.70 | Very Easy |
| 2 | 0.70 - 0.85 | Easy |
| 3 | 0.85 - 0.95 | Moderate |
| 4 | 0.95 - 1.05 | Average |
| 5 | 1.05 - 1.20 | Challenging |
| 6 | 1.20 - 1.45 | Hard |
| 7 | > 1.45 | Extreme |

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

Difficulty score: **1.085** — Tier 5 (Challenging), Confidence: Low (6 indices)

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
| 1 | Bottom 25% | Casual |
| 2 | Top 75% | Enthusiast |
| 3 | Top 50% | Proficient |
| 4 | Top 30% | Advanced |
| 5 | Top 15% | Expert |
| 6 | Top 5% | Master |
| 7 | Top 1% | Grandmaster |

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

Personalized estimate for how long a puzzle will take a specific player.

### Prediction Hierarchy

| Prior Solves (N) | Method |
|---|---|
| 0 | Statistical: `baseline × difficulty` |
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

### Safety Bounds

- Floor: `best_time × 0.70` (max 30% improvement over personal best)
- Range spread: 15% for N=1, residual-based MAD×1.5 for N≥2

### Statistical Prediction (N=0)

```
predicted_time = player_baseline × puzzle_difficulty
range_low = baseline × P25
range_high = baseline × P75
```

### Requirements

- Personal: At least 1 prior solo solve on the same puzzle
- Statistical: Player baseline + puzzle difficulty score (at least Low confidence)

### Example (Personal, N=1)

- Player's 1st attempt: 65 minutes
- Player's personal 1→2 ratio: 0.87 (13% improvement)
- Gap: 2 weeks (bucket: lt30d)

```
Predicted time: 65 × 0.87 = 56.55 → ~57 minutes
Range: 57 ± 15% → 48–65 min
Display: "Attempt #2 (based on 1 prior solve): ~57 minutes (48-65 min range)"
```

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
| Range spread (N=1) | 15% of predicted |
| Range spread (N≥2) | MAD × 1.5, min 5% |
| Holt's blend cutoff | N ≥ 6 (pure Holt's) |

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

Non-members see a blurred/locked CTA overlay triggering the membership modal (`#membersExclusiveModal`).

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

### Execution Order

1. Recompute all player baselines
2. Recompute improvement ratios (global + player)
3. Recompute all puzzle difficulty scores
4. Recompute derived metrics
5. Recompute all player skill scores + percentiles
6. Recompute MSP-ELO ratings
7. Record skill history snapshots

### Options

- `--player=UUID` — Recompute only for specific player
- `--puzzle=UUID` — Recompute only for specific puzzle

### First-Time Setup

After initial migration, run the command to populate all data:
```
docker compose exec web php bin/console myspeedpuzzling:recalculate-puzzle-intelligence
```
