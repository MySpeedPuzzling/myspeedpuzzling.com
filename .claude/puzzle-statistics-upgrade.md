# Puzzle Statistics Query Optimization - Step 2

This document outlines the step-by-step plan to update all read queries to use the new `puzzle_statistics` table and `puzzling_type`/`puzzlers_count` columns on `puzzle_solving_time`.

## Prerequisites

Before starting, ensure Step 1 is complete:
- [x] `puzzle_statistics` table created
- [x] `puzzling_type` and `puzzlers_count` columns added to `puzzle_solving_time`
- [x] Migrations run
- [x] `myspeedpuzzling:recalculate-puzzle-statistics` command executed

---

## Phase 1: HIGH IMPACT - Replace Aggregations with `puzzle_statistics` Table

These changes eliminate expensive GROUP BY + COUNT/AVG/MIN aggregations by reading precomputed values.

### 1.1 SearchPuzzle.php - `byUserInput()`

**File:** `src/Query/SearchPuzzle.php`
**Method:** `byUserInput()` (line 80)

**Changes:**
- Replace `LEFT JOIN puzzle_solving_time pst ON pst.puzzle_id = pb.puzzle_id` with `LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = pb.puzzle_id`
- Replace aggregation columns (lines 163-169):
  ```sql
  -- FROM:
  COUNT(pst.id) AS solved_times,
  AVG(CASE WHEN pst.team IS NULL THEN pst.seconds_to_solve END) AS average_time_solo,
  MIN(CASE WHEN pst.team IS NULL THEN pst.seconds_to_solve END) AS fastest_time_solo,
  AVG(CASE WHEN json_array_length(pst.team->'puzzlers') = 2 THEN pst.seconds_to_solve END) AS average_time_duo,
  MIN(CASE WHEN json_array_length(pst.team->'puzzlers') = 2 THEN pst.seconds_to_solve END) AS fastest_time_duo,
  AVG(CASE WHEN json_array_length(pst.team->'puzzlers') > 2 THEN pst.seconds_to_solve END) AS average_time_team,
  MIN(CASE WHEN json_array_length(pst.team->'puzzlers') > 2 THEN pst.seconds_to_solve END) AS fastest_time_team

  -- TO:
  COALESCE(ps.solved_times_count, 0) AS solved_times,
  ps.average_time_solo,
  ps.fastest_time_solo,
  ps.average_time_duo,
  ps.fastest_time_duo,
  ps.average_time_team,
  ps.fastest_time_team
  ```
- Remove GROUP BY clause (lines 173-184)
- Update ORDER BY to not reference removed columns

**Tests to verify:** Run existing tests for SearchPuzzle

---

### 1.2 GetPuzzleOverview.php - All 3 Methods

**File:** `src/Query/GetPuzzleOverview.php`
**Methods:** `byEan()`, `byId()`, `byTagId()`

**Changes for each method:**
- Replace `LEFT JOIN puzzle_solving_time` with `LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = puzzle.id`
- Replace aggregation columns with direct reads from `ps.*`
- Remove GROUP BY clause

**Example for `byId()` (lines 103-128):**
```sql
-- FROM:
COUNT(puzzle_solving_time.id) AS solved_times,
AVG(CASE WHEN team IS NULL AND seconds_to_solve > 0 THEN seconds_to_solve END) AS average_time_solo,
...

-- TO:
COALESCE(ps.solved_times_count, 0) AS solved_times,
ps.average_time_solo,
ps.fastest_time_solo,
ps.average_time_duo,
ps.fastest_time_duo,
ps.average_time_team,
ps.fastest_time_team
```

**Tests to verify:** Run existing tests for GetPuzzleOverview

---

### 1.3 GetPuzzlesOverview.php - `allApprovedOrAddedByPlayer()`

**File:** `src/Query/GetPuzzlesOverview.php`
**Method:** `allApprovedOrAddedByPlayer()` (line 20)

**Changes:**
- Replace `LEFT JOIN puzzle_solving_time` with `LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = puzzle.id`
- Replace aggregation columns (lines 35-41)
- Remove GROUP BY clause (line 48)

**Tests to verify:** Run existing tests for GetPuzzlesOverview

---

### 1.4 GetMostSolvedPuzzles.php - `top()`

**File:** `src/Query/GetMostSolvedPuzzles.php`
**Method:** `top()` (line 20)

**Changes:**
- Change FROM clause: `FROM puzzle_statistics ps` instead of `FROM puzzle_solving_time`
- Join puzzle: `INNER JOIN puzzle ON puzzle.id = ps.puzzle_id`
- Replace aggregations with direct column reads:
  ```sql
  ps.solved_times_count AS solved_times,
  ps.average_time_solo,
  ps.fastest_time_solo
  ```
- Update GROUP BY to only include puzzle and manufacturer

**Note:** `topInMonth()` method CANNOT be optimized - it needs time-based filtering.

**Tests to verify:** Run existing tests for GetMostSolvedPuzzles

---

## Phase 2: MEDIUM IMPACT - Replace `json_array_length()` with `puzzling_type`

These changes replace expensive JSON parsing with indexed column lookups.

### 2.1 GetPuzzleSolvers.php - 3 Methods

**File:** `src/Query/GetPuzzleSolvers.php`

#### Method: `soloByPuzzleId()` (line 24)
- Line 49: Replace `AND puzzle_solving_time.team IS NULL` with `AND puzzle_solving_time.puzzling_type = 'solo'`

#### Method: `duoByPuzzleId()` (line 88)
- Line 125: Replace `AND json_array_length(team -> 'puzzlers') = 2` with `AND pst.puzzling_type = 'duo'`
- Line 123: Can remove `AND pst.team IS NOT NULL` (implied by puzzling_type)

#### Method: `teamByPuzzleId()` (line 164)
- Line 201: Replace `AND json_array_length(team -> 'puzzlers') > 2` with `AND pst.puzzling_type = 'team'`
- Line 199: Can remove `AND pst.team IS NOT NULL`

#### Method: `relaxCountsByPuzzleId()` (line 240)
- Lines 248-250: Replace with:
  ```sql
  COUNT(*) FILTER (WHERE puzzling_type = 'solo') AS solo_count,
  COUNT(*) FILTER (WHERE puzzling_type = 'duo') AS duo_count,
  COUNT(*) FILTER (WHERE puzzling_type = 'team') AS team_count
  ```

**Tests to verify:** Run existing tests for GetPuzzleSolvers

---

### 2.2 GetFastestPlayers.php - `perPiecesCount()`

**File:** `src/Query/GetFastestPlayers.php`
**Method:** `perPiecesCount()` (line 21)

**Changes:**
- Line 33: Replace `WHERE pst.team IS NULL` with `WHERE pst.puzzling_type = 'solo'`

**Tests to verify:** Run existing tests for GetFastestPlayers

---

### 2.3 GetFastestPairs.php - `perPiecesCount()`

**File:** `src/Query/GetFastestPairs.php`
**Method:** `perPiecesCount()` (line 21)

**Changes:**
- Line 69: Replace `AND json_array_length(team -> 'puzzlers') = 2` with `AND puzzle_solving_time.puzzling_type = 'duo'`
- Line 67: Can remove `AND puzzle_solving_time.team IS NOT NULL`

**Tests to verify:** Run existing tests for GetFastestPairs

---

### 2.4 GetFastestGroups.php - `perPiecesCount()`

**File:** `src/Query/GetFastestGroups.php`
**Method:** `perPiecesCount()` (line 21)

**Changes:**
- Line 69: Replace `AND json_array_length(team -> 'puzzlers') > 2` with `AND puzzle_solving_time.puzzling_type = 'team'`
- Line 67: Can remove `AND puzzle_solving_time.team IS NOT NULL`

**Tests to verify:** Run existing tests for GetFastestGroups

---

### 2.5 GetPlayerSolvedPuzzles.php - Multiple Methods

**File:** `src/Query/GetPlayerSolvedPuzzles.php`

#### Method: `soloByPlayerId()` (around line 144)
- Replace `team IS NULL` with `puzzling_type = 'solo'`

#### Method: `duoByPlayerId()` (around line 268)
- Replace `json_array_length(team -> 'puzzlers') = 2` with `puzzling_type = 'duo'`

#### Method: `teamByPlayerId()` (around line 395)
- Replace `json_array_length(team -> 'puzzlers') > 2` with `puzzling_type = 'team'`

**Tests to verify:** Run existing tests for GetPlayerSolvedPuzzles

---

### 2.6 GetPlayerStatistics.php - Multiple Methods

**File:** `src/Query/GetPlayerStatistics.php`

#### Method: `solo()` (around line 22)
- Replace `team IS NULL` with `puzzling_type = 'solo'`

#### Method: `duo()` (around line 69)
- Replace `json_array_length(team -> 'puzzlers') = 2` with `puzzling_type = 'duo'`

#### Method: `team()` (around line 122)
- Replace `json_array_length(team -> 'puzzlers') > 2` with `puzzling_type = 'team'`

**Tests to verify:** Run existing tests for GetPlayerStatistics

---

## Phase 3: Additional Optimizations

### 3.1 GetExportableSolvingTimes.php

**File:** `src/Query/GetExportableSolvingTimes.php`
**Method:** `byPlayerId()` (line 24)

**Changes:**
- Lines 45-50: Replace CASE expression for puzzling type:
  ```sql
  -- FROM:
  CASE
      WHEN pst.team IS NULL THEN 'solo'
      WHEN json_array_length(pst.team -> 'puzzlers') = 2 THEN 'duo'
      ELSE json_array_length(pst.team -> 'puzzlers')
  END AS group_size

  -- TO:
  pst.puzzling_type,
  pst.puzzlers_count
  ```

**Note:** This may require updating the Results DTO and export format.

---

### 3.2 GetUnsolvedPuzzles.php

**File:** `src/Query/GetUnsolvedPuzzles.php`

**Changes:**
- Replace complex JSON checks with `puzzling_type` column checks where applicable

---

## Phase 4: Verification & Cleanup

### 4.1 Run All Tests
```bash
docker compose exec web vendor/bin/phpunit --exclude-group panther
```

### 4.2 Run Static Analysis
```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
```

### 4.3 Manual Testing Checklist
- [ ] Puzzle search page loads correctly with statistics
- [ ] Puzzle detail page shows correct solo/duo/team times
- [ ] Fastest players leaderboard works
- [ ] Fastest pairs leaderboard works
- [ ] Fastest groups leaderboard works
- [ ] Player profile shows correct statistics
- [ ] Most solved puzzles page works

### 4.4 Performance Verification
Compare query times before and after for:
- Puzzle search with filters
- Puzzle detail page load
- Leaderboard pages

---

## Files NOT to Modify

These files need row-level data or time-based filtering:

| File | Reason |
|------|--------|
| `GetMostSolvedPuzzles.php:topInMonth()` | Filters by month/year |
| `GetMostActivePlayers.php` | Per-player aggregations |
| `GetStatistics.php` | Global stats (could create separate table later) |
| `GetPlayerChartData.php` | Player-specific, time-filtered |
| `GetCompetitionParticipants.php` | Competition-specific filtering |

---

## Rollback Plan

If issues are discovered:
1. Revert query changes
2. Old queries will work - data in puzzle_statistics is supplementary
3. Statistics will still update via domain events

---

## Implementation Order

Recommended order to minimize risk:

1. **Phase 2 first** (puzzling_type replacements) - Lower risk, isolated changes
2. **Phase 1 second** (puzzle_statistics joins) - Higher impact, needs careful testing
3. **Phase 3 last** - Optional optimizations
4. **Phase 4** - Verification after each phase
