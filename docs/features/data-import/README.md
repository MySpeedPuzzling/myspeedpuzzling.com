# Data import (spreadsheets of results and collections)

**Status:** 2026-11-14 - design, revised after two reviews and a third real file; nothing built. Staged delivery in
[implementation-plan.md](implementation-plan.md), the measurements and outside research in [research.md](research.md),
the reviews and what they changed in [reviews.md](reviews.md). Jan decided the five heaviest questions on 2026-11-14
("Decided by Jan") and the rest the same day in a second round; no question is open.

A puzzler who kept their history in Excel or Google Sheets uploads the file (or pastes a public Google Sheets link),
tells MySpeedPuzzling what the columns mean, pairs the rows with catalogue puzzles and adds them - a few rows or a
thousand, today or over several weeks. Rows whose puzzle is not in the catalogue become **unlisted puzzles**: the
player's own, without a picture, shown to nobody else, until the player finds the puzzle in the catalogue or adds it
with a photo.

Three more things belong to the same work and ship on their own: **custom fields** (the player's own columns on
results and collection items), **My data** (a table view of the player's results and collections, with editing) and the
**unlisted puzzles** list.

## Why

Production, read-only, 2026-11-14:

- 3,569 players logged their first result in the last 12 months. **667 of them (19 %) typed in older results by hand
  during their first 14 days** (a solve date more than 30 days before the entry), 178 of them ten or more, 39 fifty or
  more, one 666.
- Over the same 12 months 1,857 players entered 31,874 back-dated results (9 % of all results added), 139 players fifty
  or more.
- Nobody knows how many gave up at the sight of a thousand rows to retype. No feature request mentions an import.

The demand shows in what people already do by hand. The import is for them and for the ones who never started.

## What real spreadsheets look like

Four real files from players (kept out of the repository) and one public template were taken apart - three
spreadsheets people built themselves and one **export of another puzzle-tracking app** ("Puzzle Tracker"):

| Seen | Consequence |
|---|---|
| Another app's export (CSV, 459 puzzles): a stable **ID** per row, a **Status** (Completed / Waiting list / Wish list), time written `0:56:4`, start and end date, three ratings 1-5 (satisfaction, difficulty, quality), price, sell price, missing pieces, artist, place of purchase, new / second-hand, a **barcode** for 37 % of the rows, hashtags the player used for their own system (`#02-pair`, `#xSOLD`, `#Lended_…`) and a **link to a picture** of every puzzle | Exports of known apps are **built-in layouts** - recognised by their headers, nothing to map ("Coming from Puzzle Tracker?"). A row id of the source is the row's identity. A status column decides what a row is, the wish list included. A word or tag can carry a meaning (pair, sold). Times without leading zeros. Ratings are ready-made fields. The picture link is kept with the puzzle |
| A training workbook of 20 sheets: a **puzzle list** (1,338 puzzles with brand, pieces, tags), results sheets **by category** (500 pieces solo, 300 pieces, pairs, competitions, large, small, one sheet per speed-run puzzle) that name the puzzle only by a dropdown into the list - two of them have no brand column - plus statistics, goals, drills, a brand list and another person's results | A sheet has a **kind** (results / puzzles I own / a puzzle list other sheets point to / not puzzles / somebody else's); brand and pieces may be **looked up in another sheet** by the puzzle's name; a sheet may set a value for all its rows (pair, the puzzle) |
| A dashboard of formulas in rows 1-5, the header in row 6; Excel tables and frozen panes mark where the data starts; one sheet holds 20 small tables side by side | Excel's own table and freeze marks are the first hint for the header row; several tables on a sheet are several regions, the largest offered first |
| Tables stretched to row 1,000 with unticked checkboxes (641 "rows" holding only `FALSE`), and rows with the puzzle prefilled but no date and no time | A row with nothing but default values is empty; a results row without date and time is a template row - both left out |
| Columns that are formulas: pieces per minute, difference to the best attempt, place and percentage on MySpeedPuzzling | Computed columns default to "Don't import - MySpeedPuzzling works this out" |
| `attempt` (1, 2, 3 …), `No box?`, and **`Entered on MSP?`** - 1,101 of 1,927 results already typed into MySpeedPuzzling | New fields: attempt number (1 = first try), solved without the box, already on MySpeedPuzzling. The comparison with the player's existing results is the heart of the import, not a safety net |
| One puzzle solved 153 times, up to 13 times a day; 13 pairs of results equal in puzzle, day **and** time, on one sheet, with different attempt numbers | Equal rows are not always a copy: the attempt number is part of a row's identity; many results of a puzzle on one day are normal |
| The same result on two sheets (a competition sheet and the 500-piece sheet): 144 results | Across sheets, an equal result is one result |
| A column of tags (`Photo, Collage, Animals`), status columns (`Got rid of`: yes / x / "Back to the library"; `Waiting to arrive`; `Have multiple copies`: 2) | A custom field type "tags"; a column may say a row is **not** owned |
| Dates typed with a wrong year (2028 among 2024) | A date in the future is a problem to fix |
| One workbook = 10 sheets: a to-do pile (1,392 rows), a keep pile, one sheet per year of results (80-715 rows each). Headers drift between the year sheets | Sheets are chosen and mapped one by one; sheets with the same layout are mapped once |
| The other file: one sheet, 793 puzzles, **every cell a formula** (a Google Sheets export built with IMPORTRANGE), no time column at all | Read the cached values, never calculate a formula. Results without a time are a main case |
| Time column: real duration cells, time-of-day cells, and text - `1:13:??`, `8min`, `1:40ish`, `17:36 for both`, a list of four times, a bare number, words | A duration reader with levels of certainty; every unclear **distinct** value is shown once with our reading |
| Dates: real dates shown as `m/d` (the year hidden - one sheet named 2019 holds 2020 dates), month-only cells, text like `Sept 2020`, the year only in the sheet's name; `Y` without any date | Date precision is real data (day / month / year / none) and is stored, not invented |
| One cell holding two facts: `Y - 30/12/2023` (solved + when) | A column may feed two fields through a small set of built-in readers |
| Pieces: `13x99`, `500*4`, `?`, `mini?`, averages computed by a formula | Multipacks and unknown counts are a row problem with choices, never a guess |
| Summary blocks under the data, notes typed into data columns, a column of tags without a header, rows with brand and pieces but no title | Rows that are not puzzles are recognised and listed as "left out"; a row missing only its name is a problem to fix, not left out |
| Columns beyond ours: artist, year of release, condition, where from, purchase price, purchase date, material, yes/no flags per place | Custom fields; nothing a player tracked is thrown away |
| Notes with other people's names, prices, who wants the puzzle | Notes go to a **private** field by default - a result's comment is public |
| The same puzzle on the to-do pile and on a year sheet; the same result on two sheets | Decisions are per puzzle; an identical result is added once unless the player says otherwise |
| No barcode column in either file | Name + brand + pieces is the main way to pair, the barcode a bonus |

**How much pairs by itself** - the 4,764 distinct puzzle rows of the first two files against the production catalogue
(46,472 puzzles):

| Outcome | Rows | Share |
|---|---|---|
| Same name, same pieces, same brand - exactly one catalogue puzzle | 1,746 | 36.7 % |
| Same name and pieces, brand written differently or missing - exactly one | 455 | 9.6 % |
| Same name and pieces - several catalogue puzzles to choose from | 121 | 2.5 % |
| Same name, other piece count or other brand only | 269 | 5.6 % |
| No catalogue puzzle of that exact name | 2,173 | 45.6 % |

Of the rows without an exact name (a sample of 400): 36 % have a catalogue puzzle of the same piece count with a
similar name (trigram similarity ≥ 0.6), 20 % a weak candidate, **43 % nothing**. The brand text was recognised for
75 % of the rows.

The third file belongs to an active MySpeedPuzzling player (1,381 distinct puzzles): 53 % pair exactly on name +
pieces + brand, 15 % on name + pieces, 23 % have no puzzle of that exact name - and of those four in five have a
similar one, because the player's spelling differs from the catalogue's.

So for a real import **a third to a half pairs with one tap, about a third needs the player to look or choose, and up
to a quarter are puzzles the catalogue does not have.** Unlisted puzzles are a main path, and the review of the middle
part is where the import is won or lost.

**How well "already yours" works** - the third file's 1,101 results flagged "entered on MSP", compared with their
owner's results on production: for 865 the puzzle was found by its exact name, and 837 of them (97 %) have a result
with the same day and time; 4 are a day off, 16 have the time on another date, 6 the day with another time, 2 nothing.
The other 236 were not found by name at all - the spreadsheet's name is not the catalogue's. Two consequences: the
comparison must run **after** pairing, and **a result of the player with the same day and time is itself the best hint
which puzzle a row means**.

## Principles

1. **Nothing is added without the player's yes.** The import proposes; the player confirms - one row, a group, or
   everything of one kind with one tap. A proposal the player did not confirm changes nothing.
2. **Every row stands alone.** A row that cannot be read or paired waits with its reason and its choices; it never
   stops the others.
3. **Value first.** The first results can be added a few taps after the upload; everything unclear waits and can be
   done later, in portions.
4. **Nothing is lost.** The import lives in the database from the upload on: a closed tab, a dead phone, three weeks of
   pause - the player continues where they stopped. Columns we do not know become custom fields or stay with the row.
5. **Never twice.** The same file uploaded again adds nothing. The same file with 30 new rows adds 30.
6. **Shared data is protected.** A result reaches a public leaderboard only on a catalogue puzzle the player confirmed
   - in a group only when name, pieces and brand agree, another piece count only one by one after a warning - and after
   the same checks as a typed result. An unlisted puzzle reaches no public page, list, search, statistic or API. A date
   we do not know is never stored as a day.
7. **Everything can be taken back.** Every change is written to an append-only log; the player can undo an "Add", an
   admin can undo anybody's.
8. **Phone first.** Every step works one-handed at 380 px: lists and sheets, one decision per screen, no horizontal
   scrolling of the page.
9. **The add form is never put at risk.** Adding a result is the most important thing the site does. Nothing in this
   work changes its handlers; what is added around it (the player's own fields) is saved after the result, can never
   refuse or lose a result, is invisible for players who do not use it, and has a switch.
10. **Decide per puzzle, per brand, per value - not per row.** One answer about "MB" fixes 60 rows; one answer about a
   puzzle fixes its to-do row and its three results; an answer given once is remembered for the next file.

## The player's journey

Screens: the mock-ups canvas linked in [reviews.md](reviews.md) (drawn before the reviews - the differences are listed
there).

### 0. Imports home - `/{_locale}/import`

The player's imports with a progress line each ("2,105 of 4,764 puzzles added · 310 waiting for you"), **Continue**,
what was added with **Undo**, and **Import a spreadsheet**. Entry points: the Getting started card, the add-time page,
collection pages, the export page, the footer.

### 1. Bring the file

- Paste a **Google Sheets link** shared with "Anyone with the link" (on a phone the spreadsheet usually lives there),
  or pick a file: `.xlsx`, `.csv`, and MySpeedPuzzling's own export in any of its formats (later `.xls`, `.ods`).
- "Start from our template" downloads a small `.xlsx`.
- The upload answers at once with the import's page; reading happens in the background with a live progress line
  (usually a few seconds). A big file may take minutes: the player may leave and is told when it is ready ("Telling
  the player").
- **The same file again** (same bytes, or the same Google sheet): "You imported this on 3 November - 2,105 added, 310
  waiting. **Continue that import** / Look for new rows". Never a silent second copy.
- After a Google import: "You can switch the sharing off again."

### 2. Sheets and columns

One screen per **layout** (sheets with the same headers are one layout; the largest results layout comes first):

- The sheets of the layout with their row counts and a switch each, and what each sheet is: results, puzzles I own,
  **a puzzle list the other sheets point to**, not puzzles (statistics, goals, drills, lists), somebody else's.
- **What a row is** - a result, a puzzle I own, a puzzle on my wish list, or both a result and owned. A status
  column may decide it per row (`Completed` / `Waiting list` / `Wish list`).
- **Words that mean something.** For a column of words or tags the player can give a value a meaning from a short
  list: this is a pair result / a team result / solo, a first try, not mine any more (sold, returned, given away),
  borrowed. `#02-pair`, `#xSOLD`, `borrowed`, "Got rid of: yes" - one answer per distinct value, remembered with the
  layout.
- A yes/no column may decide it per row too ("Completed?").
- **What the whole sheet says:** "every row here is a pair result", "every row is this puzzle"; and **where the rest
  is**: "brand and pieces come from the sheet 'Speed Puzzles', by the puzzle's name".
- **The columns.** Those we are sure about are one line: "Recognised: Title, Brand, Pieces, Completed, Time -
  **Change**". Only the unsure ones are cards: the header, three sample values, and "This is": Puzzle name · Brand ·
  Pieces · Barcode (EAN) · Brand code · Solved on · Time · Solved with · Solo / pair / team · First try · Attempt
  number · Solved without the box · Already on MySpeedPuzzling · I own it / I don't · Public comment · Private notes ·
  Collection · one of my fields · a new field · Don't import. A column of formulas that MySpeedPuzzling works out
  itself (pieces per minute, place, percentages) is preset to "Don't import" and says why.
- The questions only this layout needs: day/month order when no value settles it; the year when the cells hide it and
  the sheet's name disagrees; what `17:36` means; which collection; the header row when we guessed it wrong.
- A live line: "Row 2 will be read as: Ravensburger · Cozy Cabin · 1000 pieces · solved 3 Jan 2025 · 1:09:17".
- **Remembered:** the layout is saved for the player (`import_layout`). The next file with the same headers is mapped
  by itself ("Recognised from your import on 3 November - **Review**"); a file with most of the headers gets the known
  ones and asks about the new ones.

**Right after the first layout:** "We found 734 of these 2,245 puzzles in the catalogue. **Look and add them now** /
Set up the other sheets first." The exact pairing takes under a second, so the first results can be on the profile
minutes after the upload.

### 3. Puzzles

The import's **distinct puzzles** in groups, each opening a list:

| Group | What the player does |
|---|---|
| **Found** - one catalogue puzzle, same name, pieces and brand | A list with pictures, all ticked. Untick the wrong ones. **Add all** |
| **Probably** - one catalogue puzzle, the brand differs or is missing | The same list, **not** ticked: "Tick all I see here" after a look |
| **Choose** - several candidates, or similar names | One puzzle per screen: the row's text on top, candidates as big picture buttons, "None of these" |
| **Not in the catalogue** | "Keep as unlisted puzzles" for all, or search for one |
| **To fix** - rows with something unclear | Grouped by distinct value: one answer for all rows with `8min`, with `MB`, with `13x99` |
| **Already yours** - on your profile or added by an earlier import | Nothing to do; shown folded with the count |

- **A row is added when its puzzle is decided and nothing about the row is unclear.** "Add all Found" adds the ready
  rows of the ticked puzzles; a row of a found puzzle with an unreadable time stays in "To fix" and the button says so
  ("1,790 rows now · 12 wait in To fix").
- **To fix is never in the way.** It can be opened first (one answer often fixes dozens of rows) or never.
- **In portions:** "Do the next 20" walks the open decisions one by one and stops after 20 with "20 done, 290 to go".
  Every tap is saved.
- A row can always be opened: its original cells, what we read, every field editable (the row sheet of My data).
- Decisions are remembered per player (`import_puzzle_decision`): the same puzzle in the next file, or in a newer
  version of this file, arrives decided.

### 4. Add

Before the first "Add" of an import, once: "Results appear on your profile and on each puzzle's leaderboard, like the
ones you type. Unlisted puzzles and your own fields are visible only to you."

"Add 1,790 results and 359 collection puzzles" → a live progress line (the work runs in the background, the page may
be closed; a notification and an e-mail say when it is done) → "Added. 2,173 puzzles still wait for you" + **Undo**.

### 5. Afterwards

- Unlisted puzzles sit in **Unlisted puzzles** (`/{_locale}/unlisted-puzzles`): find in the catalogue, or add to the
  catalogue with a photo. A quiet line on the player's own profile and the Hub: "212 unlisted puzzles - only you see
  them".
- The import stays on Imports home: continue, look for new rows, undo.

## Decisions

| # | Decision | Why |
|---|---|---|
| D1 | The import is rows in the database from the first second, not a session and not a file with state in the URL | Resumable for weeks, on another device; the participants import's "file + URL state, 24 h" does not stretch to 5,000 rows |
| D2 | Unlisted puzzles live in **their own tables**; `puzzle`, `puzzle_solving_time`, `collection_item` never hold one | 180 files read `puzzle`, 152 read `puzzle_solving_time`, no single place filters them, and the aggregates (`GetStatistics`, brand counts, insights, XP) would count them. A table nothing reads cannot leak. The price: owner-only pages that should show both read two places, and unlisted results are outside charts, streaks and rankings (Q2) |
| D3 | The unit of pairing is the **distinct puzzle** of an import, not the row; decisions are kept per player | One decision for a puzzle's to-do row and all its results, asked once ever |
| D4 | Paired rows become ordinary results / collection items through **one write path of the import, built beside the add form's - the add form's own handlers are not touched** (Jan, 2026-11-14: the add form is the most important part of the app and must never break). The import's writer uses the same building blocks (`PuzzlersGrouping`, `PuzzlingTeamResolver`, `FirstTryAssessor`, the entity's constructor) with its own rules for side effects; a parity test saves the same input through the form and through the import and compares the rows | The form's handlers stamp "tracked now", notify followers per result and recompute XP, badges and insights per result: 40-60 statements and several follow-up jobs per row. Extracting a shared writer out of the two handlers (the first plan) would have rewritten the most used write path of the site for the import's sake |
| D5 | `tracked_at` of an imported result = **noon of its solve day in the player's time zone** (`player.timezone`, else their country's zone, else UTC), plus the row's position in microseconds (an order without ever leaving the day); a month-only or year-only result takes noon of the period's first day. Every reader that takes `tracked_at` for "when it was logged" has a rule for imports ("Readers of `tracked_at`") | Monthly global statistics, the feeds, the digest and the moments read `tracked_at`; "now" would put ten years of puzzling into this week. Noon keeps the day in every zone and stays out of the night-time rules; the offset keeps an order |
| D6 | **Identity in three parts.** (1) `source_key` - what the row says, folded and parsed - answers "was this row added by an import before?". (2) At "Add", the import's rows of a puzzle are compared with **all** the player's results of that puzzle: equal ones pair off, what is left on the profile's side becomes one question per puzzle. (3) Rows that are equal inside one import are **one result** unless the player says "two" (Jan, 2026-11-14) - equal rows on different sheets are one result, and on one sheet differing attempt numbers keep a log of repeated solves apart. How many rows are added is settled when the batch is created, under a lock per player, so order and retries cannot change it | Row numbers, file names, raw cells and the mapping are never identity (Firefly III, BeanHub and Lunch Money failed exactly there). No derived ids: a new result gets a new UUIDv7 |
| D7 | A re-import **adds new rows, skips known ones, offers changes, never removes**; reading a file again makes a new **version** of the same import, and decisions carry over | What Linear, Letterboxd, Shopify and every CRM do; rows missing from a newer file may simply have been cut out |
| D8 | Every "Add" - and later every bulk change in My data - is a **batch** with an append-only log of what it created and changed (before / after); **Undo** removes what the batch created and puts back what it changed, unless the player changed it since | HubSpot, Zoho and Pipedrive only delete created records; keeping the before-value lets us do better |
| D9 | Reading, similar-name search, adding and undoing run in the background on **their own queue with their own consumer** (`imports`), in chunks driven by the rows' state; the page follows live over Mercure (polling as the fallback), and the end is told by a notification and an e-mail | The `async` queue carries sign-in e-mails; one fuzzy lookup costs 26 ms on production (50 s for 2,000 puzzles); a worker restart must lose nothing |
| D10 | **No AI, no fuzzy auto-pairing.** Only name + pieces + brand on exactly one puzzle (or a barcode on exactly one, or our own id) is offered ticked; everything else is a suggestion the player picks | A wrong pair puts a result on another puzzle's public leaderboard |
| D11 | Notes columns map to **private notes** (a custom field) by default; "Public comment" must be chosen | `puzzle_solving_time.comment` is shown to everybody |
| D12 | Co-puzzlers from a file are **guests** (names), never registered players | Adding a registered player puts the result on their profile; the existing consent flow (`GuestLinkRequest`) links a guest later |
| D13 | **Date precision is stored** (`puzzle_solving_time.finished_at_precision`: day / month / year; a result without any date needs the player's answer). Day-based rules skip imprecise results. Until that stage ships, only results with an exact day are added | A month stored as the 1st counts for Weekend Puzzler, streaks, the calendar, the duplicate check's "same day" and can become the "first" solve that sets a baseline. File 1 has ~700 month-only results, file 2 ~200 with no date |
| D14 | **The `.xlsx` reader is OpenSpout** (streaming), behind `SpreadsheetReader`, with a small pass of our own over the workbook's parts for what a streaming reader does not give (a cell's number format, Excel tables, frozen panes). Measured 2026-11-14 (research.md "Reader measurement") | On the real files OpenSpout needs 4 MB and 1.4-1.6 s; PhpSpreadsheet with styles 34 MB and 5-8 s, and for the largest allowed file (50,000 rows, a million cells) 554 MB even without styles - against 2 MB. `ParticipantFileReader` (PhpSpreadsheet, styles off) stays as it is for the participants import |
| D15 | First try is **off** for imported results unless a column says so (a first-try column, or attempt number 1); a first try somebody of the result already holds is a row problem with choices | First tries feed difficulty and skill; a guessed one is worse than none |
| D16 | Custom values live on the row they describe: a `jsonb` column on `collection_item` and the unlisted tables, a side table per (result, player) for results | A pair's result is one row of several players - each has their own values; no existing query selects the new columns, so they are private by construction |
| D17 | An unlisted puzzle becomes a catalogue puzzle **one at a time, through the normal add-puzzle path**, after a "Is it one of these?" step | "Not in the catalogue" only means "no puzzle of that exact name" - a third of them have a similar one. A new puzzle is public at once and lands in the approvals queue |
| D18 | The wishlist's "remove when solved / when added to my collection" is honoured (it is the player's own setting), done by the import's writer and written to the log so Undo puts the item back | Ignoring an opt-in would surprise; doing it behind the log's back could not be undone |

### Decided by Jan (2026-11-14)

| # | Question | Decision |
|---|---|---|
| Q2 | Do unlisted results count? | **Not until paired.** Shown and counted only for the owner ("1,480 results · 212 more on unlisted puzzles"); not in public numbers, charts, streaks, rankings, global statistics, XP or achievements. Paired, they are ordinary results |
| Q3 | Month-only, year-only and undated results | **Store the precision** (D13). Undated results: the player answers once per sheet - a year, or "collection only" |
| Q4 | XP and achievements for imported results | **As the code does for history:** results before the formula cutoff earn the core formula without bonuses, recalculated once per "Add". Night-time and time-of-day rules never count an imported result. Guard: at most 2,000 results added by import per player per day (the screen says so and offers the rest tomorrow) |
| Q5 | Two rows that are exactly the same | **One result unless the player says two.** Rows with different attempt numbers are not the same row (the third file has 13 real pairs of equal results in a log of attempts) |
| Q6 | A dedicated consumer for imports on lily | **Yes** - and more containers or tools where they clearly help |
| - | A model (Claude Haiku or Sonnet) as a helper | **Not at first.** Only if a really hard problem stays unsolved - see "A model as a helper" |

### Decided by Jan, second round (2026-11-14)

| # | Question | Decision |
|---|---|---|
| Q1 | Free or members? | **Everything free:** the import, custom fields (up to 20 per player), and My data including changes of many rows at once. Custom collections stay the members' feature they are - a free player's imported puzzles go to their collection, a member picks or creates one per sheet. Nothing new on the membership page |
| Q7 | The name | **Unlisted puzzles** - not in the catalogue yet, only you see them |
| Q8 | Limits | **25 MB, 100 sheets, 50,000 rows** per import (plus 60 columns, 1,000,000 cells, 3 unfinished imports per player). What that asks of the design: "Large imports" |
| Q9 | What is kept | The file 30 days; the rows' content as long as the import exists - the player can wipe it ("Forget the spreadsheet's content") and keep what was added; the row's key, state and links to what it created always stay, so "never twice" and Undo keep working. Everything goes with the account |
| Q10 | Who gets it first | **Everybody, from day one.** No admin-only or invited stage: we build and test in public, and because every change is in the log, wrong rows can be taken back. What replaces the staged rollout: "Opening to everybody at once" |
| - | Build order | Custom fields, then the import; My data's table follows the import's row editor |
| - | Progress and the end of background work | **Live on the page** (Mercure, polling as the fallback) **and an e-mail when it is finished**, with the outcome and a link - "Telling the player" |

## Data model

```
data_import            one file / one Google sheet of one player
  id, player_id, source (file | google_sheet), file_name, file_sha256, file_key (object storage, 30 days),
  google_spreadsheet_id, version, status (reading | setting_up | reviewing | done | discarded), read_error,
  created_at, last_activity_at, cells_wiped_at

data_import_sheet      a sheet (tab) of it
  id, import_id, position, title, google_gid, included,
  kind (results | owned | puzzle_list | not_puzzles | somebody_else), region (the table or range read), header_row,
  header_signature, row_meaning (result | owned | both | by_column), mapping jsonb,
  options jsonb (date order, year, sheet-wide values such as "pair", lookup_sheet_id), value_answers jsonb,
  rows_total, rows_left_out

data_import_puzzle     a distinct puzzle of the import - the unit of pairing (D3)
  id, import_id, puzzle_key, name, brand_text, manufacturer_id, pieces_count, eans, brand_codes, picture_url,
  tier (found | probably | choose | none), candidates jsonb [{puzzleId, how, score}], searched_at,
  choice (undecided | catalogue | unlisted | left_out), puzzle_id, unlisted_puzzle_id, decided_at,
  decided_how (player | remembered)

data_import_row        a row of a sheet. Its key, state and outcomes are kept for ever; cells and parsed can be wiped
  id, import_id, sheet_id, import_puzzle_id, row_number, source_row_id, first_seen_version, last_seen_version,
  cells jsonb (typed value + shown text per column), parsed jsonb, problems jsonb [{field, code, choices}],
  answers jsonb, kind (result | owned | both | not_a_puzzle), source_key, same_as_row_id, add_as_separate,
  state (waiting | ready | queued | added | already_yours | left_out | failed | undone), failure, batch_id, added_at

data_import_row_outcome   what a row made (a result, one item per collection, their unlisted twins)
  row_id, subject_type, subject_id, created (true = made by this row, false = found existing)

import_puzzle_decision    the player's remembered answers
  player_id, puzzle_key, choice, puzzle_id, unlisted_puzzle_id, decided_at          primary key (player_id, puzzle_key)

import_layout          a remembered layout of a player
  id, player_id, header_signature, headers jsonb, row_meaning, mapping jsonb, options jsonb, value_answers jsonb,
  uses, last_used_at        unique (player_id, header_signature)

data_change_batch      one "Add", one Undo, one bulk change in My data - the unit of Undo
  id, player_id, source (import | my_data | unlisted), import_id, started_at, finished_at,
  rows_asked, rows_done, rows_refused, state (running | finished | cancelled | undone), undone_by_batch_id

data_change            append-only log, no foreign keys (ids and names copied, like puzzle_moderation_decision)
  id, at, player_id, batch_id, import_row_id, action (created | updated | deleted | paired | listed | undone),
  subject_type, subject_id, before jsonb, after jsonb, by_player_id (null = the system), note

unlisted_puzzle        the player's own puzzle, nobody else's
  id, player_id, name, brand_text, manufacturer_id, pieces_count, eans, brand_codes, puzzle_key,
  created_at, created_by_import_id, dismissed_at ("fine as it is"),
  resolved_at, resolved_puzzle_id, resolved_how (paired | listed)                    unique (player_id, puzzle_key)
unlisted_solving_time  id, unlisted_puzzle_id, player_id, seconds_to_solve, finished_at, finished_at_precision,
                       puzzling_type, people jsonb (names), first_attempt, comment, custom_values jsonb, import_row_id
unlisted_collection_item  id, unlisted_puzzle_id, player_id, collection_id (null = the system one), comment, added_at,
                          custom_values jsonb, import_row_id

player_custom_field    id, player_id, name, standard_key (null = the player's own), type (text | number | date | yes_no | choice | tags | rating), choices jsonb,
                       for_results, for_collection_items, position, archived_at
                       (built in stage 1a; not `custom_field` - Doctrine ignores every table named `custom_*`)
solving_time_custom_values   id, solving_time_id, player_id, custom_values jsonb      unique (solving_time_id, player_id)
collection_item.custom_values jsonb
puzzle_solving_time.finished_at_precision   null = day (every existing row), month, year
```

**Keys, exactly.**

- `puzzle_key` = folded name (`SearchText::fold()`, a `(…)` part kept) + piece count + the brand: the catalogue brand's
  id when the text resolved to one, else the brand text reduced to letters and digits. No barcode, no raw text - adding
  an EAN column or fixing a brand's spelling must not make a second puzzle.
- `source_row_id` = the source's own id of the row, when the file has one (our export's `result_id`, another app's
  `ID`). Within one source it **is** the row's identity and wins over the key below: a renamed puzzle or a corrected
  time in a newer export is the same row, changed - not a new one.
- `source_key` = kind + `puzzle_key` + solve date at its precision + seconds (or "no time") + solo/pair/team, plus
  an occurrence counter for rows of one sheet that are equal in all of it. No attempt number (see "Never twice"), no
  comment, no people, no codes, no custom values.

Additions to existing code: `SolvingTimeSource::Import`, `SolvingTimeDeletedVia::ImportUndo`,
`collection_item.custom_values`, `puzzle_solving_time.finished_at_precision`. `data_import_puzzle.puzzle_id`,
`import_puzzle_decision.puzzle_id` and `unlisted_puzzle.resolved_puzzle_id` point at `puzzle`: a merge moves them to
the survivor (a line each in `PuzzleMergeForeignKeyCoverageTest`); ids inside `candidates` follow `puzzle_redirect`
when they are used.

`DeletePlayerHandler` gets explicit steps: the import tables, the log by player id, the player's custom values on
other people's results, the unlisted tables, the stored files (`DeletePlayerStoredFiles`).

## Reading a file

`SpreadsheetReader`: `.xlsx` and `.csv`/`.tsv`, every sheet, hidden sheets listed as hidden, rows hidden by a filter
read like any other, merged cells, **cached formula values only**, the cell's kind kept (text, number, date and how
exact its format shows it, duration, time of day, yes/no, error), hyperlinks as text. CSV: delimiter and encoding
detection as today (BOM, UTF-8, Windows-1250/1252, `sep=`). A leading `'` written by our own export
(`SpreadsheetSafeValue`) is taken off again.

Our own export is read in all four formats (`.xlsx`, `.json`, `.xml`, the ZIP of CSVs) and needs no mapping: its
headers are a built-in layout, `puzzle_id` pairs exactly, `result_id` / `collection_item_id` that exist are "already
yours". Other XML or JSON is not read - "save it as Excel or CSV".

**Exports of other apps** are built-in layouts too (`KnownExportLayout`: the header set that identifies it, the
mapping, the words with a meaning, the id column). The first is Puzzle Tracker's CSV; each further app is one more
entry, added when a player sends us its export. The import page says so: "Coming from another app? Upload its
export - we know Puzzle Tracker's."

The file goes **straight to object storage** by a presigned upload: 25 MB from a phone on a slow connection must not
hold a web worker or run into the proxy's 60 seconds. The presigner and the bucket's rules of the photo uploads are
reused; the rest is the import's own - a start endpoint with its own limits, the content type chosen by the server
(browsers send none or a wrong one for `.csv` and `.xlsx`), a confirmation that checks the object arrived before
reading is queued, a state `uploading` and a prune for uploads that never finished. The plain form is the fallback
for small files only. The file's hash is known once the worker has read it, so "the same file again" is answered
when reading starts, not at the upload.

Limits before anything is parsed: file size, uncompressed size and ratio of the zip, parts per archive; then sheets,
rows, columns, cells (Q8). Over a limit = a clear message, never a 500. Pictures inside a workbook are ignored (a
public template is 17 MB, 16 MB of it photos).

Google Sheets: the id is cut out of the pasted link (`^[A-Za-z0-9_-]{20,100}$`), the URL (`…/export?format=xlsx`) is
built by us, redirects are followed only to `*.googleusercontent.com`, the answer must be a zip (`PK`) within the size
limit and 30 s. A sheet that is not shared answers with a sign-in page - "Share it with 'Anyone with the link' and try
again", with the three taps drawn. "Look for new rows" is a button, never a cron.

The file is stored privately under `tmp-imports/player-<id>/<import>` and never served. Parsed rows go into
`data_import_row.cells` in batches, so every later step reads the database, not the file.

**Reading again** (the same Google sheet, or a file with the same bytes as before / chosen as "a newer version of
this import") raises `data_import.version`: rows are matched to the earlier ones by `source_key`; matched rows keep
their state, decisions and outcomes; new rows wait; rows no longer in the file keep everything and are counted as
"not in the file any more".

## Recognising columns and values

No model, two signals per column, combined into a certainty:

1. **The header** against a synonym list per field in the six locales (name / title / puzzle / název / Titel / nombre /
   titre / タイトル …). The list is ours, grown from real files; later every confirmed mapping feeds it (header → field,
   counted, without the player).
2. **The values.** Every field has a reader that says how many of the column's values it can read: pieces (whole numbers
   in a plausible range, `1,000` allowed), barcode (8/12/13/14 digits, check digit; `4.00556E+12` recognised as a
   damaged barcode and reported), duration, date, yes/no, brand (share of values that are catalogue brands), name
   (text that is none of the others, longest).

**Where the data is.** An Excel table on the sheet says it exactly (header row and range); without one, a frozen pane
is the hint, and without that the header is the first row whose values do not fit the types of the rows under it. The
player can move it. Several tables on one sheet are several regions - the largest is offered, the others listed. A
column without a header is offered as "Column N"; a column that is empty in every row is not shown.

**Which rows are puzzles.** A row is a puzzle row when it has a name and at least one of brand, pieces, barcode - its
own, or looked up in the sheet the player pointed to. A row with brand and pieces but no name is a problem ("no
name"). A row holding only default values (unticked checkboxes), and a results row without date and time (a template
row), are empty. Everything else is listed under "Left out - not puzzles" and can be put back.

**Which sheets are puzzles.** A sheet needs a puzzle name column (or one puzzle for the whole sheet) to be offered as
results or as owned puzzles: a drill log with dates and times but no puzzle, a statistics table, a list of brands are
"not puzzles". A sheet whose rows other sheets refer to by name is offered as the **puzzle list**: its rows fill the
brand and pieces of results that lack them, and may be imported as owned puzzles too.

**Durations** - certainty per value:

| Level | Example | Handling |
|---|---|---|
| certain | a duration cell under 24 h; `1:09:17`; `0:55:41`; `0:56:4` (parts without leading zeros, as some apps export) | taken |
| read from text | `8min`, `1h 23m`, `25 min`, `1.5 h` | taken, shown once in "To fix" as read |
| read with leftovers | `0:55:41 (slower than…)`, `1:40ish` | time proposed, the rest goes to the private notes, needs a yes |
| two readings | `17:36`, a time-of-day cell `1:02`, a duration cell of 24 h or more, a duration with `:00` seconds in a column that has seconds (`45:30` typed, stored as 45 h 30 min) | one question per column and shape, helped by the piece count |
| unreadable | `1:13:??`, a list of several times, words | a problem: fix, add without time, leave out |
| unusual | too fast (the add form's limit) or far outside what the time verification accepts for that puzzle, fast **or slow** | a problem that needs the player's yes, see "Time verification" |

**Dates:** an Excel date is exact unless its format shows only month and year (then month precision) - and when the
format hides the year and the sheet's name is another year, we ask. A date in the future is a problem to fix. Text dates start with every order allowed and lose
the orders a value contradicts (a 13 in the first place settles it); still open = one question. No year anywhere = the
sheet's name if it is a year, else the question (Q3).

**Pieces:** `13x99` / `500*4` is a problem with choices - a puzzle of 99 pieces (the count goes to the notes), fix by
hand, leave out. One row never becomes several results.

**Brands:** the existing rule (`ManufacturerResolver`'s trimmed, case-folded name), then letters and digits only
(`Master Pieces` = `MasterPieces`), then the player's remembered answers; the rest is asked once per distinct text. A
brand answer is the player's own alias - the import never creates or renames a catalogue brand.

## Pairing with the catalogue

Per distinct puzzle (D3), in the background, cheapest first:

| Step | Rule | Offered as |
|---|---|---|
| Remembered | the player decided this `puzzle_key` before | as decided, ticked |
| Our own id | the file has `puzzle_id` (our export) and the puzzle exists (following `puzzle_redirect`) | Found |
| The player's own result | a result on the player's profile with the same day **and** time as a row, on a puzzle of the same piece count | Found - "you logged this one as *Circle of Colours: Ocean*" - **only when all of this holds:** the row has an exact day and a time with real seconds (not `45:00`, not `8min`), the same solo/pair/team, exactly one result fits the row and exactly one row fits the result, every such row of the puzzle points to the same catalogue puzzle, and no name or barcode match says otherwise. Anything less is "Probably", never ticked for the player |
| Barcode | a valid barcode on exactly one catalogue puzzle (`PuzzleTextSearch::barcodeCondition`, hidden puzzles left out) whose piece count agrees (a count 1 apart counts as agreeing - a box says 501, the catalogue 500; anything else is "Probably") | Found |
| Barcode, several | a multipack code | Choose |
| Name + pieces + brand | a folded name (also without a `(…)` part) equal to a line of `search_names`, the same `pieces_count`, the resolved brand - one puzzle | Found |
| Name + pieces | as above without the brand - one puzzle | Probably (never ticked for the player) |
| Several of the above | | Choose |
| Brand code | a `c:` line of `search_codes` and the same brand | Probably |
| Similar name | trigram similarity ≥ 0.6 with the same piece count (the same brand first), best three | Choose |
| Nothing | | Not in the catalogue |

The exact steps are one statement for the whole import (0.24 s for 4,764 rows on production), run as soon as a layout
is confirmed. The similar-name step is a `LATERAL` lookup per puzzle over `custom_puzzle_name_trgm` (26 ms each on
production), in chunks of 100 on the `imports` queue while the player already works on "Found". Every statement of the
import gets an `EXPLAIN` on production before it ships (a first draft of the exact statement ran into a 45 s timeout
because of one `OR`).

Among several candidates, a puzzle the player already solved or owns comes first.

The decision is per puzzle and remembered, so a wrong one travels: taking back results that "landed on the wrong
puzzle" also clears the decision for that puzzle (`import_puzzle_decision`) and puts it back into "Choose".

Rules that never bend: a secret or hidden puzzle is never a candidate; a candidate with another piece count is shown
with the difference in red and can only be chosen one by one; a puzzle the player picks by searching passes the same
checks.

"Search for it" inside a row uses the catalogue search (`SearchPuzzle::byUserInput` behind a small JSON endpoint),
prefilled with the row's name.

When the catalogue grows: a weekly job tries the exact steps again for every open unlisted puzzle and tells the player
once ("3 of your unlisted puzzles are in the catalogue now").

## Unlisted puzzles

- **What it is:** name, brand (a catalogue brand or text), pieces, codes - no picture. Its results and its place in the
  player's collections hang on it. One per player and `puzzle_key`, so the same puzzle from two sheets or two imports
  is one unlisted puzzle; two spellings of one puzzle can be joined by the player ("These are the same").
- **Who sees it:** the owner, on pages only they can open: the Unlisted puzzles list, an owner-only block on their
  profile's results and on their collection pages, My data, their export (new sections). Nobody else, nowhere.
- **Never twice here too:** an unlisted result equal to one the unlisted puzzle already has (day + time + type) is
  "already yours".
- **Becoming a regular puzzle**, one at a time (D17):
  - **Find in the catalogue** - "Is it one of these?" (same brand and pieces, similar names) and the search. Choosing a
    puzzle moves the unlisted puzzle's results and collection items onto it through the import's writer - the same
    checks, the same "never twice" rule, one batch in the log, undoable (Undo makes it unlisted again).
  - **Add to the catalogue** - only after "Is it one of these?" was answered "none". When the export carried a
    picture of the puzzle, "Use the picture from my export" fetches it on that tap (only from the hosts the known
    layout names, size and type checked, stored like an uploaded photo); it is still the box-photo rule that decides
    and the moderators who approve. Then the normal add-puzzle form,
    prefilled: a photo of the box is required (`PuzzleBoxPhoto`), the barcode optional, the brand chosen as in that
    form; `AddPuzzle` creates the puzzle (unapproved, into the approvals queue like any new puzzle), then the same
    move. At most 10 a day per player. A puzzle added to the catalogue is not taken back by Undo.
  - **Fine as it is** - stays unlisted, leaves the to-do count.
- **The reminder:** one more count in `GetPlayerReviewCounts` → a line in the existing banner on the player's own
  profile and the Hub, dismissable (`HintType::UnlistedPuzzles`).

## Adding rows

**The work.** "Add" creates a `data_change_batch`, settles which rows are added (below), marks them `queued` and puts
one message on the `imports` queue. `ContinueImportBatch` (handled outside a transaction, holding the lock
`import:<playerId>`) takes the batch's next 25 `queued` rows and hands each to `ApplyImportRow` - a message handled at
once in its own transaction (the pattern of `CheckOnlineFinishPhoto` → `RecordOnlineMediaCheck`). A row that throws is
marked `failed` with the reason (and the entity manager cleared); it is never retried by itself and shows up in "To
fix". While rows are left, the message dispatches itself again; then `FinishImportBatch`. Everything is driven by the
rows' state, so a restarted worker, a retry or a second delivery repeats nothing. A sweeper (every 5 minutes) continues
batches that stand still.

**The import's writer** (`ImportedResultWriter`) stands beside the add form's handlers and does not change them: it
builds the result with the same building blocks - the group (`PuzzlersGrouping`, `PuzzlingTeamResolver`), the
first-try rule (`FirstTryAssessor`), the pace limit, the entity's own constructor. `ImportWritePathParityTest` saves
the same input through the add form's handler and through the import and compares the two rows field by field, apart
from the listed differences - a change of the form's rules that the import does not follow turns it red.

**What an imported result does.** The entity records `PuzzleSolved` in its constructor. The five handlers of that
event run on every result anybody adds, so the import does not make them branch: its writer takes the event off the
entity before the flush and does itself what an imported result needs. (Only if the event cannot be taken off does
each handler get one early return for `created_via`, each under its own test.)

| | Typed result | Imported result |
|---|---|---|
| `tracked_at` | now | noon of the solve day (D5) |
| `created_via` | form / stopwatch / api | `import` |
| `NotifyWhenPuzzleSolved` (followers) | one each | none |
| `RemoveFromWishListWhenPuzzleSolved` | yes | not the handler - the writer does it and logs it (D18) |
| `DetectDuplicateResultsOnSave` | on save | not run - the import decided before (D6) |
| `RecalculatePuzzleStatisticsOnSolvingTimeChange` | per result | once per puzzle touched, in `FinishImportBatch` |
| Incremental insights | per result | the 15-minute batch |
| First try | as ticked | off unless mapped (D15) |
| Prediction | live for a fresh solve, else one backfill job per result | always reconstructed, one backfill job per player per batch |
| XP, achievements | one job per result | once per player per batch (`XpRecomputer::recomputeForPlayer`, `BadgeEvaluator::recalculateForPlayer`) |

**Readers of `tracked_at`** - each with its rule for `created_via = import`:

| Reader | Takes `tracked_at` as | Import |
|---|---|---|
| `GetStatistics` (global monthly numbers), digest, `PlayerMomentDetector`, feeds | when it happened | right by D5: an old solve counts in its own month and is not "recent" |
| Night Owl (`AchievementSqlRules::IS_NIGHT_OWL_RESULT`), hour-of-day chart | the hour it was logged | excluded - an import has no hour |
| `SolvingTimePredictor` "solved recently" | fresh entry → live prediction | the writer asks for the reconstruction |
| Duplicate detection, tier "identical, saved within 10 s" | a double tap | two imported results the player confirmed as two get a `ResultDuplicatePrevention` row, like the form's "yes, both" |
| `XpFormulaCutoff::isHistory` | before the cutoff → core formula, no bonuses | applies as written (Q4) |
| The 10-second twin check of the form | a double submit | not used by the import |

**Collections.** The import has its own writer for collection items (`AddPuzzleToCollection` overwrites a comment,
makes its own id and returns nothing): it creates the item with an id the log can name, never touches a comment that is
there, checks that a custom collection is the player's own and that they may use it (a free player's rows go to their
collection; a member picks or creates one per sheet, and may map yes/no columns to collections - one row, several
items), and treats an item that exists as "already yours". The system collection has no uniqueness in the database
(46 players' puzzles sit in it twice on production), so the check runs under the import's lock. A free player keeps the
sheet's name as a value of a custom field ("Pile: To Do").

**Refusals.** A first try already held, a puzzle merged meanwhile (the row follows `puzzle_redirect`), a puzzle
deleted, a collection gone: the row goes back to "To fix" with the reason. Nothing else of the batch is affected.

## Never twice

Settled per puzzle when the batch is created (in the background, under the lock of the player, one running batch per
player), over the rows being added **and** every row of the player that is queued or added by any import:

1. **What the player already has** = their results of that puzzle, as tracker or as a registered member of a pair or
   team, plus rows of other imports that are queued. Whether a row is "already yours" is always worked out from what
   exists now - a result that was undone or deleted since is not there any more.
2. **Pairing off, the exact ones first.** Rows and existing results are matched one to one as two piles: first rows
   with an exact day (puzzle, day, time, solo/pair/team), then month-only rows (the same month), then year-only rows,
   then rows without a time and without an exact day (any result of that puzzle not yet matched). A coarser row that
   fits a finer, equal row of the same import is the same result as that row, not a second one. Matched rows are
   "already yours".
3. **Equal rows that are left.** Rows equal in puzzle, day, time and type: on **different sheets** they are one result
   (said in the summary: "144 results are on two sheets - counted once", with a way to say otherwise); on **one sheet**
   they are separate when both carry an attempt number and the numbers differ, otherwise the player is asked "one
   result or two?" (one by default).
4. **Close ones are asked, once per puzzle.** A leftover row and a leftover result of the same puzzle with the same
   time on any other date, or on the same day with another time, are shown together: "Your profile has 14 Sep 2020 ·
   1:02:03. The file has Sept 2020 · 1:02:03. The same one?" - yes (nothing added; the file's values offered as a
   change), no (added), leave out.
5. Rows still left are new.

The attempt number is **not** part of a row's key - the same result written with it on one sheet and without it on
another must stay one result. It only tells two otherwise equal rows of one sheet apart.

A column mapped to "Already on MySpeedPuzzling" is a hint, not a rule: a row that says yes and finds no partner is
listed ("the file says it is on MySpeedPuzzling - we did not find it") instead of being added quietly.

A pair's or team's row whose puzzle already has **somebody else's** result with the same day and time is a problem,
not a new result: two partners importing the same evening would otherwise leave two public results of one solve
("Ana already logged this one with a guest - ask her to link you, or add it anyway").

| Case | What happens |
|---|---|
| A puzzle solved 13 times on one day, 5 of them typed into MySpeedPuzzling before | 5 pair off, 8 are new, nothing is asked |
| The same result on the 500-piece sheet (attempt 3) and on the competition sheet (no attempt) | One result |
| The profile has two equal results, the file the same two rows | Both pair off, nothing asked |
| `Sept 2020 · 1:02:03` on one sheet, `14 Sep 2020 · 1:02:03` on another, the profile has 14 Sep | The exact row pairs off; the month row is the same result |
| The same file again (same bytes / same Google sheet) | "You imported this on …" - continue, or look for new rows |
| Read again, nothing changed | "0 new rows" |
| Read again, 30 rows added to the file | 30 rows waiting, the rest carried over |
| The same data in another file, renamed, re-saved, rows reordered, another column mapping | The `source_key`s are the same - "Already yours" |
| Two imports of overlapping files, both waiting | The second batch sees the first one's queued rows |
| A result an earlier import added and the player then undid | Not "already yours" any more - it can be added |
| A result the player typed by hand before | Equal puzzle + day (within the row's precision) + time + type - "Already on your profile". People are not compared: a guest "Ben" and the registered Ben are the same solve |
| A hand-typed result dated on the day it was entered, the file has the real date | Same puzzle and time on another date - asked (step 4) |
| A row "solved, 2021" without a time, the profile has a timed result of that puzzle | Paired off (step 2, last pile) |
| Another answer to a layout question than last time (day/month order, the year) | The keys differ, step 4 catches the result as a question |
| A comment or custom value changed in the file | Offered as a change; applied only where the player did not change the value on MySpeedPuzzling since (the row's stored `parsed` is the base) |
| An empty cell where MySpeedPuzzling has a value | Never erases |
| A row gone from the file | Nothing - counted in the summary only |
| An imported result the player deleted, file read again | Stays deleted ("you removed this one") unless the player asks for it back |
| An unlisted puzzle whose row gains a barcode or a corrected brand | The same `puzzle_key` - the unlisted puzzle is updated, not doubled |
| Two taps on "Add", two tabs, a retried or re-delivered job | One batch owns a row (`queued` → `added` under the lock); the second finds nothing to do |
| The import's rows wiped by the player | Keys, states and outcomes stay - everything above still holds. Only a discarded import that never added anything is deleted whole |

## Time verification

A new player has no times of their own, so the scan would judge a whole imported history by the community's scale and
could open hundreds of cases for moderators and e-mail the player about each batch. So:

- The import judges every row with the scan's own classifier **before** "Add". An unusual time is a row problem:
  correct it, say it is right, add without a time, leave out. One answer may cover all rows of the same shape.
- "It is right" is stored the way the form's answer is (`SuspiciousTimeConfirmation`), so the scan does not ask again.
- The scan's rules for imported results (what a confirmed import result may still open, a cap per player and run) are
  settled with `docs/features/suspicious-time-review.md` open, before stage 6b ships; a dry run on the sandbox after a
  2,000-row import is part of that stage's exit.

## Audit and Undo

`data_change` records every create, update and delete of a batch, with the values before and after and the row. It is
written in the same transaction as the change and never updated.

**Undo** of a finished batch (the player on their imports page or in My data; an admin on `/admin/imports` for
anybody) is itself a batch on the `imports` queue, in chunks: results the batch created are deleted through
`PuzzleSolvingTimeRepository::delete($time, SolvingTimeDeletedVia::ImportUndo)` (graveyard + tombstones as always;
re-adding later makes a new id, so no tombstone names a living result), collection items and unlisted puzzles it
created are removed, wishlist items it removed come back, values it changed go back to "before" - each only if the
subject still is what the batch left. What the player changed since is kept and listed ("12 results were edited after
the import - kept"). The rows return to "ready". XP, achievements and statistics are recalculated once at the end.
A batch that is still running is cancelled first (its remaining rows go back to "ready"), then undone.

Not taken back, and said so: a catalogue puzzle added from an unlisted one; a custom field or collection the import
created (left in place, offered for deletion when empty); empty pair/team rows (the existing cleanup removes them).

Admins see a player's imports, batches and log, and can undo.

## A model as a helper (not at first)

Jan, 2026-11-14: a cheap model may be considered **only if it solves a really hard problem**; the first version has
none. Where one could earn its place, judged by what the three files showed:

| Problem | Why rules run out | What a model would do |
|---|---|---|
| A puzzle named differently than in the catalogue (a fifth to a half of "no exact name") | Trigram similarity cannot tell *"Cubby Cats and Succulents"* from a translation, an abbreviation or a description in brackets | Rank the catalogue candidates of the same brand and pieces for a row, or say "none" |
| Headers and layouts we never saw (any language, any habit) | The synonym list grows only from files we met | Propose a field per column from the header and five sample values |
| Free text in a value (`1:40ish, with Ana`, `18hr36`) | Each new shape needs a new rule | Read the time and split off the rest |

The rules it would live by: a **suggestion** only - the player confirms exactly as they confirm ours, and nothing a
model says is ticked for them; it sees only what the task needs (headers, sample values, puzzle names - never notes or
private columns) and the player is told before anything is sent; the import works without it. Cost is not the
obstacle: at Claude Haiku's list price of November 2026 ($0.10 per million input tokens, $0.50 per million output),
ranking candidates for 2,000 puzzles is a few cents - to be checked again if it is ever built.

How we will know: the import records where the rules ended - columns nobody could place, values nobody could read,
"None of these" after a similar-name suggestion, unlisted puzzles later found by hand. If one of these stays large
after the first real imports, that is the hard problem.

## Custom fields

The player's own columns: text, number, date, yes/no, one of a list, **tags** (several of a list), a **rating** of
1-5. For results, for
collection items, or both. Private: only the owner ever reads them.

- **Manage** at `/{_locale}/custom-fields` (a card on the edit-profile page links there): add, rename, reorder, type,
  archive (values kept, field hidden).
- **Add a result:** a "My fields" toggle after the event section opens the player's result fields - only when they
  have any. The add form is protected (principle 9): its messages and handlers are unchanged; the values are saved
  by a separate step **after** the result is saved, and a failure there is logged and told ("Your result is saved.
  Your own fields could not be saved") - never an error; an invalid value is left out, never a refusal; loading the
  fields fails open (the form renders as without fields); for a player without fields the form is exactly what it
  was; `CUSTOM_FIELDS_IN_ADD_FORM` switches the block off.
- **Edit:** the values have their **own** small form and message (`SetSolvingTimeCustomValues`), never the result's
  edit: editing a pair's result tells the whole team and recalculates everybody's achievements, and a private field
  is nobody else's business. The collection item's fields sit in its edit form (an item has one owner).
- **Never in a shared read:** the values are read by one query for the signed-in player only
  (`GetCustomValues::forViewer`), never joined into the result lists, the result detail's query or the API.
- **Shown** to the owner on the result detail, in My data (as columns), in the export (extra columns in the
  spreadsheet formats, a nested object in JSON, `<custom name="…">` in XML).
- **Import:** a column mapped to a new custom field creates it at "Add" (not before - an abandoned import leaves
  nothing behind).
- A pair's or team's result: each registered member has their own values (D16).
- Guard: `CustomValuesCoverageTest` - `custom_values` may appear only in the listed owner-only readers and writers.

### Ready-made fields

What the three spreadsheets track again and again becomes a set of **ready-made fields** (Jan, 2026-11-14: "if there
was useful info in the spreadsheets, consider adding it to the data"). A ready-made field is an ordinary custom field
with a fixed key and type (`custom_field.standard_key`): one tap adds it on the custom fields page, the import
recognises its column by header words in six languages, its name is shown in the player's language, and it counts
towards the 20. Because the key and the type are the same for everybody, a later feature can read it without asking
anyone to re-enter anything.

| Field | For | Type | Seen in | What it can feed later |
|---|---|---|---|---|
| Private notes | results, collection items | text | all three files | - (the comment of a result is public; this is the private one) |
| What to work on | results | text | file 3 | a training log |
| Condition | collection items | new / like new / used / damaged (the sell/swap list's `PuzzleCondition`) | files 1, 3 | prefilled sell/swap listings |
| Missing pieces | collection items | number | files 1, 3 (in notes) | listings, "complete puzzles only" |
| Bought on | collection items | date | file 1 | time on the shelf before the first solve |
| Bought from | collection items | text | file 1 | - |
| Price paid | collection items | number | file 1 | what the collection cost |
| Where it is | collection items | text | files 2, 3 | - |
| Copies | collection items | number | file 3 | - |
| Artist | collection items | text | files 1, 2 | a catalogue attribute, once enough players agree per puzzle |
| Year of release | collection items | number | file 1 | the same |
| Material | collection items | cardboard / wood / plastic | files 2, 1 (in notes) | the same |
| My tags | results, collection items | tags | files 1, 3, 4 | personal filters; a check of our picture tags |
| How I liked it | results, collection items | rating 1-5 | file 4 | players' ratings of a puzzle |
| How hard it felt | results | rating 1-5 | file 4 | felt difficulty against the measured one |
| Quality | collection items | rating 1-5 | file 4 | players' ratings of a brand's cut and print |
| Started on | results | date | file 4 | solves that took several days |
| Sold for | collection items | number | file 4 | - |

Artist, year and material are facts about the puzzle, not about one player's copy. They are stored privately like
every custom value; nothing is copied into the shared catalogue from an import. What the import gives us is the
**count**: how many players say the same artist for the same puzzle - the evidence for a catalogue attribute and for
change proposals later.

## My data

A table of the player's own results, collection items and unlisted puzzles - for looking, fixing and bulk changes.
Owner only, `/{_locale}/my-data` (tabs Results · Collections · Unlisted), opened from the profile's results and from
collection pages by a table icon ("Open as a table"). A page of its own, not a second mode of the public profile list:
that list is a public Live component with filters and privacy rules; the table has other columns (custom fields,
private notes), other loading (all rows as JSON once) and editing.

- **Phone (below 768 px):** each row is a line - picture, name, and **one chosen column** beside it. Chips above the
  list switch the column (Time · Date · First try · Notes · every custom field). Tap a value to edit it with the right
  keyboard; **Next** jumps to the same column of the next row - filling a column the way people do it in a
  spreadsheet. Tapping the row opens the row sheet with every field. This list is new UI and the largest unknown of
  the whole plan: it gets a clickable prototype on real phones before stage 2 is cut into pull requests.
- **Wider:** a grid (`SheetGrid`, `grid_keys`, `tsv` of the participants sheet - arrows, type to edit, copy and paste
  of a block).
- **Saving one row:** field-level changes with the value the editor showed when it opened (`from`), through the
  existing messages (`EditPuzzleSolvingTime`, the collection item's), a refusal shown on the row - the participants
  sheet's protocol.
- **Many at once** (tick rows → set a field for all, move to a collection, delete): a `data_change_batch` with its
  log and Undo, so it ships after the batch and the log exist. Free for everybody, like the rest of My data.
- Sort, search, column chooser, and "Download what I see".
- Rows load once as JSON (the largest account has 2,456 results; the participants sheet renders 1,000 rows in 60-90 ms).

The import's row sheet and "To fix" list are the same components.

## Guards for shared data

| Risk | Guard |
|---|---|
| An unlisted puzzle shows up for somebody else | Own tables (D2). `UnlistedPuzzleCoverageTest`: the table names appear only in listed owner-only classes. `UnlistedPuzzleCanaryTest`: a player with unlisted puzzles, every public surface rendered for a guest and another player - none of the names |
| A result lands on the wrong puzzle's leaderboard | Only name + pieces + brand is ticked for the player; another piece count one by one; pictures in every list; the time check per row |
| An invented date bends shared numbers | Precision stored (D13); day-based rules (streaks, weekend, calendar, duplicate "same day") and "which solve was first" skip imprecise results |
| Mass-imported invented results | The same checks as typed results, the time check, `created_via = import` on every result, the daily cap (Q4), history XP only, admin Undo per batch |
| Statistics of the import month inflated, feeds flooded | `tracked_at` = the solve day (D5); no follower notifications |
| The catalogue filled with duplicates and the approvals queue flooded | One at a time through the add form, after "Is it one of these?", 10 a day (D17) |
| Sign-in e-mails waiting behind an import | Its own queue and consumer (D9) |
| Private notes made public | Private by default (D11); "Public comment" says so; a notice before the first "Add" |
| A custom value shown to somebody else | Nothing existing reads the columns; `CustomValuesCoverageTest` |
| The add form breaks | Its handlers are not changed by any stage; what touches the form is additive, fails open, is covered by the form's untouched existing tests, tried in a real browser on the branch **before** merging, and has a switch |
| Two write paths for results drift apart | `ImportWritePathParityTest` saves the same input through the form's handler and through the import and compares the rows, apart from the listed differences |
| A parser bug turns a file into a 500 or eats memory | Limits first, the worker not the request, its own memory limit, every reader failure is a message on the import |

## Performance

| Step | Budget | How |
|---|---|---|
| Upload | the start < 1 s; the bytes go to object storage | create the import, sign the upload, queue the read when the object arrived |
| Reading 10 sheets / 5,000 rows | < 15 s in all | measured at the start of stage 3 on a 25 MB, million-cell fixture too; decides the reader (D14) |
| Reading the largest allowed file (50,000 rows) | < 3 min in all, < 64 MB at any moment, one job per sheet ≤ 60 s | OpenSpout: 24 s and 2 MB for a million cells (measured), rows written in batches of 500 |
| Exact pairing, 5,000 rows | < 1 s | one statement |
| Similar-name search | ~26 ms per puzzle, in the background | chunks of 100, results appear as they come |
| A page of the review | ≤ 6 statements, 50 rows | rows by `(import_id, state)`, puzzles by `(import_id, tier, choice)` |
| Settling a batch ("never twice") | one statement per batch | the batch's rows joined to the player's results of the same puzzles |
| Adding a row | ≤ 15 statements | per-puzzle and per-player work once per batch |
| Adding 1,000 rows | < 1 min in the background | the page shows progress, may be closed |
| Undo of 1,000 results | background, chunks of 50 | each delete runs the existing delete handlers; measured in stage 6 |

Indexes: `data_import_row (import_id, state)`, `(player_id, source_key)`; `data_import_puzzle (import_id, tier, choice)`;
`unlisted_puzzle (player_id) where resolved_at is null`. No new index on `puzzle` or `puzzle_solving_time`.

The `imports` consumer handles one message at a time, and **no message works longer than about ten seconds** (reading
one very large sheet and fetching a Google sheet excepted) - a large import is many short jobs, so other players' imports take their turns in between instead of waiting behind it.

## Large imports

The limits Jan chose (25 MB, 100 sheets, 50,000 rows) are ten times the largest real file. They hold only if nothing
in the import works on a whole file at once:

- **Reading streams.** One row in memory at a time; pictures inside a workbook are skipped, never unpacked. This
  settles D14 in practice: a reader that loads a workbook whole cannot be the one. A streaming reader gives values
  but not what the design also needs - the number format of a cell (month-only dates, hidden years, durations), Excel
  tables, frozen panes - so a small pass of our own reads those from the workbook's parts (`styles.xml`, the tables,
  each sheet's pane) first.
- **The file is opened once.** The first job turns the workbook into rows in the database (or a line-per-row file
  next to it); later jobs never download and unpack the workbook again.
- **Every background job is short** - about ten seconds - with two measured exceptions: fetching a Google sheet
  (30 s allowed) and reading one sheet, which a streaming reader can only do from its start (a million-cell sheet
  takes 24 s to read plus the writes; 60 s allowed, progress published as it goes). Reading is a job per sheet; the similar-name search a job per
  100 puzzles (50,000 puzzles are about 22 minutes of lookups, spread out); adding a job per 25 rows. A job that would
  run longer is cut smaller.
- **Screens never load an import.** Lists are pages of 50 from the database; counts come from indexed states.
- **Storage:** 1-3 KB a row (typed value and shown text per column) - a 50,000-row import is 50-150 MB in
  `data_import_row` until its content is wiped.
  A daily job reports imports over 20 MB and the table's size, so growth is seen before it is a problem.
- **Second consumer:** if the queue's waiting time grows once people use it, a second `imports` consumer is one line
  in the compose file - the per-player lock already keeps one player's jobs in order.
- The daily guard of 2,000 imported results per player stays (Q4): a 5,000-result history takes three days, and the
  screen says so.

## Opening to everybody at once

There is no admin-only or invited stage (Q10). What carries the risk instead:

- **Undo is there from the first "Add".** The log, the player's Undo and the admins' Undo ship in the same release as
  the first write - never after it.
- **Undo for rows, not only for batches.** An admin (and the player) can take back one batch, chosen rows of it, or
  everything an import added - "these 40 results landed on the wrong puzzle" must not cost the other 1,700.
- **A switch that pauses adding**, kept in the database and flipped on `/admin/imports` (an environment variable
  would need every container restarted): it stops every "Add" - running batches finish their current chunk and
  wait, the sweeper respects it - while reading, mapping and pairing stay open. A safety switch, not a rollout flag.
- **Admins see it happen:** `/admin/imports` lists imports and batches as they run, with failed rows, refused rows and
  the time verification cases they opened; a warning (→ Sentry) for a batch with more than a tenth of its rows failed
  and for a job over its time budget; the queue's waiting time in the metrics.
- **Before the stage that writes is merged**, it is rehearsed by us with the three real files' shapes - that is
  testing, not a stage of the rollout.
- **Each stage is public when it is merged.** Until adding exists, the import says so plainly: "You can already see
  how MySpeedPuzzling reads your spreadsheet and choose your puzzles - adding them arrives soon. Everything you set up
  is kept." The layouts people upload in those weeks are the test material an invited group would have given.
- What cannot be taken back gets tighter limits: a catalogue puzzle made from an unlisted one (10 a day, one at a
  time, the normal approval queue).

## Telling the player

- **Live on the page.** Every background step publishes its progress to the player's own private Mercure topic
  (one more topic in `MercureTopicCollector::getAllTopicsForPlayer()` - a topic per import would be dropped whenever
  another request rewrites the subscription cookie), from the worker, after the write is committed: rows read, puzzles
  searched, rows added, the step finished. The page updates its progress line and its group counts without a reload.
  If the stream cannot be opened or drops, the page falls back to asking every few seconds - the progress is always in
  the database, the stream only brings it sooner.
- **A notification** in the bell when a step the player waits for is finished: the file is read and ready, an "Add"
  is done, an Undo is done, unlisted puzzles were found in the catalogue.
- **An e-mail** when background work is finished and the player may have left: the file is ready (only when reading
  took longer than a minute), an "Add" is done, an Undo is done. It says what happened - "1,790 results and 359
  collection puzzles added · 12 rows wait in To fix · 3 were refused" - and links to the import. It is sent a minute after
  the step finished, and not at all if the page showed the end by then (the visible page reports it - `seen_at` on
  the batch); the bell's notification follows the same rule. So working in portions of 20 fills neither the inbox nor
  the bell. One e-mail per finished step, never per row. It travels on the `notifications` stream (so a
  blocked or bounced address gets none), has its own switch on the player (`player.import_emails`) with a signed
  one-click unsubscribe, and is built like the other e-mails (6 locales, preheader, plain-text part). No "file is
  ready" e-mail before the import can add anything - it would advertise a dead end.

## Security and privacy

- Untrusted files: size and zip checks before parsing, no formula calculation, no external entities, no pictures,
  parsing only in the worker with a memory limit; the reading library kept current (PhpSpreadsheet had three XXE
  bypasses in 2024).
- Google links: host allow-list, redirect allow-list, size and time caps, the URL built by us.
- Rate limits: `data_import_upload` 10 an hour, `data_import_google` 10 an hour.
- The file and the rows are the player's private data: never served, never in logs, deleted on schedule (Q9) and with
  the account.
- Every write checks that the import, the row and the target belong to the signed-in player.
- Spreadsheet text is shown through Twig's escaping like any other player text; exported again through
  `SpreadsheetSafeValue`.

## Not in scope (now)

- Sell/swap and lending rows (our export has them; an import could bring them later). "Lent to …", "for sale" and
  "waiting to arrive" stay as tags or custom fields. **The wish list is in**: a row whose status says so is added to
  the player's wish list (the existing `AddPuzzleToWishList`), with the same "already yours" rule.
- Fetching pictures by itself. A picture link from an export is kept with the puzzle and used only when the player
  asks ("Unlisted puzzles").
- Events / competitions of a result, the place and the number of competitors (kept as custom fields or notes).
- Results of a part of a puzzle, drills (flipping, sorting) and anything else that is not a whole puzzle solved.
- Registered co-puzzlers (D12).
- Photos (no picture travels in a spreadsheet; the unlisted list is where photos are added).
- Scheduled re-reading of a Google sheet.
- Artist and year of release as catalogue data - both files have them; worth its own look once imports show how common
  they are.
