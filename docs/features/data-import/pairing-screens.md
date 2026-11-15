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
   whole card is the button**; a tap chooses. A candidate with another piece count carries the red line and asks
   once more before it is taken ("Your file says 500 pieces, this puzzle has 1,000. Choose it anyway?") - it can
   only be chosen here, one by one.
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
button. Whatever is picked goes through `DecideImportPuzzles`, which checks it again.

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
| `ImportPuzzleChoiceRefused` (a hidden puzzle sent by hand, another piece count in a group) | nothing written; 302 with a plain notice |

"Not this one" is `Undecided` on an automatic "found": the puzzle stays undecided through every later run of the
pairing (remembered as "not this puzzle", pairing.md).

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

What the player types becomes the row's answer; a field left as it is changes nothing; **an emptied field takes its
answer back** (the cell's own value is read again). "Leave this row out" / "Take this row in again". The page
carries the row's stamp (`DataImportRow::stamp()`: its parsed version and its answers).

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
instead of refusing - the handler answers how many rows it changed.

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

A sheet that looks brand and pieces up in a puzzle list builds that lookup once per message (the list's rows are
read - up to the import's row limit).

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
| To fix | 3 - the import, a page of groups, the rows left out |
| the row sheet | 2 - the row with its sheet's mapping, the import |

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

**Nothing was run in a browser by this stage's author** - the script's pairing mode
(`data_import_setup_controller.js`), the frames' reload, the search frame and how the pages look on a phone are to
be tried on the branch with real files against the real catalogue before the merge.
