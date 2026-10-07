# Data export: results + puzzle library

Status: **built** (2026-10-07; plan reviewed against the code once, decisions below, "As built" at the end). Today only the results export exists.

## What exists today (results export)

| Piece | File |
|---|---|
| Page (owner only, 403 otherwise) | `ExportPuzzlerDataPageController` → `templates/export-puzzler-data.html.twig` (route `export_puzzler_data`) |
| Download | `ExportPuzzlerDataDownloadController` (route `export_puzzler_data_download`, `{format}` = `json\|xlsx\|csv\|xml`) |
| Read model | `Query\GetExportableSolvingTimes::byPlayerId()`: **one SQL statement**, `fetchAllAssociative()`, mapped to `Results\ExportableSolvingTime` |
| Rendering | `Services\PuzzlerDataExporter` (PhpSpreadsheet for XLSX/CSV, `SimpleXMLElement` for XML, `json_encode` for JSON) |
| Formats | `Value\ExportFormat` (content type + extension) |
| Entry points | user menu (`base.html.twig`), profile ⋯ menu (`player/_header_actions_menu.html.twig`), edit-profile danger zone |
| Tests | `tests/Controller/ExportPuzzlerData{Page,Download}ControllerTest.php`; `GetExportableSolvingTimes` is `OWN_DATA` in `BlocklistQueryCoverageTest` |

The pattern we keep: **one statement per dataset, no N+1, built in memory, returned as one `Response`, no async job.**
Size check on the dev copy of the data: the biggest library is 1,722 collection items (wishlist 655, results 2,328,
lend/borrow history 5,196 rows site-wide), so an in-memory XLSX of all library sheets stays small - no streaming,
no queue needed (step 8 measures it on prod-size data to confirm).

## Goal

A player downloads their **whole puzzle library**: every collection (system + custom) with its items, the wishlist,
puzzles lent out, puzzles borrowed, the lend/borrow history, the sell/swap list (incl. the events they bring items to)
and the sold/swapped history - with every piece of information we hold on those rows, the library settings, the
puzzle facts (names, brand, pieces, EANs, brand code, image) and the player's own solving facts per puzzle, so a
spreadsheet answers "what do I own and have I solved it".

The results export keeps its shape **byte-for-byte** (people have spreadsheets and scripts built on its columns);
it only gets the two safety fixes from Q4. Its `player_rank` / `puzzle_total_solved` are the puzzle page's
leaderboard (fixed 2026-10-07 after a user report - the rank counted every faster attempt and came out above the
total): one entry per player (solo) or exact pair/team with its best non-suspicious time, hidden players left out,
private ones only when the player may see them. A time ranks where it would stand among everybody else's best
times, so the best time carries the leaderboard's rank; a suspicious time has no rank.

## The structure: one export = a set of flat sections

The library export is a list of **sections**. A section = a name (snake_case, English, like the existing headers),
a fixed ordered list of columns and its rows. Every format renders the same sections, so "JSON key = XLSX sheet =
CSV file name = XML element" and one test of the section model covers all four formats.

| Format | Shape |
|---|---|
| XLSX | one workbook: sheet `about` first, then one sheet per section (sheet name = section name, ≤ 31 chars), header row bold + frozen, empty sections still get their header row |
| JSON | `{"export": {meta}, "collections": [...], "collection_items": [...], ...}` - every section a list of objects |
| CSV | **ZIP** with one CSV per section (`collection_items.csv`, ...) + `about.csv` + `README.txt` (what each file is) - a single CSV cannot hold 9 tables. `application/zip`, `.zip` |
| XML | `<puzzle_library format_version="1" generated_at=".."><about>..</about><collection_items><record>..</record></collection_items>...</puzzle_library>` |

Sections stay **flat** in every format (no items nested inside collections in JSON/XML): one model, and the JSON
round-trips to the same tables a spreadsheet user sees. Rows reference each other by ids (`collection_id`,
`puzzle_id`, `lent_puzzle_id`, `sell_swap_item_id`).

Language: column names, enum values and README are English; the only translated values are the names of the built-in
lists (system collection) in the request locale. README.txt says so.

### `about` (JSON `export` object, XML `<about>`, XLSX/CSV a two-column key/value sheet)

- `format_version` (1), `generated_at` (UTC, ISO 8601, from `ClockInterface`), `player_id`, `player_code`,
  `player_name`, `sections` (name → row count). Bump `format_version` only on a breaking change (removed/renamed
  column); adding columns is not breaking.
- **Library settings** (own data, nothing else holds them): `puzzle_collection_visibility`,
  `wish_list_visibility`, `unsolved_puzzles_visibility`, `solved_puzzles_visibility`, `lend_borrow_list_visibility`,
  `collection_display_mode`, and the sell/swap list settings (`description`, `currency` resolved as below,
  `shipping_info`, `shipping_countries`, `shipping_cost`, `contact_info`) from `SellSwapListSettings`.

### Shared puzzle columns (appended to every item section)

So each sheet is readable on its own (the results export is denormalised the same way):

| Column | Source |
|---|---|
| `puzzle_id` | `puzzle.id` |
| `puzzle_name`, `puzzle_name_language` | `puzzle.name` (main title), `puzzle.name_language` |
| `puzzle_other_names` | `PuzzleNames::fromJson(puzzle.alternative_names)`, joined `"Name (de), Name (fr)"`; a name without a language has no bracket |
| `brand_name` | `manufacturer.name` |
| `pieces_count` | `puzzle.pieces_count` |
| `ean` | `EanList::fromStored(puzzle.ean)->display()`, `", "`-joined (same as the puzzle page's `puzzle_eans()`) |
| `brand_code` | `BrandCodeList::fromStored(puzzle.identification_number)->display()`, `", "`-joined |
| `puzzle_url` | absolute URL of `puzzle_detail` in the request locale |
| `puzzle_image_url` | `puzzle.image` through the uploaded-assets base URL, **empty while `hide_image_until` is in the future** (same `CASE` as every list query, `:now` from `ClockInterface`) |
| `my_solved_count` | how many results the player has on this puzzle (solo + every pair/team they are a registered member of) |
| `my_first_solved_at`, `my_last_solved_at` | `COALESCE(finished_at, tracked_at)` min/max |
| `my_best_solo_seconds`, `my_best_solo_time` | best solo time, raw + `HH:MM:SS` |
| `community_solved_count` | `puzzle_statistics.solved_times_count` (what the list pages show as "Completed N×") |
| `difficulty_tier` | **members only**, `DifficultyTier::toApiValue()` (stable token, same as the API); empty for others - the header stays the same so files compare across a membership change; same rule as `ResolvePuzzleListInsights` |

Hidden puzzles (`hide_until`) keep their name and codes, exactly as on the list pages (the player put the
placeholder on their own list); only the image follows `hide_image_until`.

"Unsolved" is **not a section**: it is derived (collection + borrowed minus solved) and `my_solved_count = 0` filters
it in one click; a separate sheet would only duplicate rows.

### Sections

1. **`collections`** - one row per collection incl. the system one:
   `collection_id` (system = `Collection::SYSTEM_ID`, `__system_collection__`, so it joins to `collection_items`),
   `is_system_collection`, `name` (system = translated `collections.system_name`), `description`, `visibility`
   (`public|private`; system = `player.puzzle_collection_visibility`), `created_at` (system = empty), `item_count`.
2. **`collection_items`** - one row per `collection_item`: `collection_item_id`, `collection_id` (system =
   `__system_collection__`), `collection_name`, `added_at`, `comment` + shared puzzle columns. A puzzle in three
   collections = three rows (that is the truth of the data).
3. **`wishlist`** - `wish_list_item_id`, `added_at`, `remove_on_collection_add` + shared puzzle columns.
4. **`lent_out`** - `lent_puzzle` where `owner_player_id = me`: `lent_puzzle_id`, `lent_at`, `notes`,
   `current_holder_name` (registered player's name, else `#CODE`, else the free-text name), `current_holder_code`
   (registered only), `current_holder_is_registered` + shared puzzle columns.
5. **`borrowed`** - `lent_puzzle` where `current_holder_player_id = me` and I am not the owner (same condition as
   `GetBorrowedPuzzles::byHolderId`): `lent_puzzle_id`, `lent_at`, `notes`, `owner_name`, `owner_code`,
   `owner_is_registered` + shared puzzle columns.
6. **`lend_borrow_history`** - every `lent_puzzle_transfer` where I am from / to / owner (same condition as
   `GetLendBorrowHistory::byPlayerId`): `transfer_id`, `lent_puzzle_id` (empty once the loan ended),
   `transferred_at`, `transfer_type` (`initial_lend|pass|return`), `from_name`, `to_name`, `owner_name`,
   `my_role` (`owner|from|to`, comma-joined when several), `is_active` (`lent_puzzle_id IS NOT NULL`) + shared puzzle
   columns (the puzzle may be gone: `LEFT JOIN`, columns empty).
7. **`sell_swap`** - `sell_swap_list_item`: `sell_swap_item_id`, `added_at`, `listing_type`, `price`, `currency`,
   `condition`, `comment`, `published_on_marketplace`, `reserved`, `reserved_at`, `reserved_for_name` + shared puzzle
   columns. `currency` = the list's currency **at export time** (prices are stored without one): `custom_currency` when
   `currency = 'custom'`, else `currency` - the same resolution as `GetMarketplaceListings`.
8. **`sell_swap_events`** - every `sell_swap_list_item_event` of my items, **including rows the site no longer shows**
   (kept after the event ends or I stop going): `sell_swap_item_id`, `puzzle_id`, `puzzle_name`, `event_id`,
   `event_name`, `event_date_from`, `event_date_to`, `added_at`, `currently_shown` (= the marketplace label rule:
   `GetMarketplaceEvents::SQL_QUALIFIES` + `sqlPlayerGoing()`, with `:marketplace_today` from
   `GetMarketplaceEvents::todayParameter()`; the statement joins `competition c` + `competition_series cs` because the
   fragment uses both aliases). Only the short puzzle columns here - the full ones are on the `sell_swap` row.
9. **`sold_swapped`** - `sold_swapped_item` where `seller_id = me`: `sold_swapped_item_id`, `sold_at`,
   `listing_type`, `price`, `buyer_name` (registered name / `#CODE` / free text), `buyer_code` + shared puzzle columns.
   **No currency column**: `sold_swapped_item` stores none, and today's list currency may not be the one the sale was
   in. README.txt says prices there are in whatever currency the list had at the time.

Deliberately **not** in this export (candidates for a later "everything" export): ratings given/received
(`transaction_rating`), conversations, notifications, profile settings, puzzles bought by me
(`sold_swapped_item.buyer_player_id = me`), results (already their own export - see Q2).

### Privacy and safety rules

- **Own data, everything included**: private collections, unpublished listings, notes, contact info - it is the
  player's own data (GDPR access/portability), so custom collections export **regardless of membership** (Q1).
- Counterparties (holder, owner, buyer, reserved-for) are **bilateral history**: shown exactly as the lend/borrow and
  sold pages show them, not filtered by the blocklist (`player-blocklist.md`: "bilateral history … deliberately
  unfiltered"). New queries get `OWN_DATA` / `BILATERAL` entries in `BlocklistQueryCoverageTest`. No `is_private` is
  selected, so `PrivateProfileQueryCoverageTest` stays green. Counterparty **ids are not exported** (only public
  codes), same as the results export's `team_members`.
- Hidden puzzle images stay hidden (`hide_image_until`).
- **Spreadsheet formula injection**: free-text values other people typed (holder/owner/buyer names, player names)
  and the player's own comments end up in cells. XLSX text cells are written with
  `setCellValueExplicit(…, DataType::TYPE_STRING)` (never `setCellValue()`, which turns `=…` into a formula); CSV
  values starting with `=`, `+`, `-`, `@`, tab or CR get a leading `'`. Numbers and booleans stay typed. JSON/XML
  are untouched (not interpreted).
- Response header `Cache-Control: private, no-store` (personal data must never land in a shared cache).

## Performance budget

**9 section statements + 1 solving-facts statement + 1 insights statement, constant in library size** (an empty
library skips the two puzzle-level ones). The page itself costs nothing new.

- Each section = one statement shaped like the existing list query it mirrors, but with the export columns
  (`ean`, `identification_number`, `alternative_names`, `name_language`, …). Every `WHERE` hits an existing index
  (`collection_item.player_id`, `wish_list_item.player_id`, `lent_puzzle.owner_player_id` / `current_holder_player_id`,
  `lent_puzzle_transfer.{from,to,owner}_player_id`, `sell_swap_list_item.player_id`, `sold_swapped_item.seller_id`) -
  **no new index, no migration**.
- Solving facts: `GetExportablePuzzleSolveSummary::forPuzzles(playerId, puzzleIds)` - one aggregate,
  `GROUP BY puzzle_id`, `WHERE puzzle_id = ANY(:ids)`, over the player's results in the index-friendly UNION form
  `GetFirstTryTimes` already uses (`puzzling_team_id IS NULL AND player_id = :p` ∪ the `puzzling_team_member`
  join on `player_id = :p`). The tracker is always a member of the team, so this neither double-counts nor misses
  anything vs. the jsonb containment of `GetExportableSolvingTimes`. `EXPLAIN ANALYZE` on the largest player; numbers
  go here.
- Community count + difficulty: **reuse `ResolvePuzzleListInsights::forViewer()`** with the logged-in
  `PlayerProfile` (one query, tiers only for members).
- Puzzle ids from all sections are collected in PHP (`array_unique`) and fed to the two puzzle-level queries; their
  results are merged into the rows in PHP, so section SQL stays simple and no section re-aggregates results.
- **Guard test** (pattern: `PlayersPageQueryBudgetTest` + `QueryCountAssertions`, which counts *every* statement of
  the request incl. profile and session): the download's statement count for a 1-item library **equals** the count
  for a library with many items in every section, plus a ceiling. Never assert an exact "11".
- CSV files are written with `fputcsv` to `php://temp` (no PhpSpreadsheet for CSV - faster, less memory). The ZIP
  goes through `ZipArchive` on a `tempnam()` file removed in `finally` (ZipArchive cannot write to a stream).
  `ext-zip` is guaranteed already: `phpoffice/phpspreadsheet` requires it. XLSX keeps PhpSpreadsheet.

## Code layout

```
src/Value/ExportFormat.php                 (unchanged - both exports use it; the writer picks .zip for csv)
src/Results/Export/ExportSection.php       name, columns (list<string>), rows (list<array<string, scalar|null>>)
src/Results/Export/ExportFile.php          content, contentType, filename
src/Results/Export/ExportablePuzzle.php    the shared puzzle columns (const SQL fragment + fromDatabaseRow + toColumns)
src/Results/Export/Exportable*.php         one per section row (like ExportableSolvingTime: fromDatabaseRow + toArray)
src/Query/GetExportable{Collections,CollectionItems,WishList,LentOut,Borrowed,LendBorrowHistory,SellSwap,SellSwapEvents,SoldSwapped}.php
src/Query/GetExportablePuzzleSolveSummary.php
src/Services/Export/PuzzleLibraryExportBuilder.php   runs the queries, merges puzzle facts, returns about + list<ExportSection>
src/Services/Export/SectionedExportWriter.php        sections + about + ExportFormat → ExportFile (xlsx/json/zip-of-csv/xml)
src/Controller/ExportPuzzleLibraryDownloadController.php   single action, owner-only like the results download
```

- Queries stay **flat in `src/Query/`** (prefix `GetExportable…`, like `GetExportableSolvingTimes`):
  `BlocklistQueryCoverageTest` globs only `src/Query/*.php`, so a subfolder would silently skip the guard.
- They are new classes rather than new methods on `GetCollectionItems` & co.: the export columns differ from the page
  DTOs, and widening the page queries would cost every page view.
- `ExportablePuzzle` is the **one** place that turns a puzzle row into the shared columns (names via `PuzzleNames`,
  codes via `EanList`/`BrandCodeList`, image masking, URL). Every section query selects the same puzzle fragment -
  a `const` SQL snippet on `ExportablePuzzle` so the queries cannot drift.
- `PuzzlerDataExporter` (results) keeps its output shape; it only gets the formula-injection and `no-store` fixes
  (Q4). If we later want results inside an "everything" file, they become one more `ExportSection` (Q2).
- Time: `ClockInterface` for `:now` (image masking), `generated_at` and the filename date - no `date()` /
  `new DateTimeImmutable()` in new code. Timestamps `Y-m-d H:i:s` like the results export (stored values, UTC).
- Nothing caches between requests → no `ResetInterface`. Read-only → no Messenger message.

### Route

```
export_puzzle_library_download
  cs /export-dat-hrace/{playerId}/knihovna/{format}
  en /en/export-puzzler-data/{playerId}/library/{format}
  es /es/exportar-datos-puzzler/{playerId}/biblioteca/{format}
  ja /ja/パズラーデータエクスポート/{playerId}/ライブラリ/{format}
  fr /fr/export-donnees-puzzler/{playerId}/bibliotheque/{format}
  de /de/puzzler-daten-exportieren/{playerId}/bibliothek/{format}
  requirements: format = json|xlsx|csv|xml
```

Same guards as `ExportPuzzlerDataDownloadController`: `IS_AUTHENTICATED_REMEMBERED`, no profile → `my_profile`,
another player's id → 403. Filename `speedpuzzling-library-YYYY-MM-DD.{xlsx|json|zip|xml}`.

### Page

`export-puzzler-data.html.twig` becomes two cards, same 4-button grid each:

1. **Results** (existing buttons + "data included" list, unchanged).
2. **Puzzle library** - buttons JSON / Excel / CSV (ZIP) / XML; "data included" list = collections, wishlist,
   lent/borrowed + history, sell/swap + sold, library settings, "puzzle details and whether you solved it", and a line
   that difficulty is included for members.

The menu label stays "Export data". Also link the library export from the Puzzle library page (`puzzle_library`,
own profile only) - a small "Export" button next to the title. New translation keys in **all 6 locales**.

## Implementation steps

1. `ExportSection`, `ExportFile`, `SectionedExportWriter` + unit tests (each format: section names, header order,
   empty section, booleans/nulls, non-ASCII + `&<>` in comments, `=SUM(…)` stays text in XLSX and gets `'` in CSV,
   ZIP holds one CSV per section + README, sheet names ≤ 31 chars).
2. `ExportablePuzzle` + `GetExportablePuzzleSolveSummary` + tests (solo, pair where I'm a registered member, team
   with only guests besides the tracker, hidden image masked, multi-EAN puzzle, name without a language).
3. The 9 section queries + DTOs, each with a query test on fixtures (`.claude/fixtures.md` has collections,
   lent/borrowed, sell/swap, wishlist data): system vs custom collection, private collection included, borrowed
   excludes own, history roles, sold buyer name variants, custom currency, event rows shown/not shown.
4. `PuzzleLibraryExportBuilder` (+ `about` incl. settings) + query budget test (equal count small vs large library).
5. Controller + route + controller tests (4 formats, content types, `.zip` for csv, 403 for another player,
   anonymous redirect, invalid format 404, `Cache-Control: no-store`).
6. Page cards + Puzzle library link + translations (en first, then cs/de/es/fr/ja).
7. `BlocklistQueryCoverageTest` allowlist entries; results export Q4 fixes; quality
   gates (phpstan, cs-fix, paratest, schema:validate, cache:warmup).
8. Measure on prod-size data (largest library): wall time + peak memory per format; numbers go in this doc.
9. Update this doc (status → shipped) and add a one-liner in CLAUDE.md's feature list.

## Decisions (Jan, 2026-10-07)

- **Q1** Custom collections export regardless of membership - own data.
- **Q2** Results stay a separate download; no "everything" file yet.
- **Q3** `difficulty_tier` included, members only.
- **Q4** The results download gets the formula-safe cells and `Cache-Control: private, no-store` too.

## As built (2026-10-07)

- Files as in "Code layout", plus `Results/Export/ExportDocument` (root name + about + sections, what the writer takes)
  and `Services/Export/SpreadsheetSafeValue` (the one place cells are written: XLSX explicit types, CSV `'` prefix) -
  `PuzzlerDataExporter` uses it too.
- `collections.item_count` is counted in PHP from the exported items - no aggregate query; the builder test checks it
  against `GetCollectionItems::countByCollectionAndPlayer()` for every collection.
- The library settings come from the logged-in `PlayerProfile` (no query) except `collection_display_mode`
  (`GetCollectionDisplayMode`, one tiny query).
- Entry points: second card on the export page (`#puzzle-library`), "Export" button on the own Puzzle library page.
- Tests: `tests/Services/Export/SectionedExportWriterTest.php` (all formats, formula safety, missing column),
  `tests/Services/Export/PuzzleLibraryExportBuilderTest.php` (sections, counts, lending sides, currencies, event marks,
  pair results, members-only difficulty), `tests/Controller/ExportPuzzleLibraryDownloadControllerTest.php` (formats,
  access, `no-store`, query count equal for a small and a large library), two formula tests in
  `tests/Services/PuzzlerDataExporterTest.php`.
- Measured on the dev copy of production (largest library: 1,722 collection items, 1,727 rows in all; debug kernel):
  build (all statements + merge) 58 ms; writing JSON 7 ms / 1.9 MB, XLSX 708 ms / 233 KB / +40 MB peak memory,
  CSV ZIP 25 ms / 175 KB, XML 25 ms / 2.2 MB. No streaming or queue needed.
