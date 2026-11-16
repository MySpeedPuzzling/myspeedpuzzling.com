# Data import: the "Sheets and columns" screen (stage 4b-2)

What stage 4b-2 built of [the design](README.md) ("2. Sheets and columns"): the screen on which a player sees what
MySpeedPuzzling understood of each sheet of their file, answers its questions and corrects its columns. The logic is
stage 4b-1's ([columns.md](columns.md)) - this stage is its UI, and every change goes through the one message
`ConfirmImportMapping`. **Nothing is paired with the catalogue and nothing is added to a profile**; no page of the
site links to the import yet.

Phone first: one column of cards, every target at least 44 px, selects and number fields at 16 px (no zoom on
iOS), no sideways scrolling, nothing that needs JavaScript to be used (the live line is the only script, and the
pages work without it).

## Routes

All under `/{_locale}/import/{importId}` (6 locales), signed in, `noindex`. Another player's import - or a sheet of
another import - is a 404 on every one of them. An import that is not in `setting_up` (not read yet, discarded)
redirects to its own page.

| Route | | |
|---|---|---|
| `data_import_columns` | `GET …/columns` | the sheets of the file as cards |
| `data_import_sheet` | `GET …/sheets/{sheetId}` | one sheet: questions, what it is, columns, preview |
| `data_import_sheet_confirm` | `POST …/sheets/{sheetId}` | saves the sheet's form (CSRF `data_import`) → `ConfirmImportMapping` |
| `data_import_sheet_rows` | `GET …/sheets/{sheetId}/need-a-look?page=N` | the rows that need a look, 50 a page |
| `data_import_progress` | `GET …/progress` (3b) | gained `working`, `rowsToParse`, `rowsParsed`, `revision` |

The import's own page (`data_import_detail`) gained, once the file is read, the summary line "Rows ready: N · Need a
look: M · Waiting for your answer: K · Sheets left out: L", the button to this screen, and the honest line that
pairing with the puzzle database and adding arrive soon. Its read-only table of sheets stays.

Code: `src/Controller/DataImport/Import{Columns,Sheet,SheetRows}Controller.php`, `ConfirmImportSheetController.php`,
`src/Services/DataImport/ImportSheetScreen.php` (the mapping as the page shows it, and the form as the message takes
it - pure), `ImportSheetPage.php`, `src/Query/GetImportSheetScreen.php`, templates `templates/data_import/columns`,
`sheet`, `sheet_rows`, `_sheet_state`, `_parsed_row`, `_setup_live`, script
`assets/controllers/data_import_setup_controller.js`. No new styles: Bootstrap utilities only, as the other import
pages.

## The list of sheets

A card per sheet: its name, its number of rows, how it stands, what MySpeedPuzzling takes it for. **The sheets that
ask something come first**, then the file's own order. Above the cards the summary line.

"This sheet is: Results - Change" folds out the six kinds (results, puzzles I own, wish list, puzzle list the other
sheets point to, not puzzles, somebody else's) as buttons: **one tap** saves it (the same form and message as the
sheet's page, back to the list) and the sheet is read again.

### What each state shows

`DataImportSheetOverview::state()` on the list, `ImportSheetPage::state()` on a sheet's page - the same words:

| State | When | Shows |
|---|---|---|
| `recognising` | no mapping yet (`mapping_version = 0`) | "Working out what it holds…"; a visit to the sheet's page goes back to the list, which gets the card by itself |
| `parsing` | mapped, rows not read for the current mapping | "Reading through its rows…" |
| `waiting` | read, and its questions decide **at least half of its rows** | "Waiting for your answer"; the card's button says "Answer the question". **A sheet whose reading is a guess** (`checkFirst`, [columns.md](columns.md) "Check first") is `waiting` with all its rows and says "Waits for you to check its columns" instead - never a spinner |
| `parsed` | rows read | "Rows understood: N · Need a look: M · Waiting for your answer: W · Left out: K"; with a question left, the card's button is "Questions to answer: 2 (rows: 12)" |
| `left_out` | not puzzles / somebody else's (and its rows are left out) | "Left out"; its page offers only what it is, so it can be taken back |
| `failed` | `set_up_failure` (`parse_failed`, `proposal_failed`, `mapping_invalid`) | "We could not work this sheet out - a fault on our side"; with a mapping: "Save the sheet once more below and we will try again" (a confirmed mapping starts the set-up over), without one: nothing of it is imported for now |

### A question and its rows

**A question never holds back its whole sheet** (a 629-row sheet once waited for two questions that decide 12 rows).
A sheet is parsed as soon as it has a mapping - this changed stage 4b-1's rule "a sheet with a question is not
parsed". The rows a pending question decides are marked right after the parser read them
(`PendingQuestionRows::mark()`): the problem the parser left open for want of the answer (`time_two_readings`,
`date_day_or_month_open`, `date_order_open`, `date_no_year`) gets `waiting: true`; for "were the typed dates turned
around?", which leaves nothing open in the parser, every row with a typed date gets the problem `date_typed_open`.
Such a row is **neither ready nor "needs a look"**: it is counted in `data_import_sheet.rows_waiting` (a generated
migration), left out of the need-a-look list, and says "This row waits for your answer" in the preview. Answering
parses the sheet again, as before; version checks and "a parse of a sheet and a version happens once" are untouched.

A time question no longer offers a reading that makes one of its example rows longer than 99 hours
(`326 → 326:00:00`); when a single reading is left, it is taken and nothing is asked (`ImportColumnDecisions`).

## One sheet

Its own page (a card that unfolds would be a page inside a page on a phone), top to bottom:

1. **How it stands** - the state line and, when rows need a look, the button to them.
   - **"Our reading is a guess - look first"** (only while the sheet asks for the check, `check_first`): a notice
     above everything else. Without a header row: "This sheet has no header row. We guessed the columns from what
     is in them - tell us what each column is." (with "Sheet 1 of 2 to check" above it when several sheets of the
     file ask; and, on a results sheet without a time column, "We did not find a column with your times. Pick it
     below - otherwise your puzzles are added without a time."); with one: "Please check how we read this sheet." Both say "Until
     you have looked, we match no puzzles of this sheet and add nothing from it." and carry the one-tap
     **"Yes, this is right"**. The form holds `answers[check_columns] = yes` hidden, so "Save and read the rows
     again" - with or without a change - is the player's word too. It is not listed among the questions.
2. **Its questions** (only when it has some): one card each - a plain sentence naming the column, the sheet's own
   examples (`ImportQuestion::$examples`), and one big button per answer. Each button says **how that answer reads
   the examples**: `45:10 → 0:45:10` against `45:10 → 45:10:00`, `3/4/2023 → 3 April 2023` against `4 March 2023`
   (worked out by the value readers from the example's text; an example the reader can not read from its text alone
   is shown without a reading). The year question offers the mapping's choices and "Another year" with a number
   field. **No answer is preselected** - a suggestion is never a silent choice.
3. **What is this sheet?** - the six kinds.
4. **What is a row of it?** - a result · a result, and I own the puzzle · a puzzle I own · a puzzle on my wish list ·
   "a column says it for each row" (only where a status column can). Kind and row meaning hang together: whichever
   of the two the player changed decides the other (`ImportSheetScreen::submission()`).
5. **Who solved these?** (results only) - as each row says · every row is a solo / pair / team result
   (`sheetWide.solvingType`).
6. **Columns.** Notes first: a known export ("its columns need no setting up"), the sheet brand and pieces are looked
   up in, the table that is read. Then
   - **"Please check these"** - the columns we are not sure about (`probable`, `unknown`) as cards: the header (or
     "Column C"), three sample values, a picker; a column left unknown because another has the field says so;
     A column that is a guess (`probable`) carries the badge **"Our guess"**; the brand guessed from its values
     says "These look like puzzle brands we know."
   - **No brand column** (the sheet names puzzles, no column is the brand or a barcode, and it looks nothing up in
     another sheet): "No column of this sheet is the brand. We find your puzzles much more surely with it - if a
     column holds the brand, choose “Brand” for it below." There is no "one brand for the whole sheet" setting.
   - **"Recognised"** - the columns we are sure about (`certain`), one line each: "Time → Solving time - Change".
     The line unfolds to the same samples and picker: **any column can be changed**. A known export's own columns
     (the source's row id, our puzzle id, the picture link) are lines without a picker.

   The picker: The puzzle (name, brand, pieces, barcode, brand code) · The result (solved on, time, solved with,
   solo/pair/team, first try, attempt number, without the box, public comment) · Owning and lists (status, I own
   it, not mine any more, collection, already on MySpeedPuzzling) · My fields (the 17 ready-made fields, in the
   page's language) · Not imported ("Don't import", "Worked out by MySpeedPuzzling").
7. **Save and read the rows again** - one button for everything above.
8. **The first rows, as we understood them** - five rows as cards: puzzle name, brand, pieces, time, day, people,
   what the row is, and its problems in plain words.

### Saving

`POST` → `ImportSheetScreen::submission()` turns the form into the message's arguments: `mapping` (the stored one
with kind, row meaning and the changed columns - or null when none of them changed), `answers`, `sheetWide`. What
can be said at a field is checked first:

| | Shown |
|---|---|
| two columns on a field a row has once | at the column the player changed |
| a picker value that is none of the list | at the column |
| "Another year" without a year 1950-2100 | at the question |

Everything else is the handler's check (`InvalidImportMapping`) and is shown at the top of the form. **A refused
submit answers 422 with the same page and the player's choices kept**; nothing is changed. A sheet changed meanwhile
(`ImportMappingChangedMeanwhile`: another tab, another device) redirects to the sheet as it stands with a notice. A
saved one **moves on** (PRG): to "Where you are" (the import's page), which says "Saved - your rows are being read
again", follows the reading with the live line and names the next step - the player never stays on the sheet
wondering whether it worked. A sheet saved as it was ("Nothing was changed") moves on the same way. The one-tap
change of the list leads back to the list (`back=list`), an answer on step 2's one-question screens to the next
question (`back=check`).

"A column says it for each row" is refused (422) on a sheet without a status column - the page never offers it
there. **A save that changes nothing reads nothing again** (the notice "Nothing was changed") - except on a sheet
whose set-up was given up: saving it as it is, is how it is tried again.

### The live line

After a save the sheet is read again in the background. `_setup_live.html.twig` + `data_import_setup_controller.js`
show "Reading your rows… 1,200 of 3,400" (or "Working out what your sheets hold…" while sheets are still
recognised) through 3b's mechanism: the player's private Mercure topic, and asking `GET …/progress` every 4 seconds
whenever no event came - with the same back-off, the same giving up after ten minutes, the same reload when signed
out.

The payload (topic and endpoint alike) gained, numbers only:

| | |
|---|---|
| `working` | a step that runs by itself is still due: a sheet without a mapping, or one whose rows are not read for its mapping (no question, no failure) |
| `rowsToParse`, `rowsParsed` | the rows of the sheets being read, and how many are done for the current mapping |
| `revision` | an md5 over every sheet's id, mapping version, parsed version and failure - another one whenever a sheet's state changes |

The page is rendered with the revision it shows (`data-…-revision-value`), so a sheet that finishes before the
script's first question is noticed too. Whenever the revision changes or the work ends, **every `<turbo-frame
data-import-live>` of the page is loaded again** (the page's own address; Turbo takes the frame out of it): the list's summary and cards, a sheet's state and
its preview. The sheet's form stands outside those frames - a refresh never touches what the player is filling in.
A page with nothing to wait for makes no request; it only listens. An answer that does not hold the frame (signed
out, the import discarded, an error page - `turbo:frame-missing`) becomes a full visit to the import's page. Without JavaScript the line has a "Refresh the
page" link.

## Rows that need a look

`…/need-a-look`: the rows of the sheet that are puzzles and carry a problem, in the sheet's order, 50 a page (the
page number is the only thing in the address). Each is the same card as in the preview; every problem shows **the
value as the sheet has it** (highlighted) and a plain sentence. Every code `ImportRowParser` can give has one
(`data_import.columns.problem.<code>`, 6 locales; a code without a sentence falls back to "We could not read this
for sure"):

`no_name`, `lookup_ambiguous` · `pieces_multipack`, `_unknown`, `_possibly_thousands`, `_not_whole`, `_zero`,
`_negative`, `_too_many`, `_unreadable` · `date_typed_open` · `time_unreadable`, `time_needs_yes`, `time_two_readings` ·
`date_unreadable`, `date_in_the_future`, `date_before_1950`, `date_order_open`, `date_day_or_month_open`,
`date_other_order`, `date_no_year` · `barcode_scientific_notation`, `barcode_digits_lost`, `barcode_damaged` ·
`type_unknown`, `attempt_unreadable`, `status_unknown`, `yes_no_unknown`.

**Read only**: fixing single rows (and "one result or two?", "yes, that is the time") is the row sheet of stage 5.

## Wording

Neutral and plain: "needs a look", "we could not read …", "left out" - never "invalid", "error" or "suspicious" for
a player's data. Counts are written "Label: N" so that no sentence needs a plural form in any of the six languages.

**A gotcha found here:** a text of the form `Word: %count%` loses its label when the count is a number below 1,000 -
Symfony's translator takes a single word before a colon for the label of a plural rule (`one: …|other: …`). The new
texts use `%number%`; the import page's "Left out: N" was moved to such a key too.

## Privacy and safety

- Every statement of the screen names the signed-in player (`GetImportSheetScreen`, `GetDataImports`); ids come from
  the URL only together with it.
- Cell values (sample values, examples, parsed names, problem values) are the player's own text: rendered escaped
  by Twig, never as markup, **never put into a URL** (a test posts `<b>` in a cell).
- The progress payload carries numbers, states and an md5 of ids and versions - nothing of the file.
- The form posts to its own route with a CSRF token; the handler checks the import is the player's again.

## Performance

Statements on top of what every signed-in page loads, whatever the number of sheets or rows (pinned by
`ImportColumnsScreenTest`):

| Page | |
|---|---|
| the list | 3 - the import, its sheets, the revision (the progress statement) |
| a sheet | 5 - the sheet, a dozen of its first rows (sample values), five rows as understood, the import, the revision |
| rows that need a look | 3 - the sheet, a page of rows (`LIMIT 50 OFFSET`, on the `(sheet_id, row_number)` index), the import |

`GET …/progress` counts the rows done only for sheets that are being read at that moment (nothing is counted once
the import rests); what it costs on a large sheet, and the index that would make it cheap, is noted in
`docs/TODO.md`.

## Deliberately not editable yet

- **A new field of the player's own for an unknown column** ("Keep as my own field"): the mapping has no target for
  it - `ImportField` knows the ready-made fields only (columns.md: "`custom:own:<name>` columns are unknown columns
  until the screen can make a new field"). The picker offers the 17 ready-made fields and "Don't import".
- **Words that mean something** (`valueAnswers`: what `#02-pair` or `Waiting list` means) - the message takes them,
  the screen does not ask yet.
- **Which table, and the header row** when we guessed it wrong (`mapping.region`, `regions`): shown ("We read the
  table in A1:E", "Tables on this sheet: 3"), not changeable.
- **Where the rest is** (`lookupSheetId`) and **which collection** (`collectionLabel`): shown / not asked.
- **Layouts** - one screen per layout, sheets with the same headers set up together, the remembered layout: stage
  4c. Here every sheet is its own.
- **The README's line "Row 2 will be read as: …"** is the preview of the first five rows *as saved*; a preview of
  unsaved choices needs the parser in the request and is left for the row sheet's stage.
- **A sheet's on/off switch** is the kind "Not puzzles" / "Somebody else's" (`data_import_sheet.included` is not
  used).
- Fixing single rows, pairing, adding, entry points from other pages.

## Tests

`tests/Controller/DataImport/ImportColumnsScreenTest.php` - imports built by the real handlers from made-up
workbooks: the list with a sheet in every state and the waiting one first; a question with the sheet's examples read
each way; the columns with samples, the preview and the rows that need a look; a sheet that is not puzzles, one
whose set-up failed (and is tried again by a save), one not recognised yet; an answer saved → the live line's
numbers, the rows read again, the counts changed; a changed column and the one-tap kind; a refused change (two
columns on one field, an answer nobody asked for, a value the picker never offers) → 422 at its field, nothing
changed; a stale page; CSRF; another player's import, and mixed-up import and sheet ids, → 404 on every route; 50 rows
a page with a cell's markup shown as text; the statement budgets.
`ImportReadingTest` pins the progress payload's new fields.

**Nothing was run in a browser by this stage's author** - the script (`data_import_setup_controller.js`), the
frames' reload and the pages' look on a phone are to be tried on the branch with real files before the merge.
