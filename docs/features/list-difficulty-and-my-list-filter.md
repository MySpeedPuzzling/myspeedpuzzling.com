# Difficulty on puzzle lists + "My list" filter on the puzzle database

Issue #214, feature request "Difficulty filter for puzzles in collection". Shipped as one PR.

## Puzzle-list pages

System and custom collections, wishlist, unsolved, solved, sell/swap (with the client-side
`collection-filter` controller) and lend/borrow (display only - that page has no filter controls).

- Every item shows the **difficulty icon** next to the piece count and **"Completed N×"** (community solve count).
- Members get the **difficulty chips** (`_difficulty_filter_chips.html.twig`) - filtering is client-side
  (the pages load their whole list); items carry `data-difficulty-tier`. `0` = not rated yet, which has its own
  "Unknown" chip (`diff-unknown` icon) here and on the puzzle database
  (`PuzzleSearchCriteria::UNRATED_DIFFICULTY` → `pd.difficulty_tier IS NULL` in `SearchPuzzle::difficultyFilter()`).
- Non-members and guests: locked icon opening `#membersExclusiveModal`, no chips, no `data-difficulty-tier`.
- **The viewer's membership decides**, never the list owner's.
- Data: `ResolvePuzzleListInsights::forViewer()` → `GetPuzzleListInsights::forPuzzles()` = **one query per page**
  however long the list; `puzzle_difficulty` is joined only for members. Spread into `render()` via
  `PuzzleListInsights::templateParameters()` (`puzzle_insights`, `insights_with_difficulty`).
- Turbo-stream re-renders of an item carry no insights: the item shows neither until reload and is never hidden
  by the chips (same limitation as "my times"; see `docs/TODO.md`).
- Icon markup lives once: `_difficulty_icon.html.twig` macro (also used by `_puzzle_item.html.twig`),
  `DifficultyTier::icon()` / `translationKey()`. The sell/swap item wrapper lives once in `sell-swap/_item.html.twig`.

## Difficulty on thumbnails (2026-10-03)

Members see the puzzle's tier on the bottom-right corner of its picture, like on the comparison (D19 in
`player-comparison.md`): `_difficulty_corner.html.twig` inside a `.diff-corner-host` (a link directly inside the host
is made a flex box, its line box would push the disc below the corner). "Unknown" icon = not rated yet.

| Where | Template | Tiers from |
|---|---|---|
| Profile results (solo, pairs, teams) | `_player_solvings.html.twig` | `PlayerSolvedPuzzles` - the whole history |
| Recent activity (Hub all + favorites, profile, `/recent-activity`, homepage) | `components/RecentActivity` | `RecentActivity::getDifficultyTiers()` |
| Ladders (`/ladder`, per-pieces ladder) | `components/LadderTable` | `LadderTable::getDifficultyTiers()` |
| Most solved puzzles (Hub) | `components/MostSolvedPuzzles` | `MostSolvedPuzzles::getDifficultyTiers()` |
| Marketplace cards | `marketplace/_listing_card` | `MarketplaceListing::getDifficultyTiers()` |
| Round results | `round_results` | `RoundResultsPage::$difficultyTiers` - **only puzzles whose picture is out**: a puzzle hidden until the round starts shows no tier |
| Result detail (modal + page) | `puzzle_result/_puzzle` (small disc) | `PuzzleResultDetailController` |
| Activity calendar, the picked day | `components/ActivityCalendar` | `ActivityCalendar::$selectedDayDifficultyTiers` |

- **One service decides**: `ResolveDifficultyTiers::forViewer($viewer, $puzzleIds)` - `null` for anybody without an
  active membership (templates test `is not null`; nothing is queried), else `GetPuzzleDifficulty::tiersOf()`:
  tier-only rows, one query per list. Dev copy 2026-10-03, warm: 20 puzzles 0.04 ms, 100 0.25 ms, 210-500 ~1.4 ms,
  the heaviest solver's whole history (2,159) ~1 ms (cold up to ~7 ms). `GetPuzzleListInsights` (+ solve counts)
  was ~12 ms for the same history.
- Viewer-dependent, so never in a shared cache - none of these pages is HTTP-cached.
- Turbo-stream re-renders of a marketplace card (reserve / unreserve) carry no tiers: no corner until reload.

### Difficulty filters (members)

`Value\DifficultyFilter` (normalise, options) + `_difficulty_filter_live.html.twig` (checkboxes bound to an array
LiveProp, `data-model="difficulty[]"`, strings, `"0"` = not rated yet as on the puzzle search). Non-members see the
chips disabled, and a value they send is ignored.

- **Profile results**: in the members' part of the filter dropdown (under the "Members exclusive" overlay for
  others); offers only the tiers the player has a result in plus the selected ones (like the piece-count chips);
  filters in PHP like the other profile filters, cleared by "Reset", counted in the badge.
- **Marketplace**: `difficulty[]` in the URL like every marketplace filter (`?difficulty[]=5`), all seven chips, a
  lock button opening `#membersExclusiveModal` for non-members. SQL in `GetMarketplaceListings::difficultyFilter()`
  (search + count, same meaning as `SearchPuzzle::difficultyFilter()`); dev copy (1,482 listings): a wide choice
  +2-4 ms on the page query, a narrow one makes it faster.

Tests: `tests/Controller/DifficultyOnThumbnailsTest.php`, `tests/Component/PlayerSolvedPuzzlesDifficultyTest.php`,
`tests/Component/MarketplaceListingDifficultyFilterTest.php`, `tests/Query/GetMarketplaceListingsTest.php`,
`tests/Query/GetPuzzleDifficultyTest.php`, `tests/Services/ResolveDifficultyTiersTest.php`.

## "My list" on the puzzle database (`/en/puzzle?list=...`)

Single select (`tomselect-sync` with pre-rendered, per-viewer options - never the shared cached filter-options endpoint).

| Value | Meaning | Who |
|---|---|---|
| `library` | system collection ("Puzzle Collection") | signed-in |
| `wishlist` | wishlist | signed-in |
| `unsolved` | in any of my collections or borrowed by me, not solved (as `GetUserPuzzleStatuses`) | signed-in |
| `solved` | my time or a team time I was part of (jsonb containment → `custom_pst_team_puzzlers_gin`) | signed-in |
| `collection:<uuid>` | one custom collection, always scoped to the viewer | members |
| `borrowed` / `lent` / `sell-swap` | lending and sell/swap lists | members |

- Encoding: `PuzzleSearchList` + `PuzzleSearchListKind`. Gating in `PuzzleSearchCriteria::fromUserInput()`
  (guests lose any list, non-members the member lists); the component also drops a collection that is not the
  viewer's so the select never hides an active filter.
- A list makes `isDefault()` false, so the shared `initial_puzzles_v3` cache is never served for it; the guest
  default view costs exactly what it did. `toQueryParameters()` carries it, so "load more" keeps it.
- SQL: `SearchPuzzle::listFilter()` - one `AND puzzle.id IN (...)` semi-join used by the count and the page query.
  Measured on a production copy (2026-09-29): solved for the heaviest solver (2,328 times) 18 ms, unsolved for the
  largest collection (1,722 items) 22 ms, cold; all index scans.
- Cost: guests 0 extra queries, non-members 0, members +1 (`GetPlayerCollections` for the options).
- Not in the public API (`/api/v1` puzzle search) - its criteria never carry a list.

Tests: `tests/Value/PuzzleSearch{List,Criteria}Test.php`, `tests/Query/SearchPuzzleListTest.php`,
`tests/Query/GetPuzzleListInsightsTest.php`, `tests/Services/ResolvePuzzleListInsightsTest.php`,
`tests/Component/PuzzleSearchTest.php`, `tests/Controller/{PuzzleListInsights,PuzzleSearchItemsController,PuzzlesController}Test.php`.
