# Difficulty on puzzle lists + "My list" filter on the puzzle database

Issue #214, feature request "Difficulty filter for puzzles in collection". Shipped as one PR.

## Puzzle-list pages

System and custom collections, wishlist, unsolved, solved, sell/swap (with the client-side
`collection-filter` controller) and lend/borrow (display only - that page has no filter controls).

- Every item shows the **difficulty icon** next to the piece count and **"Completed N×"** (community solve count).
- Members get the **difficulty chips** (`_difficulty_filter_chips.html.twig`) - filtering is client-side
  (the pages load their whole list); items carry `data-difficulty-tier`. `0` = not rated yet, which has its own
  "Not rated yet" chip (`diff-unknown` icon) here and on the puzzle database
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
- A list makes `isDefault()` false, so the shared `initial_puzzles_v2` cache is never served for it; the guest
  default view costs exactly what it did. `toQueryParameters()` carries it, so "load more" keeps it.
- SQL: `SearchPuzzle::listFilter()` - one `AND puzzle.id IN (...)` semi-join used by the count and the page query.
  Measured on a production copy (2026-09-29): solved for the heaviest solver (2,328 times) 18 ms, unsolved for the
  largest collection (1,722 items) 22 ms, cold; all index scans.
- Cost: guests 0 extra queries, non-members 0, members +1 (`GetPlayerCollections` for the options).
- Not in the public API (`/api/v1` puzzle search) - its criteria never carry a list.

Tests: `tests/Value/PuzzleSearch{List,Criteria}Test.php`, `tests/Query/SearchPuzzleListTest.php`,
`tests/Query/GetPuzzleListInsightsTest.php`, `tests/Services/ResolvePuzzleListInsightsTest.php`,
`tests/Component/PuzzleSearchTest.php`, `tests/Controller/{PuzzleListInsights,PuzzleSearchItemsController,PuzzlesController}Test.php`.
