# Difficulty on puzzle lists + "My list" filter on the puzzle database

Issue #214, feature request "Difficulty filter for puzzles in collection". Shipped as one PR. The difficulty sort
followed with issue #230 (see "Difficulty sort" below).

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

### Difficulty sort (members, 2026-10-04)

Issue #230, feature request "Filter/sort Collection/solved puzzles by difficulty". "Easiest first" / "Hardest first"
(`easiest` / `hardest`, labels `sorting.easiest` / `sorting.hardest` as on the puzzle database) on the profile results,
the collection-like pages and the marketplace. The order is `puzzle_difficulty.difficulty_score` - finer than the tier,
which is a band of it, so the order always agrees with the icons - and **not rated yet comes last in both
directions**, as on the puzzle database. The viewer's membership decides; a difficulty sort sent by anybody else is
dropped back to the default.

- **Profile results**: two more items in the sort dropdown; non-members get them locked (`#membersExclusiveModal`).
  `ResolveDifficultyTiers::ratingsForViewer()` → `GetPuzzleDifficulty::ratingsOf()` replaces the tier lookup (tier +
  score from the same rows, still one query); `PuzzlesSorter::sortGroupedByDifficulty()` (solo: puzzle groups by
  score, fastest within a puzzle, the first-try / unboxed head kept) and `sortByDifficulty()` (pairs/teams, then
  grouped by team as before); equally difficult = fastest first. `sortBy` is a writable LiveProp, so `populate()`
  resets a difficulty sort to `fastest` without membership.
- **Collection-like pages** (collections, wishlist, unsolved, solved, sell/swap): `_difficulty_sort.html.twig`
  (members only, like the chips) - "Default order" (the page's own: recently added, A-Z on the solved page) /
  Easiest first / Hardest first. Client-side in `collection_filter_controller.js#sort` (reorders the item nodes,
  independent of the filters); items carry `data-difficulty-score` next to `data-difficulty-tier` (empty = not rated
  yet, also on an item a turbo stream re-rendered without insights). The score rides on `GetPuzzleListInsights`.
- **Marketplace**: two more options in the sort select, rendered for members only (an `<option>` cannot open the
  members modal; the difficulty filter's lock button is the upsell). `MarketplaceListing::normalizePieces()` drops a
  difficulty sort to `newest` without membership. `GetMarketplaceListings::orderKeys()` +
  `pd.difficulty_score` as a page column, through the tier filter's join or its own (`DIFFICULTY_JOIN`, never twice);
  equally difficult = newest first, the item id breaks ties, so "Load more" pages stay stable. Under an event the
  listings they are bringing still come first.

Measured on the dev copy (production data: 41k puzzles, 6,285 rated, 1,482 listings, 523k times), median of 5 warm
`EXPLAIN ANALYZE` runs:

| Statement | Before | With the difficulty sort |
|---|---|---|
| Marketplace page 1 (21 rows) | newest 9.95 ms | hardest 7.26 ms |
| Marketplace page 10 (210 rows) | newest 10.23 ms | easiest 6.78 ms |
| `GetPuzzleListInsights`, largest collection (1,722 items) | tier 6.88 ms | tier + score 6.99 ms |
| Difficulty of the heaviest history (2,159 puzzles) | tiers 1.10 ms | tiers + scores 1.07 ms |

The marketplace join is a PK lookup per listing and makes the planner hash the listings instead of every puzzle, so
the difficulty sort is the cheaper one. **No custom index**: an index on the score could not help - the marketplace
sorts after the join, which starts from `sell_swap_list_item`, and the lists look the scores up by primary key. No
extra query anywhere.

Tests: `tests/Services/PuzzlesSorterTest.php`, `tests/Component/PlayerSolvedPuzzlesDifficultyTest.php`,
`tests/Query/GetMarketplaceListingsTest.php`, `tests/Query/GetMarketplaceListingsAtEventsTest.php`,
`tests/Component/MarketplaceListingDifficultyFilterTest.php`, `tests/Controller/PuzzleListInsightsTest.php`,
`tests/Query/GetPuzzleListInsightsTest.php`, `tests/Query/GetPuzzleDifficultyTest.php`,
`tests/Services/ResolveDifficultyTiersTest.php`.

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
- A list makes `isDefault()` false, so the shared `initial_puzzles_v5` cache is never served for it; the guest
  default view costs exactly what it did. `toQueryParameters()` carries it, so "load more" keeps it.
- SQL: `SearchPuzzle::listFilter()` - one `AND puzzle.id IN (...)` semi-join used by the count and the page query.
  Measured on a production copy (2026-09-29): solved for the heaviest solver (2,328 times) 18 ms, unsolved for the
  largest collection (1,722 items) 22 ms, cold; all index scans.
- Cost: guests 0 extra queries, non-members 0, members +1 (`GetPlayerCollections` for the options).
- Not in the public API (`/api/v1` puzzle search) - its criteria never carry a list.

Tests: `tests/Value/PuzzleSearch{List,Criteria}Test.php`, `tests/Query/SearchPuzzleListTest.php`,
`tests/Query/GetPuzzleListInsightsTest.php`, `tests/Services/ResolvePuzzleListInsightsTest.php`,
`tests/Component/PuzzleSearchTest.php`, `tests/Controller/{PuzzleListInsights,PuzzleSearchItemsController,PuzzlesController}Test.php`.
