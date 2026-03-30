# Puzzle Intelligence v2 — Complete Formula Reference

## MySpeedPuzzling — March 2026

---

## Table of Contents

1. [Overview](#1-overview)
2. [Player Baseline](#2-player-baseline) (unchanged)
3. [Difficulty Index](#3-difficulty-index) (unchanged)
4. [Puzzle Difficulty](#4-puzzle-difficulty) (unchanged)
5. [Player Skill v2](#5-player-skill-v2) (revamped)
6. [MSP-ELO v2](#6-msp-elo-v2) (revamped)
7. [Puzzle Personality Metrics](#7-puzzle-personality-metrics) (revamped)
8. [Time Prediction](#8-time-prediction) (unchanged)
9. [Configuration Constants](#9-configuration-constants)
10. [Changes from v1](#10-changes-from-v1)

---

## 1. Overview

Puzzle Intelligence is the analytics engine behind MySpeedPuzzling. It transforms raw solve times into meaningful insights about puzzles and players.

The system builds upward in layers:

- **Player Baseline** — a player's typical solving speed per piece count (weighted median, time-decaying)
- **Difficulty Index** — how hard a specific solve was relative to the player's baseline
- **Puzzle Difficulty** — the consensus difficulty of a puzzle across all qualifying solvers
- **Player Skill** — how good a player is, measured by percentile ranking across puzzles with difficulty weighting
- **MSP-ELO** — competitive portfolio-based rating combining first-attempt and best-time performance
- **Puzzle Personality** — derived metrics (memorability, skill sensitivity, predictability, box dependence, improvement ceiling) that describe a puzzle's character
- **Time Prediction** — estimated solve time for a specific player on a specific puzzle

### 1.1 What Changed in v2

**Player Skill** was completely redesigned. The v1 formula used a self-referential "outperformance" ratio that normalized away absolute skill — the world's fastest puzzler could end up at percentile 30 because the formula rewarded inconsistency over excellence. v2 uses percentile-based measurement with difficulty weighting.

**MSP-ELO** was replaced with a portfolio-based system. The v1 chronological match-by-match ELO didn't account for puzzle difficulty, suffered from stale pool averages, and rewarded volume over quality. v2 evaluates players on their best results from a rolling 12-month window, combining first-attempt and best-time portfolios.

**Puzzle Personality metrics** were all improved with higher minimum thresholds, bounded ranges, and — for memorability — a completely new learning-curve approach that isolates puzzle-specific signal from the general repeat-solving advantage.

---

## 2. Player Baseline (per piece count) — Unchanged

The player's "typical" solving speed — a weighted median of their first-attempt solo times.

### Formula

```
weight(solve) = exp(-age_in_months / 18)
```

1. Collect all first-attempt solo solves for player + piece count.
2. Assign each a weight based on age (recent solves count more).
3. Sort by time ascending, accumulate weights.
4. Weighted median = solve where cumulative weight crosses 50% of total.

### Requirements

- Minimum: **5 distinct puzzles** with qualifying first-attempt solves.

### Notes

- The 18-month half-life means a solve from 18 months ago carries ~37% the weight of a today's solve. This naturally tracks player improvement over time.
- The baseline is used by the Difficulty Index and Time Prediction systems. It is NOT used by Player Skill v2 or MSP-ELO v2 (which are percentile-based).

---

## 3. Difficulty Index (per solve) — Unchanged

How hard a puzzle was for a specific player relative to their baseline.

### Formula

```
difficulty_index = seconds_to_solve / player_baseline
```

- < 1.0 = easier than average for this player
- = 1.0 = exactly average for this player
- > 1.0 = harder than average for this player

### Outlier Handling

- Indices > 5.0 are excluded from downstream calculations (outlier ceiling).

---

## 4. Puzzle Difficulty — Unchanged

The consensus difficulty of a puzzle, computed as the median of all qualifying difficulty indices.

### Formula

```
puzzle_difficulty = median(all qualifying difficulty_indices for this puzzle)
```

### Requirements

- Minimum: **5 qualifying indices** from different players.

### Tiers

| Tier | Range |
|------|-------|
| Very Easy | < 0.75 |
| Easy | 0.75–0.90 |
| Average | 0.90–1.10 |
| Challenging | 1.10–1.25 |
| Hard | 1.25–1.45 |
| Very Hard | > 1.45 |

---

## 5. Player Skill v2 (revamped)

How good a player is at solving puzzles, measured by difficulty-weighted percentile ranking. Computed per piece count, all-time (no rolling window).

### Why v1 Was Wrong

The v1 formula (`outperformance = puzzle_difficulty / player_difficulty_index`) divided by the player's own baseline, which normalized away absolute skill. A consistently fast player had a low baseline and always performed near it, yielding outperformance ~1.0. A variable casual player with occasional good days could score outperformance > 1.0. The formula rewarded inconsistency over excellence. Combined with a minimum of only 10 qualifying puzzles (allowing noisy small-sample medians to flood the percentile pool), this produced absurd results — the world's fastest puzzler at percentile 30.

### v2 Formula

For each first-attempt solve on a puzzle with a known difficulty score:

#### Step 1: Compute Percentile

```
percentile = count(slower_first_attempts_on_this_puzzle) / (total_first_attempt_solvers - 1)

0.0 = slowest among all first-attempt solvers
1.0 = fastest among all first-attempt solvers
```

This directly measures how fast the player is compared to everyone else on this puzzle — no self-referential baseline.

#### Step 2: Apply Difficulty Weight

The difficulty weight uses the same confidence-scaled blending as MSP-ELO v2, preventing mismeasured difficulty from distorting scores.

```
confidence = min(1.0, qualifying_difficulty_indices / DIFF_CONFIDENCE_THRESHOLD)
blend = DIFFICULTY_BLEND × confidence
difficulty_weight = (1 - blend) + blend × puzzle_difficulty
```

#### Step 3: Compute Weighted Percentile

```
weighted_percentile = percentile × difficulty_weight
```

#### Step 4: Aggregate

```
skill_score = median(all weighted_percentiles for this piece count)
```

### Requirements

- Minimum: **20 qualifying puzzles** (up from 10) with known difficulty per piece count.
- Puzzle must have at least MIN_SOLVERS_PER_PUZZLE (20) first-attempt solvers.
- Player must have a first-attempt solve on the puzzle.
- Public and private players both receive skill scores (private players are only excluded from ELO).

### Tiers

Percentile is computed among all players with valid skill scores for each piece count.

| Tier | Percentile |
|------|-----------|
| Casual | Bottom 25% |
| Enthusiast | Top 75% |
| Proficient | Top 50% |
| Advanced | Top 30% |
| Expert | Top 15% |
| Master | Top 5% |
| Legend | Top 1% |

### Why This Fixes the Bug

The world's fastest puzzler now gets 0.95+ percentile on nearly every puzzle, multiplied by difficulty weights (often > 1.0 for the hard puzzles they tackle). Their median weighted_percentile will be well above 1.0. A casual player with scattered results correctly averages out to a lower score. The increased minimum of 20 puzzles prevents lucky small samples from inflating the percentile pool.

### Difference from MSP-ELO v2

| Aspect | Player Skill v2 | MSP-ELO v2 |
|--------|----------------|-------------|
| Purpose | "How good are you?" (absolute grade) | "Where do you rank competitively?" |
| Time window | All-time | Rolling 12 months |
| Piece counts | Per piece count (expandable) | 500pc only |
| Solve types | First attempts only | First attempts (75%) + best times (25%) |
| Portfolio cap | None (all qualifying puzzles) | Top 100 |
| Best-time credit | None | 25% weight |
| Output | Tier label (Casual → Legend) | Continuous rating score |

---

## 6. MSP-ELO v2 (revamped)

Competitive portfolio-based rating. Replaces the original chronological ELO. Evaluates players on their best results from a rolling 12-month window, combining first-attempt and best-time portfolios with difficulty weighting.

### Why v1 Was Wrong

The original ELO had four known issues:

1. **No difficulty accounting** — beating others on easy puzzles gave the same ELO boost as beating them on hard ones.
2. **Stale pool average** — `avg_pool_elo` read from the previous run's data, not the current pass.
3. **Volume rewarding** — more matches = more chances to climb, regardless of quality.
4. **First-attempts-only** — no credit for genuine improvement through practice.

### v2 Design Goals

- **Reward quality over quantity:** A player with fewer but faster solves should outrank a volume grinder.
- **Value first attempts:** Solving a puzzle cold carries 75% weight — higher skill signal than grinding.
- **Account for difficulty:** Hard puzzles are worth more than easy ones.
- **Never punish non-repeaters:** Players who only solve once get identical entries in both portfolios.
- **Discourage ELO hunting:** Grinding, farming easy puzzles, and volume accumulation are all losing strategies.

### Formula

#### Step 1: Identify Qualifying Puzzles

For each player, collect all puzzles they solved within the rolling window (last WINDOW_MONTHS months). A puzzle qualifies only if it has been solved by at least MIN_SOLVERS_PER_PUZZLE (20) different players.

#### Step 2: Compute Percentiles

For each qualifying puzzle, compute two percentiles:

**First-Attempt Percentile** — rank the player's first-attempt time against all other players' first-attempt times on this puzzle.

```
first_attempt_percentile = count(slower_first_attempts) / (total_first_attempt_solvers - 1)
```

The first attempt only enters the first-attempt portfolio if it falls within the rolling window. If it's older (the player only has repeat solves in the window), this puzzle contributes only to the best-time portfolio.

**Best-Time Percentile** — rank the player's all-time fastest time against all other players' best times.

```
best_time_percentile = count(slower_best_times) / (total_solvers - 1)
```

For non-repeaters, both percentiles are identical.

#### Step 3: Apply Difficulty Weight

Same confidence-scaled blending as Player Skill v2:

```
confidence = min(1.0, qualifying_difficulty_indices / DIFF_CONFIDENCE_THRESHOLD)
blend = DIFFICULTY_BLEND × confidence
difficulty_weight = (1 - blend) + blend × puzzle_difficulty
```

Effective multipliers at full confidence (DIFFICULTY_BLEND = 0.5):

| Puzzle Tier | Difficulty | Weight |
|-------------|-----------|--------|
| Very Easy | 0.65 | 0.825 |
| Easy | 0.83 | 0.915 |
| Average | 1.00 | 1.000 |
| Challenging | 1.18 | 1.090 |
| Hard | 1.35 | 1.175 |
| Very Hard | 1.50 | 1.250 |

For puzzles with fewer qualifying indices, the weight is dampened toward 1.0 proportionally.

#### Step 4: Calculate Puzzle Points

```
first_attempt_points = first_attempt_percentile × difficulty_weight
best_time_points     = best_time_percentile × difficulty_weight
```

#### Step 5: Build Portfolios

**First-Attempt Portfolio:**
1. Collect first_attempt_points for all puzzles where the first attempt falls within the window.
2. Sort descending, take top CAP (100) entries.
3. Compute mean → `first_attempt_score`.

**Best-Time Portfolio:**
1. Collect best_time_points for all puzzles where any solve falls within the window.
2. Sort descending, take top CAP (100) entries.
3. Compute mean → `best_time_score`.

#### Step 6: Final Rating

```
rating = FIRST_ATTEMPT_WEIGHT × first_attempt_score + (1 - FIRST_ATTEMPT_WEIGHT) × best_time_score
rating = 0.75 × first_attempt_score + 0.25 × best_time_score
```

### Entry Requirements

All must be met within the rolling window:

- **MIN_PUZZLE_COUNT (50):** At least 50 qualifying puzzle results across both portfolios.
- **MIN_FIRST_TRIES_COUNT (20):** At least 20 first-attempt solves within the window. Prevents qualifying purely on repeat grinds — forces players to keep trying new puzzles.
- **Public profile:** Private players are excluded.
- **Eligible piece count:** Currently 500pc only.

### Worked Examples

#### The Cold Solver vs The Grinder

Same puzzle, Hard tier, difficulty = 1.35, full confidence. Difficulty weight = 1.175.

**Player A: Strong first attempt, never re-solves**
- First-attempt percentile: 0.92 | Best-time percentile: 0.92 (same)
- First-attempt points: 0.92 × 1.175 = 1.081
- Best-time points: 0.92 × 1.175 = 1.081
- **Blended: 0.75 × 1.081 + 0.25 × 1.081 = 1.081**

**Player B: Weak first attempt, grinds to excellence**
- First-attempt percentile: 0.60 | Best-time percentile: 0.95
- First-attempt points: 0.60 × 1.175 = 0.705
- Best-time points: 0.95 × 1.175 = 1.116
- **Blended: 0.75 × 0.705 + 0.25 × 1.116 = 0.808**

Player A wins decisively (1.081 vs 0.808), but Player B gets partial credit.

#### The Improver

**Player C: Decent first attempt, meaningful improvement**
- First-attempt percentile: 0.75 | Best-time percentile: 0.95
- First-attempt points: 0.75 × 1.175 = 0.881
- Best-time points: 0.95 × 1.175 = 1.116
- **Blended: 0.75 × 0.881 + 0.25 × 1.116 = 0.940**

Player C (0.940) closes the gap. Solid first attempt + genuine improvement is fairly rewarded, but raw cold-solving talent still leads.

#### Easy vs Hard Puzzle Farming

Both players achieve 90th percentile:

- **Very Easy puzzle** (diff 0.65, weight 0.825): 0.90 × 0.825 = **0.743**
- **Very Hard puzzle** (diff 1.50, weight 1.250): 0.90 × 1.250 = **1.125**

Same percentile, but the hard puzzle is worth **1.51x** more. Cherry-picking easy puzzles is a losing strategy.

### Anti-Gaming Properties

- **Grinding same puzzle:** Only affects one best-time portfolio entry (25% weight). First-attempt portfolio (75%) is locked.
- **Volume accumulation:** CAP of 100. After 100 qualifying puzzles, more solves only help if better than the weakest entry.
- **Easy puzzle farming:** Difficulty weighting makes hard puzzles worth more at the same percentile.
- **Avoiding new puzzles:** MIN_FIRST_TRIES_COUNT (20) within the window forces continued first-attempt activity.
- **Obscure puzzle exploitation:** MIN_SOLVERS_PER_PUZZLE (20) filters out thin percentile pools.

### Recalculation

MSP-ELO v2 is a snapshot calculation with no running state. Can be recalculated at any time (daily, weekly, on-demand) with identical results. This eliminates the v1 stale pool average issue.

---

## 7. Puzzle Personality Metrics (revamped)

### 7.1 Memorability v2 (redesigned)

Measures how quickly players learn a specific puzzle through repeated attempts. Replaces the v1 first-try/repeat ratio which produced nearly identical scores for all puzzles.

#### Why v1 Was Wrong

The v1 formula (`median(first_try_indices) / median(repeat_indices)`) measured whether any improvement happened on repeats. But the general "I've done this before" effect — seeing the image again, familiarity with the cut style — gives ~15–25% improvement on virtually every puzzle. This constant baseline swamped the puzzle-specific signal, making every puzzle look equally memorable. Selection bias in repeat solvers (they're motivated re-solvers, not random) further compressed the ratio.

#### v2 Formula: Learning Curve Approach

For each player with 3+ attempts on a puzzle, compute how steeply they improve across their first three solves:

```
player_learning_rate = (attempt_1_time - attempt_3_time) / attempt_1_time
```

This gives a 0–1 value: 0 means no improvement, 0.4 means 40% faster by the third attempt. Negative values are possible (worse on attempt 3) and should be kept — they're real signal about puzzles where practice doesn't help.

Then normalize against the global average to isolate puzzle-specific signal:

```
puzzle_learning_rate = median(player_learning_rates for this puzzle)
global_learning_rate = median(puzzle_learning_rates across ALL puzzles with memorability data)
memorability = puzzle_learning_rate / global_learning_rate
```

#### Interpretation

- **memorability = 1.0** — average learning curve. Typical repeat improvement.
- **memorability > 1.0** — more memorable than average. Distinctive image, recognizable regions, memorable piece shapes. Players learn this puzzle faster than typical.
- **memorability < 1.0** — less memorable. Uniform colors, abstract patterns, random-looking cuts. Practice helps less than usual.

#### Requirements

- Minimum: **8 players with 3+ attempts** on the puzzle (up from 5 players with any repeat).
- Attempts must be solo solves.
- Attempts are ordered by datetime.

#### Why This Is Better

The v2 formula measures the *speed* of learning, not just whether improvement exists. Two puzzles might both show 20% improvement eventually, but a memorable one shows it by attempt 3 while a forgettable one takes 8 attempts. The global normalization strips out the constant "I've done this before" effect that made v1 useless.

---

### 7.2 Skill Sensitivity (improved thresholds)

Measures how much a puzzle separates strong solvers from weak ones. A high value means the puzzle is a "skill check" — there's a big gap between the best and worst performances.

#### Formula

```
skill_sensitivity = percentile_75(difficulty_indices) / percentile_25(difficulty_indices)
```

#### Requirements

- Minimum: **20 qualifying indices** (up from 10).

#### Interpretation

- Close to 1.0 — everyone finds this puzzle similarly difficult relative to their baseline. The puzzle is an equalizer.
- High (e.g., 2.0+) — big gap between P75 and P25. Strong solvers find it relatively easy while weaker solvers struggle. The puzzle rewards skill.

---

### 7.3 Predictability v2 (bounded scale)

Measures how consistently a puzzle performs relative to player baselines. A predictable puzzle behaves "as expected" — your baseline is a good predictor of your time.

#### Why v1 Was Wrong

The v1 formula (`1 / CV`) had no natural ceiling. A very consistent puzzle (CV near 0) produced predictability approaching infinity, making the metric impossible to compare across puzzles or display meaningfully.

#### v2 Formula

```
coefficient_of_variation = std_dev(difficulty_indices) / mean(difficulty_indices)
predictability = 1 / (1 + coefficient_of_variation)
```

#### Scale

| CV | Predictability | Interpretation |
|----|---------------|----------------|
| 0.0 | 1.00 | Perfectly predictable (theoretical) |
| 0.2 | 0.83 | Very predictable |
| 0.5 | 0.67 | Moderately predictable |
| 1.0 | 0.50 | Unpredictable |
| 2.0 | 0.33 | Very unpredictable |

#### Requirements

- Minimum: **20 qualifying indices** (up from 10).

---

### 7.4 Box Dependence (improved thresholds)

Measures how much having the box image helps when solving. A high value means solvers without the box struggle significantly more.

#### Formula

```
box_dependence = median(unboxed_difficulty_indices) / median(boxed_difficulty_indices)
```

#### Interpretation

- Close to 1.0 — the box image doesn't matter much. Piece shapes and local patterns drive solving.
- High (e.g., 1.5+) — the box image is critical. Without it, the puzzle becomes much harder.

#### Requirements

- Minimum: **10 unboxed solvers with baselines** (up from 5).
- Minimum: 5 boxed solvers with baselines (unchanged).

---

### 7.5 Improvement Ceiling (new metric)

Measures how much a puzzle can be optimized through practice and skill. Captures the gap between a typical first attempt and the best performances across all attempts.

#### Formula

```
improvement_ceiling = P50(first_attempt_times) / P10(all_attempt_times)
```

Where P50 is the median and P10 is the 10th percentile (near-best, but not a single outlier).

#### Interpretation

- **Ceiling ~1.0–1.2** — "What you see is what you get." Even the best solvers and dedicated grinders barely beat a typical first attempt. The puzzle doesn't reward practice much.
- **Ceiling ~1.5–2.0** — Moderate optimization potential. Practice and skill yield meaningful time improvements.
- **Ceiling ~2.5+** — High optimization potential. The best times are dramatically faster than typical first attempts. This puzzle rewards deep practice — there are faster paths to discover.

#### Requirements

- Minimum: **20 solvers** (for reliable P50 of first attempts and P10 of all attempts).

#### Use Cases

- Helps competitive players decide which puzzles are worth grinding.
- Combined with memorability: high ceiling + high memorability = "learnable puzzle" (practice pays off quickly). High ceiling + low memorability = "technique puzzle" (practice pays off but through general skill improvement, not remembering the puzzle).

---

## 8. Time Prediction — Unchanged

```
predicted_time = player_baseline × puzzle_difficulty
range_low      = player_baseline × (puzzle_difficulty - stddev_of_indices)
range_high     = player_baseline × (puzzle_difficulty + stddev_of_indices)
```

Requires player baseline + puzzle difficulty score.

---

## 9. Configuration Constants

### 9.1 Player Baseline

| Constant | Value | Notes |
|----------|-------|-------|
| Baseline min solves | 5 | Distinct puzzles with first-attempt solves |
| Decay half-life | 18 months | `exp(-age_in_months / 18)` |

### 9.2 Puzzle Difficulty

| Constant | Value | Notes |
|----------|-------|-------|
| Min qualifying indices | 5 | From different players |
| Outlier ceiling | 5.0 | Indices above this excluded |

### 9.3 Player Skill v2

| Constant | Value | Notes |
|----------|-------|-------|
| MIN_SKILL_PUZZLES | 20 | Min qualifying puzzles per piece count (up from 10) |
| MIN_SOLVERS_PER_PUZZLE | 20 | Puzzle must have this many first-attempt solvers |
| DIFFICULTY_BLEND | 0.5 | Difficulty weight safety factor |
| DIFF_CONFIDENCE_THRESHOLD | 50 | Indices for full difficulty weight |
| PIECE_COUNTS | all | Per piece count (expandable) |

### 9.4 MSP-ELO v2

| Constant | Value | Notes |
|----------|-------|-------|
| CAP | 100 | Max portfolio size |
| MIN_PUZZLE_COUNT | 50 | Min qualifying puzzles in window |
| MIN_FIRST_TRIES_COUNT | 20 | Min first attempts in window |
| WINDOW_MONTHS | 12 | Rolling window |
| MIN_SOLVERS_PER_PUZZLE | 20 | Min solvers for puzzle to qualify |
| DIFFICULTY_BLEND | 0.5 | Difficulty weight safety factor |
| DIFF_CONFIDENCE_THRESHOLD | 50 | Indices for full difficulty weight |
| FIRST_ATTEMPT_WEIGHT | 0.75 | 75% first-attempt, 25% best-time |
| PIECE_COUNTS | [500] | Eligible piece counts |

### 9.5 Puzzle Personality Metrics

| Constant | Value | Metric |
|----------|-------|--------|
| Min players with 3+ attempts | 8 | Memorability |
| Min qualifying indices | 20 | Skill Sensitivity |
| Min qualifying indices | 20 | Predictability |
| Min unboxed solvers | 10 | Box Dependence |
| Min boxed solvers | 5 | Box Dependence |
| Min solvers | 20 | Improvement Ceiling |

---

## 10. Changes from v1

### 10.1 Player Skill

| Aspect | v1 | v2 |
|--------|----|----|
| Core formula | `outperformance = puzzle_difficulty / (time / baseline)` | `weighted_percentile = percentile × difficulty_weight` |
| What it measures | Deviation from own baseline (self-referential) | Absolute ranking among all solvers |
| Difficulty accounting | Implicit through ratio (amplifies baseline issues) | Explicit confidence-scaled weight |
| Minimum puzzles | 10 | 20 |
| Min solvers per puzzle | None | 20 |
| Known bug | World's fastest at percentile 30 | Fixed — fastest player gets highest percentile |
| Piece counts | 500 only | Per piece count (expandable) |

### 10.2 MSP-ELO

| Aspect | v1 | v2 |
|--------|----|----|
| Architecture | Chronological match-by-match | Portfolio-based snapshot |
| Solve types | First attempts only | First attempts (75%) + best times (25%) |
| Difficulty | Not accounted for | Confidence-scaled difficulty weight |
| Volume control | K-factor decay (60/30) | Portfolio CAP of 100 |
| Time window | All-time | Rolling 12 months |
| Pool average issue | Stale from prior run | Eliminated (no running state) |
| Entry requirements | 15 first attempts + 50 total | 50 puzzles + 20 first attempts in window |
| Grinding | Invisible | Small benefit via best-time portfolio |

### 10.3 Puzzle Personality Metrics

| Metric | v1 Issue | v2 Fix |
|--------|---------|--------|
| Memorability | First/repeat ratio always the same (~1.15–1.25) | Learning curve approach normalized against global average |
| Predictability | `1/CV` has no ceiling, blows up | `1/(1+CV)` bounded 0–1 |
| Skill Sensitivity | Min 10 indices (noisy P25/P75) | Min 20 indices |
| Box Dependence | Min 5 unboxed (one person's result) | Min 10 unboxed |
| Improvement Ceiling | Did not exist | New metric: P50(first) / P10(all) |

---

## Appendix A: Implementation Pseudocode

### A.1 Player Skill v2

```
function calculatePlayerSkill(player, pieceCount):
    weightedPercentiles = []

    for each puzzle with known difficulty:
        if puzzle.firstAttemptSolverCount < MIN_SOLVERS_PER_PUZZLE:
            continue
        if player has no first-attempt solve on puzzle:
            continue

        # Percentile
        playerTime = player's first-attempt time on puzzle
        slowerCount = count of first-attempt times on puzzle slower than playerTime
        percentile = slowerCount / (puzzle.firstAttemptSolverCount - 1)

        # Difficulty weight
        confidence = min(1.0, puzzle.qualifyingIndices / DIFF_CONFIDENCE_THRESHOLD)
        blend = DIFFICULTY_BLEND * confidence
        diffWeight = (1 - blend) + blend * puzzle.difficulty

        weightedPercentiles.append(percentile * diffWeight)

    if len(weightedPercentiles) < MIN_SKILL_PUZZLES:
        return null

    return median(weightedPercentiles)
```

### A.2 MSP-ELO v2

```
function calculateMspEloV2(player):
    cutoffDate = now() - WINDOW_MONTHS months
    firstAttemptEntries = []
    bestTimeEntries = []

    for each puzzle the player has solved:
        if puzzle.solverCount < MIN_SOLVERS_PER_PUZZLE:
            continue

        # Difficulty weight
        confidence = min(1.0, puzzle.qualifyingIndices / DIFF_CONFIDENCE_THRESHOLD)
        blend = DIFFICULTY_BLEND * confidence
        diffWeight = (1 - blend) + blend * puzzle.difficulty

        # Best-time portfolio: any solve in window qualifies
        if player has any solve on puzzle after cutoffDate:
            bestTime = player's fastest time on puzzle (all-time)
            btPercentile = rank(bestTime, allPlayersBestTimes(puzzle))
            bestTimeEntries.append(btPercentile * diffWeight)

        # First-attempt portfolio: first attempt must be in window
        if player's first attempt on puzzle is after cutoffDate:
            faTime = player's first-attempt time on puzzle
            faPercentile = rank(faTime, allPlayersFirstAttempts(puzzle))
            firstAttemptEntries.append(faPercentile * diffWeight)

    # Entry requirements
    totalEntries = len(set(puzzles from both portfolios))
    if totalEntries < MIN_PUZZLE_COUNT:
        return null
    if len(firstAttemptEntries) < MIN_FIRST_TRIES_COUNT:
        return null

    # Build portfolios
    firstAttemptEntries.sortDescending()
    bestTimeEntries.sortDescending()

    faScore = mean(firstAttemptEntries[:CAP])
    btScore = mean(bestTimeEntries[:CAP])

    return FIRST_ATTEMPT_WEIGHT * faScore + (1 - FIRST_ATTEMPT_WEIGHT) * btScore
```

### A.3 Percentile Calculation

```
function rank(playerTime, allTimes):
    slowerCount = count of times in allTimes where time > playerTime
    totalOthers = len(allTimes) - 1   # exclude the player themselves
    return slowerCount / totalOthers   # 0.0 = slowest, 1.0 = fastest
```

### A.4 Memorability v2

```
function calculateMemorability(puzzle):
    learningRates = []

    for each player with 3+ solo attempts on puzzle (ordered by datetime):
        t1 = attempt_1_time
        t3 = attempt_3_time
        learningRate = (t1 - t3) / t1
        learningRates.append(learningRate)

    if len(learningRates) < 8:
        return null

    puzzleLearningRate = median(learningRates)
    globalLearningRate = median of puzzleLearningRates across all puzzles
    return puzzleLearningRate / globalLearningRate
```

### A.5 Improvement Ceiling

```
function calculateImprovementCeiling(puzzle):
    firstAttemptTimes = all first-attempt times for puzzle
    allAttemptTimes = all attempt times for puzzle (including repeats)

    if len(firstAttemptTimes) < 20:
        return null

    return percentile_50(firstAttemptTimes) / percentile_10(allAttemptTimes)
```

### A.6 Predictability v2

```
function calculatePredictability(puzzle):
    indices = all qualifying difficulty indices for puzzle

    if len(indices) < 20:
        return null

    cv = stddev(indices) / mean(indices)
    return 1 / (1 + cv)
```

---

## Appendix B: Key Implementation Considerations

- **Percentile pools for ELO v2 include ALL players' times** (not just those within the window), so the pool is as large and stable as possible. The window only determines which puzzles enter a player's portfolio.
- **Best time for ELO v2 is all-time**, not just within the window. If a player set their best time 2 years ago but solved the puzzle again within the window, that all-time best is used.
- **First-attempt time is fixed** — always the very first time a player solved a puzzle.
- **Player Skill and MSP-ELO share the difficulty weight formula** (DIFFICULTY_BLEND, DIFF_CONFIDENCE_THRESHOLD). Changes to these constants affect both systems.
- **Global learning rate for memorability** should be cached and recalculated periodically (e.g., weekly), not per-query. It's a property of the entire puzzle ecosystem.
- **Recalculation**: both Player Skill and MSP-ELO are snapshot calculations. They can be recalculated at any time with identical results for the same input data. No running state, no order dependency.
