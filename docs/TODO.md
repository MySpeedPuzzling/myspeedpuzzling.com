# TODO: Real-Time Puzzle Intelligence Updates

## Problem

All puzzle intelligence metrics (baselines, difficulty, skill, ELO, predictions) are computed hourly via `myspeedpuzzling:recalculate-puzzle-intelligence`. When a user adds a new solving time, their prediction and the puzzle's difficulty won't reflect it until the next hourly run. This creates a stale-data window of up to 60 minutes.

The full batch recalculation is expensive (touches all players and puzzles) and can't run on every solve. But most of what matters to the user who just solved a puzzle can be updated cheaply and incrementally.

## What Needs Updating When a Solve Is Added

### Dependency chain

```
Player Baseline  ←  affected if it's a first attempt on a new puzzle for this piece count
       ↓
Puzzle Difficulty  ←  affected if the player has a baseline (new difficulty index added)
       ↓
Derived Metrics  ←  memorability, skill sensitivity, predictability (need all indices)
       ↓
Player Skill  ←  needs all player's puzzles with difficulty scores
       ↓
MSP-ELO  ←  needs all players' portfolios, percentile pools
```

### Cost analysis

| Metric | Incremental cost | Batch cost | Worth real-time? |
|--------|-----------------|------------|-----------------|
| Player Baseline | Cheap — re-run weighted median for 1 player × 1 piece count | O(players × piece_counts) | Yes |
| Puzzle Difficulty | Cheap — re-run median for 1 puzzle | O(puzzles) | Yes |
| P25/P75 indices | Cheap — re-run percentiles for 1 puzzle | O(puzzles) | Yes |
| Derived Metrics | Medium — need all indices for 1 puzzle | O(puzzles) | Maybe |
| Player Skill | Expensive — need all puzzles for 1 player, then percentile rank among all players | O(players × puzzles) | No |
| MSP-ELO | Very expensive — portfolio-based, needs full recalc | O(players × puzzles) | No |
| Skill History | Monthly snapshot | Trivial | No |

### Recommendation: Tier the updates

**Tier 1 — Immediate (sync or fast async, <1s):**
- Player baseline for the affected piece count
- Puzzle difficulty (median + P25/P75) for the solved puzzle
- Time prediction for this player on this puzzle is then automatically correct on next page load

**Tier 2 — Fast async (<30s):**
- Derived metrics for the affected puzzle (memorability, predictability, etc.)

**Tier 3 — Keep in hourly batch:**
- Player skill scores (needs global percentile ranking)
- MSP-ELO (needs global portfolio comparison)
- Skill history snapshots

## Proposed Architecture

### Events to hook into

The existing event system already dispatches:
- `PuzzleSolved` — when a new solving time is created (sync routed)
- `PuzzleSolvingTimeModified` — when a solving time is edited (sync routed)
- `PuzzleSolvingTimeDeleted` — when a solving time is deleted (sync routed)

Currently handled by `RecalculatePuzzleStatisticsOnSolvingTimeChange` (basic stats like solver_count, median_time on the puzzle entity).

### New handler: `RecalculatePuzzleIntelligenceOnSolvingTimeChange`

```
Listens to: PuzzleSolved, PuzzleSolvingTimeModified, PuzzleSolvingTimeDeleted
Route: async (via Messenger)
```

Steps:
1. Load the solving time to get player_id, puzzle_id, piece_count
2. Call `PlayerBaselineCalculator::calculateForPlayer(playerId, pieceCount)`
3. If baseline changed → upsert to `player_baseline`
4. Call `PuzzleDifficultyCalculator::calculateForPuzzle(puzzleId)`
5. If difficulty changed → upsert to `puzzle_difficulty` (including P25/P75)
6. Optionally: call `DerivedMetricsCalculator::calculateForPuzzle(puzzleId)` for Tier 2

### Considerations

**Race condition with hourly batch:** If the async handler and the hourly cron run simultaneously, both are upsert-based (INSERT ON CONFLICT UPDATE), so the last writer wins. The hourly batch uses `computed_at` timestamps. The incremental handler should also update `computed_at`. Since both compute from the same source data, the result will be identical — no conflict.

**Deleted solves:** When a solve is deleted, we need the puzzle_id and player_id from the event (the entity is already gone). The `PuzzleSolvingTimeDeleted` event already carries this data.

**Modified solves:** When seconds_to_solve changes, both the player's baseline and the puzzle's difficulty may be affected. Same flow as new solve.

**Interpolated/extrapolated baselines:** If a player's direct baseline at one piece count changes, their interpolated baselines at other piece counts may need updating too. This is more complex and should stay in the hourly batch. The incremental handler should only update the direct baseline.

**No preloading in incremental mode:** The calculators have `preloadAllData()` methods for batch efficiency. For single-entity updates, skip preloading and use the per-entity query paths (already implemented as fallbacks).

## Implementation Order

1. Create `RecalculatePuzzleIntelligenceOnSolvingTimeChange` message handler
2. Extract the single-entity recalculation logic from `PuzzleIntelligenceRecalculator` into reusable methods (or call the calculators directly)
3. Route the handler as async in messenger config
4. Add to sync routing in dev config for testing
5. Test: add a solving time, verify baseline and difficulty update within seconds
