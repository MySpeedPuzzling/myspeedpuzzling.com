# Data import - implementation plan

Design: [README.md](README.md). Reviews that shaped this order: [reviews.md](reviews.md).

Twelve stages (1a, 1b, 3, 4, 5, 6a, 6b, 6c, 7, 2a-c, 8), each its own pull request or a few, each useful or harmless
when it ships alone. Stage 1 does not need
the import; stages 3-5 write nothing to real data; **stage 6 is the first that writes to `puzzle_solving_time`**.

```
1 Custom fields
   ── 3 Import: queue, upload, reading ── 4 Columns + values ── 5 Pairing + review (builds the row sheet)
   ── 6a The import's own writer ── 6b Add, never twice, Undo ── 6c Date precision ── 7 Unlisted puzzles
   ── 2a My data, read-only ── 2b one-row editing ── 2c many at once ── 8 Entry points and the guide
```

**The order Jan chose (2026-11-14):** custom fields, then the import; My data's table follows the import's row
editor, which is the same component. The phone list of My data is prototyped on real phones while the import is
built. The stage numbers below are names, not the order.

**Every stage, not only the last:** texts in all 6 locales, the feature's entry in CLAUDE.md and the docs it names
(`docs/features/feature_flags.md` for the safety switch, `docs/database-indexes.md`; nothing here is members-only),
cron rows in lily.srv, `docs/TODO.md` for loose ends.

**No staged rollout (Jan, 2026-11-14):** every stage is public when it is merged - for everybody, from day one.
Until stage 6b the import says plainly that adding arrives soon and keeps everything the player set up. The only
switch is the safety switch that pauses every "Add" (kept in the database, flipped on `/admin/imports`), added in 6b.

**Before stage 3 - the test files.** The four real files stay out of the repository. `tests/fixtures/import/` gets
hand-made workbooks that copy their *shapes* with invented puzzles: the ten-sheet workbook (piles + year sheets,
drifting headers, summary blocks, mixed time cells, month-only dates, hidden years), the all-formulas Google export
without times, the training workbook (Excel tables under a dashboard of formulas, a puzzle list the results sheets
point to, results sheets without a brand, rows of unticked checkboxes, template rows, attempt numbers, equal results
in a log, the same result on two sheets, an "entered on MSP" column, tags, twenty small tables on one sheet, drill
sheets, another person's sheet), another app's export (a row id, a status column, times without leading zeros, hashtags with a meaning, barcodes,
picture links), our own export in each of its four formats, a semicolon CSV in Windows-1250, a file over each limit, a
zip bomb. Every reader and recogniser test runs on them.

---

## Stage 1 - Custom fields

Ships alone, in two pull requests: **1a** fields on results, **1b** fields on collection items and the export.

**1a - fields and results**

1. Entities + migration: `custom_field` (with `standard_key`; at most 20 per player, names unique per player),
   `solving_time_custom_values` (id, unique on result + player, both foreign keys cascading).
2. `CustomValues` value object (parse, validate, format one value per type: text, number, date, yes/no, one of a
   list, tags) - reused by the import and My data. **Ready-made fields** (`StandardCustomField`: key, type, where it
   applies, the label's translation key, header words in 6 languages for the import), names shown in the player's
   language.
3. Messages: `AddCustomField`, `EditCustomField`, `ReorderCustomFields`, `ArchiveCustomField`,
   `SetSolvingTimeCustomValues` (the actor must be the tracker or a registered member of the result; values validated
   against the field's type; unknown field ids refused).
4. `/{_locale}/custom-fields` (manage, ready-made fields with one tap) and a card on edit-profile.
5. **Add a result - without touching the add path** (Jan: the add form must never break): `AddPuzzleSolvingTime`,
   `AddPuzzleTracking` and their handlers are unchanged. The controller saves the values in a separate step after
   the result is saved (also on the resend path; an idempotent upsert), inside a catch-all: a failure is logged and
   told, never an error. An invalid value is left out, never a 422. Loading the fields fails open. For a player
   without fields the form's HTML is what it was (test). Switch `CUSTOM_FIELDS_IN_ADD_FORM`. A "My fields" toggle
   after the event section, only when the player has fields. **Tried in a real browser on the branch before the
   merge**: add with and without fields, with a photo, pair, no time, a 422 for another reason, the resend.
6. **Edit:** an own route and form in the modal frame (explicit `action`), linked from the result detail and the edit
   form - **never through `EditPuzzleSolvingTime`**: that message tells every member of a pair/team result and
   recalculates their achievements and XP.
7. Read side: `GetCustomValues::forViewer(timeIds, viewerId)` and nothing else; an owner-only block on the result
   detail. Never joined into `GetPlayerSolvedPuzzles`, `GetPuzzleResultDetail` or the API.
8. `DeletePlayerHandler`: fields and values by player id, including the player's values on other people's results;
   a member taken off a result loses their row.
9. Guards: `CustomValuesCoverageTest` (the table and column names appear only in listed owner-only classes); a canary
   with member B on A's pair result - the edit form, the result detail, API v1 and the profile show B nothing of A's;
   `SolvingTimeDeletionCoverageTest` if a new file trips it.
10. Six locales, CLAUDE.md, `docs/features/custom-fields.md`.

**1b - collection items and the export**

- The custom field type `rating` (1-5) and the ready-made fields the fourth file added (how I liked it, how hard it
  felt, quality, started on, sold for).
- `collection_item.custom_values` + migration; the fields in the collection item's edit form
  (`EditCollectionItemCommentController`); copying an item to another collection copies its values.
- The puzzle merge: an item moving onto the survivor keeps its values; two items merging keep the survivor's and fill
  its gaps (`ApprovePuzzleMergeRequestHandler` keeps the survivor's item to fill). No values in the merge audit.
- Export: extra columns in xlsx and csv (results file and `collection_items`), a nested object in JSON,
  `<custom name="…">` in XML (a field's name is not a valid element name), tags joined, everything through
  `SpreadsheetSafeValue`; `PuzzleLibraryExportBuilder::FORMAT_VERSION` unchanged (new columns only).
- The collection page says where the values are until My data exists (the item's edit form and the export).
- The candidates the spreadsheets suggested (research.md "What players track …") written to `docs/TODO.md`.

Exit: a field created, filled on a new result, edited from the result detail, exported in all four formats, invisible
to a pair partner, another player and a guest.

## Stage 2 - My data

- **Before cutting it up:** a clickable prototype of the phone list ("one column beside the puzzle, Next goes down")
  tried on real phones by Jan and two players. It is new UI and the largest unknown of the plan.
- **2a Read-only table.** `/{_locale}/my-data` (Results · Collections), owner only, `noindex`, `private, no-store`.
  One JSON read per tab (`GetMyDataResults`, `GetMyDataCollectionItems` - the export's queries are the starting point;
  they enter `SuspiciousTimeQueryCoverageTest`, `TrackerlessTimeQueryCoverageTest`, `SeriesPickQueryCoverageTest`).
  Client: a lazy Stimulus controller + ES modules under `assets/my_data/`; the grid for ≥ 768 px reuses `SheetGrid`,
  `grid_keys`, `tsv`; the phone list is new. Sort, search, column chooser, "Download what I see". Entry: a table icon
  on the own profile's results and on the own collection pages. Own Encore style entry.
- **2b One-row editing.** The row sheet (all fields of one row) and cell editing; a save queue (field-level
  `from`/`to`, one request in flight, retries, an idempotency id per change) → the existing messages
  (`EditPuzzleSolvingTime`, the collection comment, custom values), answers applied / conflict / refused per change.
  First-try and pace rules answer exactly like the edit form.
- **2c Many at once** - after stage 6b: the selection bar (set a field for the selected rows, move/copy to a
  collection, delete) as a `data_change_batch` with its log and Undo. Free, like the rest (Q1).

Exit 2a: the largest account's results (2,456 rows) open in under a second on a mid-range phone; statement count
constant in the number of rows (test).

## Stage 3 - Import: queue, upload, reading (no real data touched)

- **The queue first, in this order** (otherwise the consumer crash-loops or messages rot): a pull request that only
  adds the transport; then the consumer on lily; then the feature. Messenger transport `imports` (routing for every
  import message, `failed` as today, the redelivery timeout set on purpose) and its
  consumer - a service in `compose.yml` and in lily.srv's `apps/myspeedpuzzling/compose.yaml` (its own memory limit,
  restarted by `deploy.sh` like `messenger-consumer`; the sandbox gets one too).
- **The reader - measured 2026-11-14, OpenSpout it is** (research.md "Reader measurement"); what was planned to be measured: peak memory and time of reading the fixture shaped like the ten-sheet workbook and a
  10,000-row sheet, with the cell kinds the design needs (date vs duration vs time of day, month-only formats), in
  PhpSpreadsheet with styles on and in OpenSpout (docs checked then). The winner goes behind `SpreadsheetReader`;
  `ParticipantFileReader` stays as it is. Budget: 256 MB, 15 s.
- Entities + migration: `data_import`, `data_import_sheet`, `data_import_row`.
- The file goes straight to object storage by a presigned upload (`DirectPhotoUpload`'s presigner and bucket rules,
  its own prefix and size limit of 25 MB); the form is the fallback, as for photos.
- `ImportProgress`: one place that stores a step's progress and publishes it to the import's private Mercure topic
  after the commit; the page's controller listens and falls back to polling. The "file is ready" notification and,
  the page's `MarkImportBatchSeen`-style report that it showed the end. No e-mail in this stage (nothing can be added
  yet). The consumer on lily gets the Mercure settings (no consumer publishes to Mercure today).
- The upload's own start endpoint (limits, server-chosen content type), the "uploaded" confirmation that checks the
  object, the state `uploading` and a prune for uploads that never finished; `file_sha256` set by the worker.
- The reader's pass of its own over the workbook's parts for what a streaming reader does not give: number formats,
  Excel tables, frozen panes. The workbook is unpacked once (rows into the database); later jobs never re-open it.
- The measurement uses the three shapes **and** a 25 MB, million-cell fixture: 512 MB, 10 s per job.
- `StartImport` (stores the file under `tmp-imports/player-<id>/`, creates the import, same-bytes check) and
  `StartImportFromGoogleSheet` (`GoogleSheetFile`: id from the link, `export?format=xlsx`, host and redirect
  allow-list, size/time caps, sign-in page detection) → `ReadImportFile` on `imports` (reads outside a transaction,
  writes rows in batches of 500) → sheets with header row, row counts, kind guess. Reading again = a new version.
- Pages: Imports home, bring the file (link first), the live reading progress, and the honest line "adding arrives
  soon" until stage 6b.
- Reading as short jobs: one per sheet and per 2,000 rows, none over ten seconds; the limits of Q8 (25 MB, 100
  sheets, 50,000 rows, 1,000,000 cells); the consumer's memory limit 512 MB.
- Daily prune: files after 30 days, discarded imports. Rate limits `data_import_upload`, `data_import_google`.
- Tests: reader on every fixture; over-limit files; the same file twice opens the same import; another player gets a
  404; Google with recorded responses (public, not shared, wrong id, over the cap, a redirect to another host).

Exit: an admin pastes a Google link on a phone and sees its sheets and rows counted correctly; a sign-in e-mail sent
during a 10,000-row read arrives at once; nothing outside the import tables changed.

## Stage 4 - Columns and values (2-3 pull requests)

- **4a Recognising:** `ColumnRecogniser` (header synonyms in 6 locales + value readers → field + certainty; formula
  columns MySpeedPuzzling works out itself preset to "Don't import"), `DataRegionFinder` (Excel tables, frozen panes,
  then the type rule; several regions per sheet), `SheetKindGuesser` (results / owned / puzzle list / not puzzles),
  `PuzzleRowFilter` (default-only rows, template rows), `SheetLookup` (brand and pieces from the puzzle list by name).
  Fields beyond the first draft: attempt number, solved without the box, already on MySpeedPuzzling, owned / not owned. Readers: `DurationReader` (the certainty levels of the README),
  `ImportDateReader` (order elimination, precision, hidden years, the sheet's name), `PiecesReader`, `YesNoReader`,
  `FlagWithDateReader`, barcode and brand-code parsing through `EanList` / `BrandCodeList`, `ImportBrandResolver`.
- **4b Parsing + the screen:** `ParseImportSheet` on `imports`: `cells` + mapping + options + answers → `parsed`,
  `problems`, `kind`, `puzzle_key`, `source_key`, equal rows linked (`same_as_row_id`); every parse job carries the sheet's `mapping_version` (an older job never overwrites a newer one); re-run for one sheet whenever
  its mapping or an answer changes. The "Sheets and columns" screen: confident columns in one line, unsure ones as
  cards, row meaning, the layout's questions, the live line.
- **Known exports:** `KnownExportLayout` with Puzzle Tracker's CSV as the first entry (header set, mapping, words
  with a meaning, the id column, the hosts its pictures may be fetched from); `source_row_id` as a row's identity
  within a source; "words that mean something" for a column of words or tags (pair / team / solo, first try, not
  mine any more, borrowed); a status column deciding what a row is, the wish list included.
- **4c Memory:** `import_layout` (saved on leaving the screen; exact signature → applied with a notice; ≥ 80 % of the
  headers → applied to the known columns; similar sheets of one workbook share it); the built-in layout of our own
  export in its four formats.
- Tests: recogniser on the fixtures' headers in six languages; every reader with a table of real-world values; a
  mapping change re-parses only its sheet; the remembered layout; our own export round-trips with zero questions.

Exit: both sample shapes go from upload to "every row read or listed with a reason" without touching a column by hand
for the standard fields.

## Stage 5 - Pairing and review (still no real data touched)

- `data_import_puzzle`, `import_puzzle_decision` + migration; `BuildImportPuzzles` (distinct puzzle keys).
- `ImportPuzzleMatcher`: the exact steps as one statement, run when a layout is confirmed; `MatchImportPuzzlesFuzzy`
  on `imports` in chunks of 100. Both statements get `EXPLAIN (ANALYZE)` on production before merging.
- "Already yours" before pairing: `source_key` against the player's earlier added rows.
- **The player's own results as a hint:** one statement joins the import's dated rows to the player's results on day +
  time + piece count; a hit pairs the row's puzzle and marks the row "already yours". Among candidates, puzzles the
  player solved or owns first.
- **Where the rules end** is recorded per import (columns nobody placed, values nobody could read, "None of these"
  after a suggestion, unlisted puzzles later found by hand) - the numbers that decide whether a model is ever needed.
- Pages: "We found N - add them now" after the first layout, the Puzzles summary, the lists per group (50 a page,
  pictures, tick / untick, "Tick all I see here" for Probably), the one-puzzle-per-screen chooser, "Do the next 20",
  the search sheet (`import_puzzle_search` JSON endpoint over `SearchPuzzle::byUserInput`), "To fix" grouped by
  distinct value, the row sheet for an import row (its field editors are what My data's row sheet reuses later).
- **One quiet entry point** from this stage on (a "Preview: import a spreadsheet" link on the export page), so real
  layouts arrive before the import can add; "Keep as unlisted" is shown as arriving with stage 7.
- The lookup sheet is remembered in a layout by its signature and title (never by an id of another import); a name
  that is on the puzzle list twice is a row problem; changing the list's mapping re-parses the sheets that point to it.
- The own-result statement reads `puzzle_solving_time`: entries in `SuspiciousTimeQueryCoverageTest` and
  `TrackerlessTimeQueryCoverageTest` in this stage.
- `DecideImportPuzzles` (catalogue / unlisted / left out, one or many, three-way on `decided_at`, writes the player's
  remembered decision), `AnswerImportRows` (one answer for all rows of a value).
- The merge handler moves `data_import_puzzle.puzzle_id` and `import_puzzle_decision.puzzle_id` to the survivor;
  `PuzzleMergeForeignKeyCoverageTest`.
- Guards: hidden and secret puzzles never candidates (test with a hidden fixture puzzle); another piece count never
  ticked in a group; "Probably" never ticked for the player.
- **Threshold check:** 200 sample rows labelled by hand - is 0.6 the right line for "Choose"?
- Tests: tiers on fixtures against fixture puzzles; ambiguity; remembered decisions in a second import; query budget
  of the review pages.

Exit: for the sample shapes the group sizes match the measured shares; every decision survives a reload, a second
device and a newer version of the file.

## Stage 6 - Add

- **6a The import's writer beside the form's (own PR; the add form's handlers are not touched):**
  `ImportedResultWriter` built from the same building blocks, used by nothing yet; `ImportWritePathParityTest`
  (the same input through `AddPuzzleSolvingTimeHandler` / `AddPuzzleTrackingHandler` and through the writer → equal
  rows apart from the listed differences); how the writer keeps `PuzzleSolved` from reaching the five handlers
  decided and tested here.
- **6b Add, never twice, Undo** (second reviewer; a rehearsal on the sandbox):
  - `SolvingTimeSource::SpreadsheetImport` (`spreadsheet_import` - `event_import` sits next to it),
    `SolvingTimeDeletedVia::ImportUndo`; entities + migration: `data_change_batch`,
    `data_change`, `data_import_row_outcome`.
  - `AddImportRows` (on `imports`, never in the web request - the lock is blocking; creates the batch, settles "never
    twice" set-based under the lock `import:<playerId>` as the README's five steps, one running batch per player, marks rows
    `queued`), `ContinueImportBatch` (outside a transaction, 25 rows a turn, dispatches itself), `ApplyImportRow`
    (validate first, then write; result and/or collection items; log), `FinishImportBatch` (puzzle statistics per
    puzzle, one prediction backfill, XP and badges once, the notification and the e-mail `import_added` with the
    outcome and a link - not sent when the page saw the end within a minute), the sweeper
    `myspeedpuzzling:continue-import-batches` (every 5 minutes, lily cron).
  - The five handlers of `PuzzleSolved` check `created_via`; Night Owl and the hour-of-day chart leave imports out;
    the writer asks for reconstructed predictions; `ResultDuplicatePrevention` for rows confirmed as two.
  - The import's collection writer (own ids, comments never overwritten, membership and ownership checks, several
    collections per row); wish-list rows through the existing `AddPuzzleToWishList`, "already yours" when the puzzle
    is on the list.
  - "Never twice" per puzzle: equal rows collapsed (not across attempt numbers), rows paired off one to one with the
    player's equal results, one question per puzzle only when the profile has results left without a partner; a row
    that says "already on MySpeedPuzzling" and finds no partner is listed, not added.
  - The questions: "are any of these the same?" (per puzzle), "one result or two?" (equal rows), first-try conflicts,
    unusual times with the stored confirmation.
  - `UndoImportBatch` and `UndoImportRows` (chunked, on `imports`; player + admin; a batch, chosen rows, or all an
    import added), `/admin/imports` (running and finished imports and batches, failed and refused rows, the time
    verification cases they opened, the log, undo).
  - The safety switch in the database, on `/admin/imports`, respected by the sweeper; honest states for what cannot
    be added yet ("unlisted puzzles arrive with the next release", "month-only dates arrive with the next release" -
    never an invitation to invent a day); taking back "landed on the wrong puzzle" clears the remembered decision; a
    pair's row that equals somebody else's result is a row problem; the notification type (`NotificationType`, the
    target column, the `page` UNION branch, the row template) and the e-mail on the `notifications` stream with
    `player.import_emails` and a one-click unsubscribe; warnings for a batch with over a tenth
    of its rows failed and for a job over its time budget.
  - Reading again: changes offered against the stored `parsed` (three-way), rows gone from the file counted.
  - Only results with an **exact day** are added in this stage; month-only, year-only and undated rows wait with
    "needs stage 6c" hidden behind "To fix: the date".
  - Time verification: the scan's rules for imported results decided with `suspicious-time-review.md` open and built
    here; `detect-suspicious-times --dry-run` on the sandbox after a 2,000-row import is read before the exit.
  - Coverage tests touched: `SuspiciousTimeQueryCoverageTest`, `TrackerlessTimeQueryCoverageTest`,
    `SolvingTimeDeletionCoverageTest`, `SerializedByLockMessagesTest`, the `SystemClock` list.
  - Tests: the whole "Never twice" table of the README as one test class; a puzzle solved 13 times on a day with 5
    already on the profile adds 8 and asks nothing; rows applied in any order give the same
    result; a worker killed between two rows; undo with and without later edits, undo of a running batch; a failing
    row leaves the batch's other rows added; followers get nothing; `tracked_at` at noon in the player's zone; no
    Night Owl from an import; the API's `deleted` list after undo and re-add.
- **6c Date precision:** `puzzle_solving_time.finished_at_precision` + migration; the day-based readers skip imprecise
  results (`GetPlayerStatsSnapshot` streak and weekend, `GetPlayerActivityCalendar`, the duplicate detector's "same
  day", `PlayerBaselineCalculator`'s "which solve was first"), every other reader of `finished_at` is listed in a new
  `DatePrecisionCoverageTest`; `RemovedResultSnapshot` and the undo of an automatic removal carry the precision; the
  date is shown at its precision on the profile results, the result detail
  and the export; API v1 gets an additive `finished_at_precision`; the edit form lets the player set the exact day.
  Then month-only and year-only rows can be added.

Exit: an admin imports a few hundred paired rows on the sandbox, re-imports the file (0 new), imports it renamed and
re-saved (0 new), undoes a batch, and the profile, the puzzle pages and the statistics are exactly as before.

## Stage 7 - Unlisted puzzles

- Entities + migration: `unlisted_puzzle`, `unlisted_solving_time`, `unlisted_collection_item`.
- `ApplyImportRow` for the choice "unlisted"; `PairUnlistedPuzzle` (one locked message that calls the writer as a
  service and moves the puzzle's results and items onto a catalogue puzzle, one batch), `ListUnlistedPuzzle` (only
  after "Is it one of these?": the add-puzzle form prefilled → `AddPuzzle` → pair; 10 a day), `JoinUnlistedPuzzles`,
  `DismissUnlistedPuzzle`, `EditUnlistedPuzzle`, `DeleteUnlistedPuzzle`.
- Pages: Unlisted puzzles (list, "Is it one of these?", search, add to the catalogue, fine as it is), the owner-only
  blocks on the profile's results and the collection pages, the banner line
  (`GetPlayerReviewCounts`, `HintType::UnlistedPuzzles`), the owner's totals line (Q2), export sections
  `unlisted_puzzles`, `unlisted_results`, `unlisted_collection_items`.
- "Use the picture from my export" on "Add to the catalogue": fetched on the player's tap only, from the hosts the
  known layout names, size and type checked, never by itself.
- Weekly `myspeedpuzzling:match-unlisted-puzzles` (exact steps only, one notification per run and player; lily cron).
- The merge handler moves `unlisted_puzzle.resolved_puzzle_id`; `PuzzleMergeForeignKeyCoverageTest`.
- Guards: `UnlistedPuzzleCoverageTest`, `UnlistedPuzzleCanaryTest`.
- Tests: pairing moves everything once, duplicates respected, undo makes it unlisted again; a listed puzzle appears
  in the approvals queue; a corrected brand or a new barcode does not double an unlisted puzzle; account deletion.

Exit: the import of a sample-shaped file ends with every row either added, unlisted, left out or waiting - and a guest
sees nothing of the unlisted ones anywhere.

## Stage 8 - Entry points and the guide

The import is public from its first stage; this stage makes it findable once it is whole.

- Entry points (Getting started card, add-time page, collections, export page, footer), the guide page, the template
  file, every plural checked in 6 locales, a line in the newsletter.
- A look after the first two weeks of real imports: the `imports` queue's waiting time, the moderators'
  time-verification queue, the undo log, and "where the rules ended" (columns nobody placed, values nobody read,
  "None of these") - the numbers that decide the next work, including whether a model is ever needed.

---

## Points to settle while building

| Point | Where |
|---|---|
| Which library reads `.xlsx` | start of stage 3 (measurement) |
| The scan's rules for imported results; a cap per player and run | 6b, with `suspicious-time-review.md` |
| `PuzzleSolvingTimeDeleted` handlers during Undo (statistics, insights, duplicates per deleted result): fast enough in chunks of 50, or does the event need to carry `created_via`? | 6b (measured) |
| The system collection holds 46 players' puzzles twice on production: clean up and add a partial unique index, or keep the check under the import's lock | 6b |
| Similarity 0.6 for "Choose" | stage 5 (200 labelled rows) |
| Header synonyms in ja / fr / es / de / cs - ask native players for their sheets | stage 4, ongoing |
| The redelivery timeout of the Doctrine transport for `imports` (Symfony's default is an hour) | stage 3 |

Settled by the reviews (see [reviews.md](reviews.md)): API v1 sync is not affected by an old `tracked_at` (`GET
/me/results` is the full list, `deleted` pages by `deleted_at`); XP for history is keyed on `tracked_at` and has a
per-player entry point; badges are safe to recalculate once per batch; the entity can be built with a chosen
`trackedAt` and `createdVia`; the lock middleware works for consumed messages but a locked message dispatched inside
another handler lets go too early.

## Where the risk is

Stages 1, 2a, 2b, 3, 4, 5 cannot damage shared data (new tables, owner-only pages, existing messages). **6a** adds a writer next to the add form's handlers and changes none of them. **6b** writes results in bulk: second reviewer, sandbox
rehearsal, the "Never twice" test class. **6c** changes readers of existing data: its coverage test is the net.
Stage 7 adds no reader to shared tables; its risk is a leak, held by the two guard tests.
