# My data: your results and puzzles as a table

Status: **stage 2a built - read-only** (2026-11-15). Part of the data import (`docs/features/data-import/README.md`
"My data", `implementation-plan.md` "Stage 2"). Editing one row is 2b, changes of many rows 2c.

The spreadsheet a player used to keep, now on MySpeedPuzzling: their own results, the puzzles of their collections and
their wish list in a table. Owner only, free for everybody, phone first.

## Routes

| Route | Address | What |
|---|---|---|
| `my_data` | `GET /{_locale}/my-data` (en, cs, de, es, fr, ja) | the page - `MyDataController` |
| `my_data_columns` | `POST /{_locale}/my-data/columns` | the column chooser - `ChangeMyDataColumnsController` → `ChangeMyDataColumns` |

- Signed-in only (`IS_AUTHENTICATED_REMEMBERED`; a guest goes to the sign-in page). **No player id in the address at
  all** - every read takes the signed-in player's id, so there is nobody else's page to ask for.
- `noindex, nofollow` (meta + `X-Robots-Tag`), `Cache-Control: private, no-store`.
- Entry: one link on the data export page ("See your data as a table"). Nothing else yet - the entry points (profile
  results, collection pages, user menu) are stage 8.
- The page writes nothing but the column choice (`player.my_data_columns`, a preference).

## The address is the state

`Value\MyDataCriteria::fromUserInput()` reads the query string; anything unknown, malformed or meant for another tab
falls back to its default - never an error. `query()` writes the canonical address (defaults left out); a change of
anything but the page starts at the first page again.

| Parameter | Values | Default |
|---|---|---|
| `tab` | `results` · `puzzles` (collection items) · `wishlist` | `results` |
| `q` | the player's own search text, ≤ 100 characters, control characters and runs of spaces folded | - |
| `sort` | results: `date` · `time` · `pieces` · `name` · `brand`; puzzles and wish list: `added` · `name` · `pieces` · `brand` | `date` / `added` |
| `dir` | `asc` · `desc` | newest first for `date` / `added`, ascending for the others |
| `pieces` | `PiecesRange`'s grammar: `N` · `A-B` · `A-` · `-B` (the chips are `pieces_presets()`: "500" = 495-505) | - |
| `page` | 1… (50 rows a page, `MyDataCriteria::PAGE_SIZE`); a page behind the last row shows the first page | 1 |
| `type` (results) | `solo` · `duo` · `team` | - |
| `year` (results) | 1990…2100 - the year of `COALESCE(finished_at, tracked_at)` | - |
| `first=1` (results) | first tries only | - |
| `imported=1` (results) | `created_via = spreadsheet_import` only | - |
| `comment=1` (results, puzzles) | rows with a comment | - |
| `photo=1` (results) | results with a photo of the finished puzzle | - |
| `collection` (puzzles) | a collection's uuid · `main` (the main collection); somebody else's collection matches nothing | - |

The search is a `GET` parameter on purpose: it is the player's own typed text. No cell text - a comment, an own
field's value, a name - is ever put into an address; rows link by id.

**Search rule:** the puzzle's names and codes through `PuzzleTextSearch` (the catalogue's one search rule, on
`search_names` / `search_codes`), or the brand's name contains the text (`manufacturer.name ILIKE`, wildcards escaped).
A puzzle is never searched by ILIKE.

The direction of the sort is a link beside the sort select (and the wide table's column headers), not a form field:
choosing another sort in the form starts in that sort's own direction (fastest first, not "slowest first because the
dates were newest first").

## What a row shows

**Results** - one row per result the player tracked or took part in (pairs and teams through the registered
membership, like the profile and the export):

| Column id | What | Default |
|---|---|---|
| (always) | the puzzle: picture, main title, the name in the viewer's language (`puzzle/_name.html.twig`) - opens the result detail | shown |
| `brand`, `pieces` | brand, piece count | shown |
| `time` | the time, "No time" for a relax result, the label "Needs verification" on a marked result (it is the player's own) | shown |
| `date` | `result_date()` with the stored precision (`finished_at_precision`: "Mar 2021", "2021"); `tracked_at` for a result without a finish date | shown |
| `group` | Solo / Pair / Team, the team's name, "with Anna, Ben" (the other people, guests included) | shown |
| `first_try` | a tick | shown |
| `comment` | the result's comment | shown |
| `source` | how it was added: Typed in / Stopwatch / App / Event / Import (`created_via`; empty for results older than the column) | shown |
| `photo` | a picture icon when the result has a photo | hidden |
| `ppm` | pieces per minute per person (`SolvingTime::calculatePpm()`, like the website and the export) | hidden |
| `f:<field id>` | every own field in use for results, in the player's order (below) | shown |

**Puzzles** - one row per collection item (main collection and custom ones, private ones and those of a lapsed
membership included): the puzzle (opens its page), `brand`, `pieces`, `collection`, `added`, `comment`, `solved`
("Solved 3× · best 1:02:03" from the player's own results - best = solo, never a result that needs verification),
`lent` ("Lent out" when a `lent_puzzle` row of the owner holds the puzzle), then the own fields in use for collection
puzzles. **Wish list** - `brand`, `pieces`, `added`, `solved`; no own fields (a wish has none).

The same facts the exports carry: the puzzle columns come from `ExportablePuzzle::SQL_COLUMNS` (one fragment for the
exports and this page - the hidden-picture rule included), the solve summary is the export's
`GetExportablePuzzleSolveSummary`, the collections of the filter are `GetExportableCollections`.

### Own fields

`Services\MyData\OwnFieldsInMyData` - takes **no player id** (resolves the signed-in player itself, like
`OwnCustomValuesInExport`), reads the player's fields (`GetCustomFields`) and the values of **the page's rows only**
through the two viewer-bound readers (`GetCustomValues::forViewer()`, `GetCollectionItemCustomValues::forViewer()`),
and hands the page worded texts (`MyDataOwnFields`; `CustomFieldLabels` words them: ready-made names and choices in
the page language, a date in the locale's form, tags joined). Columns = the fields **in use** for the place; an
archived field is no column and its values are not shown. Reading fails open: the table is shown without the columns
and a warning is logged (it carries ids only).

A pair partner's values on a shared result are never read - both readers are bound to the viewer. Listed in
`CustomValuesCoverageTest` (the service, its one caller, "takes no player id"; the two row queries and DTOs are on
the "know nothing of the values" list) and opened as everybody else in `CustomValuesPrivacyCanaryTest`.

**"My data" is now the third place an owner sees a collection item's values** (after the item's edit form and the
export) - still never on a collection page.

## The column chooser

Wide screens only (a `<details>` panel, no JavaScript): every built-in column of the tab and the own fields, ticked =
shown. Stored as the **hidden** columns per tab in `player.my_data_columns` (jsonb, null = never chose;
`Value\MyDataColumns`): `{"results": ["photo", "ppm"], "puzzles": ["lent"]}`. Hidden, not shown, so a field the player
adds later shows up by itself. A tab the player never chose anything on shows `MyDataTab::hiddenByDefault()`.

The form posts what the chooser offered (`columns[]`) and what is ticked (`shown[]`); the rest is hidden. An id that
is no built-in column of the tab and no `f:<uuid>` is dropped - by the handler and again when the column is read; at
most 60 per tab. Stateless CSRF `my_data_columns`; always answers with a 303 back to the table in its state (`state`
= the table's own query string, read through `MyDataCriteria` - never an address to go to).

## Phone and wide screen

Both are in the HTML; CSS shows one (`d-none d-lg-block` / `d-lg-none`), so no JavaScript decides.

- **Below 992 px - a list, never a table:** each row is one tap target at least 56 px tall - the picture, the puzzle's
  name, a second line (brand · pieces · pair/team with whom · first try · "Import"), on the right the time with the
  date under it (puzzles: "Solved N× · best …" with the day it was added). When the row has own fields filled, a third
  small line names up to three of them ("Shelf: top"). A tap opens the existing result detail (in the modal frame) or
  the puzzle's page. Search, filters, the sort select with the direction link and the pager (44 px buttons) are the
  same form as on a wide screen, stacked.
- **From 992 px - a real `<table>`** inside `.my-data-scroll`: it scrolls both ways inside its own box
  (`max-height: calc(100vh - 140px)`), so the header row stays in sight (`position: sticky`) and **the page body never
  scrolls sideways** whatever the number of columns. Sortable headers are links (`aria-sort` on the sorted one).

Styles: `assets/styles/_my-data.scss` (everything under `.my-data`), imported by `app.scss` - small enough not to be
an Encore entry of its own.

**Not the design's client-side grid.** The README sketches rows loaded once as JSON into the participants sheet's
`SheetGrid`, and a phone list with one chosen column and "Next". 2a is server-rendered and paged in SQL instead: it is
read-only, needs no JavaScript, and the address carries the state. The grid, cell editing and the phone's
"one column, Next" belong to 2b, after the phone prototype the plan asks for.

## Statements

Per page, beside the 4 every signed-in page loads - constant in the number of rows, fields and filled values
(`MyDataControllerTest::testTheStatementsOfAPageDoNotGrowWithRowsAndFields`: 120 more results, 10 more fields and
values on every row cost no statement more):

| Tab | Statements |
|---|---|
| Results (5) | `GetMyDataResults::facts()` (count + oldest year), `::page()`, the player's fields, the values of the page's rows, the column choice |
| Puzzles (7) | `GetMyDataPuzzles::facts()`, `::page()`, the solve summary of the page's puzzles, the collections of the filter, the fields, the values, the column choice |
| Wish list (4) | facts, page, solve summary, column choice |

A page without rows skips the values statement; a page behind the last row runs the page statement a second time
(for page 1).

**Indexes used** (no new index):

- the player's own results: the Doctrine index on `puzzle_solving_time (player_id)` (bitmap scan; the registered
  `custom_pst_player_tracked_at (player_id, tracked_at)` and `custom_pst_player_puzzle_type (player_id, puzzle_id,
  puzzling_type)` serve the same predicate - the solve summary plans on the latter);
- pair/team results: `puzzling_team_member (player_id)` → `puzzle_solving_time (puzzling_team_id)` (both Doctrine
  indexes) - never the `team` JSON, so `custom_pst_team_puzzlers_gin` is not needed;
- then primary keys (`puzzle_solving_time`, `puzzle`, `manufacturer`, `puzzling_team`);
- `collection_item (player_id)`, `wish_list_item (player_id)`, the unique index of `lent_puzzle` (read index-only for "Lent out").

Everything is narrowed to one player first, then filtered and sorted in memory (a top-N heapsort for a page):
no `COALESCE(finished_at, tracked_at)` index is needed (`docs/database-indexes.md` says the same of every other use).
`COUNT(*) OVER ()` gives the total with the page - one statement, and the total never depends on the page.

### Measured

**Test database, the fixture player with 5,020 results on 3,000 seeded puzzles, 3,009 collection items, values on
every row** (seeded inside a rolled-back transaction, `ANALYZE`d, PostgreSQL 16, warm):

| Statement | Execution |
|---|---|
| results facts | 2.0 ms |
| results page, newest first (default) | 5.9 ms |
| results page 3, search + year + first tries, by time | 2.0 ms |
| values of the page's 50 results | 0.4 ms |
| puzzles page, newest first | 2.0 ms |
| solve summary of the page's 50 puzzles | 0.5 ms |

(The player holds nearly the whole table there, so the planner scans it - the plan shape is not production's.)

**Plan shape on the local copy of production** (weeks old, 523k results; read-only `EXPLAIN ANALYZE`, warm, the
heaviest account: 2,328 results; the largest library: 1,722 items): facts 4.4 ms, results page 8.3 ms (bitmap scan on
the player's index → 2,328 primary key lookups → top-N heapsort), search + year by time 18 ms (the planner starts
from a parallel scan of `puzzle` for a short search word), puzzles page 9.0 ms. Cold (first run after a restart):
180 ms / 115 ms.

### To be explained on production by the orchestrator before merge

With the heaviest account's id for `:playerId` (2,456 results at the time of the design) and a member of many
pairs/teams as a second run.

1. **Results page** (`GetMyDataResults::fetch()`), default state and the worst filter mix:

```sql
EXPLAIN (ANALYZE, BUFFERS)
WITH mine AS (
    SELECT pst.id FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId AND pst.puzzling_team_id IS NULL
    UNION
    SELECT pst.id FROM puzzling_team_member member
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
    WHERE member.player_id = :playerId
)
SELECT pst.id AS time_id, pst.seconds_to_solve, pst.finished_at, pst.finished_at_precision, pst.tracked_at,
    pst.first_attempt, pst.finished_puzzle_photo IS NOT NULL AS has_photo, pst.comment, pst.puzzling_type,
    pst.puzzlers_count, pst.created_via, pst.suspicious,
    (SELECT string_agg(COALESCE(other.name, player_elem.player ->> 'player_name',
            CASE WHEN other.code IS NOT NULL THEN '#' || UPPER(other.code) ELSE NULL END), ', ' ORDER BY player_elem.ordinality)
        FROM json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
        LEFT JOIN player other ON other.id = (player_elem.player ->> 'player_id')::UUID
        WHERE (player_elem.player ->> 'player_id') IS DISTINCT FROM :playerId
            AND COALESCE(other.name, player_elem.player ->> 'player_name', other.code) IS NOT NULL) AS with_whom,
    (SELECT puzzling_team.name FROM puzzling_team WHERE puzzling_team.id = pst.puzzling_team_id) AS team_name,
    p.id AS puzzle_id, p.name AS puzzle_name, p.name_language AS puzzle_name_language,
    p.alternative_names AS puzzle_alternative_names, m.name AS puzzle_brand_name, p.pieces_count AS puzzle_pieces_count,
    p.ean AS puzzle_ean, p.identification_number AS puzzle_identification_number,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > now()::timestamp THEN NULL ELSE p.image END AS puzzle_image,
    COUNT(*) OVER () AS total
FROM mine
INNER JOIN puzzle_solving_time pst ON pst.id = mine.id
INNER JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
-- run 1: no WHERE. Run 2 (search + year + first tries, by time, page 2):
-- WHERE (p.search_names LIKE '%cat%' OR m.name ILIKE '%cat%')
--     AND COALESCE(pst.finished_at, pst.tracked_at) >= '2025-01-01 00:00:00'
--     AND COALESCE(pst.finished_at, pst.tracked_at) < '2026-01-01 00:00:00'
--     AND pst.first_attempt = true
-- ORDER BY pst.seconds_to_solve ASC NULLS LAST, pst.id LIMIT 50 OFFSET 50
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.tracked_at DESC, pst.id
LIMIT 50 OFFSET 0;
```

   (`'%cat%'` stands for `PuzzleTextSearch`'s `:ptsNamesContains`; a search of 5+ letters or digits adds its code
   tests on `search_codes`.)

2. **Puzzles page** (`GetMyDataPuzzles::fetch()`), with the id of the largest library (1,722 items at the time of
   the export's design):

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT item.id AS item_id, item.added_at, item.collection_id, c.name AS collection_name, item.comment,
    EXISTS (SELECT 1 FROM lent_puzzle lp WHERE lp.owner_player_id = :playerId AND lp.puzzle_id = item.puzzle_id) AS lent_out,
    p.id AS puzzle_id, p.name AS puzzle_name, p.name_language AS puzzle_name_language,
    p.alternative_names AS puzzle_alternative_names, m.name AS puzzle_brand_name, p.pieces_count AS puzzle_pieces_count,
    p.ean AS puzzle_ean, p.identification_number AS puzzle_identification_number,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > now()::timestamp THEN NULL ELSE p.image END AS puzzle_image,
    COUNT(*) OVER () AS total
FROM collection_item item
LEFT JOIN collection c ON c.id = item.collection_id
INNER JOIN puzzle p ON p.id = item.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE item.player_id = :playerId
ORDER BY item.added_at DESC, item.id
LIMIT 50 OFFSET 0;
```

Expected: both narrowed by the player's index first, a top-N sort of that player's rows, no scan of
`puzzle_solving_time` or `collection_item` as a whole. If the search run starts from `puzzle` on production too and
is slow for a heavy account, the fix is `MATERIALIZED` on a CTE of the player's rows before the text condition.

## Guards

- `tests/Controller/MyData/MyDataControllerTest.php`: guest → sign-in; private + noindex in 6 locales; only the
  signed-in player's rows whatever the address says; broken addresses; every filter against an independently written
  count (the snapshot's JSON instead of the membership rows); every sort both ways; paging (stable total, no row on
  two pages, the state in every link); own fields as columns, archived / other-place / the partner's fields absent;
  date precision; the column chooser (persisted, validated, wrong token); puzzles + wish list; the statement budget.
- `tests/Value/MyDataCriteriaTest.php`: the grammar, the canonical address, `MyDataColumns`.
- `CustomValuesCoverageTest`, `CustomValuesPrivacyCanaryTest` (pair partner, another player, the owner of a public
  collection), `BlocklistQueryCoverageTest` (own data; co-puzzlers of own results are bilateral history, like the
  export), `TrackerlessTimeQueryCoverageTest` (a result without a tracker has no member - never anybody's row).

## What 2b and 2c add

- **2b - one row:** the row sheet (every field of one row) and editing in place, through the existing messages
  (`EditPuzzleSolvingTime`, the collection item's comment, `SetSolvingTimeCustomValues` /
  `SetCollectionItemCustomValues`) with field-level `from` values; first-try and pace rules answer like the edit
  form. The phone's "one chosen column beside the puzzle, Next goes down" after its prototype; the wide grid
  (`SheetGrid`, keyboard, paste) if editing needs it.
- **2c - many at once:** tick rows → set a field, move/copy to a collection, delete - a `data_change_batch` with its
  log and Undo.
- Later (stage 7 / 8): the Unlisted tab, entry points from the profile's results, collection pages and the user menu,
  "Download what I see".

## Left out of 2a on purpose

- **Unlisted puzzles** (stage 7, built in parallel) - a result on one is not listed specially, the tab is not there.
- **Difficulty, placing, event** columns and filters: they need the members' insights, the ranking statement and the
  event visibility rules - none is in the old spreadsheet, each is a statement more. The export carries rank and event.
- **A custom from-to piece range** in the form: the chips only (the address takes any `PiecesRange`).
- **Sorting or filtering by an own field** - the values live in jsonb per row; 2b decides with the grid.
- **"Solved / not solved" filter and sort on the puzzles tab**: the solve summary is read for the page's puzzles only
  (the export's aggregate), so SQL can not sort by it without a second, larger statement.
