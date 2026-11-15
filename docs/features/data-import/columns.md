# Data import: recognising columns and parsing rows (stage 4b-1)

What stage 4b-1 built of [the design](README.md) ("Recognising columns and values", "Which rows / sheets are
puzzles", "Keys, exactly"): once a file is read ([reading.md](reading.md)), the import works out by itself what
every sheet holds, proposes a mapping, asks only what it could not settle, and parses the rows of every sheet that
needs no answer into typed values, problems and keys. **No screen yet** - the "Sheets and columns" screen is the next
slice; it is built on the message `ConfirmImportMapping`, which exists and is tested here. Nothing is paired with the
catalogue and nothing is added to a profile. The value readers and the keys are stage 4a's
([value-readers.md](value-readers.md)); nothing here makes a key those do not make.

Code: `src/Services/DataImport/` (the recognisers and the parser - pure, no database), `src/Value/DataImport/` (the
mapping as data), `src/Query/GetImportSheetRows.php`, the messages below. Tests: `tests/Services/DataImport/`,
`tests/Value/DataImport/ImportMappingTest.php`, `tests/Controller/DataImport/ImportParsingTest.php`.

## The flow

```
RecordImportRead  (3b: the file is read, `reading` → `setting_up`)
  └─ ProposeImportMappings        imports queue, outside a transaction
       1. per sheet, from a sample (the first 300 rows + the rows under each Excel table's header):
          DataRegionFinder → KnownExportLayout → ColumnRecogniser → SheetKindGuesser   (ImportSheetAnalyser)
       2. across the workbook: the puzzle list the results sheets point to              (SheetLookupFactory::link)
       3. per sheet, over up to 5,000 rows: how its times and dates are read, what must be asked
                                                                                       (ImportColumnDecisions)
       └─ RecordImportMappings    one transaction: mapping_version 1 for every sheet; queues, in the same transaction,
            └─ ParseImportSheet   imports queue, one per sheet that is data and asks nothing
                 pages of 500 rows: ImportRowParser → RecordParsedImportRows (one short transaction each)
                 └─ RecordImportSheetParsed   counts; the sheet's equal rows numbered and linked (ImportEqualRows)
                      └─ LinkEqualImportRows  the same row on two sheets linked, across the import

ConfirmImportMapping (the player; no controller yet)
  validated → mapping_version + 1 → ParseImportSheet for that sheet only
  (and for every sheet that looks puzzles up in it - each with a new version of its own)
```

The import stays **`setting_up`** throughout - the state the README names for "the player sets up sheets and
columns". Whether a sheet waits is the sheet's: it has a question.

## Where the data is - `DataRegionFinder`

A sheet's table(s) (`DataRegion`: columns, header row, first and last row, how it was found). In this order:

| | Found by | `source` |
|---|---|---|
| 1 | Excel's "Format as table" (the reader's hints): header row, range, totals rows left out. Twenty small tables side by side are twenty regions | `table` |
| 2 | the row above a frozen pane | `frozen_pane` |
| 3 | the first row whose cells are column names we know (≥ 2, at least half of the row) - or a known export's header set | `header_words` |
| 4 | the first row of texts, as wide as the rows under it, over rows that hold something else than text - skips a title line and a dashboard of formulas | `types` |
| 5 | no header: the sheet is one table from its first row, its columns are "Column N" | `no_header` |

Under a header found by rows, an empty column is the border between two tables only when the block right of it
starts a table of its own - it repeats a header of the table left of it, or both have a puzzle-name column.
Otherwise it is a spacer inside one table. A block without any header beside a table is somebody's notes.

A table found by rows runs to the sheet's end: totals and notes under the data are left out **row by row**
(`PuzzleRowFilter`), not by a guess where the table ends. A table wider than 60 columns is cut on its right
(`cut`). The largest table (rows × columns) comes first.

**One table per sheet is the sheet's data** (`mapping.region`, the largest at first); the others are listed in
`mapping.regions`. A stored row is one spreadsheet row, so two tables standing side by side can not both be parsed
into the same rows - the player can switch to another table (`ConfirmImportMapping`), not take two at once. Left
for when a real file needs it (`docs/TODO.md`).

## What a column is - `ColumnRecogniser`

Two signals: the header (`HeaderMatcher`, six languages) and what share of a sample of the column (up to 200 values)
each value reader takes.

### Certainty

| `certainty` | When | The next slice shows it as |
|---|---|---|
| `certain` | a known export's column · a header that can only mean one field (`FieldGuess::$sure`) whose values go with it (≥ 60 %) or that has no values · a header two fields share, decided clearly by the values (≥ 80 % against ≤ 50 %) · "computed" by its header, or by a formula's word in the header over formulas | one line: "Recognised: …" |
| `probable` | a header that means several things (`Year`, `Size`, `Name`) whose values go with it · a header that only holds a field's word, with fitting values (≥ 70 %) · no known header, but values that say it plainly: barcodes, duration cells, date cells (≥ 80 %); on a sheet without a header row also piece counts and the name | a card, ticked |
| `unknown` | nothing to go by · the values speak against the header · **the weaker of two columns that wanted the same single-valued field** (`reason: duplicate`) - what it would have been stays in `offered` | a card, "This is: …" |

`reason` says why: `header`, `values`, `header_and_values`, `formula`, `known_export`, `duplicate`, `player`,
`nothing`.

- **Computed** (pieces per minute, rank, percentages, differences): preset to "Don't import" - the field is
  `computed`. Formulas alone make nothing computed: a whole sheet may be formulas (a Google Sheets IMPORTRANGE
  export), and its columns are read like any other's.
- **Never two columns on one single-valued field.** Several columns may be people, collections, "not mine any
  more" flags and computed values; everything else is one per table.
- A column without a header that holds values is kept with `header: ""` ("Column N"); a column without a header and
  without a value is not in the mapping.

## What a sheet is - `SheetKindGuesser`, `SheetLookupFactory`

| `kind` | When | `rowMeaning` |
|---|---|---|
| `not_puzzles` | no puzzle name column (a drill log, statistics, a brand list, an empty sheet). Its rows are left out (`sheet_left_out`) by the same parse | - |
| `results` | a time, a solve date or an attempt number | `result` |
| `results` / `owned` | a status column ("Completed?", a status of words): the column says per row what the row is | `by_column` |
| `wish_list` | a list of puzzles whose sheet name says so (wish, wishlist, přání, Wunschliste, deseos, souhaits, ほしい) | `wish_list` |
| `owned` | a name with brand, pieces or a barcode and nothing of a result | `owned` |
| `puzzle_list` | **across the workbook:** a list of puzzles that holds ≥ 60 % of the names of a results sheet lacking a brand or a pieces column (certain from 90 %). That results sheet gets `options.lookupSheetId`; the list's rows are still read as owned puzzles | `owned` |
| `somebody_else` | never guessed - only the player knows | |

`kindCertainty` uses the same three levels. `DataImportSheetKind`, `DataImportRowKind` and
`DataImportRowMeaning` gained `wish_list`.

`SheetLookup`: brand and pieces by the puzzle's name (folded like a key's). A name on the list twice with another
brand or piece count is no answer - the row gets `lookup_ambiguous`; the same puzzle listed twice alike is one.

## Which rows are data - `PuzzleRowFilter`

Left out, with the reason in `parsed.leftOut` (kind `not_a_puzzle`, state `left_out`) - never an error:

`outside_table` · `header` · `repeated_header` · `blank` · `defaults_only` (unticked checkboxes, formulas over
empty cells) · `template` (a results row with the puzzle but no date and no time) · `summary` (Total / Average / …
in six languages in front of the row, or nothing but worked-out numbers without a puzzle) · `not_a_puzzle` ·
`not_mine` (the row says the puzzle went, and holds no result) · `sheet_left_out`.

A puzzle row has a name and something that says which puzzle or what happened: brand, pieces or a barcode (its own
or looked up), a result, or the source's own row id. **A row with brand and pieces (or a result with a piece
count) but no name is a problem to fix (`no_name`), not left out.**

## Known exports - `KnownExportLayout`

Recognised by a set of identifying headers (compared as `HeaderMatcher::words()`); every column is `certain`,
`reason: known_export`; nothing is mapped by hand and nothing is asked. A header that stands in the file twice
(a second "Notes") is held to the one-column-per-field rule too: the later one is unknown, `reason: duplicate`.

| `layout` | Identified by | Says |
|---|---|---|
| `puzzle_tracker` | `ID`, `Name`, `Brand`, `Number of elements`, `Status`, `Time`, `Start date`, `End date`, `Bar code`, `Tags` | `ID` → the row's `source_row_id`; `Status`: Completed → solved **and** owned, Waiting list → owned, Wish list → the wish list; dates day first; hashtags: `#01-individual` / `#02-pair` / `#03-team` → solo / pair / team, `#xSOLD` `#xRETURNED` `#xGIFTED` → not mine any more (a completed row becomes a result only); ratings, price, missing pieces, place of purchase, artist, puzzle type, tags, notes → ready-made fields; `Picture URL` kept with the row; `Creation date`, `Sell price`, `Picture id` not imported |
| `myspeedpuzzling_results` | `result_id`, `puzzle_id`, `puzzle_name`, `pieces_count`, `seconds_to_solve`, `finished_at`, `tracked_at`, `type` (our results export, `.csv` and `.xlsx`) | `result_id` → `source_row_id`, `puzzle_id` → `parsed.puzzleId`; the time is seconds; `comment` is the public comment; everything MySpeedPuzzling worked out for the export (`time_formatted`, ranks, `ppm`, the rating columns …) is computed; `custom:<key>` → the ready-made field of that key; the apostrophe the export puts in front of a formula sign is taken off again |

Not built: our export's `.json`, `.xml` and the library export's ZIP (the reader takes `.xlsx` and `.csv`);
`custom:own:<name>` columns are unknown columns until the screen can make a new field; the hosts a Puzzle Tracker
picture may be fetched from (`pictureHosts`, empty - stage 7 fetches).

## The mapping - `data_import_sheet.mapping` (`ImportMapping`)

```json
{
  "shape": 1,
  "layout": null,
  "kind": "results", "kindCertainty": "certain", "rowMeaning": "result",
  "region": 0,
  "regions": [{"range": "A1:E", "firstColumn": 1, "lastColumn": 5, "headerRow": 1, "firstRow": 2, "lastRow": null,
               "source": "header_words", "name": null, "rows": 4, "cut": false}],
  "columns": [{"column": 5, "header": "Time", "field": "time", "readyMade": null, "role": null,
               "certainty": "certain", "reason": "header_and_values", "offered": null, "unit": null}],
  "questions": [{"id": "duration_shape.two_part_text", "type": "duration_shape", "column": 5,
                 "shape": "two_part_text", "choices": ["minutes_seconds", "hours_minutes"], "suggested": null,
                 "examples": ["45:10", "52:31", "40:05"], "rows": 4}]
}
```

- `field` is an `ImportField` (null = not imported); `readyMade` a `StandardCustomField` when the field is
  `ready_made_field`; `role` is what only a known export has: `source_row_id`, `puzzle_id`, `picture_url`.
- `ImportMapping::fromArray()` is the one check of a stored **and** of a submitted mapping: the shape, every enum,
  columns inside the table and named once, at most 60 of them, never two on one single-valued field.
- Mirrored into the sheet's own columns for lists and filters: `kind`, `row_meaning`, `region` (the range),
  `header_row`, `header_signature` (`1:` + sha256 of the headers as they are compared - what stage 4c knows a layout
  by).
- The questions' `examples` are values of the player's file (at most three, 60 characters each): "Forget the
  spreadsheet's content" must clear them with the cells (`docs/TODO.md`).

### Options - `data_import_sheet.options` (`ImportSheetOptions`)

```json
{"durations": {"two_part_text": {"rule": "minutes_seconds", "by": "player"}},
 "dateOrder": {"order": "dmy", "by": "rows"}, "shortDates": null, "typedDatesSwapped": null,
 "year": {"year": 2023, "by": "sheet_name"},
 "solvingType": null, "lookupSheetId": null, "collectionLabel": null}
```

`by` says who settled it: `rows`, `header` (a unit in the header), `sheet_name`, `known_export`, `player`. What the player or a known
export said is kept whenever the mapping changes; what the rows settled is worked out again.

`data_import_sheet.value_answers`: `{"<column number>": {"<word, folded>": "<ImportWordMeaning>"}}` - what a word
or a tag of a column means (`result`, `owned`, `wish_list`, `both`, `solo`, `pair`, `team`, `first_try`,
`not_mine`, `borrowed`, `nothing`). A known export's status words are stored there; its tags are read by the
layout's patterns.

### Questions - what a sheet can ask

A question is asked only when neither the values nor the header nor the sheet's name settled it. **A sheet with a
question is not parsed**; its page line says "Waiting for your answer".

| `type` (`id`) | Asked when | Answers |
|---|---|---|
| `duration_shape` (`duration_shape.<shape>`) | a shape of the time column has several readings and the rows' pace does not settle it (`17:36`, a bare `237`, a time cell `1:02`, `45:30:00` …) - one question per shape | a `DurationReadingRule` of `choices` |
| `short_dates` | every `Jan 23` / `10/23` can be a day or a month of a year | `day_of_month` · `month_of_year` |
| `date_order` | several orders make every date real and read some differently · rows that can only be day first stand beside rows that can only be month first (always asked, a row of each shown; the rows that do not follow the answer get `date_other_order`) · day first against month first rests on fewer than 3 rows that can only be read that way **and** on less than 30 % of the column's dates (one `25/3` among hundreds of dates that read both ways may be the typo) | `dmy` · `mdy` · `ymd` (those left) |
| `typed_dates` | typed dates beside day-first text dates, all with a day ≤ 12: turned around by the spreadsheet? | `yes` · `no` |
| `year` | dates without a year (`20-Sep`, a cell showing `m/d`) and the sheet's name says no year - or another than the cells store | a year 1950-2100 · `stored` ("the year each cell stores", offered when every such date is a cell) |

Not asked here, later stages': the header row when we guessed it wrong and which table (the screen changes the
mapping), which collection, undated results (README Q3), "one result or two?", unusual times.

## What a parsed row holds - `ImportRowParser`

Per row, for one `mapping_version` of its sheet: `kind` (`result`, `owned`, `both`, `wish_list`,
`not_a_puzzle`), `state` (`waiting`, or `left_out`), `parsed_version`, `puzzle_key`, `source_key`,
`source_row_id`, `same_as_row_id`, and

```json
"parsed": {"name": "…", "brand": "…", "pieces": 500, "barcodes": ["…"], "otherCodes": ["…"], "brandCodes": ["…"],
           "puzzleId": "<uuid, our export>", "pictureUrl": "https://…",
           "date": "2026-01-10 | 2021-09 | 2021", "datePrecision": "day | month | year", "seconds": 2893,
           "type": "solo | duo | team", "people": ["Ana"], "firstTry": true, "attempt": 1, "withoutBox": false,
           "alreadyOnMsp": true, "comment": "public", "collection": "…", "notes": ["what stood beside a time"],
           "custom": {"<ready-made field's key>": "…"}, "from": {"brand": "lookup", "seconds": "text"},
           "content": "<sha256 of all of the above>", "occurrence": 2,
           "keys": {"puzzle": "1:…", "result": "1:…", "owned": "1:…", "wishList": "1:…"}}
"problems": [{"field": "pieces", "code": "pieces_multipack", "value": "13x99", "choices": [13, 99]}]
```

Only what the row has is there. `source_key` is the key of what the row first of all makes - its result, else its
owned puzzle, else its wish; a row that is a result and owned has both in `parsed.keys` (only the first is in the
column rows are linked by - `docs/TODO.md`).

**`source_row_id` is not part of `source_key`.** The README ("Keys, exactly") makes the source's own id the row's
identity *within one source*, beside the keys and winning over them when a file is read again - a key stays a pure
function of what the row says (the same result typed into another file must meet it). So an edited time in a
newer export has another `source_key` and the same `source_row_id`; matching rows of a new version by the id first
is the versions stage's.

- **Problems** (`code`, per `field`): `no_name`, `lookup_ambiguous` · `pieces_multipack` / `_unknown` /
  `_possibly_thousands` / `_not_whole` / `_zero` / `_negative` / `_too_many` / `_unreadable` · `time_unreadable`,
  `time_needs_yes` (a time with words around it: proposed, the words go to `notes`), `time_two_readings` (a shape
  the first 5,000 rows did not show) · `date_unreadable`, `date_in_the_future`, `date_before_1950`,
  `date_order_open`, `date_day_or_month_open`, `date_other_order` (it can only be read the other way round than the
  sheet's dates - taken, and shown), `date_no_year` · `barcode_scientific_notation`, `barcode_digits_lost` ·
  `type_unknown`, `attempt_unreadable`, `status_unknown`, `yes_no_unknown`. A value with a problem is never taken:
  no piece count in the key, no time.
- **What a row is:** the sheet's `rowMeaning`; `by_column` = the status word, a tick (yes → solved; on a list of
  puzzles also owned), else a result when the row holds one, else owned. An "I own it" yes makes a result `both`;
  "not mine any more" (a flag, a word, a tag) makes `both` a result and an owned row nothing (`not_mine`).
- **Solo / pair / team:** its column, else a word or tag with that meaning, else what holds for the whole sheet,
  else the number of people named. **First try** is off unless a column says so - a first-try column, or attempt
  number 1 (README D15).
- **Counts** on the sheet: `rows_understood` (data, no problem), `rows_need_look` (data with a problem),
  `rows_left_out`.
- A row something was already made of (queued, added, …) is never parsed again.

### Equal rows - `ImportEqualRows`, `same_as_row_id`

- Groups are found by `data_import_row.equal_key` - the row's key before numbering, which the numbering never
  changes - a page of whole groups at a time (never a cut one), from the rows' light facts; so numbering a sheet
  again, or beside another job, gives the same.
- Rows of **one sheet** equal in all a key holds are numbered 1, 2, 3 … by a stable order (the attempt number, then
  the row's content - never its place on the sheet); the number is the result key's occurrence. Each is linked to
  the first of them - **one result unless the player says two** - except rows that both carry an attempt number
  and the numbers differ (a log of repeated solves).
- A row with the same key as a row of an **earlier sheet** is linked to that row (the same result on two sheets is
  one). One statement over the import after every sheet's parse, so any order of sheets ends the same.
- At most two steps lead from a row to the row that stands for them all (an equal row of its own sheet → the same
  row on an earlier sheet).

## Versions - a job of an older mapping writes nothing

`data_import_sheet.mapping_version`: 0 = none, 1 = the import's proposal, + 1 with every `ConfirmImportMapping`
(and when the sheet it looks puzzles up in changes). `parsed_version` = the version whose parse ran to its end;
`data_import_row.parsed_version` = the version a row was written for.

**A parse of a sheet and a version happens once.** `ParseImportSheet` first claims it (`BeginImportSheetParse`,
under the import's lock): not when the sheet is parsed for that version, not while another job's claim is fresh
(`parsing_version`, `parsing_started_at` - every batch renews it; dead after 15 minutes without news), and after
three starts that never ended the sheet is given up: `set_up_failure = parse_failed`, logged once, shown on the page
("We could not work this sheet out"), never queued again until the player confirms a mapping. The same end for a
proposal that can not be stored (`mapping_invalid` - that sheet only) and for one asked for three times that never
arrived (`proposal_failed`).

Every write of a parse (`RecordParsedImportRows`, `RecordEqualImportRows`, `RecordImportSheetParsed`) takes the import's advisory lock -
which `ConfirmImportMapping` and a discard take too - reads the sheet on fresh entity state and is refused unless
the sheet's version is the job's and the import is being set up. So a late job of version 1 stops at its next
batch and has written nothing over version 2. Two jobs of the same version write the same (the parser is pure).

## Confirming - `ConfirmImportMapping`

`(importId, playerId, sheetId, mappingVersion, mapping?, answers, sheetWide?, valueAnswers?)` - message and
handler only. The import must be the player's and being set up, the version the one the player saw
(`ImportMappingChangedMeanwhile`, 409); anything invalid is `InvalidImportMapping` (422) and changes nothing.

- `answers`: question id → value, checked against the sheet's questions.
- `mapping`: the whole shape. Taken from it: kind, row meaning, the tables and which one, what each column is. A
  column the player left alone keeps its certainty; a changed one is `certain`, `by: player`. Certainty, reasons,
  roles, a layout and questions a browser sends are not taken.
- `sheetWide`: `solvingType`, `lookupSheetId` (another sheet of the same import), `collectionLabel`.
- `valueAnswers`: replaces the sheet's.

Then times and dates are settled again (answers kept), the version goes up, and - without a question - the sheet
is parsed; a sheet that became "not puzzles" has its rows left out by the same job.

## Progress and the page

`ImportProgress::publish()` after every step and at most once a second between batches. The payload (and
`GET …/progress`) gained `sheetsMapped`, `sheetsParsed`, `sheetsWaiting`. The import's page shows per sheet, read
only: the kind, "Rows understood: N · need a look: M · Left out: K", "Waiting for your answer", "Reading through
its rows…" - and, while something is still worked on, a line with a refresh link (the list does not update by
itself yet; the screen of the next slice will).

## The safety net

`myspeedpuzzling:read-waiting-imports` (every 5 minutes, 3b) also finds imports in `setting_up` that wrote nothing
for 15 minutes while a sheet still waits for a step that runs by itself - no mapping yet, or not parsed for its
version, no question and no fresh claim - and queues that step again (`RequestImportSetUp`), for a week after the read (a step
that fails every time is not tried for ever). It covers a lost message and the imports read before this stage
shipped.

## Limits and measures

| | |
|---|---|
| Sample for recognising | the first 300 rows of a sheet + 60 rows under the header of each of its first 40 Excel tables further down; 200 values per column |
| Rows that settle times and dates | the first 5,000 of the table |
| Parse batch | 500 rows **or** 4 MiB of cells (`BYTES_PER_BATCH`), whichever comes first - the page read, the batch written and the batch of numbered rows alike; memory does not grow with the sheet |
| Mapped table | 60 columns; 200 tables listed per sheet |
| Equal rows numbered per sheet | all of them, 200 keys a page |
| Rows a table's questions are settled from | read by query from the table's first row to its last, 5,000 at most |
| Lookup | 50,000 names |

## Messages, migration

| | |
|---|---|
| On `imports` | `ProposeImportMappings`, `ParseImportSheet` |
| Handled at once | `RecordImportMappings`, `BeginImportSheetParse`, `RecordParsedImportRows`, `RecordEqualImportRows`, `RecordImportSheetParsed`, `LinkEqualImportRows`, `RequestImportSetUp`, `ConfirmImportMapping` |
| Migrations (two, generated) | `data_import_sheet`: `mapping_version`, `parsed_version`, `rows_understood`, `rows_need_look`, `parsing_version`, `parsing_started_at`, `set_up_attempts`, `set_up_failure`; `data_import_row`: `puzzle_key`, `parsed_version`, `equal_key`, index `(import_id, source_key)` |

## The four shapes

`tests/Services/DataImport/ImportShapes.php` copies the shapes of the four real files with invented puzzles;
`ImportShapesTest` pins what comes out without a column touched by hand:

| Shape | Sheets | Rows understood | Need a look | Sheets waiting |
|---|---|---|---|---|
| Ten-sheet workbook, drifting headers, summary blocks | 10 (2 piles, 8 years) | 25 | 7 | 2 - the year (cells store another than the sheet's name), what `45:10` means |
| All-formulas export without times | 1 | 6 | 2 | 0 |
| Training workbook: tables under dashboards, a puzzle list, twenty tables on one sheet | 7 (3 not puzzles) | 22 | 2 | 0 |
| Puzzle Tracker's CSV | 1 | 8 | 0 | 0 |

The real files are tried on the sandbox once the screen exists.

## Not built here

The "Sheets and columns" screen and every editing UI · remembered layouts (`import_layout`, stage 4c) · pairing
with the catalogue, the distinct puzzles (`data_import_puzzle`) · adding anything · versions of a file read again
(rows matched by `source_key` / `source_row_id`) · brand resolution (`ImportBrandResolver`) · unusual times ·
undated results' question · Google Sheets.
