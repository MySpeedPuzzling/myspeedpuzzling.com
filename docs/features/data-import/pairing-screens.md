# Data import: the pairing screens (stage 5b)

What stage 5b built of [the design](README.md) ("3. Puzzles"): the screens on which a player sees which catalogue
puzzle each puzzle of their file was paired with, confirms the "probably" ones, chooses among candidates, searches by
hand, and fixes rows that need a look. The data and the rules are stage 5a's ([pairing.md](pairing.md)) - this stage
is their UI, plus one new message for single rows (`AnswerImportRows`). **Nothing is added to a profile**: there is
no "Add" button anywhere (stage 6b), and an "unlisted" puzzle is a stored decision only (stage 7).

Phone first: one column, every target at least 44 px, inputs at 16 px, no sideways scrolling, one decision per
screen in the chooser. Everything works without JavaScript; the only script is the live line.

- [Routes](#routes)
- [The summary](#the-summary) · [The lists](#the-lists) · [The chooser](#the-chooser) · [The search](#the-search)
- [Decisions](#decisions) - what every button sends
- [To fix](#to-fix) · [The row sheet](#the-row-sheet) · [`AnswerImportRows`](#answerimportrows)
- [The live line](#the-live-line)
- [Wording](#wording) · [Privacy and safety](#privacy-and-safety) · [Performance](#performance)
- [Where the README and the screens differ](#where-the-readme-and-the-screens-differ) · [Tests](#tests)

## Routes

All under `/{_locale}/import/{importId}` (6 locales), signed in, `noindex`. Another player's import - or a puzzle or
a row of another import - is a 404 on every one of them. An import that is not in `setting_up` redirects to its own
page. Every POST checks the CSRF token `data_import` and answers a redirect or a 422.

| Route | | |
|---|---|---|
| `data_import_puzzles` | `GET …/puzzles` | the Puzzles summary |
| `data_import_puzzle_group` | `GET …/puzzles/{group}?page=N` | a list: `found`, `probably`, `choose`, `not-found`, `remembered` |
| `data_import_puzzle_next` | `GET …/puzzles/{group}/next?after=<id>&done=N` | "Do the next 20": redirects to the next puzzle that waits |
| `data_import_puzzle_choose` | `GET …/puzzle/{importPuzzleId}?flow=<group>&done=N` | the chooser, one puzzle per screen |
| `data_import_puzzle_search` | `POST …/puzzle/{importPuzzleId}/search` | the chooser with what the search found |
| `data_import_puzzles_decide` | `POST …/puzzles/decide` | every decision → `DecideImportPuzzles` |
| `data_import_to_fix` | `GET …/to-fix?page=N` | rows that need a look, grouped |
| `data_import_rows_answer` | `POST …/to-fix` | one answer for a group, leave out, take in → `AnswerImportRows` |
| `data_import_row` | `GET …/rows/{rowId}` | the row sheet |
| `data_import_row_save` | `POST …/rows/{rowId}` | → `AnswerImportRows` |
| `data_import_progress` | `GET …/progress` (3b) | gained `pairing` |

Ways in: the import's own page (the pairing card's "Look at the puzzles"), the "Sheets and columns" list ("We found
N of the M different puzzles of your file in the database"), each row of "rows that need a look" ("Open this row").
No other page of the site links to the import yet.

Code: `src/Controller/DataImport/{ImportPuzzles,ImportPuzzleGroup,NextImportPuzzle,ImportPuzzleChooser,SearchImportPuzzle,DecideImportPuzzles,ImportRowsToFix,AnswerImportRows,ImportRow,SaveImportRow}Controller.php`,
`src/Query/GetImportPuzzleScreen.php`, `GetImportRowsToFix.php`, `src/Services/DataImport/ImportRowAnswers.php`
(what an answer is), `ImportRowSheet.php`, `ImportToFixPage.php`, `src/Message/AnswerImportRows.php` + handler,
templates `templates/data_import/puzzles`, `puzzle_group`, `puzzle_choose`, `to_fix`, `row`, `_puzzle_card`,
`_catalogue_puzzle`, `_import_puzzle_said`, `_answer_input`, `_pages`. No new styles: Bootstrap utilities only.

## The summary

"Different puzzles in your file: N", then one clear next step and the groups as big tappable rows.

| Group | Its puzzles | "Waiting for you" |
|---|---|---|
| **Found in the database** | `tier = found` | a "found" the player took back |
| **Probably** | `tier = probably` | undecided |
| **Choose** | `tier = choose` | undecided |
| **Not found** | `tier = none` | undecided |
| **Decided as in your earlier import** (only when there are some) | no tier, decided by a remembered decision | - |

**A group is what the rules said** (`tier`), not what is left to do: a card stays on its list when it is decided, so
the player finds it again and can change it. Each row says "Puzzles: N · Waiting for you: M" (or "Nothing waits for
you"). Puzzles the similar-name search has not come to are on no list yet ("Still looking for similar names: N").

The next step is the first group that needs the player, in the order Probably → Choose → Not found → Found; for
Choose and Not found the button leads straight into the chooser. Then "Fix the rows that need a look", else "Nothing
waits for you here".

Below the groups: **Rows that need a look: N** (→ To fix) and, folded with its count, **Rows already on your
profile: N**. A row counts there when the result an earlier import of the player made still exists, or when the
result the pairing found on the profile is on the very puzzle the row's puzzle was decided to be - until then the
pairing's mark is a hint only (pairing.md "Already yours").

## The lists

Fifty cards a page (`LIMIT 50 OFFSET` in SQL, by the name the file writes), the page number the only thing in the
address. A card:

- **In your file**: name, brand, pieces, "Rows: N" - the player's own text, escaped.
- The catalogue puzzle: the decided one, else the best candidate - picture (`lazy_puzzle_image`), main title, the
  name in the viewer's language (`puzzle/_name.html.twig`), brand, pieces.
- **Why** in plain words, from the candidate's `how`: "Same barcode." · "Same name, pieces and brand." · "Same name
  and pieces - the brand differs or is missing." · "Same name without the part in brackets." · "Same short name." ·
  "You already have this result on your profile." · "A result on your profile looks like one of these rows." · "Same
  brand code." · "Similar name." · "It is the puzzle your MySpeedPuzzling export names." · "You added it from an
  earlier import." · "You chose it." · "As you decided in an earlier import." · "Found by your search."
- **Another piece count** (more than one apart) in red: "Another piece count: your file says 500, this puzzle has
  1,000."

| State of the card | Buttons |
|---|---|
| decided by the rules (Found) | **Not this one** · Change |
| decided by the player or remembered | Untick (a catalogue puzzle) / Take back (unlisted, left out) · Change |
| waits, on Probably, one candidate of the file's piece count | **Yes, this is it** · It is something else |
| waits, anything else | **Choose** (the chooser) - "Have a look" when the candidate has another piece count |

"Change", "Choose" and "It is something else" open the chooser.

**"Tick all I see here (N)"** stands under the cards of a Probably page: one `DecideImportPuzzles` with every card
of the page that still waits and whose candidate has the file's piece count - each with the `decision_version` and
the candidate **the page showed**. Nothing is ever pre-ticked: there are no checkboxes. Choose and Not found have
"Do the next 20" on top.

## The chooser

One puzzle per screen, top to bottom on a phone (560 px at most on a desktop):

1. **What the file says** - name, brand, pieces, rows - and up to three of its rows as they were understood (each
   opens its row sheet).
2. When it is decided: how, with "Take the decision back".
3. **"Is it one of these?"** - the candidates as large cards: a big picture on top, name, brand, pieces, why. **The
   whole card is the button**; a tap chooses. A candidate with another piece count carries the red line, and a tap
   on it writes nothing: **the server asks once more** (the chooser again with a 422 and "Your file says 500 pieces,
   this puzzle has 1,000. Choose it anyway?" - `DecideImportPuzzles` refuses it without `confirmedOtherPieces`, so
   it is asked without a script too). Only "Yes, choose it" takes it - here, one by one. A candidate of **another
   brand** than the file's (the file's brand text stands for a catalogue brand, the puzzle is another one's) says
   so as prominently, "Another brand: Jumbo" - on the lists' cards too; no extra question.
4. **Search the database** (below).
5. **None of these**, folded: "Keep it as an unlisted puzzle" (stored as the `unlisted` decision - "Its rows stay
   with your import and nothing is lost. Unlisted puzzles - visible only to you - arrive with a later step.") and
   "Leave these rows out".
6. **Skip** (inside "Do the next 20") and "Back to the list".

**In portions.** "Do the next 20" (`data_import_puzzle_next`) leads to the first puzzle of the group that waits;
every choice is saved at once and leads on to the next one after it in the list's order (`flow`, `done` in the
address - ids and a number). Skip leads on without deciding; at the end of the list it starts once more from the
beginning, so what was skipped comes again. After twenty decided the round ends on the list: "Done in this round:
20 · Still waiting: 290" - and the button is there for the next twenty. When nothing waits: "Nothing else waits for
you in this group."

## The search

A text field prefilled with the puzzle's name as the file writes it; a name, a part of one, a barcode or a brand
code - `PuzzleTextSearch`, as the site's search - among the puzzles that may be a candidate for this player
(`GetImportPuzzleCandidates::sqlCandidate()`: never hidden, secret or somebody else's unapproved puzzle). The best
match first, the file's piece count first among equals, then the most solved; 20 results, the same cards, each a
button. **Two characters at least** (a barcode is longer anyway) - checked by the server; less searches nothing and
says what to type. Whatever is picked goes through `DecideImportPuzzles`, which checks it again.

**It is a POST** although it changes nothing: nothing of a file - and the field is prefilled with it - may stand in
an address. The form targets its own frame (`<turbo-frame id="import-puzzle-search">`): with the `Turbo-Frame` header
the answer is the page (200), of which Turbo takes the frame; without it (no script) the same page with a **422** -
a full-page POST never answers 200. No JSON endpoint and no script: the results are rendered by the server.

## Decisions

Every button posts to `data_import_puzzles_decide`:

| `action` | Sends |
|---|---|
| `catalogue` | this catalogue puzzle (`catalogue`), `version` |
| `undecided` | "Not this one" / untick / take back |
| `unlisted`, `left_out` | "None of these" |
| `tick_all` | `cards[<import puzzle>][version]`, `cards[…][catalogue]` for every waiting card of the page |

| Outcome | Answer |
|---|---|
| saved, from a list | 302 to the list's page, at the card (`#puzzle-<id>`) |
| saved, in "Do the next 20" | 302 to the next puzzle that waits |
| `ImportPuzzleDecidedMeanwhile` (another tab, a run of the pairing) | nothing written; 302 to the page as it stands now, "This was decided meanwhile …" |
| `ImportPuzzleChoiceRefused` `other_piece_count_unconfirmed` (another piece count, tapped) | nothing written; **422**: the chooser with the question and "Yes, choose it" (`confirmed_other_pieces=1`) |
| `ImportPuzzleChoiceRefused` (a hidden puzzle sent by hand, another piece count in a group) | nothing written; 302 with a plain notice |

"Not this one" is `Undecided` on an automatic "found": the puzzle stays undecided through every later run of the
pairing (remembered as "not this puzzle", pairing.md).

## Answers in place

The table above is what a **plain browser post** gets. A tester with 30+ puzzles on "Looks like a match" was sent
back to the top of the page by every "Yes, this is it"; since then every answer of these screens - and of "People in
your file" (people.md) - is **shown in place**: the same controller, the same message, the same checks, and instead
of the redirect an answer of Turbo Streams with the screen *as it stands after the answer*. Nothing scrolls.

**Who gets it.** `ImportInPlace::asked()`: the request names `text/vnd.turbo-stream.html` first in `Accept` - Turbo
does that on every form it sends, and so does the import's own script. Nothing else decides; a request without it
gets the redirect (the no-JavaScript path, pinned by the same tests).

**What is rendered again** (`DecideImportPuzzlesController::shown()`, `_puzzles_answer.stream.html.twig`) - read with
the screens' own queries after the message was handled, never returned by the handler:

| The form stood on | Streams |
|---|---|
| a list (`…/puzzles/{group}?page=N`, "Matched", "Matched like last time") | `replace #puzzle-<id>` - the card where it stands, in its new state - and `replace #import-puzzle-group-counts` |
| a pile's own screen: the cards to look at closer (`part`), the picture grid (`tick_all`), a card of a pile's folded list (`in_pile=1`), one answer for the pile (`all_in_pile`) | `replace method="morph" #import-pile` - the screen (`_pile.html.twig`, what `ImportPuzzleGroupController::screen()` gives): the answered card is gone, the next one moved up, a waiting one took the free place, the counts are right; the folded list stays open; "Nothing left here" with ONE way on when nothing waits |
| the chooser | `replace #import-chooser` (`_chooser.html.twig`): the same puzzle as it stands now - or, in a round, **the next puzzle** (`NextImportPuzzleController::target()`, one rule for the address and the stream); the element says its address (`data-import-answers-url`) and the script puts it in the address bar |
| - always - | `update #import-answer-said`: the line that stays in view |

Where the answer leads to **another screen** - the round rests after twenty, nothing waits in the pile any more, the
import is past its set-up - the answer is the redirect it always was. Another piece count tapped on the chooser: the
question takes its place at the top of the chooser (a stream; 422 with the page for a plain post). Tapped on a card
(only a stale one can): a redirect to the chooser with `?ask=<catalogue puzzle>`, which asks the same question
(`ImportPuzzleChooserController` - the id is checked like every puzzle that is offered) - never a 422 page the script
could only call "not saved".

**The line that stays in view** (`_answer_said.html.twig`, `#import-answer-said`, `position: sticky; bottom: 0`,
`aria-live="polite"`): what the answer did ("1 matched.", "1 puzzle kept just for you.", "This was decided
meanwhile …"), **"Undo the last one"** - a form with the puzzle, the version the answer left and the fields that
lead back to the screen it was given on - and, when the answer was the last puzzle that waited, "Nothing waits for
you here." with the button to the next step (`?after=puzzles`). An answer for several that was **taken** (the grid, a pile) is said at the top of the screen it renders instead -
that screen is a new one, shown from its beginning - and the line is cleared; one that was **refused** (a set that
holds a puzzle needing a closer look, a stale tile) is said in the line, because the grid's button is at the bottom.

**What the player set and did not send stays.** A screen rendered again comes with the server's defaults; the
script puts back the boxes the player unticked (and has them counted again - the tick counter also recounts on
`turbo:morph-element`), the answer picked for a pile ("Don't import them") and the folded parts they opened - unless
another screen took the place (`data-import-answers-show`).

**The address stays true.** `#import-pile` says the address of the screen it shows (`data-import-answers-url`:
`?part=…&after=…`, `?done=…`, `?page=…`), like the chooser; after an answer shown in place the script puts it in
the address bar, so loading the page again shows the screen the player was on.

**A card that comes back by "Undo the last one"** carries the handler's memory of it: a match taken back is
remembered as "not this puzzle" (pairing.md) - there is no neutral take-back - so the card says **"You took this
match back."** (it said "You said this is not it.", which was not true for an Undo; the same words now stand on a
card after "Wrong puzzle").

**A round says its real size:** "Do the next 3" and "This round: 1 of 3" when fewer than twenty wait
(`ImportPuzzleChooserController::portion()` - one statement, inside a round only).

**No "Undo" for one answer for several.** The handler would take twenty decisions back in one message, but it is
not an undo: a match taken back is remembered as "not this puzzle", and such a puzzle may no longer be confirmed
with others (`ImportPuzzleBulkConfirm`) - the twenty would come back as cards to look at closer, not as the grid
they were. An answer for a pile can run on the worker for thousands. Each puzzle is taken back on its own card.

The line lies over the screen, so it is small (≤ ~96 px at 390 px): row 1 the sentence, the whole width, two lines
at most; row 2 the actions to the right - "Undo the last one", "Close" (the script's `dismiss`; focus returns to the
card that is answered next). Where a kept puzzle will be is a short link in the sentence ("Where do I find it?" →
`unlisted_puzzles`, a new tab, so a round is not left). **It never covers what is answered next:** while it is
shown the script gives the page `scroll-padding-bottom` and the screen's element a bottom padding of the line's
real height (`room()`), and after every answer brings the element focus goes to wholly into view - below the site's
header (`--header-height`), above the line - without moving anything that already is (`reveal()`). A notice on a
card (`data-import-answers-attention`, a refused code) and the beginning of a new screen are revealed the same way;
a screen that only says "Nothing left here" gets its Continue focused, not its heading.

The chooser's own link under "Later" leads to the previous puzzle's screen and is called that ("Go back to the
previous puzzle"); "Undo the last one" is only the line's button, which takes the answer back at once.

**Found in a real browser (2026-11), and why the script reads attributes:** every answer form holds
`<button name="action">`, and in a browser a control's name shadows the form's property - `form.action` is the
button, the request went to `…/[object HTMLButtonElement]` and nothing was saved. The address is
`form.getAttribute('action')`; the jsdom harness now shadows `action` / `method` the way a browser does, and
`ImportAnswersScriptTest` refuses `form.action|method|id|name|target` in the script's source.

**"Keep it just for me" says where the puzzle goes**, at the moment it is picked: "After you add the import, you
find puzzles kept just for you under My data → Unlisted puzzles. There you can match them … or add them to our
database later." with the link to `unlisted_puzzles` - in the line, in the pile's notice for "Keep them just for me",
and in the flash of the plain post.

**The step is done** when no pile holds a waiting puzzle and nothing is looked up: every screen of the piles then
shows `import-step-done` and its Continue leads to the next step (`continue_after: 'puzzles'`) instead of back to the
overview.

**The script** (`data_import_answers_controller.js`, on the element around the screen -
`_answers_controller.html.twig`) sends every `<form data-import-answer>` itself:

- the tapped button turns (a spinner, "Saving…" for a screen reader), every button of its card
  (`data-import-answers-scope`) is off, `aria-busy`; a second tap sends nothing;
- answers go one after another, in the order tapped; a card whose answer is still on its way stays switched off on a
  screen that was rendered again meanwhile; while an answer for the whole screen is on its way no other is sent;
- streams → `Turbo.renderStreamMessage`; then focus: a new screen (`data-import-answers-show`) is brought into view
  and its heading focused; else the card where it stands, else the card that moved up, else the one before - its
  first button or link; else the line that stays in view;
- a page instead of streams (a redirect) → `Turbo.visit(url, {response})` - the page that was fetched, not asked for
  twice (so its flash is not lost);
- no connection, a timeout (20 s), an error page (403 of a token that ran out, 422, 500) → **nothing changes**: the
  card as it was, its buttons on again, one line under them ("That did not save. Check your connection and try
  again."), focus back on the button, which is brought above the line at the bottom. **The failure is always said
  in the line that stays in view** (`role="alert"`, in place of what the answer before did, until the next answer) -
  a card keeps its own line under its buttons as well;
- "Undo the last one" names the card it brings back (`data-import-answers-returns`): that card is brought wholly
  above the line and its first button focused, and it is the card focus returns to on "Close". Nothing is ever
  focused without being brought into view first - Enter must not answer a card nobody sees;
- the last card of a screen answered: the screen's ONE way on (`#import-pile .btn-primary`, or the next step's
  button in the line) is brought into view and focused - the page got shorter under the player;
- "No" on the piece-count question takes the question away in place (`dismiss`), nothing is sent.

Before the script has loaded (it is lazy) Turbo sends the form and puts the same streams in place - without the
waiting state and the focus. `assets/app.js` puts `loaded` back on a lazy picture a morph kept
(`turbo:morph-element`).

`_flow_footer.html.twig` inside a part rendered again links the guide by the screen's route and path
(`ImportInPlace::answerFor()` sets `_import_screen_route` / `_import_screen_path`), not by the address the form was
posted to.

## To fix

The rows that need a look - waiting, a puzzle, read for their sheet's current mapping, with a problem no question of
the sheet decides - **grouped by field, what is unclear and the value as the sheet has it**: "Time · Rows: 14 ·
`1:15ish` - we could not read this time". Fifty groups a page, the largest first.

- **One answer for all rows of the group**: an input of the field's kind (`_answer_input.html.twig`) and "Use for
  these rows (14)". When every row of the group reads the same (a time with other text around it, a date the other
  way round), that reading is offered in the input - never taken by itself.
- **Leave these rows out** (asks once), and below the groups "Rows you left out: N" with "Take it in again".
- "Look at one of these rows" opens a row sheet.
- A group whose field can not be answered here (a brand code, "solved with") says so and offers the row and the
  sheet's columns.
- **A group without a value never gets one answer**: rows without a name (`no_name`) or a status whose date could
  not be read share a problem, not a puzzle or a day - one name for 37 nameless rows would make them one puzzle.
  Such a group offers only "Leave these rows out" and a row to open; the handler refuses a shared answer for it,
  and one for another field than the group's.
- "Rows fixed: N" counts the rows whose problem went away, not the rows that were touched.
- **Import these without a time (N)** - beside the answer of a group of the time's field ("we could not read a
  time" for a puzzle solved casually): every row of the group becomes a result without a time (the answer
  `ImportRowAnswers::NO_TIME`, see "No time" below). Refused for a group of any other field.

- **Import all of them without a time (N)** - one answer above the groups, offered while **more than one** group
  is about a time: the groups are told apart by the cell's text, so "casual", "no timer" and "relaxed" are three
  groups of one row and one thing to say. `action=no_time_all` gives the answer `NO_TIME` to every group about a
  time that shares an answer (`GetImportRowsToFix::timeGroups()`: the cell holds a text) - a message a group, each
  with the group's own guard, at most 500 rows a request; in place the groups are listed anew under the
  confirmation (`#import-to-fix-all-no-time`).

**Answered in place.** The forms are plain POSTs. Turbo sends them asking for streams, and
`AnswerImportRowsController` then answers with `_to_fix.stream.html.twig` instead of a redirect - the page does not
load again and does not jump:

| The answer | What changes on the page |
|---|---|
| every answer | `#import-to-fix-pages` (the pager - an answer may make the list a page shorter) |
| a value / "without a time" / "Don't import them" for a group | `#group-<key>` gives way to a short confirmation where the card stood ("Rows fixed: 14") - or to the card again with the confirmation in it, when rows of the group still wait (more than 500, or the answer did not settle all); `#import-to-fix-count` ("Groups still to answer: N", or "Nothing to do here."); `#import-to-fix-left-out` (the rows not imported, listed anew) |
| "Import after all" on a row not imported | `#left-out-row-<id>` gives way to its confirmation and `#import-to-fix-left-out-title` counts anew - or, when it was the last one, the whole block gives way to the confirmation; `#import-to-fix-groups` is listed anew (the row may be in a group again); the count |
| a value nobody can read | the group's card alone, with the reason at its field and what was typed - a **422** stream |
| changed meanwhile | a redirect to the list as it stands, with the notice (for a stream request too) |

**Nothing jumps under the finger** (`data_import_settle_controller.js`, on the page): what replaces a group's card
or a row not imported keeps the height of what it replaced **until the player moves on by themselves** - the first
scroll, a pointer moved more than 12 px, a touch outside the confirmation, a key (15 s at the latest) - and only
then gives it up, slowly: a pointer resting where it tapped never finds another button under it. The confirmation
is scrolled into view (`block: 'nearest'`) only when it is under the sticky header or below the screen.

Without a script the same POST redirects back to the list (PRG) with the same words as a flash, and a refused value
is the whole page with a 422 - as before. Known limit: a row that is in two groups (two things unclear) and is left
out through one of them still counts in the other group's card until the page is loaded again; the handler passes
such a row over, so nothing wrong is saved.

A group is named by a key in its form - `md5(field:code:value)`, `ImportRowAnswers::problemKey()`, the same in SQL -
never by its value.

## The row sheet

One import row on a page: what was understood (the card of the columns screen), then every field its sheet's
mapping gives it and a player can answer - the file's own text beside an input:

| Field | Input | A valid answer |
|---|---|---|
| Puzzle name, brand, public comment | text | any text |
| Pieces, attempt number | number | what `PiecesReader` reads for sure; a whole number from 1 |
| Barcode | digits | what `BarcodeCellReader` reads as a barcode |
| Solved on | date | a whole day with one reading, not in the future (`ImportDateReader`) |
| Time | text | a time with one reading (`DurationReader`): `1:15:00`, `0:45:10`, `45 min` - never `45:10` (minutes or hours?) or `75` |
| Solo / pair / team | select | solo · pair · team |
| First try, solved without the box, I own it | select | yes · no |
| Status | select | a result · a puzzle I own · both · wish list |

A "Completed" column that carried a date we could not read, on a sheet without a date column, gets a "Solved on"
input here: the day settles it, an answer about the status never would.

What the player types becomes the row's answer; a field left as it is changes nothing; **an emptied field takes its
answer back** (the cell's own value is read again). "Leave this row out" / "Take this row in again". The page
carries the row's stamp (`DataImportRow::stamp()`: its parsed version and its answers).

### No time

Beside the time's input: **"No time - I didn't time this one"** (a tick; `data_import_no_time_controller.js`
switches the input off while it is ticked - without a script the tick still wins over what is typed). It can be
chosen for a row whose time nobody could read and for one whose time was read, and taken away again: with a time
typed the answer is that time, with the field empty the answer is taken back and the cell is read as before.

It is an answer of the time's field like any other, kept as `{"text": "no_time", "value": 0}`
(`ImportRowAnswers::NO_TIME`; the form sends `no_time=1`, the controller makes it the time's text). The parser then
reads the row **exactly as it reads a row whose time cell is empty**: no `seconds`, nothing unclear about the time,
the same puzzle key, result key and equal-rows key (`ImportRowAnswersTest::testNoTimeMakesTheRowWhatAnEmptyTimeCellMakesIt`
compares the two). One thing more: a row whose **time cell holds something** (a time, a text nobody could read)
stays a **result** even when nothing but that cell said it was solved (`ImportRowDraft::$untimed`) - the player
said "solved, just not timed". **"No time" never makes a row a result by itself**: with an empty time cell the
answer changes nothing (a row of a shelf stays a row of a shelf), and the tick is offered only for a row that is a
result already or whose time cell holds something (`ImportRowSheet`'s `canBeUntimed`; a tick sent all the same is
not taken). A group of "Rows we could not read" about the time always has a text in the cell. From there on nothing is new: the adder
writes a result with `seconds_to_solve` null through `ImportedResultWriter` as for any time-less row (it is on the
profile as "solved"; like every result saved without a time it is in no leaderboard, average or statistic - those
read `seconds_to_solve IS NOT NULL`; no new kind of result is written), "never twice" matches it against a time-less result of the same day, and two time-less rows of
one puzzle and day are equal rows.

### The way back

Every link into a row's editor says where it leads back to: `?return=<path of the screen>` (with the screen's own
page number / filter and, where the screen has one, the anchor of the row or group - `#group-<key>` on "Rows we
could not read", `#row-<id>` on "What will be added"). `Value\DataImport\ImportRowOrigin::tryFrom()` takes it only
when it is a same-site path (`ReturnUrl`) that is **one of the pages of this very import a player can come from** -
a closed list of its GET pages (`ImportRowOrigin::SCREENS`: where you are, to-fix, columns, a sheet and its rows,
people, the puzzles' overview and lists, one puzzle, check and add, what will be added, the rows that wait, what
was added); never a POST-only address, a fragment a frame loads (the unusual-times card of "Check and add" links
back to `/add`), another row's editor, or a path that merely starts like the import's - and names the screen
(`data_import.row.back_to.<screen>`: "Back to your puzzles", "Back to “What will be added”" …). Anything else is no
origin: the back link is "Back to “Rows we could not read”", as before. Both forms of the page carry the origin as a
hidden `return`, so it survives a refused save (422) and the redirect after a save.

**Said where the player lands.** The page a save leads to is scrolled to the row or group the player came from, so
"Row 14 is saved" is a flash of its own (`SaveImportRowController::SAVED_FLASH`) that the flow header renders
**fixed in view right under the sticky site header** (`_saved_notice.html.twig`, `.import-saved-notice`: `top` =
`--header-height`, the header's measured height) - on every screen of an import, at any width, never over the
buttons a screen keeps at its bottom. It fades by itself after six seconds (CSS; still in the page for a screen
reader, × closes it at once). Anchored rows, groups and puzzles land below the header - and below the notice while
it is shown (`assets/styles/_data-import.scss`: `scroll-margin-top` from `--header-height`). A group the player came
from that is gone after the save (its last row was the one fixed) gives way to `#import-to-fix-count`, the line
above the groups.

**After a save** (PRG, the flash names the row - "Row 14 is saved. We can read all of it now." or "… Something in it
still needs a look.", "Row 14 is not imported."):

| | Where |
|---|---|
| Save / Don't import, with an origin | the origin (with its anchor) |
| … without one | the row itself while it still needs a look (the flash says so); else the next row that needs a look (in the file's order after this one, then from the top - `GetImportRowsToFix::nextRowId()`), "Rows we could not read" when none is left |
| "Save and fix the next one" (offered while another row needs a look) | the next row's editor, the origin carried along; the origin (or the list) when none is left |
| "Put this row back" | the row stays open |
| nothing was changed / changed meanwhile | the row, with the notice ("Save and fix the next one" moves on all the same) |
| the origin is the page of one puzzle of the file (`…/puzzle/{id}`) and the save made the row another puzzle (name, brand or pieces changed, or it is not imported) | the puzzles' overview, with "We are matching this row's puzzle again" - that puzzle's page may be gone once the puzzles are built again |

The editor has one primary button (Save); "Save and fix the next one" is the quiet second one.

The editor's forms are sent by the browser itself (`data-turbo="false"`): a redirect followed by Turbo's fetch loses
its anchor, and the anchor is what brings the player back to the very row or group they left. Nothing disables a
button of such a form, so `data_import_submit_once_controller.js` does (the clicked button's name and value travel
in a hidden field): a second click would send the old stamp again and be answered "changed meanwhile".

`_answer_input.html.twig` and `ImportRowAnswers` are what a later "My data" row sheet reuses.

## `AnswerImportRows`

`AnswerImportRows(importId, playerId, rowIds, answers, leaveOut, onlyWithProblem, seenStamp)` - at most 500 rows
(`ImportRowAnswers::MAX_ROWS`; a larger group is answered in portions, the page says how many still wait). Under the
import's lock, **everything checked before anything is written**:

| Refused with | When |
|---|---|
| `DataImportNotFound` (404) | not the player's import, not being set up, its cells were wiped, or a row that is not the import's |
| `ImportRowAnswerRefused` (422) `unreadable` + field | the typed value can not be read for sure by its field's reader |
| … `not_answerable`, `too_many_rows`, `nothing` | a field nobody answers, more than 500 rows, neither a value nor "leave out" |
| `ImportRowsChangedMeanwhile` (409) | the row's sheet is not parsed for its current mapping (it is just being read again), something was already made of the row, or its stamp is not what the page showed |

With `onlyWithProblem` (a group of "To fix") a row that does not carry the group's problem any more is passed over
instead of refusing; a typed value is taken only for the group's own field and only when the group has a value
(`not_answerable` otherwise). The handler answers how many rows it changed - for a group's answer: the rows whose
problem went away.

**What an answer is.** `data_import_row.answers`:
`{"time": {"text": "1:15:00", "value": 4500}, "pieces": {"text": "1000", "value": 1000}, "leftOut": true}` - the
text as typed and the value as the parser keeps it. `ImportRowParser::parse()` takes the row's answers
(`ImportRowAnswers::apply()`): an answered field has the player's value instead of its cell's, and no problem of
that field is left; `leftOut` leaves the row out (`ImportRowLeftOut::ByPlayer`). **Every parse reads the answers
with the cells** (`GetImportSheetRows::page()`), so they survive a changed mapping.

**Read again at once, this row only.** The handler parses each answered row with its sheet's mapping, options,
word meanings and lookup - the same call as `ParseImportSheetHandler`'s - so the row is what a whole parse would make
of it (keys included: an answered name is another puzzle key). Then:

- the sheet's counts (`rows_understood`, `rows_need_look`, `rows_left_out`, `rows_waiting`) move by what the rows
  were and are (`DataImportSheet::rowsReadAgain()`);
- rows of the sheet that are equal to a changed row before or after are numbered and linked again
  (`ImportEqualRows`, the parse's own rule) - two rows that become the same result by an answer are one result;
- once that is committed (`DispatchAfterCurrentBusStamp`): `LinkEqualImportRows` (the same result on two sheets)
  and `BuildImportPuzzles` (the import's puzzles and their pairing).

A sheet that looks brand and pieces up in a puzzle list asks the list **about the names of the answered rows
only**: the list's name cells are read alone (`GetImportSheetRows::cellsOfColumn()`, one light statement, streamed),
folded like a key's, and only the rows that hold one of the names are read whole and given to `SheetLookupFactory` -
the same answer as the whole list's for those names (a name the list has twice differently is still a question),
without building a 50,000-row lookup in a request under the import's lock.

## The live line

The puzzles' pages - and the import's own page - follow a running pairing by themselves, through 3b's mechanism
(`_setup_live.html.twig`, `data_import_setup_controller.js` with `pairing: true`): the player's private Mercure
topic, and asking `GET …/progress` every 4 seconds while something is worked on. **No page says "refresh the page"
any more** (the import page's hint is gone).

The payload gained, numbers only:

```json
"pairing": {"running": true, "puzzles": 1338, "found": 610, "probably": 120, "choose": 240, "notFound": 368,
            "searching": 120, "revision": "3:0"}
```

- the counts are `GetImportPairing::summary()`'s (what is left to do);
- `revision` = the run's number and whether it came to rest - the page's own revision is the sheets' one and this
  one together, and whenever it changes every `<turbo-frame data-import-live>` is loaded again;
- `running` = the import is being set up, is not paired, has a parsed sheet, and its run gave a sign of life in the
  last 20 minutes and was not given up - so a run that failed is not polled for ever (the safety net tries it again).
- **The sign of life is the pairing's own**: `data_import.pairing_alive_at` (a generated migration), set when a run
  is due (`pairingDue()`), starts, writes a chunk of its search, comes to rest or is asked for again. `running` and
  the safety net (`DataImportRepository::idsPairingStandingSince()`) read it - not `last_activity_at`, which every
  decision and answer of the player moves: a failed run no longer looks alive because the player keeps working.

While it runs the line says "Looking up your puzzles in the database…", then "Looking for similar names… Puzzles
left: 120" (from the payload, without loading anything again).

**`paired_at` is cleared as soon as rows change** (`DataImport::pairingDue()`): when a sheet's parse is recorded
and when rows are answered - not only when the queued `BuildImportPuzzles` starts. Without it a page saw "paired"
in the moment between the parse and the build and stopped following; with it the safety net also notices a lost
`BuildImportPuzzles`. The pairing's own handlers do not publish to Mercure (they run inside their transaction) - the
page's asking carries it.

## Wording

Plain and neutral: "needs a look", "we could not read this for sure", "left out", "waits for you" - never "invalid",
"error" or "suspicious" for a player's data. Counts are written "Label: N" (`%number%`, never `%count%` - see
columns-screen.md) so no sentence needs a plural form in any of the six languages. The honest line stays on the
summary, the columns screen and the import's page: "Adding your puzzles and results to your profile arrives soon.
Everything you decide here is saved and waits for it - until then nothing is added to your profile."

## Privacy and safety

- Every statement of the screens names the signed-in player; ids from the URL count only together with it.
- A puzzle's name, a cell's value, an answer: the player's own text - rendered escaped by Twig (no `|raw`, no
  `innerHTML`), **never put into an address**: the search is a POST, a group is a key, pages are numbers.
- A candidate is read through `puzzle_redirect` (a puzzle merged since the pairing is its survivor) and
  `sqlCandidate()` (a puzzle hidden since is not offered); a decided puzzle's picture respects `hide_image_until`.
- The progress payload carries numbers and revisions - nothing of the file.

## Performance

Statements on top of what every signed-in page loads, whatever the number of puzzles, rows or groups (pinned by
`ImportPairingScreensTest`):

| Page | |
|---|---|
| the summary | 3 - the import, the overview (one statement: every count, the rows that need a look, the rows already yours), the live line's progress |
| a list | 4 - those, and the page of cards with their catalogue puzzles (one statement) |
| the chooser | 3 - the import, the puzzle with its candidates, three of its rows (+1 for a search) |
| To fix | 4 - the import, a page of groups, the groups about a time, the rows left out |
| the row sheet | 3 - the row with its sheet's mapping, the import, whether another row needs a look |

To look at on production before it is opened to many (none of these was run there):

- **To fix's groups** - `GROUP BY` over `jsonb_array_elements(problems)` of the import's waiting rows; the group's
  key (`md5`) is not indexed, so "the rows of a group" reads the import's rows with problems once more.
- **The overview's "already yours"** - the import's waiting rows with a hint, joined to `puzzle_solving_time` by id.
- **The progress endpoint** counts the import's puzzles by tier and choice on every ask (the
  `(import_id, tier, choice)` index).
- **The lists' order** - `lower(name), id` within a tier: a sort of the tier's puzzles, no index.

## Where the README and the screens differ

| README | Built | Why |
|---|---|---|
| Found: "all ticked … **Add all**" | decided for the player, "Not this one"; no Add | adding is stage 6b |
| Not in the catalogue: "Keep as unlisted puzzles" for all | one by one, in the chooser | a group decision about puzzles nobody looked at is one tap from 300 unlisted puzzles - left for stage 7, with the unlisted puzzles themselves |
| The search sheet over a JSON endpoint (`import_puzzle_search`) | a section of the chooser, rendered by the server into a frame | no script, nothing of a file through `innerHTML`; works without JavaScript |
| The row sheet "of My data" (a sheet) | a page of its own | a sheet inside the chooser would be a page inside a page on a phone; the editors are a partial for My data's |
| Every field editable | the 13 fields of `ImportRowAnswers::INPUTS` | "solved with", brand code, collection and the ready-made fields are not answerable yet |
| To fix "with `MB`" (a brand answered once) | not built | a player's brand aliases have no storage (pairing.md) |
| "One result or two?" for equal rows | not built | `add_as_separate` is asked when rows are added (6b) |
| Groups by what is left | lists by the rules' tier, with "waiting for you" counted | a decided card must stay where the player saw it |
| "Already yours" folded group | a count on the summary | nothing to do there; the rows themselves come with 6b |

## Tests

`tests/Controller/DataImport/ImportPairingScreensTest.php` - imports seeded with invented puzzles
(`ImportPairingScenario`) and, for "To fix", built by the real handlers from a made-up workbook: the summary with
every group, its next step, the live line and the payload's pairing counters; the lists with what the file says, the
catalogue puzzle and why; fifty cards a page with a name's markup shown as text and no name in an address; "Not this
one" → undecided through the next run; "Tick all" with the page's versions, a stale one refused whole with a
message; the chooser choosing and moving to the next undecided puzzle, a stale tap, "None of these", the round's
end; the search never offering a hidden puzzle nor somebody else's unapproved one (the player's own yes), a barcode,
200 for the frame and 422 without it, a hidden puzzle sent by hand refused; a merged candidate as its survivor and
another piece count shown prominently; every route a 404 for another player (and mixed-up ids), every POST a 403
without its token; one answer fixing every row of a value (counts moved, pairing due, a later parse honours it); an
unreadable answer → 422 at its field with the text kept; a sheet being read again and a group that is gone → a
message; leave out and take in; the row sheet (fields, 422 at the field, saved, stale stamp, emptied field); rows
that become equal by an answer; the statement budgets.
`tests/Services/DataImport/ImportRowAnswersTest.php` - what each kind of answer is read as, and what the parser
makes of a row with answers. `ImportReadingTest` pins the payload's `pairing`.

Answers in place: `ImportPuzzlesInBulkTest` (four tests - a card to look at closer: the streams' targets, the card
gone, the count, "Undo the last one" with its fields, a second tap refused, the unchanged redirect; the grid; one
answer for a pile, a card of a folded list, a card of a list, "Keep it just for me" saying where, the last puzzle
→ the next step on every screen; the chooser's round - the next puzzle in place, Undo, the round's end as a
redirect, 403 / 404), `ImportPairingScreensTest` (the other piece count asked in place), `ImportPeopleScreenTest`,
and `tests/ImportAnswersScriptTest.php` - the real script in jsdom (`tests/import-answers-harness.mjs`): one request
for three taps, the waiting card, focus after each kind of answer, no connection and an error page leaving the card
as it was, a redirect handed to Turbo, the order of two answers. **Not run in a real browser** - what to try is in
`docs/TODO.md` "Data import - answers in place".

**Nothing was run in a browser by this stage's author** - the script's pairing mode
(`data_import_setup_controller.js`), the frames' reload, the search frame and how the pages look on a phone are to
be tried on the branch with real files against the real catalogue before the merge.
