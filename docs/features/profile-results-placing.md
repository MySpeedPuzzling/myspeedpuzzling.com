# Profile results: sorting and filtering by placing

Feature request [019e0b68](https://myspeedpuzzling.com/en/feature-requests/019e0b68-84e3-7377-90bc-359613a59a05)
(issue #292): "sort the puzzles on my profile by ranking, both absolute and relative, to see what kind of images I am
relatively good at". Solo tab of the profile results (`PlayerSolvedPuzzles`) only - pair and team rows show no rank.

## What a player gets

| Piece | Who | Rule |
|---|---|---|
| Sorts "Best / Worst placing first" (`best_rank`, `worst_rank`) | everyone | the rank on the puzzle's solo leaderboard (best non-suspicious time, the puzzle page's pool - `GetRanking`); a tie = the bigger board first, then the time |
| Sorts "Best / Lowest top % first" (`best_share`, `worst_share`) | everyone | rank ÷ puzzlers on the board, the share of the rank chip (`RankShare`); boards under `RankShare::MIN_TOTAL` (10) come after the ones with a share, by place |
| Placing chips (`placing`: `top3`, `top10`, `top10_percent`, `top25_percent`, `bottom_half`) | everyone | `Value\PlacingFilter`, one at a time; the share chips count only boards of 10+. Part of the "Show more" list key, reset with the other filters, counted on the Filters button |
| Median line "Median placing: top 23% across 412 puzzles" | everyone | `Value\PlacingSummary` over the filtered list, boards of 10+ only; shown while every rank is loaded (a placing sort or chip) |
| Sorts "Most ahead / behind prediction" (`ahead_of_prediction`, `behind_prediction`) | members | the puzzle's **latest attempt** (finished date, else tracked; same day = added later - `PuzzlesSorter::latestAttempt()`): its time ÷ the prediction stored for it (`predicted_seconds` where `predictable = true`). Not predicted = last either way. A chip on each row says it ("Latest: 12% faster than predicted"). Not offered on the profile of a player who turned predictions off (`predictionsOptedOut` prop from `player.timePredictionsOptedOut`, docs/features/opt-out.md) |

A puzzle without a rank (relax only, every time waiting for verification) goes last in every placing sort and never
matches a chip. On the pairs / teams tabs the placing and prediction sorts are not offered; a sort left over from the
solo tab sorts them fastest first.

## Live, nothing precomputed

The component filters and sorts the whole history in PHP and renders 50 rows. Every rank comes from one statement,
`GetRanking::allForPlayer()`, loaded only while a placing sort is on (solo tab) or a placing chip is set; otherwise
the shown rows' ranks are fetched as before (`forPuzzles()`, which reads the full map once it is loaded - one
statement either way). The prediction is a column of the existing solo query - no statement.

**Measured on production 2026-10-26** (heaviest profiles, warm, `EXPLAIN ANALYZE`):

| Profile | One aggregate over all boards (before) | Per puzzle (LATERAL), old index |
|---|---|---|
| 2,159 puzzles, 87k board entries | 240-300 ms | 140-155 ms |
| 1,218 puzzles | 108-131 ms | 67-71 ms |
| 970 puzzles, popular ones | 360-430 ms | 222-268 ms |

The cost follows the size of the boards, not the number of puzzles. On top of the LATERAL rewrite:

- `custom_pst_solo_board` is keyed `(puzzle_id, player_id) INCLUDE (seconds_to_solve)` since `Version20261027020000`
  (was `(puzzle_id) INCLUDE (player_id, seconds_to_solve)`): each board arrives sorted by player, so the
  `COUNT(DISTINCT player_id)` needs no sort - on a copy of production 0.05-0.10 → 0.035-0.05 ms per board. Same entries
  and size; the Times boards and close calls look it up by `puzzle_id` and read the same pages.
- `GetRanking::allForPlayerCached()` keeps the map for `CACHE_TTL_SECONDS` (120) in the pool `player_rankings_cache`,
  keyed by the player, the viewer's pool (blocks, allow list) and a fingerprint of the player's own solo results (the
  component has them loaded anyway): the player's own changes show at once, other players' new times within two minutes.
  Without it every chip and "Show more" would recount every board.

Rejected: a stored rank per player and puzzle. One new time moves the rank of everybody slower on that puzzle (up to
~1,400 rows on popular puzzles), and the pool depends on the viewer (blocklist, private allow list), so one stored
number would be wrong for some viewers. A stored "puzzlers per board" count would let the query read only the faster
times, but it has to equal the board exactly through suspicious marks, merges, deletions and privacy changes - not worth
the invalidation paths while the live count is fast enough.

## Files

- `src/Component/PlayerSolvedPuzzles.php` (sorts, `placing`, `predictionsOptedOut`, `getPlacingSummary()`,
  `getLatestAttemptPredictionPercent()`), `templates/components/PlayerSolvedPuzzles.html.twig`, `templates/_player_solvings.html.twig`
- `src/Services/PuzzlesSorter.php` (`sortGroupedByPlacing()`, `sortGroupedByPrediction()`, `latestAttempt()`, `predictionRatio()`)
- `src/Value/PlacingFilter.php`, `src/Value/PlacingSummary.php`, `RankShare::ofFraction()`
- `src/Query/GetRanking.php`, `src/Query/GetPlayerSolvedPuzzles.php` (`predicted_seconds` on solo rows)
- Tests: `tests/Services/PuzzlesSorterPlacingTest.php`, `tests/Value/PlacingFilterTest.php`, `tests/Value/PlacingSummaryTest.php`,
  `tests/Query/GetRankingTest.php` (cache), `tests/Query/GetPlayerSolvedPuzzlesTest.php`, `tests/Component/PlayerSolvedPuzzlesPlacingTest.php`
