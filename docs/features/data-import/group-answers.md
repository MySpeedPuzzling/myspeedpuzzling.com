# Data import: one answer for a whole group of rows that were not added (stage 6b-3)

A real file showed it: a batch of 1,893 rows added 918 and left 928 - **441 × "the file says it is on
MySpeedPuzzling"**, **416 × an unusual time** (a fast puzzler's history judged against a profile with other times),
71 × rows that follow a twin row. Answering row by row is not usable for somebody who brings years of history. This
stage adds **one answer per reason** on top of [the "Add" screens](adding-screens.md) and [the engine](adding.md).

The add-result form is not touched. Results are written only by the engine.

## What a group action does, step by step

1. **The card.** The done page of an "Add" and the list of rows that could not be added show, per reason, a card
   with the number of rows (all of them, not the fifty of a page) and one action (`_group_cards.html.twig`):

   | Reason | One action for all its rows |
   |---|---|
   | `unusual_time` | **These times are right - add all N** / Leave them out - after a summary (below) |
   | `said_already_on_profile` | **They are missing - add all N** / Leave them out |
   | `people_do_not_fit_type` | the existing "Who did you solve these with?" card (adding-screens.md) |
   | `similar_result_exists`, `somebody_elses_result` | **none** - possible duplicates are answered one by one, with the existing result beside the row; the card says so |
   | `equal_row_not_added` | none - "they follow their twin row and are settled when it is added" |

   On the done page the reasons with one action stand first.
2. **The form** (`POST …/group-answers`, `data_import_group_answer_start`) names the reason, the action, the "Add"
   the page is about and a `request_id` - **never the rows**. CSRF; always a redirect.
3. **`AnswerImportRowsGroup`** (under the import's lock): the import is the player's and rows can be added from it;
   the same `request_id` answers the same group answer; only the two reasons above take a group answer (anything
   else is a 404); **one answer at a time per reason** - while one goes through the rows, a second ("Leave them out"
   beside "Add all") is not started: "Another answer is already going through these rows", with its page; the group is counted; a `data_import_group_answer` row is written (`DataImportGroupAnswer`: what
   it is about, how far it is - kept for good, like a batch).
4. **The rows are gone through in chunks** by `ProcessImportGroupAnswer` - at once in the request for up to 200
   rows, on the `imports` queue for more (200 rows a turn, the job sending itself again so other players' jobs take
   their turns in between). **One turn, one chunk - claimed and answered in one transaction**
   (`AnswerImportGroupChunk`, under the answer's lock): the rows after the answer's cursor are read, answered,
   written down as answered and the cursor moves past them, all or nothing. Two turns side by side (a job delivered
   twice, "Go on" pressed while a turn runs) never take the same rows: the second waits for the lock and finds the
   cursor moved. So each row is confirmed by exactly one turn and the answer's list is exactly the confirmed rows.
   - "add it anyway" → `ConfirmImportRowsHandler`. **Per row, the same checks as a single answer**: the row still
     waits, the question is the one it waits with, and the word is stored with the fingerprint of what the row says
     now (its time, day, kind, people, paired puzzle) - a later change asks again. A confirmed row's `failure` is
     cleared: its question is answered, it is ready like any other row;
   - "leave out" → the pairing screens' `AnswerImportRowsHandler(leaveOut)`, which checks everything before it writes
     anything: a chunk it refuses (its sheet is being read again) wrote nothing and is passed over.
   **An answer that can not go on ends as failed.** A chunk that fails is rolled back whole and tried again, three
   times in its turn; then the answer is ended with `add_status = failed` and what it did confirm (those rows are
   ready), logged once with the exception - its reason is free for another answer. An answer that runs without a
   sign of life for 15 minutes (a worker restarted, its job lost) is ended the same way by the sweeper
   (`myspeedpuzzling:continue-import-batches`). Before that, its page offers "Go on".
5. **The end.** The answer is ended once (`RecordImportGroupAnswer`, under its lock), and the turn that ended it starts **one "Add" of exactly the rows that took "add it anyway"**
   (`AddImportRows` by their ids). Everything an "Add" does applies: never twice, the daily guard of 2,000 results
   (what it leaves is ready tomorrow from the normal review - the question is answered), the safety switch (paused:
   the rows stay ready, the page says so).
6. **The page** `…/group-answers/{id}`: the live line ("Going through your rows… 200 of 416", the existing
   `data_import_adding_controller.js` asking `…/progress`), then a redirect to the batch's page, which follows the
   adding and shows what came of it. An answer without an "Add" (leave out, nothing took it, paused) says what
   happened and leads to the review. An answer no turn touched for a minute offers "Go on" (the same form again).

**A row corrected since is not swept in.** A row that waits with a reason and is read differently afterwards
(another time, day, kind or puzzle - its key changes: the row sheet, a changed mapping) loses the reason
(`DataImportRow::parsedAs()`): it is not that refused row any more, it is ready and judged afresh when added. So
"all rows refused for X" is always the rows as they were refused.

## Unusual times: the summary before the button

`…/not-added/times` (`ImportUnusualTimesGroupController`, a page loaded into a lazy frame of the card;
`ImportUnusualTimesSummary`): the time verification is asked **now** about an even sample of the group (at most 200
rows - each costs several statements) and the page says, before the two buttons:

- "Looked at 200 of the 416 just now." · "Faster than expected: 188 · slower than expected: 12";
- "The faster ones are between 2× and 4× faster than we expected." (expected ÷ entered; for slower ones entered ÷
  expected);
- five examples - the fastest, the slowest, three around the middle - each with the puzzle, the time and "We
  expected about 2:55:00.";
- **"Most of the times in this file are faster than your results on MySpeedPuzzling so far - if this file is your
  own history, that is expected."** - only when the rows that wait as unusual are more than half of the import's
  results with a time: **exact counts over all the import's rows** (one aggregate statement,
  `GetImportAddScreen::notAddedByReason()`), never an extrapolation of the sample. The sample only chooses the word:
  "faster" / "slower" when four of five of the times looked at are off that way, else "unusual".

Rows already on the profile change the judgement: a time refused as unusual may not look unusual any more once the
file's other rows were added ("None of the times we looked at seems unusual any more…").

## Before the "Add"

The review's times frame (which lists the unusual ones among the first 100 results) gained **"All unusual times in
this file are right"** / **"Leave out all unusual times of this file"**: a group answer with `before_add` - every
ready result with a time is shown to the time verification (100 a turn, always on the worker), only an unusual one
takes the answer, nothing is added: the player presses "Add" as usual.

## What the time-verification scan does with a group-confirmed result

Exactly what it does with a single "It is right" (adding.md "Time verification", suspicious-time-review.md): when
the row is added and its time is still unusual for the player, the engine stores a `SuspiciousTimeConfirmation` -
and the scan still raises such a result, with the reason `confirmed_while_saving`. The scan is unchanged and never
marks or tells the player anything by itself.

**The moderators' queue is not flooded by it**: the pending cases on results that one "Add" made are **one card**
there - the import judged as a whole (the imported times beside the player's typed ones), with "Looks fine - all N",
"Mark all N as needing verification" (the player told once for the group) and "Open them one by one"
(suspicious-time-review.md "Import cards").

(Fewer confirmations than rows is normal: a row whose time is not unusual any more when its turn comes - the file's
earlier rows are on the profile by then - is added without one.)

## Code

`src/Entity/DataImportGroupAnswer.php` (+ migration `data_import_group_answer`), `src/Message/{AnswerImportRowsGroup,ProcessImportGroupAnswer,AnswerImportGroupChunk,RecordImportGroupAnswer}.php`
and handlers, `ConfirmImportRows` (`refusedWith`, `ofBatchId`, `afterRowId`, `limit`; answers the confirmed ids),
`DataImportRow::addAnyway()` (clears the answered `failure`) / `parsedAs()` (clears a stale one),
`DataImportRowRepository::{idsRefusedWith,countRefusedWith,idsOfTimedReady}`, `GetImportAddScreen::{groupAnswer,notAddedByReason,sampleNotAddedFor}`,
`src/Services/DataImport/ImportUnusualTimesSummary.php`, controllers `StartImportGroupAnswer`, `ImportGroupAnswer`,
`ImportGroupAnswerProgress`, `ImportUnusualTimesGroup`, templates `_group_cards`, `not_added_times`,
`group_answer`; `ProcessImportGroupAnswer` is routed to `imports` (`config/packages/messenger.php`).

## Tests

`tests/Controller/DataImport/ImportGroupAnswersTest.php`: 60 rows of a reason (more than a page) answered by one
form that names no row → one "Add" of exactly those 60, each added once, a resent form → the same answer;
another player's rows with the same reason untouched, their import a 404, a group of possible duplicates a 404, no
token a 403; 205 rows → nothing in the request, two turns of the real worker handler with the live line and JSON, a
third delivery doing nothing, one "Add" of 205; unusual times: the summary (counts, range, examples, the "most of
the file" sentence at 4 of 6 and gone at 3 of 6), a row corrected since not swept in and not waved through, the
confirmations stored; before the "Add": only the unusual times take the word, nothing is added; "Leave them out"
through a workbook read by the real handlers; every group text in every language.

**Nothing was run in a browser by this stage's author.**

## Asked once (guided flow, slice 6)

A row with two doubts is not sent back twice any more, "the file says it is on MySpeedPuzzling" is asked before
the "Add" too, and "add them" for a group stands for the file - adding.md "A question is asked once",
adding-screens.md "Already on MySpeedPuzzling, asked before the Add". What that changed here: step 3 takes
`before_add` for both reasons and an `also` answer for the rows that wait with both; step 4's chunk passes the rows
that still wait with another question over when it names the rows to add; "add it anyway" writes the standing
answer.

## Loose ends

- The summary looks at a sample of 200 rows; its shares are the sample's.
- "Leave out" needs a sheet whose rows are read for its current mapping (the pairing screens' rule).
- The ids of the rows that took "add it anyway" are kept on the answer (`answered_row_ids`) until the "Add" - up to
  the import's row limit.
- `people_do_not_fit_type` keeps its own card and route (one answer, in the request, up to 500 rows).
