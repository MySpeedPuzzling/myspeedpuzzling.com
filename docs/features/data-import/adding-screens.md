# Data import: the "Add" screens (stage 6b-2)

What stage 6b-2 built of [the design](README.md) ("4. Add", "Audit and Undo"): the screens on which a player sees
what is ready, reviews it, adds it to their profile, follows the work, sees what came of it, and takes it back. The
engine is stage 6b-1's ([adding.md](adding.md)) - this stage is its UI and changes none of its rules. Two small
things were added beside it: the message that writes the player's words about rows (`ConfirmImportRows`) and the
read model of the screens (`GetImportAddScreen`).

**The add-result form is not touched.** Results are written only by the engine.

Phone first: one column, every target at least 44 px, no sideways scrolling. Everything works without JavaScript;
the only script is the live line.

- [Routes](#routes)
- [Ready to add](#ready-to-add) · [Review before adding](#review-before-adding) · [The words before adding](#the-words-before-adding)
- [The button](#the-button) · [Progress and done](#progress-and-done) · [The lists](#the-lists) · [Undo](#undo)
- [Imports home and Discard](#imports-home-and-discard)
- [The live line](#the-live-line) · [A sentence per reason](#a-sentence-per-reason)
- [Wording](#wording) · [Privacy and safety](#privacy-and-safety) · [Performance](#performance)
- [Where the README and the screens differ](#where-the-readme-and-the-screens-differ) · [Tests](#tests) · [Loose ends](#loose-ends)

## Routes

All under `/{_locale}/import/{importId}` (6 locales), signed in, `noindex`. Another player's import - or a batch, an
Undo or a row of another import - is a 404 on every one of them. Every POST checks the CSRF token `data_import` and
answers a redirect (never a 200, never a page).

| Route | | |
|---|---|---|
| `data_import_add` | `GET …/add` | the review before adding |
| `data_import_add_times` | `GET …/add/times` | the unusual times - a page of its own, loaded into a frame of the review |
| `data_import_add_confirm` | `POST …/add/confirm` | "It is right", "Leave out", "They are N results" / "It is one result" |
| `data_import_add_start` | `POST …/add` | "Add" → `AddImportRows` |
| `data_import_batch` | `GET …/batches/{batchId}` | one "Add": the live line, then what came of it |
| `data_import_batch_progress` | `GET …/batches/{batchId}/progress` | JSON for the live line |
| `data_import_added` | `GET …/added?batch=&page=` | the rows the import added |
| `data_import_not_added` | `GET …/not-added?batch=&page=` | the rows that could not be added, with the reason |
| `data_import_undo` | `GET …/undo?row=` / `?batch=` / nothing | the step before an Undo: what is removed, what is kept |
| `data_import_undo_start` | `POST …/undo` | Undo → `UndoImportRows` |
| `data_import_undo_progress` | `GET …/undos/{undoId}?row=` | one Undo: the live line, then what happened |
| `data_import_undo_progress_json` | `GET …/undos/{undoId}/progress` | JSON for the live line |
| `data_import_forget_pairing` | `POST …/rows/{rowId}/forget-pairing` | forget how the row's puzzle was paired → `DecideImportPuzzles` |

Ways in: the "Adding to your profile" card on the import's own page and on the Puzzles summary, the line on the
"Sheets and columns" screen, the notification of a finished "Add" (the import's page), Imports home.

Code: `src/Controller/DataImport/{ImportAddReview,ImportAddTimes,ConfirmImportRows,StartImportAdd,ImportBatch,ImportBatchProgress,ImportAddedRows,ImportNotAddedRows,ImportUndoConfirm,StartImportUndo,ImportUndo,ImportUndoProgress,ForgetImportPairing}Controller.php`,
`src/Query/GetImportAddScreen.php`, `src/Results/ImportAddSummary.php`, `ImportAddRow.php`,
`src/Message/ConfirmImportRows.php` + handler, `DataImportRow::addAnyway()` / `countOnItsOwn()`,
`DataImportRowRepository::equalRowsOf()`, templates `templates/data_import/add`, `add_times`, `batch`, `added_rows`,
`not_added_rows`, `undo_confirm`, `undo`, `_ready_card`, `_not_ready`, `_add_row`, `_adding_live`,
`assets/controllers/data_import_adding_controller.js`. No new styles: Bootstrap utilities only. No migration.

## Ready to add

`_ready_card.html.twig` - on the import's page and the Puzzles summary, inside the part of the page that follows the
pairing by itself (`<turbo-frame data-import-live>`), so it moves as puzzles are found and chosen. It replaced the
line "adding arrives soon" everywhere.

| State (`data-state`) | The card |
|---|---|
| `ready` | "Results: N · Puzzles you own: M · Wish list: K" and **Review and add** |
| `nothing` | "Nothing is ready to be added yet." |
| `adding` | "Rows of this import are being added…" and **See how far it is** (the running batch) |
| `paused` | The safety switch is on: said calmly ("…paused for a moment by our team… nothing is lost"), **no button**. The admins' reason is never shown |

Below: **Not ready yet**, each line with where it is settled, and "Added so far - results: … · See what was added".

| Not ready | Leads to |
|---|---|
| Rows whose puzzle waits for your choice | the Puzzles summary |
| Rows that need a look | To fix |
| Sheets whose columns are not set up yet | Sheets and columns |
| Rows that could not be added | the list of them |
| Rows of unlisted puzzles - "arrive with a later step, nothing is lost" | - (stage 7) |
| Results without an exact day - "arrive with a later step, nothing is lost" | - (stage 6c) |
| Results that wait for tomorrow (the daily guard of 2,000) | - |

**The numbers** are `GetImportAddScreen::summary()` - one statement. "Ready" mirrors the engine's one statement that
queues (`DataImportRowRepository::queueReadyRows()`, adding.md "Ready") and what a row would make mirrors
`ImportRowAdder`, **for showing only**:

- a new result = a ready row with a result that is not the same result as another row (one result unless the
  player says otherwise) and that the pairing did not find on the profile (its hint, counted as the Puzzles summary
  counts it: the result exists, on the very puzzle);
- a puzzle for the collection = not in the player's collection yet; a wish = not on the list, not owned, not solved;
- a row that would make none of them is "already yours".

The engine decides again, row by row, under the player's lock: a result typed after the pairing ran, a similar
result, a time that turns out unusual are found there and shown on the done page. The test compares the review's
numbers with what the real worker then adds.

## Review before adding

`GET …/add` (`add.html.twig`). Nothing is added by opening it; nothing starts by itself.

1. **This will be added to your profile**: Results / Puzzles you own / Wish-list puzzles, then - muted - the rows
   already yours and the rows that repeat another row; the daily guard when it bites ("Today: N. The remaining M are
   ready tomorrow"); a line per sheet ("Rows ready: N").
2. **The first results as they will appear** (5): the catalogue puzzle with its picture, time, day, solo / pair /
   team, the people "as guests", first try.
3. **Unusual times** - a frame (below).
4. **The same result on several rows** - "Rows: 3 - counted as one result." with **They are 3 results** (and back:
   **It is one result**). The first 20 groups.
5. **Already on your profile: N** - folded, the first 20 rows, nothing to do.
6. What an import does (results are public like typed ones, people are guests; everything can be taken back) and
   what it does **not** do, in two lines: followers are not notified; results are dated by their day and do not show
   up as new activity.
7. **Not ready yet** - the card's lines again.
8. **The button**.

An import rows can not be added from (still being read, its content wiped) redirects to its own page.

### Unusual times

`ImportRowTimeCheck::unusualAmong()` asks the time verification about each row on its own (several statements a
row), so it is **not** part of the review page: the review holds
`<turbo-frame id="import-add-times" src="…/add/times" loading="lazy">` with a plain link inside for a browser
without a script; `…/add/times` is a full page that holds the same frame.

It checks the first **100** ready results that have a time and no "it is right" yet (`ROWS_CHECKED`), and lists the
unusual ones: the row, why ("Much faster than your other results suggest - we expected about 2:55:00" / "Much
slower…" / "Unusually fast / slow for this puzzle" when nothing was expected), **It is right**, **Leave out**, "Fix
the row" (the row sheet) - and **They are all right (N)** for all shown.

A time the check did not come to - beyond the first 100, or one that looks unusual only once earlier rows of the
same file are on the profile (a row is judged against the results that exist when its turn comes) - is refused by
the engine with `unusual_time` and waits in "Rows that could not be added" with the same answer: **It is right -
add it**. So nothing unusual is ever added without the player's word, whatever the page showed.

## The words before adding

`POST …/add/confirm`, `action`:

| | Sends | Writes |
|---|---|---|
| `right` + `rows[]` | `ConfirmImportRows(addAnyway: [unusual_time])` | `data_import_row.answers.addAnyway` |
| `leave_out` + `rows[]` | `AnswerImportRows(leaveOut: true)` - the pairing screens' own message | the row is left out (it can be taken in again in "To fix") |
| `separate` / `one` + `group` | `ConfirmImportRows(equalGroupOf:, separate:)` | `add_as_separate` on every row that is the same result as the group's standing row |

Always a redirect back to the review (`#unusual-times` / `#equal-rows`) with what was saved said on it. The forms of
the times frame leave the frame (`data-turbo-frame="_top"`).

**`ConfirmImportRows`** (`importId, playerId, rowIds, addAnyway, equalGroupOf, separate`) - under the import's lock
(the one `AnswerImportRows` takes), everything checked before anything is written:

- the import is the player's, is being set up, its content not wiped; every row and the group's row are the
  import's - else `DataImportNotFound` (404);
- `addAnyway` takes only the four codes a player may wave through (`GetImportAddScreen::WAVABLE`:
  `similar_result_exists`, `somebody_elses_result`, `said_already_on_profile`, `unusual_time`) - anything else is a
  404, a page never sends it;
- a row that an "Add" holds or that made something is past its words: `ImportRowsChangedMeanwhile` - nothing is
  written, the page says "these rows changed meanwhile".

It only writes the answer. Nothing is added by it - "Add" reads the answers (`ImportRowAdder`).

## The button

**Add N to my profile** - N is what would be made (results + collection puzzles + wishes); "Go through these rows
(N)" when everything ready is already the player's. It is not shown while adding is paused, while an "Add" of the
import runs, or when nothing is ready.

`POST …/add` with `_token` and **`request_id`** - a UUIDv7 made when the page was rendered. The same form sent again
(a double tap, a resent form, the same page in a second tab) carries the same id and `AddImportRows` answers the
same batch: nothing is queued twice. PRG:

| Outcome | Answer |
|---|---|
| started / the same request | 302 to the batch's page |
| an "Add" of the player still runs | 302 to that batch's page, "…still running - one at a time" |
| paused | 302 back, "Adding is paused for a moment by our team. Nothing was started and nothing is lost…" |
| too many "Add"s in an hour, not addable, nothing ready, over today's limit | 302 back with the line that says so |

From the list of rows that could not be added the same route takes `rows[]` (the row's own form, its own
`request_id`) and optionally `anyway` = one of the four codes: the answer is written first (`ConfirmImportRows`),
then that row is added (`AddImportRows(rowIds:)`). A code that may not be waved through is ignored - the row is
only tried again.

## Progress and done

`GET …/batches/{batchId}` (`batch.html.twig`).

**While it runs**: "Adding… 320 of 1,240" with a bar (the live line, below), "You can leave this page - the work
goes on in the background", "We tell you in your notifications, and by e-mail, when it is done"; when the safety
switch is flipped meanwhile: "…paused for a moment… It goes on by itself - nothing is lost." When every row is
through and what is owed for the results is being done: "Finishing…".

**Done**:

- Rows added (Results · Puzzles you own · Wish list), Already yours, Could not be added;
- **Why rows could not be added**: per reason code the number of rows and a plain sentence
  ([below](#a-sentence-per-reason)), and "Rows that could not be added" (the list);
- "Not taken this time": what the "Add" left where it was (`skipped`: unlisted, no exact day, over today's limit);
- **See them on my profile** · What was added · Review what else is ready;
- "Statistics, XP and achievements follow within a few minutes." (XP and achievements are queued per result when
  the batch ends; nobody's followers are told);
- **Take back** - "Take back this adding" / "Take back everything this import added".

A batch that an Undo stopped says so; one that was taken back says when.

A finished batch shows the numbers it stored when it ended; "why" counts the rows that carry the reason now (a row
settled since is gone from it).

## The lists

Fifty rows a page (`LIMIT 50 OFFSET` in SQL); the page number and a batch's id are all the address holds.

**What this import added** (`…/added`): the row (`_add_row.html.twig`), what it made (Result / In your collection
/ On your wish list), "See the result", **Take back** (the step before an Undo, for this row). A result an Undo
left because it was changed says so.

**Rows that could not be added** (`…/not-added`), grouped by reason: the row, the reason's sentence, and where it
helps:

| Reason | Offered |
|---|---|
| `unusual_time` | **It is right - add it** · Open this row |
| `similar_result_exists`, `somebody_elses_result`, `said_already_on_profile` | **Add it anyway** |
| `needs_exact_day`, `time_too_long`, `too_fast`, `comment_too_long`, `people_do_not_fit_type`, `empty_group`, `first_try_taken`, `not_ready` | Try again · Open this row (the row sheet) |
| `puzzle_not_available`, `puzzle_not_found`, `puzzle_not_revealed` | Try again · Choose the puzzle again (the chooser) |
| `equal_row_not_added`, `error` | Try again |
| `unlisted_not_yet` | - |

Every row's form has its own `request_id`.

## Undo

**No `confirm()` dialog.** Every "Take back" is a link to a step that says what happens, and the Undo is the POST
of the form on that step.

`GET …/undo` - `?row=<id>` (one row), `?batch=<id>` (one "Add"), nothing (everything the import added):

- **This is removed from your profile**: Results / Puzzles you own / Wish-list puzzles, "Wish-list puzzles that
  come back" (the ones the import removed because of the player's own "remove when solved / owned");
- **What stays**: "Results you changed since the import stay on your profile. You can remove them one by one
  afterwards." · "Puzzles you moved to another collection stay there." · "The rows are ready again afterwards and
  can be added again.";
- the button (`request_id`, `scope`, the row or the batch) and "Keep it as it is".

`POST …/undo` → `UndoImportRows` → 302 to `…/undos/{undoId}` (`?row=` for a single row - an id), or back with
"There is nothing to take back here." The same form sent again answers the same Undo. Undo is never paused, and
every running "Add" of the import is stopped first (the engine's rule).

`GET …/undos/{undoId}`: the live line ("Taking back… 3 of 40"), then: Results removed, Puzzles removed from your
collection / wish list, Wish-list puzzles that come back, **Results kept - you changed them since the import**,
puzzles kept (moved), "Rows ready again: N", and **Review and add**.

**"Remove it too" - asked only when it applies.** The engine knows whether a result was changed since
(`UndoImportRowHandler`); the screens do not guess. An Undo leaves such a result, marks its row
(`made.keptByUndo`) and counts it; its page then lists exactly those rows (the first 50), each with **Remove it
too** - a new Undo of that one row with `include_edited` (the only place that sends it; the engine takes it for
chosen rows only). So a single row's "Take back" asks the question only for a row that was edited, and a batch's or
an import's take-back offers it per result afterwards.

**Paired with the wrong puzzle?** Undo keeps the pairing's decision. After a single row's take-back the page
offers **Forget the pairing and choose again**: `DecideImportPuzzles` with `Undecided` for the row's puzzle - which
also forgets the remembered decision (it is remembered as "not this puzzle", so the next file does not repeat it) -
and the player lands in that puzzle's chooser. After a batch's or an import's take-back the page leads to the
Puzzles summary instead ("use 'Not this one' on the wrong ones").

The player's own Undo tells nobody anything (no notification, no e-mail - the engine's rule).

## Imports home and Discard

Imports home: each import that ever added says "Added so far - results: N · puzzles you own: M · wish list: K ·
date" ("Nothing of it is on your profile now" after everything was taken back) and "Adding now" while a batch runs.
One statement for all imports.

**Discard.** The engine never deletes an import that queued a row (adding.md "What is never deleted"). So the
import's page shows **"This import stays in your list"** instead of Discard: "It added rows to your profile, so it
is kept: that is how the same rows are never added twice and how you can take them back. Discarding it would not
remove anything from your profile - to remove what it added, take it back." with "Take back everything this import
added". A page that was open before the "Add" may still post Discard: nothing is discarded, the player lands on the
import's page with that line.

## The live line

`_adding_live.html.twig` + `data_import_adding_controller.js` - the mechanism of the reading's and the set-up's
lines: `import_adding` messages on the player's own private Mercure topic (published by the worker after each row's
commit - `ImportProgress::publishAdding()`), and asking the JSON endpoint every 3 seconds when no message came
(more and more seldom when it does not answer, a notice after ten minutes). An Undo publishes nothing - its page
asks.

```json
{"id": "<batch or undo>", "running": true, "paused": false, "total": 1240, "done": 320}
```

Numbers and states only. When `running` turns false the page is loaded again and shows what came of it. Text is
written with `textContent`; the bar is a width. Without a script: a "Refresh the page" link.

## A sentence per reason

`data_import.reason.<code>` - all 6 locales; `ImportAddScreensTest` fails when a code of `ImportRowRefusal` or
`ImportedResultRefusal` has no sentence in a locale.

| Code | Sentence (English) |
|---|---|
| `puzzle_not_found` | The puzzle is not in the database any more. Choose the puzzle again. |
| `puzzle_not_revealed` | The puzzle is a competition puzzle that is not revealed yet. Try again after its reveal. |
| `empty_group` | The row names people, but nobody besides you. Open the row and check who you solved with. |
| `first_try_taken` | The row says first try, and you already have a first try on this puzzle. Open the row and change it. |
| `too_fast` | The time is faster than 100 pieces a minute per person, which MySpeedPuzzling does not take. Open the row and check the time. |
| `time_too_long` | The time is longer than MySpeedPuzzling takes (999:59:59). Open the row and check the time. |
| `comment_too_long` | The comment is longer than MySpeedPuzzling takes. Shorten it in your file, or map the column to private notes. |
| `not_ready` | The row was not ready any more when its turn came - its puzzle or something about the row changed meanwhile. |
| `unlisted_not_yet` | The puzzle is kept as an unlisted puzzle. Unlisted puzzles arrive with a later step - nothing is lost. |
| `needs_exact_day` | The result has no exact day. Results known to the month or year arrive with a later step - or open the row and give it a day. |
| `puzzle_not_available` | The puzzle can not be chosen any more. Choose the puzzle again. |
| `similar_result_exists` | Your profile already has a result of this puzzle that looks like this row - the same time on another day, or the same day with another time. If it is another solve, add it anyway. |
| `somebody_elses_result` | Somebody else already logged a pair or team result of this puzzle with this day and time. If you solved it together, ask them to link you instead - or add it anyway. |
| `said_already_on_profile` | Your file says this result is already on MySpeedPuzzling, and we did not find it. Add it anyway if it is missing. |
| `people_do_not_fit_type` | The row says pair or team and names nobody - or says solo and names people. Open the row and set who you solved with. |
| `unusual_time` | The time is unusual for you and this puzzle. If it is right, say so and it is added. |
| `equal_row_not_added` | It is the same result as another row of your file, and that row was not added. |
| `error` | Something went wrong on our side with this row. We know about it - you can try again. |
| `over_daily_limit` (skipped) | Over today's limit of results from spreadsheets - ready again tomorrow. |

## Wording

Plain and neutral, as on the screens before: "unusual", "needs a look", "could not be added", "take back" - never
"error", "invalid" or "suspicious" for a player's data. Counts are "Label: N" (`%number%`), so no sentence needs a
plural form in any of the six languages; the button is the one sentence with a number in it ("Add 7 to my profile",
worded per language so that it reads right for any number). The admins' reason for a pause is never shown.

## Privacy and safety

- Every statement of the screens names the signed-in player; ids from the URL count only together with it.
- A puzzle's name, a partner's name, a cell's value: the player's own text - rendered escaped by Twig (no `|raw`,
  no `innerHTML`), **never put into an address**: addresses hold ids, a page number and nothing else.
- A decided puzzle's picture respects `hide_image_until`.
- The payloads of the live line carry numbers and states - nothing of the file.
- "Add" and Undo are POST + CSRF + an idempotent request id; nothing is written by a GET.

## Performance

Statements on top of what every signed-in page loads, whatever the number of rows (pinned by
`ImportAddScreensTest::testTheScreensCostTheSameForThreeRowsAndForSixty`):

| Page | |
|---|---|
| the review | 6 - the import, the summary, the sheets, the sample, the equal rows, the rows already yours |
| the times frame | bounded by 100 rows, several statements a row - why it is a request of its own |
| one "Add" | 2 - the import, the batch (+1: the reasons, when rows could not be added) |
| its progress | 1 |
| a list | 3 - the import, its counts, a page of rows |
| the step before an Undo | 3 at most |
| one Undo | 2 (+1: the results it kept; +1: the row's pairing) |
| Imports home | +1 - what each import added |
| the import's page, the Puzzles summary | +1 each - the ready card's summary |

To look at on production before many use it (none of these was run there):

- **The summary** reads every waiting row of the import with its puzzle's decision once (the `(import_id, state)`
  index) and asks three small `EXISTS` per ready row (collection, wish list, solved) - for a 50,000-row import this
  is the heaviest statement of the screens, and it runs on the import's page and the Puzzles summary too.
- **The equal rows** group the import's waiting rows that point at another row.
- **The times frame**: up to 100 rows × the classifier's statements in one request.

## Where the README and the screens differ

| README | Built | Why |
|---|---|---|
| "Add all Found" on the Found list; "Add 1,790 results and 359 collection puzzles" straight from the Puzzles step | one review page, reached from the card; one button for everything that is ready | one place that says what will happen before it does; adding rows of chosen puzzles only (`importPuzzleIds`) is the engine's and has no button yet |
| "Before the first Add, once: results appear on your profile…" | the lines stand on every review | no "seen" state to keep; it is two lines |
| Step 4 of "never twice": one question per puzzle before adding ("Your profile has 14 Sep… The same one?"), with yes / no / leave out, and the file's values offered as a change | after the "Add": the row waits under "could not be added" with **Add it anyway** | the engine refuses per row (adding.md); "yes, the same one" is leaving it there; offering the file's values as a change is My data's |
| "144 results are on two sheets - counted once" as a line of the summary | every group of equal rows is listed with its switch, whichever sheets they are on | one control for both cases |
| Unusual time: "correct it, say it is right, add without a time, leave out"; "one answer may cover all rows of the same shape" | It is right (one / all shown) · Leave out · Fix the row | "add without a time" is emptying the time on the row sheet; a shape-wide answer needs the shapes |
| The e-mail and the notification are not sent when the page showed the end (`seen_at`) | sent by the engine when the batch ends | 6b-1's call; no `seen_at` exists |
| A notification and an e-mail for a finished Undo | none for the player's own | the engine's rule |
| Undo "in My data" | on the import's own pages | My data is a later stage |
| "12 results were edited after the import - kept" as a list | the same, with "Remove it too" per result | the brief's "asked only when that row was edited since" |
| Imports home: "2,105 of 4,764 puzzles added · 310 waiting for you", **Continue**, **Undo** on the card | "Added so far - results · owned · wish list · date", "Adding now"; Undo on the import's page | the card links to the import, where the numbers and the buttons are |
| Discarding an import that added | not offered - the page says why and offers "Take back everything" | the engine keeps such an import |
| A free player's sheet name as a custom field, a member's choice of collection | not built | the engine adds to the system collection (adding.md) |
| "Forget that these puzzles were paired this way" after an Undo of rows | for a single row's take-back; a batch's / an import's leads to the Puzzles summary | forgetting hundreds of pairings with one tap would undo the player's own work; "Not this one" on the wrong ones does the same per puzzle |

## Tests

`tests/Controller/DataImport/ImportAddScreensTest.php` - the screens driven as a browser drives them, the worker's
turns taken by hand with the real handlers (`ImportAddingScenario`): the card on both pages with its counts, its
not-ready lines and their links; the review's numbers, sample (a name's markup shown as text, nothing of the file
in an address), equal rows, folded "already yours", the two lines - **and the batch the real processor then makes
has exactly those numbers**; the running batch's live line and JSON, the done page; a resent form → the same batch,
a second press → "one at a time"; the pause: no button on the card and the review, the POST refused calmly, nothing
queued; an unusual time listed with its reason (a workbook read by the real handlers), "It is right" → added and
stored as the add form's answer is, "Leave out" → not; one result or three, there and back, a foreign group → 404;
the done page's counts by reason with each sentence, the list with the way to settle each, "Add it anyway" → its
own batch (and the same press twice → the same one), a code that may not be waved never written; a sentence for
every refusal code in every language; Undo of a row / a batch / the import: the step before (no `confirm()`), the
same form twice → one Undo, the live line, the row ready again, the edited result kept and listed, "Remove it too"
only there, nothing left → no form; forgetting a pairing; every route a 404 for another player (and mixed-up ids),
every POST a 403 without its token; the statement budgets at 3 and at 60 rows, a list's pages; Imports home and the
import that is kept instead of discarded.

The tests of the screens before were updated where the "arrives soon" line stood (`ImportColumnsScreenTest`,
`ImportPairingScreensTest`, `ImportReadingTest`, `ImportUploadTest`, `ImportGoogleSheetTest`) and where a pinned
budget gained the card's statement.

**Nothing was run in a browser by this stage's author** - the live line's script
(`data_import_adding_controller.js`: the Mercure messages, the asking, the reload at the end), the lazy frame of the
unusual times and how the pages look on a phone are to be tried on the branch with real files before the merge.

## Loose ends

- **Adding chosen puzzles only** (`AddImportRows::$importPuzzleIds`) has no button - "Add all Found" of the README.
- **The pre-check of times covers the first 100 results**; the rest is asked after the "Add" (never added without
  the word).
- **Results the profile has "similar"** are asked after the "Add", not before.
- **"Leave out" needs a sheet whose rows are read for its current mapping** (the pairing screens' own rule): while
  a sheet is being read again the answer is "these rows changed meanwhile".
- **A row that was added and taken back can be added again and its puzzle chosen again, but its fields can not be
  answered on the row sheet**: `AnswerImportRows` refuses a row that ever linked a result (`solvingTimeId` stays
  after an Undo - 5b's rule met 6b-1's). The texts therefore say "can be added again", not "fix them". To settle
  between the two stages.
- The notification of a finished "Add" leads to the import's page, not to the batch.
- The import's status stays `setting_up` after an "Add" (`reviewing` / `done` are not used by anything yet).
- The summary statement on a 50,000-row import was not measured on production.
