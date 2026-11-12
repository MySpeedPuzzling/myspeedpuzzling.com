# Trackerless times - audit of every reader (2026-10-30)

Every `src/` file reading `puzzle_solving_time` (174), classified for a row with `player_id` NULL: an imported event
result nobody has claimed (README "Anonymous slots": a pair/team, `created_via = 'event_import'`, team snapshot of
`{player_id: null, player_name: null, anonymous_slot: "a:<uuid>"}` puzzlers, its own `puzzling_team` named after the
sheet's team).

The PHP side (entity, events, handlers reading `->player`) is done in the commit "Trackerless solving times" - PHPStan
proves it. This file is the SQL side, which PHPStan cannot see.

## Wanted behaviour

- **Counted / shown:** global statistics, puzzle statistics ("Completed N×", team counts), the puzzle page Pairs/Teams
  tab (team name + event + "N puzzlers"), the round results page, the result detail, the team page, the event page.
- **Not shown until claimed (D4):** `GetFastestGroups`, `GetFastestPairs`, `GetRecentActivity::latest()` - their inner
  joins on the tracker already leave the row out; pin it with a test, do not rely on luck.
- **Nobody's:** every per-player surface (profiles, Players page, ratings, insights, XP, notifications) - a row
  without a tracker and without a registered member is naturally absent there.
- **Background jobs** skip it at the source.

## Must change

| File | Problem | Fix |
|---|---|---|
| `Query/GetPuzzleSolvers.php` (Duo/Team tabs, ~150/247) | `pst.player_id` into `PuzzleSolversGroup::$addedByPlayerId` (non-null) - TypeError | nullable; template shows the team name |
| `Services/PuzzlesSorter.php` (~740, `calculatePlayersIdentification`) | anonymous members all read `"..."` - every anonymous team collapses to one row | key by `puzzling_team_id` (fall back to the old key when null) |
| `Query/GetRoundResults.php` (~69 inner join, ~290 `identification()`) | inner join on the tracker drops the row; identification collapses | LEFT JOIN; key by `puzzling_team_id` |
| `Query/GetPuzzleResultDetail.php` (~133 `byTimeId`) | inner join `focus_player` - 404 for the result linked from the Teams tab | LEFT JOIN; `PuzzleResultAttempt::$trackedByPlayerId` nullable |
| `Query/GetParticipantsSheetState.php` (~445) | `strtolower($row['player_id'])` on NULL | skip rows without a tracker |
| `Query/GetSuspiciousTimeCandidates.php` (~244) | `SuspicionCandidate::$playerId` non-null - the nightly scan dies | `pst.player_id IS NOT NULL` at the source |
| `Services/PuzzleIntelligence/PuzzleIntelligenceRecalculator.php` (~831 `getPlayersWithSolves()`) | `DISTINCT player_id` returns NULL into `calculateForPlayer(string)` | `player_id IS NOT NULL` |
| `Services/Xp/XpChainRecomputer.php` (~833 `participantsOf()`) | tracker id first into `lock()` / `rebuildScope(string)` | no participants = nothing to do |
| `Query/GetSolvingTimePrediction.php` (~53 `resultForTime()`) | `player_id` into `forPuzzle(string)` | null tracker = no prediction |
| `Services/PuzzleStatisticsCalculator.php` (`player_best_per_type`) | `GROUP BY player_id, puzzling_type` puts every anonymous team in one NULL group - team average/median wrong | group by `COALESCE(puzzling_team_id, player_id, id)` - **only for the rows that have no tracker**, existing rows must group exactly as before |
| `Query/GetSolveTimeDistribution.php` | `COUNT(DISTINCT COALESCE(puzzling_team_id, player_id))` | hygiene - check it counts each anonymous team once |
| `Services/ParticipantImport/SiteSnapshotReader.php` | `$results[$row['player_id']]` with null = `""` key | skip null |

## Product decisions inside those files

- `Query/GetFastestGroups.php`, `Query/GetFastestPairs.php`: excluded until claimed (D4) - already, through the tracker
  inner join and the "registered public member" rule.
- `Query/GetRecentActivity.php::latest()`: excluded until claimed (D4) - already, inner join.

## Correctly hidden (inner join on the tracker = per-player surface)

GetNotifications, GetPlayerSolvedPuzzles, GetMostActivePlayers, GetDuplicateCandidates, GetProgressionDrift,
Services/Community/PlayerMomentDetector, GetSuspiciousTimeQueue, GetSuspiciousTimeCaseDetail (rows kept out of the scan
anyway), GetFinishedPhotosForAnalysis, GetFinishedPhotoAnalyses, Repository/FinishedPhotoAnalysisRepository (no photos),
GetRecentActivity::forPlayer() / ofPlayerFavorites().

## Already right (counted or naturally absent) - 146 files

52 count it correctly without a change (GetStatistics, GetCatalogueNumbers, GetBrandDirectory, GetBrandHub,
GetPiecesHub, GetMostSolvedPuzzles, CountCompetitionResults, GetCompetitionPuzzles, team page queries, sitemaps,
existence checks, write-side handlers, `HiddenPlayers` NULL-safe fragments, ...) and 94 only ever read a given
player's times or solo times. The per-file list is in `TrackerlessTimeQueryCoverageTest`.
