# My data: your results and puzzles as a table

Status: **stage 2a (the table) and 2b (editing one row) built** (2026-11-15). Part of the data import
(`docs/features/data-import/README.md` "My data", `implementation-plan.md` "Stage 2"). Changes of many rows and
deleting are 2c.

The spreadsheet a player used to keep, now on MySpeedPuzzling: their own results, the puzzles of their collections and
their wish list in a table. Owner only, free for everybody, phone first.

## Routes

| Route | Address | What |
|---|---|---|
| `my_data` | `GET /{_locale}/my-data` (en, cs, de, es, fr, ja) | the page - `MyDataController` |
| `my_data_columns` | `POST /{_locale}/my-data/columns` | the column chooser - `ChangeMyDataColumnsController` → `ChangeMyDataColumns` |
| `my_data_result_row` | `GET|POST /{_locale}/my-data/result/{timeId}` | the row sheet of one result - `MyDataResultRowController` ("Editing one row") |
| `my_data_puzzle_row` | `GET|POST /{_locale}/my-data/puzzle/{itemId}` | the row sheet of one collection puzzle - `MyDataPuzzleRowController` |
| `my_data_cell` | `POST /{_locale}/my-data/cell/{result|puzzle}/{id}` | one cell saved in place, JSON - `MyDataCellController` ("Editing in place") |

- Signed-in only (`IS_AUTHENTICATED_REMEMBERED`; a guest goes to the sign-in page). **No player id in the address at
  all** - every read takes the signed-in player's id, so there is nobody else's page to ask for.
- `noindex, nofollow` (meta + `X-Robots-Tag`), `Cache-Control: private, no-store`.
- Entry: one link on the data export page ("See your data as a table"). Nothing else yet - the entry points (profile
  results, collection pages, user menu) are stage 8.
- The table itself (`my_data`) writes nothing; the column choice is a preference (`player.my_data_columns`), and a
  row is edited through its row sheet or a cell (below) - always through messages that existed before My data.

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
| `year` (results) | 1900…2100 (`MyDataCriteria::MIN_YEAR` / `MAX_YEAR` - the one rule: the select offers the years from the oldest result on, never one the address would drop) - the year of `COALESCE(finished_at, tracked_at)` | - |
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
| (always) | the puzzle: picture, main title, the name in the viewer's language (`puzzle/_name.html.twig`) - opens the row sheet (which links to the result detail) | shown |
| `brand`, `pieces` | brand, piece count | shown |
| `time` | the time, "No time" for a relax result, the label "Needs verification" on a marked result (it is the player's own) | shown |
| `date` | `result_date()` with the stored precision (`finished_at_precision`: "Mar 2021", "2021"); `tracked_at` for a result without a finish date | shown |
| `group` | Solo / Pair / Team, the team's name, "with Anna, Ben" (the other people, guests included) - see "Teammates" | shown |
| `first_try` | a tick | shown |
| `comment` | the result's comment | shown |
| `source` | how it was added: Typed in / Stopwatch / App / Event / Import (`created_via`; empty for results older than the column) | shown |
| `photo` | a picture icon when the result has a photo | hidden |
| `ppm` | pieces per minute per person (`SolvingTime::calculatePpm()`, like the website and the export) | hidden |
| `f:<field id>` | every own field in use for results, in the player's order (below) | shown |

**Teammates are named as the player's own profile results name them.** A teammate with a private profile is
"Hidden Puzzler" (`secret_puzzler_name`, the profile's text) unless they put this player on their allow list
(`PrivateProfileAccess::sqlIsPrivate()` - the name is not even selected then). A teammate the player blocks stays on
the row, as on the profile: `HiddenPlayers::sqlExcludeTeam()` never drops a result the viewer took part in, so no own
result is missing from the table. (The results export still names a private teammate - see "Loose ends".)

**Puzzles** - one row per collection item (main collection and custom ones, private ones and those of a lapsed
membership included): the puzzle (opens the row sheet; a wish opens the puzzle's page), `brand`, `pieces`, `collection`, `added`, `comment`, `solved`
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

The form posts what the chooser offered (`columns[]`) and what is ticked (`shown[]`); the rest of what it offered is
hidden. **A hidden column the form did not offer stays hidden** (`MyDataColumns::withChoice()`): the choice of an
archived field's column is still there when the field is unarchived, and a page whose own fields could not be read
(fail open - only the built-in columns are offered) can not wipe the fields' choices by being saved. An id that
is no built-in column of the tab and no `f:<uuid>` is dropped - by the handler and again when the column is read; at
most 60 per tab. Stateless CSRF `my_data_columns`; always answers with a 303 back to the table in its state (`state`
= the table's own query string, read through `MyDataCriteria` - never an address to go to).

## Phone and wide screen

Both are in the HTML; CSS shows one (`d-none d-lg-block` / `d-lg-none`), so no JavaScript decides.

- **Below 992 px - a list, never a table:** each row is one tap target at least 56 px tall - the picture, the puzzle's
  name, a second line (brand · pieces · pair/team with whom · first try · "Import"), on the right the time with the
  date under it (puzzles: "Solved N× · best …" with the day it was added). When the row has own fields filled, a third
  small line names up to three of them ("Shelf: top"). A tap opens the row sheet as a page (a wish: the puzzle's
  page). **Only the tabs and the search field stand above the rows**: everything else of the form (Solved,
  Year / Collection, the sort with its direction link, the piece chips, the checkboxes, "Show") is folded behind one
  "Filters" disclosure (`<details class="my-data-more">`), open by itself while a filter is on and showing how many
  are (`MyDataCriteria::activeFilterCount()` - the search, the sort and the page are no filters). Folded controls
  still travel with the form, so a search keeps the filters.
- **From 992 px the disclosure is always open and has no summary** (`::details-content`, like the footer's groups; a
  browser without it keeps the "Filters" button - still usable).
- **From 992 px - a real `<table>`** inside `.my-data-scroll`: it scrolls both ways inside its own box
  (`max-height: calc(100vh - 140px)`), so the header row stays in sight (`position: sticky`) and **the page body never
  scrolls sideways** whatever the number of columns. Sortable headers are links (`aria-sort` on the sorted one).

Styles: `assets/styles/_my-data.scss` (everything under `.my-data`), imported by `app.scss` - small enough not to be
an Encore entry of its own.

**Not the design's client-side grid.** The README sketches rows loaded once as JSON into the participants sheet's
`SheetGrid`, and a phone list with one chosen column and "Next". The table is server-rendered and paged in SQL
instead: it needs no JavaScript to read, and the address carries the state. 2b edits on top of that - a row sheet
that works without JavaScript and a small Stimulus controller for one cell at a time; no grid library, no arrow keys,
no paste of a block. The phone's "one column, Next" still waits for its prototype ("Left out of 2b").

## Editing one row (2b)

Who may: **only who may edit the row today** - whoever tracked a result, or a registered member of a pair's / team's
result (`SolvedPuzzleDetail::isEditableBy()`, the handler checks `PuzzleSolvingTime::canBeModifiedBy()` again); the
owner of a collection item. Anybody else's row id is a 404 (`MyDataResultEditor::rowOf()`,
`CollectionItemNotFound`). An **imported event result** is the event's: its row is in the table (the player claimed a
spot) but read-only - the sheet shows its fields as text and still edits the player's own fields on it; its cells
carry no `data-edit`. **Nothing is deleted here** (2c).

### The row sheet

Opened from the row: **in the modal frame from the wide table, as a page from the phone's list** (the same URL; the
answer is gated on the `Turbo-Frame` header, `Vary: Turbo-Frame`). `noindex`, `private, no-store`. Forms name their
own `action` (a form in the modal frame without one posts to the page under it). Stateless CSRF id `my_data_edit`.

| Row | Edited | Read-only on the sheet |
|---|---|---|
| A result | the time (hours / minutes / seconds, like the edit form), the day (`d.m.Y`, the edit form's date picker), first try, the comment, **the player's own fields for results** (`my_fields/_inputs.html.twig` - the values form's partial) | the puzzle, who solved it (a private teammate masked), how it was added, the spreadsheet import it came from (a link, for the importer only - `GetMyDataResultContext`), "Needs verification"; links to the result detail and to **"Open the full edit form"** (`edit_time`) for the puzzle, the people, the photo and the event |
| A result without a time | the day, the comment, own fields - it stays a result without a time, its first-try tag stays as stored | as above |
| A collection puzzle | the comment, **the owner's own fields for collection puzzles**, the collection (members only - moving is the members' feature it is on the collection pages) | the puzzle (a link), added on, "Solved N× · best …" |
| A wish | nothing - a wish has no fields; its row opens the puzzle's page | - |

**A result that knows only its month or year** shows the period ("All we know of this result's date is Mar 2021") and
an empty "Exact day (if you know it)" field: left empty the result keeps what it knows, a picked day makes it exact
(`PuzzleSolvingTime::modify()` - the rule of the edit form, `date-precision.md` "Editing").

**Saving** - everything is checked before anything is written, so a refusal leaves nothing half-saved:

1. the player's own values (`SubmittedValuesChange::problems()` - a value that can not be stored refuses the save as
   a whole, the result's fields too), a collection item's target collection (one of the player's own, and not holding
   the puzzle already - two items becoming one would take this item and the comment just typed away);
2. the result: `MyDataResultEditor::save()` → **`EditPuzzleSolvingTime`** (below); a collection item:
   `EditCollectionItemComment`;
3. the own values, only the changed ones: `SetSolvingTimeCustomValues` / `SetCollectionItemCustomValues` through
   `CustomFieldsOfMyDataRow` (takes no player id, never throws - what was saved before it stays saved and a warning
   says the fields were not);
4. a collection item's move: `MovePuzzlesToCollection` with the one puzzle - the item keeps its id, its added date,
   its comment and its values (`CollectionItem::moveTo()`).

**Nothing changed = nothing dispatched.** A sheet saved as it opened, or with only the player's own fields changed,
never sends `EditPuzzleSolvingTime`: a pair partner noting something in their own field does not "edit the shared
result" (no notification to the team, no recalculation).

A refused save answers **422** with what was typed still in the form and the reason at the field; a saved one answers
a 303 back to the table in its state (`?return=`, checked by `ReturnUrl::tryFrom()` - anything that leaves the site
falls back to `/my-data`), in the modal a Turbo Stream that closes the modal and refreshes the table under it
(`my_data/_row_saved_stream.html.twig`, the edit-time modal's answer).

### The edit form's checks

`PuzzleAddController`, `EditTimeController`, the add / edit messages, their handlers, form types and templates are
**not changed** by My data. `Services\MyData\MyDataResultEditor` is the edit form's save for the four fields My data
edits - the same checks in the same order, asked of the same services, answered with the same translation keys:

| Check (in `EditTimeController`'s order) | Where it lives | My data |
|---|---|---|
| time 0-999 h / 0-59 min / 0-59 s; a result with a time needs one (`forms.time_required`) and a day (`finished_at_required_for_speed`); a valid date | the form type and `EditPuzzleSolvingTimeFormData` (Symfony constraints) | **replicated** in `MyDataResultEditor` (there is no service under the form to call); "needs a time", "needs a day" and "not a date" in the form's words, a time out of range in My data's own ("Enter the time as hours, minutes (0-59) and seconds (0-59).") |
| the same time from the same day already saved → asked "It's another solve", the warning recorded | `FirstTryFormCheck::forEditedResult()` + `RecordDuplicatePrevention` | **shared** - the same call, the same record (`via = form`) |
| first try: held elsewhere → refused until "Make this result my first try"; an earlier solve without the tag → only a heads-up | `FirstTryFormCheck::forEditedResult()` → `ResultEntryCheck::firstTryBlocks()` | **shared** |
| a time far off the tracker's own times → saved only after "Yes, it's right" (the key of the values judged) | `SuspiciousTimeFormCheck::forEditedResult()` | **shared** - asked of the tracker only, a stale answer is no answer, the confirmation stored by the handler |
| a first try taken meanwhile, an impossible pace (≥ 100 pieces a minute), who may edit | `EditPuzzleSolvingTimeHandler` | **shared** - its refusals in the form's words (`first_try.form_error`, `forms.too_high_ppm`) |

The questions are asked by the edit form's own partial (`first_try/_notice.html.twig`) and its buttons talk to the
edit form's own Stimulus controller (`first-try-check`), which also runs the live check while the sheet is filled in
(`first_try_check` with the result's id) - the hidden `group_players[]` inputs of the sheet only tell that live check
who solved the result; **the save never reads them**: the people are the stored ones, read the way the edit form
fills them when it opens (`MyDataResultEditor::storedGroupPlayers()`). The event link, "unboxed" and the puzzle go
into the message as stored.

**The one difference on purpose:** the edit form is date-only and stores midnight of the day it shows with every
save; My data sends the stored moment back while the day is not changed, so a comment fixed in a cell does not move a
result that came with a time of day (the API, a stopwatch) among the results of its day. A changed day is midnight of
the new day, like the form's.

`MyDataEditParityTest` runs every case **twice - through `EditTimeController` and through the row sheet** - and
compares status, the notice's words, the stored result and the side records: a first try held elsewhere (refused,
then moved), a late first try (saved with a heads-up), a time far off the player's own (asked, a stale answer
refused, confirmed - with the stored confirmation), the same time from the same day (asked, recorded, saved anyway),
a time nobody can solve in (refused even after "Yes, it's right"), a result without its time or day, a month result
kept and made exact.

### Editing in place

On the wide table (from 992 px; the phone's list has none) a **double-click, Enter or F2** on a cell of an editable
column turns it into an input; **Enter saves that ONE field, Escape cancels**. Editable: a result's time, date, first
try and comment, a collection puzzle's comment, and the player's own fields (text, number and tags as a line, a date
as a date input, yes/no, a choice and a rating as a select). Not editable in place: everything else, a value of
several lines (a comment or a text field with a line break is edited in the sheet's textarea only), a result without
a time for its time and first try, every cell of an imported event result.

`assets/controllers/my_data_edit_controller.js` - plain Stimulus, no grid library:

- the cell carries how it is edited and what it holds (`data-edit`, `data-value`; a select's options once, on the
  column's header: `data-edit-options`), the row where it saves and where its sheet is (`data-cell-url`,
  `data-row-url`);
- the request (`POST my_data_cell`, form-encoded, the stateless token in `X-CSRF-Token`) carries the column, **what
  the cell showed when its editor opened** (`from`) and the new value. A value of an own field travels as
  `my_fields[from]` / `my_fields[value]` - the name `OwnFieldsSentryScrubber` withholds from every event;
- **three-way:** when the stored value is no longer `from`, nothing is saved and the answer (409) carries the current
  value - the cell shows it and says "This was changed in the meantime";
- the endpoint saves through the row sheet's code (`MyDataResultEditor`, `CustomFieldsOfMyDataRow`,
  `EditCollectionItemComment`) and answers the row's cells as stored now - the time's "Needs verification" label and
  the pieces per minute follow a changed time;
- a refusal keeps the editor open with what was typed and the reason under it. A refusal that is a **question** (the
  first try is elsewhere, the same time saved already, a time far off the player's own) says so (`row: true`): the
  cell offers "Open the row" and the question is answered in the sheet - **never silently, never in the cell** (the
  endpoint reads no answer at all);
- one editor at a time; an editor with typed text is closed only by Enter or Escape; texts are written with
  `textContent`, values through inputs - never markup.

Tab moves through the editable cells (they are focusable); arrows, type-to-edit and paste are not there.

### Statements of a row sheet

Beside the 4 every signed-in page loads: a result's sheet 4 (the result, the player's fields, their values on it, how
it was added), constant in the number of fields and values (`MyDataEditRowTest::testTheStatementsOfTheSheet`). A
collection puzzle's sheet loads the item through Doctrine (the item, its puzzle and brand, its collection), the
fields, the values, the solve summary and - for a member - their collections.

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

### On production

Explained on production before the merge (2026-11-15), the two main statements with the heaviest accounts:

| Statement | Execution |
|---|---|
| results page, newest first (`GetMyDataResults::fetch()`), the heaviest accounts | 13-18 ms |
| results page with search + year + first tries, by time | 39 ms |
| puzzles page (`GetMyDataPuzzles::fetch()`), the largest library | 8 ms |

Both are narrowed by the player's index first, then a top-N sort of that player's rows - no scan of
`puzzle_solving_time` or `collection_item` as a whole. The search run is fast enough as it is: the `mine` CTE needs no
`MATERIALIZED`.

## Guards

- `tests/Controller/MyData/MyDataControllerTest.php`: guest → sign-in; private + noindex in 6 locales; only the
  signed-in player's rows whatever the address says; broken addresses; every filter against an independently written
  count (the snapshot's JSON instead of the membership rows); every sort both ways; paging (stable total, no row on
  two pages, the state in every link); own fields as columns, archived / other-place / the partner's fields absent;
  date precision; a private teammate masked and named again from the allow list; the year filter takes every year it
  offers; the column chooser (persisted, validated, a post from another site, the choice of a field it did not offer
  kept); the phone's "Filters" disclosure; puzzles + wish list; the statement budget.
- `tests/Value/MyDataCriteriaTest.php`: the grammar, the canonical address, `MyDataColumns`.
- `CustomValuesCoverageTest`, `CustomValuesPrivacyCanaryTest` (pair partner, another player, the owner of a public
  collection), `BlocklistQueryCoverageTest` (own data; co-puzzlers of own results are bilateral history, like the
  export), `TrackerlessTimeQueryCoverageTest` (a result without a tracker has no member - never anybody's row).

- `tests/Controller/MyData/MyDataEditRowTest.php` (2b): a guest; somebody else's result / collection puzzle = 404 in
  the sheet and the cell; the tracker and a registered member edit a pair's result; an imported event result is
  read-only; every field through the sheet and each through a cell; 303 / 422 / 409 / 403; the modal's frame and
  stream; a month result, a result without a time; nothing changed tells nobody; the own values stay the player's own
  (a partner's sheet, a partner's cell); a collection puzzle moved keeps its id, added date and comment; moving stays
  the members'; the statements of the sheet.
- `tests/Controller/MyData/MyDataEditParityTest.php` (2b): the edit form and My data end the same ("The edit form's
  checks").
- `tests/MyDataEditScriptTest.php` + `tests/my-data-edit-harness.mjs` (2b): the cells' real controller in jsdom.
- `CustomValuesPrivacyCanaryTest` opens the row sheets too (a partner gets their own inputs, anybody else a 404).

## What 2c adds

- **2c - many at once:** tick rows → set a field, move/copy to a collection, delete - a `data_change_batch` with its
  log and Undo.
- Later (stage 7 / 8): the Unlisted tab, entry points from the profile's results, collection pages and the user menu,
  "Download what I see".

## Loose ends

- **The results export names a private teammate in clear** (`GetExportableSolvingTimes`: `team_members` takes the
  live `player.name` without the private-profile rule) - the same question this page had, left alone here; to be
  decided for the export on its own.
- The entry points (profile results, collection pages, user menu) - stage 8.

## Left out of 2b

- **Deleting a row and changes of many rows** - 2c.
- **Who solved it, the puzzle, the photo, the event, "unboxed"** - the full edit form (linked from the sheet).
- **Turning a result without a time into a timed one (and back)** - the edit form's mode switch.
- **The phone's "one chosen column, Next goes down"** - it still waits for its prototype on real phones; a phone
  edits through the row sheet.
- **A grid** (arrow keys, type-to-edit, copy and paste of a block, `SheetGrid`) - one cell at a time only.
- **Removing a wish** - a wish's row stays read-only (removing is a delete; 2c).
- **Unlisted puzzles** in My data, and the import's own row sheet - untouched.

## Left out of 2a on purpose

- **Unlisted puzzles** (stage 7, built in parallel) - a result on one is not listed specially, the tab is not there.
- **Difficulty, placing, event** columns and filters: they need the members' insights, the ranking statement and the
  event visibility rules - none is in the old spreadsheet, each is a statement more. The export carries rank and event.
- **A custom from-to piece range** in the form: the chips only (the address takes any `PiecesRange`).
- **Sorting or filtering by an own field** - the values live in jsonb per row; 2b decides with the grid.
- **"Solved / not solved" filter and sort on the puzzles tab**: the solve summary is read for the page's puzzles only
  (the export's aggregate), so SQL can not sort by it without a second, larger statement.
