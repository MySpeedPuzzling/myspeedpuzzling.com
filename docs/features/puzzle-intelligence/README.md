# Puzzle Intelligence System

## Overview

The Puzzle Intelligence System answers two core questions:

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
Step 1: Player Baselines    (independent — no puzzle data needed)
Step 2: Difficulty Indices   (depends on Step 1)
Step 3: Puzzle Difficulty    (depends on Step 2)
Step 4: Derived Metrics      (depends on Step 3)
Step 5: Player Skill         (depends on Steps 1 + 3)
Step 6: MSP-ELO              (independent ranking system)
Step 7: Skill History        (snapshot of current state)
```

---

## Step 1: Player Baseline

The player baseline is the **weighted median** of their first-attempt solo times for a given piece count. Recent solves are weighted more heavily using exponential decay.

### Formula

```
weight(solve) = exp(-age_in_months / 18)

Decay curve:
  Age  0 months: weight 1.00
  Age  6 months: weight 0.72
  Age 12 months: weight 0.51
  Age 18 months: weight 0.37
  Age 24 months: weight 0.26
  Age 36 months: weight 0.14
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

| Puzzle | Time | Age (months) | Weight |
|--------|------|-------------|--------|
| A | 45:00 | 2 | 0.895 |
| B | 48:30 | 4 | 0.801 |
| C | 50:15 | 6 | 0.717 |
| D | 52:00 | 8 | 0.641 |
| E | 47:20 | 10 | 0.574 |
| F | 55:00 | 14 | 0.460 |
| G | 62:00 | 20 | 0.329 |

Total weight: 4.417 — **below 5.0 threshold**, no baseline yet.

If they solve one more puzzle (age 0, weight 1.0), total becomes 5.417 — baseline computed.

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

Player skill measures how well a player performs **relative to puzzle difficulty**, preventing the gaming of solving only easy puzzles.

### Formula

For each puzzle the player solved (first-attempt, solo):

```
outperformance = puzzle_difficulty / player_difficulty_index
```

- outperformance > 1.0: Player was faster than the puzzle's difficulty predicts (skilled)
- outperformance = 1.0: Exactly average performance
- outperformance < 1.0: Player was slower than predicted (less skilled)

```
player_skill_score = median(outperformance across all qualifying puzzles)
```

### Why This Is Fair

**Player who only solves easy puzzles (difficulty ~0.80):**
- Their baseline is low (fast, because easy puzzles)
- Their difficulty index on easy puzzle: ~0.80 (close to difficulty)
- Outperformance: 0.80 / 0.80 = 1.0 — average, not rewarded

**Player who tackles hard puzzles (difficulty ~1.30):**
- Their baseline is higher (hard puzzles inflate times)
- If they beat the difficulty: index 1.15, outperformance = 1.30/1.15 = 1.13 — above average

### Minimum Threshold

Player needs >= **10 qualifying puzzles** (puzzles that have a difficulty score with at least Low confidence) for a skill tier to be computed.

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

A competitive ranking system separate from skill tiers. "MSP-ELO" = MySpeedPuzzling ELO.

### Entry Requirements (Per Piece Count)

- At least **15 first-attempt solo solves**
- At least **50 total solo solves**

### Algorithm

When processing a solve for player A on puzzle X:

1. Compute A's percentile rank among all solvers of puzzle X
2. Compute expected percentile based on ELO difference vs. solver pool average
3. K-factor: **60** for first 10 matches, **30** after
4. ELO change = K x (actual_percentile - expected_percentile)
5. Update rating

```
expected_percentile = 1 / (1 + 10^((avg_pool_elo - player_elo) / 400))
elo_change = K * (actual_percentile - expected_percentile)
new_elo = old_elo + elo_change
```

### Two Periods

**Monthly:**
- Fresh start at 1000 each month
- Higher K-factor for first 10 matches (placement phase)
- Creates monthly competition seasons

**All-time:**
- Persistent, never resets
- Starting rating: 1000
- No decay — inactive players keep their rating but active players overtake naturally
- Shows "last active X days ago" on ladder

### Starting Rating

All players start at **1000 ELO** in both monthly and all-time ratings.

---

## Step 7: Time Prediction

Personalized estimate for how long a puzzle will take a specific player.

### Formula

```
predicted_time = player_baseline * puzzle_difficulty
predicted_range_low = player_baseline * (puzzle_difficulty - stddev_of_indices)
predicted_range_high = player_baseline * (puzzle_difficulty + stddev_of_indices)
```

### Requirements

- Player must have a valid baseline for the puzzle's piece count
- Puzzle must have a difficulty score (at least Low confidence)

### Example

- Player 500pc baseline: 55 minutes
- Puzzle difficulty: 1.15 (Challenging)
- Puzzle index std dev: 0.12

```
Predicted time: 55 * 1.15 = 63.25 minutes
Range: 55 * (1.15 - 0.12) to 55 * (1.15 + 0.12) = 56.65 to 69.85 minutes
Display: "Estimated time: ~63 minutes (57-70 min range)"
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

| Feature | Requirement | "Need X more" display |
|---------|------------|----------------------|
| Player baseline | 5 distinct first-attempt solo solves per piece count | "Solve X more new Ypc puzzles" |
| Player skill tier | 10 first-attempt solos on puzzles with difficulty scores | "X more qualifying puzzles needed" |
| MSP-ELO entry | 15 first attempts + 50 total solos (per piece count) | "11/15 first attempts, 38/50 total" |
| Puzzle difficulty | 5 qualified player indices | "X more qualified solvers needed" |
| Memorability | 5+ repeat solvers | Only shown when available |
| Skill sensitivity | 10+ indices | Only shown when available |
| Predictability | 10+ indices | Only shown when available |
| Box dependence | 5+ unboxed solvers | Only shown when available |

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

Hourly cron:
```
0 * * * * docker compose exec web php bin/console myspeedpuzzling:recalculate-puzzle-intelligence
```

### Execution Order

1. Recompute all player baselines
2. Recompute all puzzle difficulty scores
3. Recompute derived metrics
4. Recompute all player skill scores + percentiles
5. Recompute MSP-ELO ratings
6. Record skill history snapshots

### Options

- `--full` — Force full recomputation
- `--player=UUID` — Recompute only for specific player
- `--puzzle=UUID` — Recompute only for specific puzzle

### First-Time Setup

After initial migration, run with `--full` to populate all data:
```
docker compose exec web php bin/console myspeedpuzzling:recalculate-puzzle-intelligence --full
```
