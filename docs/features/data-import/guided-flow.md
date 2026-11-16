# Data import: the guided flow (design specification)

The import works end to end ([STATUS.md](STATUS.md)) but it is a set of screens built stage by stage. This document
turns them into **one guided flow** a person who has never seen it can finish on a phone. It is a design, not code:
the engine, the messages and the data model stay; every proposal is marked

- **[UI]** - templates, texts, a controller reading what queries already return;
- **[small]** - a small server addition (named);
- **[logic]** - new logic (named).

Written against `origin/main` at #368 ("an import can be finished") and **with** the two things in flight: "People
in your file" (stage 6d) and the entry points (stage 8, #366). The add-result form is not touched by anything here.

## 1. What is wrong today (the findings this solves)

| # | Finding (real files on the sandbox) | Answer here |
|---|---|---|
| 1 | The import's page: three equal red buttons, no order, technical metadata on top | §3 one header, §5.3 one page "Where you are" with ONE primary button |
| 2 | Dot-separated counters ("Rows ready: 747 · Need a look: 45 · Waiting for your answer: 0 · Sheets left out: 0") | One number per line, a sentence each, zeros never shown (§2 rule 5) |
| 3 | 786 puzzles = 80 + 196 + 157 single decisions (another file: 208 + 319 + 122) | §6 three bulk designs; "add what is clear now" on every screen |
| 4 | The columns screen shows everything for every sheet | §5.4 one sentence per sheet, details folded, questions only when there are some |
| 5 | Developer vocabulary | §8 vocabulary table |
| 6 | No list of what will be added, no untick per row | §7 "What will be added" with untick per row / puzzle / sheet |
| 7 | After the Add, questions come back in rounds for the same rows | §5.10 questions asked once per file, before the Add where they can be known |
| 8 | "500 piece Pairs" added as solo, silently | §5.9 "How we read your file" - every assumption a line, before the button |
| 9 | Good parts | kept: values read each way, picture cards, brand / piece warnings, never twice, undo, live progress |

## 2. Rules of the flow

1. **Five steps, always numbered the same:** 1 File · 2 Check · 3 People · 4 Puzzles · 5 Add. A step with nothing
   to ask is **passed by itself** and shown ticked - the numbers never change, so "Step 4 of 5" means the same for
   everybody and in the guide.
2. **One primary button per screen**, full width, at the bottom of the content (not fixed, except in the two list
   screens where it is a sticky bar). Everything else is a link or an outline button.
3. **Continue is a link, not a state.** Where the player is, is the address (`…/import/{id}/check`, `/people`,
   `/puzzles`, `/add`). Nothing is stored about "which step was seen"; a returning player lands on "Where you are"
   (§5.3), which works the first open step out from what the queries already return. **[UI]**
4. **Nothing has to be finished.** Every step from 2 on carries the same quiet line under its primary button:
   *"Or add what is ready now (747) - you can come back to the rest any time."* It leads to step 5.
5. **Numbers:** one per line, with its noun, never chained with "·"; a zero is not printed; a number over 20 always
   comes with what one tap does about it.
6. **A suggestion is never a silent decision.** What MySpeedPuzzling decided alone (a "matched" puzzle, a sheet's
   kind, how a date is read) is listed before the Add. What it only suspects ("looks like a match", "pick one") is
   never applied until the player taps on a set they can see.
7. **Words:** §8. No "error", "invalid", "failed"; our faults are "Something went wrong on our side. Nothing is
   lost."
8. **Without JavaScript** every screen works as plain forms and links; scripts only add live progress, counting on
   buttons and auto-saving a tick.

### When a step is passed by itself

| Step | Passed by itself when | Else it shows |
|---|---|---|
| 1 File | the file is read (`status = setting_up`) | upload / reading / why it could not be read |
| 2 Check | every sheet that is in is `parsed`, has **no question**, **no column under "Please check these"**, is not `failed`, and no row needs a look | §5.4 questions and "please check" sheets only; then §5.5 rows to check |
| 3 People | the file names nobody and no sheet's title or type column says pair / team; or every name and sheet has its answer | §5.6 |
| 4 Puzzles | no puzzle waits in "Looks like a match", "Pick the right one" or "Not in our database" (and the search is through) | §5.7 |
| 5 Add | never | §5.9 |

**The clean export** (Puzzle Tracker's CSV, our own results export, a file with a remembered layout whose puzzles
are all matched): Bring the file → reading (no tap) → **step 5, "Check and add"**, which shows how the file was
read and the Add button. Two screens, two taps. Steps 2-4 are ticks in the header and can still be opened.

## 3. The header of the flow

One partial, `data_import/_flow_header.html.twig`, on every screen of an import (replaces the breadcrumbs and the
State / Size / Uploaded card). **[UI]**

```
+--------------------------------------+
| < Imports      my-puzzles.xlsx   ... |
| (1)--(2)--(3)--[4]--( 5 )            |
| Step 4 of 5 · Puzzles                |
+--------------------------------------+
```

- **`< Imports`** - link to Imports home. Leaving *is* saving: there is no "Save" anywhere. Under the last element
  of every screen: *"Everything is saved as you go. You can leave and come back any time."* (once per screen, muted).
- **File name** - one line, truncated in the middle (`my-puzz…2024.xlsx`); "Google sheet" when it has none.
- **Step dots** - five, 28 px, each a link to its step (44 px target through padding). Done = tick, current =
  filled with its number, open with something waiting = number with a small dot, not reached = outline.
  `aria-label="Step 4 of 5, Puzzles, 276 waiting"`. Under them one text line: **"Step 4 of 5 · Puzzles"**.
- **`...`** - a `<details>` menu (no script), in this order:
  - "How we read your file" (today's Sheets and columns, all sheets, every column)
  - "Column set-ups we remember (3)" (today's saved layouts)
  - "What this import added" (only after an Add)
  - "About this file": *"61 kB · brought on 14 November 2026 · kept until 14 December 2026"*
  - **"I'm done with this import"** (only where `_finish_card` shows it today)
  - **"Discard this import"** (only before anything was added; else "Undo everything this import added")

"Discard" and "I'm done" live **only** in this menu and at the very bottom of "Where you are" (§5.3) - never beside
a primary button.

## 4. The screens at a glance

| # | Screen | Address (existing route unless said) | Step |
|---|---|---|---|
| 5.1 | Your imports | `data_imports` | - |
| 5.2 | Bring your file / reading | `data_import_new`, `data_import_detail` while reading | 1 |
| 5.3 | Where you are | `data_import_detail` | - |
| 5.4 | Check: how we read your file | `data_import_columns` | 2 |
| 5.5 | Check: rows we could not read | `data_import_to_fix` | 2 |
| 5.6 | People in your file | 6d's route | 3 |
| 5.7 | Puzzles | `data_import_puzzles` + the three piles (§6) | 4 |
| 5.9 | Check and add | `data_import_add` | 5 |
| 7 | What will be added | **new** `data_import_add_list` | 5 |
| 5.10 | Adding / Added | `data_import_batch` | 5 |
| 7 | What this import added | `data_import_added` | 5 |

## 5. The screens

Shared states, used by every screen below unless it says otherwise:

| State | What shows |
|---|---|
| Working (reading, matching, adding) | A spinner line with the number moving - "Matching your puzzles… 412 of 786" - and *"You can leave this page - it goes on without you."* The primary button is not shown until there is something to press. |
| Nothing to do | A green line - "Nothing to do here." - and the primary button **Continue** to the next step. |
| Our fault | Amber card: **"Something went wrong on our side."** *"Nothing is lost. Try again - if it happens again, we already know about it."* + **Try again**. Never the word "error". |
| Changed meanwhile | Flash: *"This changed in the meantime - here is how it stands now. Nothing was saved."* (today's texts, kept) |

### 5.1 Your imports (home)

**Purpose:** see each file's one line of status and go on with one tap.

```
+--------------------------------------+
| Your imports                         |
| Bring the results and puzzles you    |
| kept in a spreadsheet.               |
|                                      |
| [  + Import a spreadsheet         ]  |
|                                      |
| +----------------------------------+ |
| | my-puzzles.xlsx                  | |
| | 471 ready to add · 276 puzzles   | |
| | still to match                   | |
| | [ Continue                     ] | |
| +----------------------------------+ |
| +----------------------------------+ |
| | Puzzle Tracker export.csv        | |
| | Added 246 on 15 November         | |
| | 31 rows wait for your answer     | |
| | [ Answer 2 questions           ] | |
| +----------------------------------+ |
| > Finished (2)                       |
+--------------------------------------+
```

The card: file name; **one status sentence** (table below); **one button**. No badge, no size, no date line.
The status and the button come from the same derivation as "Where you are" (`ImportFlow`, §9 slice 1). **[UI]** for
the texts; the numbers need the add summary per import: **[small]** `GetImportAddScreen::addedByImports()` grows
`ready` and `waiting` counts (one statement for all imports, as today).

| Import stands at | Status sentence | Button |
|---|---|---|
| uploading (not confirmed) | "The upload did not finish." | **Finish the upload** |
| fetching / uploaded / reading | "Being read… you can leave it to us." | **Open** (outline) |
| could not be read / fetched | "We could not read this file." | **See why** |
| step 2 open | "2 questions about your file" / "45 rows we could not read" | **Continue** |
| step 3 open | "Tell us who 3 people are" | **Continue** |
| step 4 open, something ready | "471 ready to add · 276 puzzles still to match" | **Continue** |
| only step 5 | "471 ready to add" | **Check and add** |
| adding now | "Adding… 320 of 1,240" | **See how far it is** |
| added, questions wait | "Added 246 on 15 November · 31 rows wait for your answer" | **Answer 2 questions** |
| added, rest waits for tomorrow | "Added 2,000 today · 1,240 more can be added tomorrow" | **Open** (outline) |
| added, nothing left | "Added 918 on 15 November" | **See what was added** (outline) |
| everything undone | "Nothing of it is on your profile now." | **Open** (outline) |

Empty state: *"No imports yet. Have your results or your collection in a spreadsheet? Bring the file - we read it,
you check it, and it is on your profile."* + **Import a spreadsheet**.
Three in progress: the button is replaced by *"You have 3 imports in progress. Finish or discard one to bring
another."* (each card's `...` has both).

### 5.2 Step 1 - Bring your file, and reading

**Purpose:** get the file in with one action and tell the player they can stop watching.

```
+--------------------------------------+
| < Imports                            |
| (1)--( 2 )--( 3 )--( 4 )--( 5 )      |
| Step 1 of 5 · Your file              |
|                                      |
| Bring your spreadsheet               |
| Results, puzzles you own, a wish     |
| list - in any layout. Only you see   |
| the file.                            |
|                                      |
| [  Choose a file                  ]  |
| Excel (.xlsx), CSV or TSV, up to     |
| 25 MB                                |
|                                      |
| -- or --                             |
| Google Sheets link                   |
| [_______________________________]    |
| [ Fetch the sheet ]  (outline)       |
| > How to share a Google sheet        |
|                                      |
| Nothing is added to your profile     |
| until you have checked it and        |
| pressed Add.                         |
+--------------------------------------+
```

- Change against today: the file button is first and primary (a phone user has a file from an app's export far more
  often than a share link they can copy); choosing a file **starts the upload at once** (no second "Upload" tap -
  `data_import_upload_controller.js` already uploads on its own; without JS the form shows a submit button
  **Upload**). The Google link stays, as the secondary form. **[UI]**
- Folded: the sharing steps; *"We keep the file for 30 days."*
- Reading (same address as the import): the header at step 1 and

```
| Reading your file…                   |
| Sheets read: 3 of 10                 |
| Rows read: 2,410                     |
| You can leave this page - reading    |
| goes on without you.                 |
```

  When reading and matching are through, the page moves by itself to "Where you are" - or straight to step 5 for a
  clean file (§2). Could not be read: today's sentences per cause, with **Try again** primary and "Bring another
  file" as a link.

### 5.3 Where you are (the import's page)

**Purpose:** the one place that always says what is done, what waits and what the next tap is. This replaces the
three red buttons. It is the landing page of every notification and of "Continue" on Imports home.

```
+--------------------------------------+
| < Imports      my-puzzles.xlsx   ... |
| (v)--(v)--(v)--[4]--( 5 )            |
| Step 4 of 5 · Puzzles                |
|                                      |
| We read 792 rows of your file.       |
|                                      |
| v 1 Your file                        |
|     10 sheets, 792 rows              |
| v 2 Check                            |
|     Read as you would expect.  See > |
| v 3 People                           |
|     Nobody else is named.            |
| * 4 Puzzles                          |
|     510 of 786 matched.              |
|     276 wait for you.                |
| o 5 Add                              |
|     471 ready to add now.            |
|                                      |
| [  Match your puzzles             ]  |
|                                      |
| Or add what is ready now (471) -     |
| you can come back to the other 276   |
| puzzles any time.                    |
|                                      |
| Everything is saved as you go.       |
+--------------------------------------+
```

- **Headline:** "We read 792 rows of your file." While something works: "Matching your puzzles… 412 of 786" (the
  live frame of today, `data-import-live`).
- **The five lines** are links to their step. Each has exactly one status sentence:

| Step | Done | Open |
|---|---|---|
| 1 | "10 sheets, 792 rows" (one sheet: "792 rows") | - |
| 2 | "Read as you would expect." + "See" | "2 questions about your file" / "45 rows we could not read" / both on two lines |
| 3 | "Nobody else is named." / "3 people: 1 player, 2 guests" | "Tell us who 3 people are" / "One sheet looks like pairs - who was your partner?" |
| 4 | "All 786 puzzles matched." | "510 of 786 matched. 276 wait for you." |
| 5 | "Added 471 on 15 November." | "471 ready to add now." / "Nothing is ready yet - step 4 first." |

- **Primary button** = the first open step, in order: **Answer 2 questions** · **Check how we read your file** (a
  sheet whose reading is a guess - "As built - a guessed sheet is checked first" at the end) · **Look at 45 rows** ·
  **Say who they are** · **Match your puzzles** · **Check and add**. When an Add runs: **See how far it is**. When rows came back
  with questions after an Add: **Answer 2 questions** (to §5.10).
- **Secondary line** (rule 4), only when a step before 5 is open and something is ready.
- **Folded / at the bottom:** "I'm done with this import" or "Discard this import" as a plain link with its one
  sentence (today's `finish.means` / `discard_help`), below a rule. The sheets table of today moves into "How we
  read your file".
- **States:** file still being read → §5.2. Finished import: headline "This import is finished.", the lines
  read-only, primary **See what was added**, link "Go on with this import".
- **Build:** **[UI]** + one read-side class `Services/DataImport/ImportFlow` (pure: takes `DataImportOverview`, the
  sheets, `summary`, `ImportPairingSummary`, `ImportAddSummary` - all already loaded by `ImportDetailController` -
  and answers the five step states, the primary action and the status sentence). No table, no migration.

### 5.4 Step 2 - Check: how we read your file

**Purpose:** answer only what MySpeedPuzzling could not work out, and see - in one sentence per sheet - what it
took each sheet for.

**When there are questions** (one screen per question, the sheet's name on top; today's question cards unchanged -
they are the best part):

```
+--------------------------------------+
| (v)--[2]--( 3 )--( 4 )--( 5 )        |
| Step 2 of 5 · Check                  |
| Question 1 of 2                      |
|                                      |
| Sheet "1000 pieces"                  |
| How should we read the times in      |
| "Time"?                              |
|                                      |
| +----------------------------------+ |
| | Hours and minutes                | |
| | 3:26 -> 3:26:00                  | |
| | 1:05 -> 1:05:00                  | |
| +----------------------------------+ |
| +----------------------------------+ |
| | Minutes and seconds              | |
| | 3:26 -> 0:03:26                  | |
| | 1:05 -> 0:01:05                  | |
| +----------------------------------+ |
|                                      |
| This decides 212 rows.               |
| Later >                              |
+--------------------------------------+
```

- **A tap on an answer is the primary action**: it saves (`ConfirmImportMapping`, as today) and goes to the next
  question, then to the summary below. No answer is preselected. "Later" skips the question (its rows wait).
- "Question 1 of 2" counts questions across all sheets, the sheet with the most rows first. **[UI]** (today the
  questions sit on each sheet's page among everything else.)

**The summary** (always the last screen of step 2; the only one when there are no questions and somebody opens
step 2 from the header):

```
+--------------------------------------+
| Step 2 of 5 · Check                  |
|                                      |
| This is how we read your file        |
|                                      |
| v "Solo 500"                         |
|   212 solo results                   |
|   Time, date, puzzle, brand   Change |
| v "500 piece Pairs"                  |
|   61 pair results                    |
|   Partner: asked in step 3    Change |
| v "Collection"                       |
|   255 puzzles you own         Change |
| ! "2019"                             |
|   113 rows - we are not sure what    |
|   this sheet is.         [ Tell us ] |
| - "Notes", "Stats" + 6 more          |
|   Not puzzles - not imported.   Show |
|                                      |
| Dates: day first (03/04/2023 is      |
| 3 April 2023).                Change |
|                                      |
| [  Looks right - continue         ]  |
+--------------------------------------+
```

- One block per sheet: **name**; **what its rows are added as** (the sentence table below); the recognised fields in
  one muted line; **Change** opens today's sheet page (kind, row meaning, who solved, every column - unchanged).
- A sheet with something under "Please check these" or an unsure kind has `!` and a button instead of a tick; such
  sheets come first. Sheets left out are **one folded line** ("Not puzzles - not imported", with **Show** → each
  with "It does hold puzzles").
- A file-wide line for each guess that came without a question: the date order, a time shape taken because only one
  reading was possible, the year of dates without one.
- **Primary: "Looks right - continue"** → the next open step. It is a link; with `!` sheets left it reads
  **"Continue - I'll look at "2019" later"**.
- Known export: instead of the per-sheet field line - *"We know this file: it is an export of Puzzle Tracker."*
- **Build: [UI]** over `GetImportSheetScreen` / `sheetsOfPlayer()`; the "added as" sentence needs per sheet the
  row meaning and sheet-wide solving type, which the mapping holds. **[small]**: `DataImportSheetOverview` grows
  `rowMeaning`, `solvingType` and `fieldsRecognised` (read from the stored mapping; no new column).

| Sheet is | Sentence |
|---|---|
| results, solo | "212 solo results" |
| results, pair / team for the whole sheet | "61 pair results" / "14 team results" |
| results, as each row says | "340 results - solo, pair or team as each row says" |
| result + owned | "212 results, and the puzzles go to your collection" |
| owned | "255 puzzles you own" |
| wish list | "12 puzzles for your wish list" |
| a column says it per row | "301 rows - a column says which are results and which you own" |
| puzzle list other sheets point to | "A list the other sheets look puzzles up in - not imported itself" |

### 5.5 Step 2 - Check: rows we could not read

**Purpose:** fix many rows with one answer, or decide not to import them - never row by row unless wanted.

Today's "To fix", with three changes: it is inside step 2 with **Continue**; groups are ordered by the number of
rows; each group card has the same two buttons in the same place.

```
+--------------------------------------+
| Step 2 of 5 · Check                  |
| 45 rows we could not read            |
| One answer fixes every row of a      |
| group.                               |
|                                      |
| +----------------------------------+ |
| | Time "1h 20" - 18 rows           | |
| | We would read it as 1:20:00.     | |
| | [1:20:00___________]             | |
| | [ Use for these 18 rows        ] | |
| | Don't import them · See one row  | |
| +----------------------------------+ |
| +----------------------------------+ |
| | No puzzle name - 9 rows          | |
| | Each needs its own name.         | |
| | See the rows · Don't import them | |
| +----------------------------------+ |
|                                      |
| [  Continue                       ]  |
| The rows you leave here are not      |
| added. You can come back to them.    |
+--------------------------------------+
```

- Per-group button is an outline-primary; the screen's primary is **Continue** (the groups are optional work).
  When every group is answered: "Nothing to do here." + **Continue**.
- Folded at the bottom: "Rows you chose not to import (6)" with **Import after all** per row (today's
  `left_out_title` / `take_in`).
- **[UI]** (messages and queries exist: `AnswerImportRows`, `GetImportRowsToFix`).

### 5.6 Step 3 - People in your file (with stage 6d)

**Purpose:** say who the people named in the file are, and who the partner was on sheets that only say "Pairs".

```
+--------------------------------------+
| (v)--(v)--[3]--( 4 )--( 5 )          |
| Step 3 of 5 · People                 |
|                                      |
| People in your file                  |
|                                      |
| #AB123 - 14 rows                     |
| v Jana Novakova (player)             |
|                                      |
| "Anna" - 38 rows                     |
| (o) A guest called Anna              |
| ( ) A player I know …                |
|                                      |
| "Tom" - 4 rows                       |
| (o) A guest called Tom               |
| ( ) A player I know …                |
|                                      |
| Sheet "500 piece Pairs" - 61 rows    |
| It looks like pair results, but      |
| names nobody. Who was your partner?  |
| [ Name ________________________ ]    |
| ( ) It differs - I'll say later      |
| ( ) These were solo                  |
|                                      |
| [  Continue                       ]  |
+--------------------------------------+
```

- Copy under the title: *"A guest is just a name on your result. A player gets the result on their profile too -
  they are told, once."*
- **Safe defaults:** a name is a guest (changes nobody else's profile); a `#code` is the player it belongs to and is
  shown, not asked; **the pair sheet has no default** - Continue with it empty leaves its 61 rows waiting and the
  step open ("One sheet looks like pairs - who was your partner?"). This is finding 8: the rows are not added as
  solo by silence.
- "A player I know …" opens 6d's suggestions (only players the importer is connected to).
- Passed by itself per §2. Whatever is chosen here is repeated in step 5 ("61 pair results with Anna (guest)").
- **Build:** 6d's screen placed in the flow - header, one **Continue**, the wording above. **[UI]** on top of 6d.

### 5.7 Step 4 - Puzzles (overview)

**Purpose:** say how many puzzles are matched, and split the rest into three piles with the cost of each.

```
+--------------------------------------+
| (v)--(v)--(v)--[4]--( 5 )            |
| Step 4 of 5 · Puzzles                |
|                                      |
| We matched 353 of your 786 puzzles   |
| [=========.............]             |
| 433 wait for you - in three piles.   |
| Do as many as you like.              |
|                                      |
| +----------------------------------+ |
| | Looks like a match            80 | |
| | Confirm a screen at a time.      | |
| | About 2 minutes.               > | |
| +----------------------------------+ |
| +----------------------------------+ |
| | Pick the right one           196 | |
| | One puzzle per screen, the ones  | |
| | with most results first.       > | |
| +----------------------------------+ |
| +----------------------------------+ |
| | Not in our database          157 | |
| | One answer for all of them.    > | |
| +----------------------------------+ |
|                                      |
| [  Start with "Looks like a match"]  |
|                                      |
| Or add what is ready now (471) -     |
| you can come back to the other 433   |
| any time.                            |
|                                      |
| > Matched (353) - see them           |
| > Matched like last time (12)        |
+--------------------------------------+
```

- Piles with nothing waiting are not shown. **Primary** = the first pile with something waiting, in the order
  above (cheapest first: 80 puzzles in four taps move the bar the most for the least work).
- "About 2 minutes" = 20 puzzles a screen, ~30 s a screen; shown only for "Looks like a match".
- Folded: "Matched (353) - see them" (today's Found list with **Wrong puzzle** per card) and "Matched like last
  time". Rows "already on your profile" move to step 5.
- While the search for similar names runs: "Still looking for 23 of them…" under the bar; the third pile's count
  has "…" and its bulk answer waits (§6.3).
- Done state: "All 786 puzzles matched." + **Continue**.
- **[UI]** over `GetImportPuzzleScreen::overview()`.

### 5.9 Step 5 - Check and add

**Purpose:** show in plain sentences what goes onto the profile and how the file was read, then one button.

```
+--------------------------------------+
| (v)--(v)--(v)--(v)--[5]              |
| Step 5 of 5 · Add                    |
|                                      |
| Ready to add                         |
|   212 results                        |
|   255 puzzles for your collection    |
|     4 puzzles for your wish list     |
| See all 471 and untick any >         |
|                                      |
| How we read your file                |
| "Solo 500": 151 solo results         |
| "500 piece Pairs": 61 pair results   |
|   with Anna (guest)                  |
| "Collection": 255 puzzles you own    |
| Dates: day first - 03/04/2023 is     |
|   3 April 2023                       |
| Times: minutes and seconds - 45:10   |
|   is 0:45:10                         |
| Something off?  Change >             |
|                                      |
| The first results, as they will      |
| look                                 |
| [img] Ocean Life · 500               |
|       0:45:10 · 3 Apr 2023 · solo    |
| (4 more)                             |
|                                      |
| > 2 quick questions before adding    |
|                                      |
| Not added this time                  |
|   395 rows of puzzles not matched    |
|       yet                 Match >    |
|    94 results with no date           |
|                           Give >     |
|    45 rows we could not read  Look > |
| You can add these later - an import  |
| can be added in parts.               |
|                                      |
| > What adding does                   |
|                                      |
| [  Add 471 to my profile          ]  |
| It runs in the background. You can   |
| undo it afterwards - one row, or     |
| all of it.                           |
+--------------------------------------+
```

1. **Ready to add** - three lines, right-aligned numbers, zeros left out; then the link to the list (§7).
   Muted below, only when not zero: "118 rows are on your profile already - not added twice." and "6 rows repeat
   another row - counted once. Show".
   Daily limit: *"We add up to 2,000 results a day. Today: 2,000. The other 1,240 can be added tomorrow - nothing
   is lost."*
2. **How we read your file** - one line per sheet that has ready rows: what its rows are **added as** (the sentence
   table of §5.4, with the people of step 3), then every file-wide reading with one of the player's own values as
   the example. **This block is the answer to finding 8**: nothing guessed is added without having been a line
   here. **Change** → step 2's summary.
   **[small]** `GetImportAddScreen::summary()`'s per-sheet line grows `rowMeaning`, counts per solo / pair / team
   and the people named; one example value per answered or assumed reading (from the mapping's stored
   `ImportQuestion::$examples`).
3. **The first results, as they will look** - today's five sample cards; the first open, "(4 more)" unfolds.
4. **Quick questions before adding** (§5.10) - only when there are some.
5. **Not added this time** - today's "Not ready yet", each line with a verb link. Calm, no warning colour.
6. **What adding does** (folded; today's `does` / `does_not`): *"Results appear on your profile and on each
   puzzle's leaderboard, like the ones you type. Your followers are not notified. Results keep the day you solved
   them - they do not show up as new activity."*
7. **Primary: "Add 471 to my profile"**; with the daily limit **"Add 2,000 of 3,240 now"**. Nothing ready:
   no button; *"Nothing is ready yet. Match your puzzles first."* + **Match your puzzles**.

States: adding paused by the team - today's calm sentence, no button. An Add running - **See how far it is**.

### 5.10 Adding, done, and the questions

**While it runs** (`batch.html.twig`): header at step 5; "Adding… 320 of 1,240" with the bar; *"You can leave this
page. We tell you in your notifications, and by e-mail, when it is done."* No button.

**Done:**

```
+--------------------------------------+
| (v)--(v)--(v)--(v)--(v)              |
| Added to your profile                |
|   212 results                        |
|   255 puzzles in your collection     |
|     4 puzzles on your wish list      |
|                                      |
| 31 rows wait for your answer         |
| +----------------------------------+ |
| | 25 rows say pair or team and     | |
| | name nobody.                     | |
| | [ Say who you solved with      ] | |
| +----------------------------------+ |
| +----------------------------------+ |
| | 6 times are much faster or       | |
| | slower than your others.         | |
| | [ Look at these times          ] | |
| +----------------------------------+ |
|                                      |
| [  See them on my profile         ]  |
|                                      |
| What was added - undo any row >      |
| 276 puzzles still to match >         |
| Statistics, XP and achievements      |
| follow within a few minutes.         |
| > Why some rows added nothing (46)   |
| > Undo this                          |
+--------------------------------------+
```

- **Primary:** when rows wait for an answer - the first question card's button is the primary (**Say who you solved
  with**), and "See them on my profile" is an outline button; with no question - **See them on my profile**.
- "Why some rows added nothing" folds today's `batch.how.*` lines (already owned, repeats, on the profile already).
- "Undo this" folds today's take-back offer: **Undo what was added just now** / **Undo everything this import
  added**.
- Links for what is left: "276 puzzles still to match", "1,240 results can be added tomorrow".

**Questions asked once per file.** Today a row can come back twice (unusual time, then "the file says it is on
MySpeedPuzzling"). Target:

| Question | Where it is asked | Group answer (exists) | Change |
|---|---|---|---|
| Pair / team rows naming nobody | **before** the Add: step 3 (§5.6) | "Who did you solve these with?" | with 6d the rows are settled in step 3; the after-Add card stays as the safety net **[UI]** |
| "The file says these are on MySpeedPuzzling already" | **before** the Add, step 5 "quick questions" | They are missing - add them / Don't import them | **[logic]** the engine's check `said_already_on_profile` run as a count in `summary()`, and `AnswerImportRowsGroup` taking rows that were not refused yet |
| Unusual times | before the Add for the first results (today's frame); the rest when added | These times are right - add all N | **[logic]** a **standing answer per import** (`data_import.standing_answers`: `{unusual_time: right, said_already: missing}` with the moment), read by `ImportRowAdder` so a row whose question the player already answered *for this file* is not sent back with it. The waiver stays per row with its fingerprint (written by the adder from the standing answer) - time verification is untouched |
| Possible duplicate (similar result, somebody else's result) | after the Add, one by one, with the other result beside it | none - and none proposed | unchanged: these are the cases where a bulk answer would make real duplicates |

Until the **[logic]** rows are built, the done page says the truth under the question cards: *"Some rows can have
one more question after this one."* **[UI]**

Wording of the two bulk cards (replacing "These times are right - add all 416"):

- **Unusual times:** title "416 times are much faster or slower than your results here"; today's summary lines;
  buttons **"Yes, these are my times - add them"** (primary) · "Look at them one by one" · "Don't import them".
- **Already on MySpeedPuzzling:** title "Your file says 441 results are on MySpeedPuzzling already - we did not
  find them"; buttons **"They are missing - add them"** (primary) · "Don't import them".

## 6. The bulk decisions for puzzles

**Why care:** a wrong match puts a result on the wrong puzzle's **public leaderboard**. So: nothing in "Looks like
a match" or "Pick the right one" is ever applied without the player's tap on a set that is on the screen; every
bulk action is one `DecideImportPuzzles` message (all or nothing, each puzzle with the version the screen showed -
a puzzle changed meanwhile refuses the whole tap and the screen shows how it stands); every match can be undone
("Wrong puzzle"), also after the Add.

### 6.1 "Looks like a match" (today: Probably - 80, 208 in the larger file)

**Purpose:** confirm a screen of likely matches with one tap after a look at the pictures.

The pile is split in two by **why** the puzzle is only "probably" (`how` on the candidate - shown today as the
"why" line):

| Part | Which | On the screen |
|---|---|---|
| **A - same puzzle, less proof** | same name and pieces and **the file has no brand** (`name`, brand missing); same barcode and the file has no piece count (`barcode`); same brand code (`brand_code`) | boxes **ticked on the screen**, one tap confirms the screen |
| **B - something differs** | "Another brand: …" (`name`, brand differs); the name without its brackets (`name_without_brackets`); a short name (`name_short`); a result that looks like one of yours (`own_result`, `own_result_loose`) | boxes **empty**; the player ticks the right ones |

A puzzle with another piece count is in neither - it is always single (the server refuses it in a group today).

```
+--------------------------------------+
| Step 4 of 5 · Puzzles                |
| Looks like a match · 1-20 of 54      |
| Same name and piece count. Untick    |
| any that are wrong.                  |
|                                      |
| +----------------+ +---------------+ |
| | [x]            | | [x]           | |
| |    [picture]   | |   [picture]   | |
| | Ocean Life     | | Old Town      | |
| | Trefl · 500    | | Schmidt · 1000| |
| | file: "ocean   | | file: "Old    | |
| |  life" 500     | |  town" 1000   | |
| +----------------+ +---------------+ |
| +----------------+ +---------------+ |
| | [ ]            | | [x]           | |
| |    [picture]   | |   [picture]   | |
| | …              | | …             | |
| +----------------+ +---------------+ |
|              (16 more)               |
|======================================|
| [  Yes, these 19 are right        ]  |
|  Not sure - show me the next 20      |
+--------------------------------------+
```

- **Tile** (2 columns at 360 px, 3 from 576 px): the catalogue picture (square, the existing imgproxy size), the
  catalogue name, brand · pieces, then muted *file: "…"* with what the file says. The whole tile is the checkbox's
  label (min. 44 px anyway). A tile with no picture shows the name large on grey. Tap-and-hold / the small "i" opens
  today's chooser for that puzzle ("It is something else").
- **Part A first**, 20 a screen. Sticky bar: **"Yes, these 19 are right"** (the number counts the ticked boxes,
  live; without JS: "Yes, the ticked ones are right") and the link "Not sure - show me the next 20" (saves
  nothing). After the tap: the next 20 with a green line "19 matched. 35 to go."
- **Part B** follows with its own heading - *"Have a closer look at these - something differs"* - each tile with
  its amber line ("Another brand: Ravensburger", "Your file says "Ocean (500)""). Boxes empty; the button reads
  **"Match the ticked ones (0)"** and is disabled at 0; second button **"None of these 20"** leaves them for
  "Pick the right one" later (saves nothing, next screen).
- End of the pile: "Nothing left here." + **Continue** (to the next pile or step 5).
- **What makes it safe:** (1) the boxes are a form on a screen the player sees - nothing is stored until their
  tap; (2) ticked-by-default only where name **and** piece count (or the barcode, or the brand's own code) are the
  same and nothing contradicts - part B is never ticked for them; (3) 20 a screen with pictures, not 50 with text;
  (4) another piece count is never in a group; (5) one message, all or nothing, version-checked; (6) undo per
  puzzle before and after the Add.
- **Build:** **[small]** `GetImportPuzzleScreen::page()` takes a part (`same` / `differs`) - a condition on the
  single weak candidate's `how` and brand flag - and the page size 20; `ImportPuzzleCard` already knows
  `canBeTickedInAGroup()`. `DecideImportPuzzlesController`'s `tick_all` honours a checkbox per card
  (`cards[id][tick]`) instead of taking every card. Template: `puzzle_group.html.twig` gets a grid variant +
  `_puzzle_tile.html.twig`. A 20-line Stimulus controller counts ticks on the button.

### 6.2 "Pick the right one" (today: Choose - 196, 319 in the larger file)

**Purpose:** one puzzle per screen, one tap per puzzle, in rounds of 20, the puzzles with the most rows first.

```
+--------------------------------------+
| Step 4 of 5 · Puzzles                |
| Pick the right one · 7 of 20         |
| [=======.............]               |
|                                      |
| In your file                         |
| "Sunset" · Trefl · 1000              |
| 6 results, in your collection        |
|                                      |
| Is it one of these?                  |
| +----------------------------------+ |
| | [picture]  Sunset over Prague    | |
| |            Trefl · 1000          | |
| +----------------------------------+ |
| +----------------------------------+ |
| | [picture]  Sunset                | |
| |            Trefl · 1500          | |
| |   ! Another piece count          | |
| +----------------------------------+ |
| +----------------------------------+ |
| | [picture]  Sunset Beach          | |
| |            Castorland · 1000     | |
| |   ! Another brand                | |
| +----------------------------------+ |
|                                      |
| [ None of these ]      [ Later > ]   |
| < Undo the last one                  |
+--------------------------------------+
```

- **A tap on a card is the answer**: it saves and shows the next puzzle (today: "This one", then the list). No
  card is highlighted, ordered-first-as-chosen or preselected; the order is the engine's (owned / solved first).
  A card with another piece count asks once more (today's confirm, kept).
- **None of these** unfolds three choices on the same screen: "Search our database" (today's search) · **"Keep it
  just for me"** (unlisted) · "Don't import it". **Later** = next puzzle, nothing saved.
- **"Undo the last one"** appears after every answer (takes the decision back - `undecided` - and returns to it).
- **Order: most rows first.** The first 20 puzzles of a collector's file settle far more than 20 rows. The round
  header says it: *"These 20 puzzles are 143 of your rows."* **[small]** `nextUndecided()` orders by the puzzle's
  row count, then name (today: its own order); the portion's row total is one count.
- **The rest screen** after 20 (and whenever the player stops):

```
| 20 done - 143 rows are ready.        |
| 176 puzzles left in this pile.       |
|                                      |
| [  Do 20 more                     ]  |
| Add what is ready now (614)          |
| Stop here - I'll come back           |
| > Keep the other 176 just for me     |
```

  The folded last line is the one bulk answer of this pile: **"Keep the other 176 just for me"** - *"They are kept
  with your unlisted puzzles: only you see them, and they do not count in statistics or rankings until matched.
  You can match each of them later."* It is offered folded, never as a button beside "Do 20 more".
- **What makes it safe:** a public match only ever comes from a tap on that puzzle's own picture; the only bulk
  answer keeps things **private** and can be matched later one by one; "Later" costs nothing.
- **Build: [UI]** for the screen (`puzzle_choose.html.twig`: card tap = submit, the folded "None of these", the
  rest screen from today's `portion_done` flash); **[small]** the order by rows, and the bulk "keep the rest" (the
  same server-side set as §6.3, for tier `choose`).

### 6.3 "Not in our database" (today: Not found - 157, 122)

**Purpose:** one answer for all of them, with a safe default, and a way to rescue single ones.

```
+--------------------------------------+
| Step 4 of 5 · Puzzles                |
| 157 puzzles are not in our database  |
| We looked for each by name, barcode  |
| and brand code.                      |
|                                      |
| What should happen with them?        |
| +----------------------------------+ |
| | (o) Keep them just for me        | |
| |  Their results and whether you   | |
| |  own them are kept on a page     | |
| |  only you see. They do not count | |
| |  in statistics or rankings until | |
| |  matched - we tell you when one  | |
| |  appears in the database.        | |
| +----------------------------------+ |
| +----------------------------------+ |
| | ( ) Don't import them            | |
| |  Their rows are left in the file.| |
| |  You can change this later.      | |
| +----------------------------------+ |
|                                      |
| [  Continue                       ]  |
|                                      |
| > See the 157 - and search for any   |
| > What is not kept                   |
+--------------------------------------+
```

- **Default: "Keep them just for me"** - selected on the screen, applied on **Continue**. One tap for 157 puzzles.
- "See the 157" (folded, 50 a page): the file's name · brand · pieces · rows, each with **Search** (today's chooser
  for that puzzle - for the few a player knows are in the database under another name). A puzzle answered there
  leaves the set.
- "What is not kept" (folded, honest): *"Notes and your own fields of these rows are not kept with an unlisted
  puzzle yet. A result without a date stays unlisted when the puzzle is matched later."*
- While the search still runs: the radio cards are there, the button reads **"Still looking for 23 of them…"**
  (disabled) and the secondary line of rule 4 is offered.
- After Continue: "157 puzzles kept just for you." → back to the overview (or step 5 when this was the last pile).
  The overview then shows a folded line "Kept just for you (157) - change".
- **What makes it safe:** the default makes nothing public and deletes nothing; it is reversible per puzzle
  ("change") and each unlisted puzzle can be matched later (and that can be undone for 30 days); the daily look
  suggests matches and never applies them.
- **Build: [small]** a controller action `all_in_pile` (`pile` = `not-found` / `choose`, `choice` = `unlisted` /
  `left_out`) that works the undecided puzzles of the pile out **on the server** (never ids in the form - 1,000 of
  them would not fit), in chunks of 200 `DecideImportPuzzles` messages, guarded by the pairing revision the screen
  showed (`live.revision`): another revision → nothing is applied, "This changed in the meantime". Over 1,000
  puzzles it runs on the `imports` queue with the live line. STATUS already lists "keep all not-found as unlisted
  in one go" as left out of 5b - this is it.

## 7. "What will be added" and "What this import added"

One list component, two modes. **This answers "can I skip single rows, also when already imported?": yes - untick
before the Add, undo after it.**

```
+--------------------------------------+
| Step 5 of 5 · Add                    |
| What will be added                   |
| [Results 212][Collection 255][Wish 4]|
| [ Search a puzzle____________ ] [Go] |
| Sheet: All v                         |
|                                      |
| "Solo 500" - 151 results             |
|                     Don't import any |
| [x] [img] Ocean Life · Trefl · 500   |
|          0:45:10 · 3 Apr 2023 · solo |
| [x] [img] Ocean Life · Trefl · 500   |
|          0:41:02 · 9 May 2023 · solo |
| [ ] [img] Old Town · Schmidt · 1000  |
|          2:10:00 · 2022 · solo       |
|          Not imported · Put back     |
| …                                    |
| < 1 2 3 4 5 >                        |
|======================================|
| 470 will be added                    |
| [  Done - back to the check       ]  |
+--------------------------------------+
```

**Before the Add** (new page `…/import/{id}/add/list`, route `data_import_add_list`):

- **Tabs** by what a row makes: Results · Collection · Wish list (a row that is both shows in Results with "+
  collection"). Counts on the tabs.
- **Search** by puzzle name (the catalogue's or the file's), a plain GET form; **Sheet** filter (a `<select>`,
  submits by itself with JS, a "Go" button without).
- **Grouped by sheet** (a heading per sheet with its count), within a sheet by puzzle name then date; 50 rows a
  page; the page and filters are the address.
- **A row:** checkbox · thumbnail (40 px) · puzzle · brand · pieces / time · date · solo-pair-team (+ "with Anna")
  · "first try". Tapping the text opens today's row page (edit a value).
- **Untick = "Don't import this row"** → `AnswerImportRows(rowIds: [id], leaveOut: true)`. With JS each tick is
  saved at once (a form in a Turbo Frame per row; the row turns muted with "Not imported · Put back"); without JS
  the page is one form and the bar's button is **Save this page**. Ticking again = `leaveOut: false`.
- **Per puzzle:** when a search narrows the list to one puzzle - "Don't import any of this puzzle (4 rows)" →
  `DecideImportPuzzles(left_out)`. **Per sheet:** "Don't import any" on the sheet heading → the sheet's kind is
  set to "not puzzles" (today's one-tap form), with a confirm naming the count: *"Don't import the 151 rows of
  "Solo 500"? You can change this in step 2."*
- **Sticky bar:** "470 will be added" (moves with each tick) and **Done - back to the check** (to step 5). The Add
  button itself stays on step 5 only - one place adds.
- Empty: "Nothing is ready to add yet." Search without a hit: "No row with that puzzle."
- **Build:** **[small]** `GetImportAddScreen::readyRows(importId, playerId, makes, sheetId, search, limit, offset)`
  over the engine's own `sqlReadyRows()` (so the list *is* what the Add takes) plus the rows the player left out
  (shown unticked in place, so a mistake is one tap to mend) - read only, the page's statements constant in size.
  Messages exist. Template `add_list.html.twig` + `_add_row.html.twig` (exists) with a checkbox.

**After the Add** (`added_rows.html.twig`, "What this import added"): the same rows, tabs, search and sheet filter;
instead of the checkbox each row has **Undo** (today's "Take back", its confirm page kept: what is removed, what
stays). Heading line: *"You can undo any row - it is removed from your profile and can be added again later."*
A selection mode ("Select") turns the Undo buttons into checkboxes with a sticky **"Undo the ticked (3)"**.
**[small]** search / sheet / makes filters on `GetImportAddScreen::added()`; "undo several" needs `UndoImportRows`
to take a list of row ids in one undo (today: a row, a batch or the import) - if it does not, it is the one
addition here; the single-row Undo is **[UI]**.

## 8. Vocabulary

English; every term is a plain noun or verb phrase that translates (no idiom, no word play). The six catalogues
change with the slice that changes the screen. "Unlisted puzzles" is a shipped page name (and in the guide): the
noun stays, the **action** is said in plain words.

| Today | Proposed | The sentence it appears in |
|---|---|---|
| pairing, paired | **matching, matched** | "We matched 353 of your 786 puzzles." |
| Found in the database | **Matched** | "Matched (353) - see them" |
| Probably | **Looks like a match** | "Looks like a match - 80. Confirm a screen at a time." |
| To choose / Choose | **Pick the right one** | "Pick the right one - 196. One puzzle per screen." |
| Not found | **Not in our database** | "157 puzzles are not in our database." |
| Decided as in your earlier import | **Matched like last time** | "Matched like last time (12)" |
| Keep it as an unlisted puzzle | **Keep it just for me** | "Keep them just for me - on a page only you see." |
| Tick all I see here (N) | **Yes, these N are right** | button under the picture grid |
| Not this one | **Wrong puzzle** | on a matched card: "Wrong puzzle" → it waits for you again |
| Yes, this is it / This one | (the card itself is the button) | - |
| Leave out / Left out | **Don't import / Not imported** | "Don't import them" · "Not imported · Put back" |
| Take it in again | **Put back** / **Import after all** | on a row / on a sheet |
| Skip (chooser) | **Later** | "Later >" |
| Need a look / To fix | **Rows we could not read** | "45 rows we could not read. One answer fixes every row of a group." |
| Rows ready | **Ready to add** | "471 ready to add" |
| Waiting for your answer | **Wait for your answer** (as a sentence) | "212 rows wait for your answer to one question." |
| Sheets left out | **Not puzzles - not imported** | "Notes, Stats + 6 more: not puzzles - not imported." |
| Sheets and columns | **How we read your file** | menu item; heading of step 2's summary |
| Look at the puzzles | **Match your puzzles** | primary button |
| Review and add / Review before adding | **Check and add** | step 5's name and button |
| Already yours | **On your profile already** | "118 rows are on your profile already - not added twice." |
| Rows that follow their twin row / equal rows | **Rows that repeat another row** | "6 rows repeat another row - counted once." |
| Take back | **Undo** | "Undo" on a row; "Undo everything this import added" |
| this adding | **what was added on 15 November, 14:02** | "Undo what was added on 15 November, 14:02" |
| Could not be added | **Wait for your answer** / **Not added** | "31 rows wait for your answer" (a question) · "3 rows were not added" (a rule, with its sentence) |
| Rows whose puzzle waits for your choice | **Rows of puzzles not matched yet** | "395 rows of puzzles not matched yet" |
| Results without a date | **Results with no date** | "94 results with no date - give them a day, a month or a year." |
| Your saved layouts | **Column set-ups we remember** | menu item |
| State: Read / Waiting for you (badges) | (no badge - the status sentence of §5.1) | - |
| Add N to my profile | **Add N to my profile** (kept) | N is always explained by the three lines above it |
| Unusual times | **Unusual times** (kept) | "6 times are much faster or slower than your others." |
| guest | **guest** (kept, explained once) | "A guest is just a name on your result." |

Never on a screen: pairing, tier, candidate, mapping, layout, batch, parse, row state, "twin", "error", "invalid".

## 9. The returning player

- **Address:** every notification, e-mail and "Continue" lands on **"Where you are"** (§5.3) - never deep inside a
  step. Exception: the "added" notification's button goes to the done page of that Add (§5.10).
- **Notification / e-mail when an Add is done** (exists; copy): *"Your import of my-puzzles.xlsx is done: 471
  added."* + when rows wait: *"31 rows wait for your answer."* Button **Open**.
- **Half-done import, days later:** the Imports home card says the one sentence of §5.1 with **Continue**. Nothing
  else reminds - no nagging. The existing rule stays visible in the `...` menu's "About this file": *"kept until
  14 December 2026"*; from 7 days before that date the card adds *"The file is kept until 14 December."*
- **The rest tomorrow (2,000 a day):** the done page and the card say *"1,240 more can be added tomorrow"*; the
  player presses Add again (as the guide says). A reminder notification the next day would be **[logic]** (a daily
  command) - proposed, not required; nothing on a screen promises it.
- **A file whose rows are gone (after 30 days) or a finished import:** "Where you are" read-only, as today's
  finished card.

## 10. Build plan

In order of value. Each slice ships alone, all six locales with it, tried at 360 px in a real browser.

| # | Slice | Size | Templates / controllers | Server |
|---|---|---|---|---|
| 1 | **The spine:** flow header on every import screen; "Where you are" replaces the import's page; one primary button and the "add what is ready now" line on the columns, puzzles, to-fix and review screens; **Continue** at the end of each; the vocabulary of §8 in the texts those screens already have | **M** | new `_flow_header.html.twig`, `_flow_footer.html.twig`; `detail.html.twig` rewritten; `columns`, `sheet`, `to_fix`, `puzzles`, `puzzle_group`, `puzzle_choose`, `add`, `batch`, `added_rows`, `not_added_rows` get the header and their one primary; `_ready_card`, `_pairing_summary`, `_not_ready` leave the detail page; `ImportDetailController` | `Services/DataImport/ImportFlow` (pure, unit-tested table: inputs → five step states, primary, sentence). No schema. **[UI]** |
| 2 | **Step 5 says everything:** "How we read your file" per sheet with added-as and examples; "Ready to add" as lines; "Not added this time" with verbs; "What will be added" list with untick per row / puzzle / sheet | **M** | `add.html.twig`; new `add_list.html.twig`, `ImportAddListController`; `_add_row.html.twig` | **[small]** `summary()` per-sheet meaning / type / people / example; `readyRows()` |
| 3 | **Puzzles in bulk:** overview as three piles; "Not in our database" one answer; picture grid with parts A / B; chooser: card tap, most rows first, rest screen, "keep the rest" | **L** (ship as 3a not-in-database **S**, 3b grid **M**, 3c chooser **M**) | `puzzles.html.twig`, `puzzle_group.html.twig` + `_puzzle_tile.html.twig`, `puzzle_choose.html.twig`; `DecideImportPuzzlesController`, `NextImportPuzzleController`; `data_import_ticks_controller.js` | **[small]** `page()` part + size; `tick_all` per checkbox; `all_in_pile` action; `nextUndecided()` order |
| 4 | **Step 2 folded:** questions one per screen across sheets; the summary with one sentence per sheet; sheets not imported folded; step passed by itself when all is certain | **S** | `columns.html.twig`, `sheet.html.twig` (unchanged behind "Change"), `ImportColumnsController` | **[small]** `DataImportSheetOverview` meaning / type / fields |
| 5 | **After the Add:** done page of §5.10; the added list with search, filters, "Undo the ticked"; Imports home card with one sentence + one button | **M** | `batch.html.twig`, `_group_cards.html.twig`, `added_rows.html.twig`, `index.html.twig`, `_import_card.html.twig` | **[small]** filters on `added()`; counts on `addedByImports()`; undo of several rows if missing |
| 6 | **Questions once per file:** "already on MySpeedPuzzling" asked before the Add; a standing answer per import | **M** | `add.html.twig`, `group_answer.html.twig` | **[logic]** count of `said_already_on_profile` before the Add; `AnswerImportRowsGroup` for not-yet-refused rows; `data_import.standing_answers` read by `ImportRowAdder` (one generated migration) |
| 7 | **People in the flow** (when 6d is live): step 3 placement and wording, its lines in steps 2 and 5 | **S** | 6d's templates | - |
| 8 | **Explanations in place** (§12): the `_explain.html.twig` partial and the texts of the table, placed on the screens as each slice touches them; the ones on untouched screens (unlisted puzzles page, undo confirm) in this slice | **S** (the partial + the unlisted / guest / undo ones ship with slice 1) | new `data_import/_explain.html.twig`; every import template; `unlisted_puzzles/index.html.twig` | - **[UI]** |
| 9 | **"How the import works"** (§13): the guide of #366 rebuilt around one sample spreadsheet followed through the five steps, examples as real HTML, "Questions people ask", and the "How this step works" link in the flow's footer | **M** (texts in six languages are most of it) | `guide.html.twig`, new `data_import/guide/_sample_*.html.twig`; `_flow_footer.html.twig`; `ImportGuideControllerTest::ANCHORS` | - **[UI]**; optional **[logic]** daily reminder "more can be added today" stays separate |

**Slice 1 first** because it removes findings 1, 2 and 5 and the "where am I / what now" question on every screen
without touching a query or a message: the same screens, in an order, with one button each. Slices 2 and 3a are
next - they answer the owner's two sentences directly ("skip rows", "nobody makes 400 decisions").

Guards to add with the slices: a test that every import template includes the flow header and renders exactly one
`.btn-primary` outside list rows (slice 1); `ImportFlowTest` (the table of §2); the add list's rows equal what the
Add then queues (slice 2, like today's review-numbers test); part A never contains a brand-differs or
other-piece-count card (slice 3b).

## 11. Testing with one real spreadsheet user

One person who keeps their own spreadsheet, on **their own phone**, with their own file, signed in on the sandbox.
No introduction beyond "this brings your spreadsheet in". Do not help; ask them to think aloud.

| # | Task | Watch for |
|---|---|---|
| 1 | "Bring your file in and tell me what it found." | Do they find the file button without scrolling? Do they wait on the reading screen or leave? After it, can they say in their own words what is ready and what waits - without reading a number aloud as a label ("rows ready 747")? |
| 2 | "Some puzzles need you. Do what feels reasonable for five minutes." | Which pile do they open first? In the picture grid: do they **look** at the tiles before the tap (scroll speed, an untick), or tap blindly - if blindly, 20 a screen is too many or the tiles too small. In "Pick the right one": do they understand a card tap is the answer? Do they ever fear they must finish all 196? |
| 3 | "There is a result in here you would rather not have on your profile. Keep it out." | Do they find "See all 471 and untick any"? Do they trust that an unticked row is not added (do they look for a Save)? |
| 4 | "Add it. Then tell me what happened to the sheet with your pair results." | Before the tap: did they read "How we read your file"? Can they say whether the pair sheet went in as pairs and with whom **before** pressing Add? After: do they understand a question card as a question, not as a failure? |
| 5 | "One result that was added is wrong. Get it off your profile. Then: you are done for today - what do you do, and how would you go on tomorrow?" | Do they find Undo for one row without undoing everything? Do they just leave (right) or hunt for a Save / Finish button? Next day, from the site's home: can they find the import and its one button? |

Pass = tasks 1, 3, 4, 5 without help and without the words "I don't know what this means"; in task 2 at least one
whole pile done or consciously left. Note every word they use for our terms - their word wins over §8.

## 12. Explanations in place

The owner, 2026-11-15: *"if there are any words that needs explaining add small font with info icon explaining
('unlisted' is great example)"*. Plain words (§8) come first; where a word or a consequence still is not
self-evident, it is explained **at the place it is met**.

### The pattern

One partial, `data_import/_explain.html.twig` (`text`, optional `more`, optional `guide_anchor`). No tooltip, no
hover, no popover - phones.

```
| (i) Only you see these. They do not  |
|     count until matched with a       |
|     puzzle in our database.          |
```

- **Always visible** by default: `<p class="fs-sm text-muted d-flex gap-2"><i class="bi bi-info-circle"
  aria-hidden="true"></i><span>…</span></p>`, directly under the word, the choice or the button it explains.
- **Tap to open** only where the same explanation would repeat on one screen (rows of a list) or is longer than
  two sentences: `<details class="fs-sm text-muted"><summary><i class="bi bi-info-circle"></i> What does this
  mean?</summary>…</details>` - no script, 44 px summary.
- **Tone:** at most two short sentences, what it *is* and what it *means for you*, in that order; "you / your";
  never a rule number, never "note that"; no promise of what is not built. A third sentence only as a link:
  "How this works" → the guide's section (§13).
- **Once per screen** per term; never under a primary button's own label (the button's hint line is the place).
- Keys `data_import.explain.<term>` in all six locales; a term explained the same everywhere uses one key.

### The texts

| Term / consequence | Exact text | Where it appears |
|---|---|---|
| **Unlisted puzzle** ("Keep it just for me") | "A puzzle we do not have in our database yet. Only you see it, with its results and whether you own it - nothing is lost." + more: "It does not count in your statistics or rankings until it is matched with a puzzle in our database. We tell you when one appears, and you confirm it." | under the "Keep them just for me" choice (§6.3) - there it is the choice's own text; under "Keep it just for me" in the chooser (§6.2); under "Keep the other 176 just for me"; on step 5 beside "34 rows kept just for you"; top of the Unlisted puzzles page |
| **Matched** | "We found this puzzle in our database by its name, pieces and brand - or its barcode. Tap "Wrong puzzle" on any that is not yours." | top of "Matched (353)" |
| **Looks like a match** (today: Probably) | "One puzzle in our database fits, but something is missing to be sure - usually the brand. Nothing is matched until you say so." | under the pile's title on the overview (§5.7) and on the grid (§6.1) |
| **Pick the right one** | "Several puzzles fit, or only the name is similar. A result goes on the puzzle's public leaderboard, so we never pick for you." | under the pile's title (§5.7), tap-to-open on the chooser |
| **Not in our database** | "We looked by name, barcode and brand code and found nothing that fits." | under the pile's title; headline of §6.3 |
| **Another brand / another piece count** | "The name fits, but your file says another brand (or piece count). Editions differ - have a closer look at the picture." | tap-to-open on a tile / card with the amber line |
| **On your profile already** | "These results are on MySpeedPuzzling already - the same puzzle, day and time. They are not added a second time." | step 5 under the muted line; done page under "Why some rows added nothing" |
| **Rows that repeat another row** | "Your file has the same puzzle, day and time on several rows. We count them as one result - say so if they are separate solves." | step 5, tap-to-open beside "6 rows repeat another row" |
| **Rows we could not read** (today: need a look) | "Something in these rows can be read in more than one way, or not at all. They are not added until you say what it should be - the rest of your file is not held up." | top of §5.5; "Where you are" step 2 line (tap-to-open) |
| **Wait for your answer** (a question's rows) | "These rows depend on one question about your file. Answer it once and they are all read." | question screen under "This decides 212 rows" |
| **Don't import** | "The rows stay in your file here and are not added. You can put them back at any time." | first "Don't import" of a screen (§5.5, §7) |
| **Guest** | "A guest is just a name on your result - nobody's profile changes." | step 3 under the title; step 5 beside "with Anna (guest)" (tap-to-open) |
| **A player as your partner** | "The result also appears on their profile, as a pair or team result with you. They are told once, and can remove themselves." | step 3 under "A player I know …" when it is chosen |
| **A `#code` in the file** | "This is a MySpeedPuzzling player code, so we know exactly who it is." | step 3 beside a code that became a player |
| **A sheet that looks like pairs** | "The sheet's title says pairs, but its rows name nobody. A pair result needs a partner - else it would be counted as a solo result, on another leaderboard." | step 3 under the sheet's question |
| **A result with only a month or a year** | "Your file gives no exact day. The result is added as "March 2021" - it counts everywhere except in things that need a day, like the calendar and streaks." | step 5 "How we read your file" when such rows are ready; tap-to-open on such a row in the list (§7) |
| **Results with no date** | "A result needs at least a year to be added. Give these a day, a month or a year - or they stay here." | step 5 "Not added this time" |
| **Unusual time** | "Much faster or slower than your other results suggest for this puzzle. We ask so a typing slip does not end up on a leaderboard - if it is right, say so and it is added." | the pre-Add frame (§5.9) and both question cards (§5.10) |
| **The file says "already on MySpeedPuzzling"** | "A column of your file marks these results as already logged here, and we did not find them on your profile." | the question card |
| **Possible duplicate** | "Your profile has a result of this puzzle that looks like this row - the same time on another day, or the same day with another time. Add it only if it is another solve." | each such row after the Add |
| **The daily limit** | "We add up to 2,000 results a day from spreadsheets, so that leaderboards and statistics keep up. The rest is ready tomorrow - press Add again then. Nothing is lost." | step 5 when it bites; done page; Imports home card (first sentence left out) |
| **Add / what an imported result is** | "An imported result counts like one you type: on your profile and - when it has a time - on the puzzle's leaderboard. It keeps the day you solved it, so it does not show up as new activity, and your followers are not notified." | step 5, folded "What adding does"; first open by default on a player's first import |
| **No photo** | "Imported results have no photo. You can add one to any result afterwards." | step 5, inside "What adding does" |
| **Undo** (today: take back) | "Undo removes from your profile what this import added - one row, one adding, or all of it. A result you changed afterwards is kept unless you say otherwise. Nobody is notified." | under the Add button (first sentence only); top of "What this import added"; the undo confirm page |
| **Wrong puzzle** | "The match is taken off and the puzzle waits for you again. We remember it for your next file too." | first matched card of a list (tap-to-open) |
| **I'm done with this import** | "Nothing more is added from this file, and it makes room for another one. What it added stays on your profile and can still be undone. You can go on with it later while the file is kept." | in the `...` menu under the item; bottom of "Where you are" |
| **Discard this import** | "The file and everything read from it are deleted. Nothing on your profile changes - nothing was added yet." | in the `...` menu under the item |
| **Why an import that added something can not be discarded** | "It stays in your list so the same rows are never added twice and you can undo them." | "Where you are" after an Add, where Discard used to be |
| **What happens to the file** | "Only you see the file. We keep it for 30 days, then it is removed - what was added stays on your profile." | step 1 under the file button; "About this file" in the menu |
| **Google sheet sharing** | "The sheet only has to be shared while we fetch it. It is here now - you can switch its sharing off again." | "Where you are", once, after a Google link was read |
| **3 imports in progress** | "Up to three spreadsheets can be in progress at a time. Finish or discard one to bring another." | Imports home instead of the button |
| **Column set-ups we remember** | "When you confirm how a sheet is read, we remember it. Your next file with the same headers is read the same way by itself." | the menu's page; step 2 summary when one was used ("Read like last time") |
| **Never twice** | "Bringing the same file again adds nothing new - only rows that are not on your profile yet." | step 1, tap-to-open "Brought this file before?"; flash when the very same file arrives |

Where a term is the screen's own headline choice (the two radio cards of §6.3, the step 3 options), the
explanation *is* the choice's text and no second line is added.

## 13. "How the import works" - the guide page

The owner: *"prepare 'How import works' webpage convenient with examples or screenshots it would be awesome"*.
Stage 8's branch (#366, held) already has the public guide `import_guide` (`/en/import-your-spreadsheet`, six
locales with own addresses, indexable, nine text sections, no pictures). This redesigns its **content**; address,
canonical / hreflang, sitemap entry, the start button's behaviour and "promises only what is built" stay.

**Title:** "How the import works" (the `<h1>`; the `<title>` keeps "Import your spreadsheet" for search).
**Lead:** *"Have your results or your collection in a spreadsheet? Bring the file - we read it, you check it, and
it is on your profile. Here is one small spreadsheet, followed all the way."*

### One sample spreadsheet, followed through five steps

Invented puzzles and people only (no real catalogue names, no real players). The same six rows in every locale
(names translated where they are words):

| Puzzle | Brand | Pieces | Time | Date | With |
|---|---|---|---|---|---|
| Harbour at Dawn | Pinewood | 500 | 0:47:12 | 14/03/2024 | |
| Autumn Market | Pinewood | 1000 | 1h 05 | 02/11/2023 | |
| Mountain Lake | Larkspur | 500 | 1:58:40 | March 2021 | |
| Night Train | Larkspur | 1000 | 2:31:05 | 20/01/2024 | Anna |
| Grandma's Kitchen | (none) | 300 | 0:36:20 | 05/05/2024 | |
| Lighthouse No. 7 | Hand-cut, own | 250 | 0:52:00 | 09/06/2024 | |

Each row is there for a reason: a clean row; a time we must ask about; a month-only date; a pair; a puzzle whose
brand is missing ("looks like a match"); a puzzle we do not have.

The page, top to bottom (each step an `<h2>` with its anchor; on a phone one column, each example a card):

| Section (anchor) | What it says | The example shown (real HTML) |
|---|---|---|
| top | title, lead, **Import a spreadsheet** (start button), "Free for everyone." | the sample as a small spreadsheet-looking table (grey header row, row numbers, horizontally scrollable inside its card) |
| `#step-file` · 1 Your file | "Choose the file or paste a Google Sheets link. Excel, CSV, TSV - any layout, any column names, up to 25 MB." | the flow header with step 1, and the reading line "Rows read: 6" |
| `#step-check` · 2 Check | "We work out what each column is. We ask only what we can not know." Then: "Row 2 says "1h 05" - that reads only one way, so we do not ask. A column like "3:26" could be hours or minutes: then we show your own values read each way." | the question card of §5.4 with "3:26 → 3:26:00 / 0:03:26"; the one-sentence sheet summary "6 results - solo, or with the person named"; the month-only line "March 2021 → added as March 2021" |
| `#step-people` · 3 People | "Row 4 names Anna. She is added as a guest - just a name on your result - unless you say she is a player you know." | the People card of §5.6 with "Anna - 1 row: (o) A guest called Anna" + the §12 guest / player texts |
| `#step-puzzles` · 4 Puzzles | "We look each puzzle up in our database of over 40,000." Then the four outcomes on the sample: rows 1-4 **matched**; row 5 **looks like a match** (no brand in the file) - you confirm; row 6 **not in our database** - kept just for you. "A result goes on a public leaderboard, so we never guess between two puzzles for you." | three real components: a matched card, one picture tile with its tick (§6.1), the "Keep it just for me" choice (§6.3) with the unlisted explanation |
| `#step-add` · 5 Check and add | "Before anything is added you see all of it - and can untick any row." | the step 5 block for the sample: "5 results ready · 1 kept just for you", "How we read your file", three rows of the list (§7) with one unticked, the button **Add 5 to my profile** (not clickable, `aria-disabled`, labelled "Example") |
| `#on-your-profile` | "What ends up on your profile" - the sample's outcome as a table: row 1 → a result on Harbour at Dawn, on its leaderboard; row 2 → 1:05:00; row 3 → "March 2021", not in calendar and streaks; row 4 → a pair result with Anna (guest); row 5 → on the puzzle you confirmed; row 6 → only you see it, until matched | a result row rendered with the profile's own result partial, twice (solo, pair) |
| `#take-back` (kept anchor) | "Changed your mind? Undo one row, one adding or everything. A result you edited afterwards is kept." | one added row with its **Undo** button |
| `#questions` | **Questions people ask** (below) | `<details>` per question, all open for crawlers without JS = plain `<details>`; FAQ structured data through `json_ld` |
| `#what`, `#your-file`, `#never-twice`, `#imported-result`, `#unlisted`, `#not-yet` (kept anchors) | the existing sections, shortened to what the steps did not say; `#not-yet` unchanged ("Not yet") | - |
| `#start` | the button again | - |

**Questions people ask** (exact questions; answers at most three sentences, from the existing sections):

1. **Will it add things twice?** - "No. Bring the same file again and only rows that are not on your profile yet are added. A result you typed yourself with the same day and time is recognised too."
2. **Can I undo it?** - "Yes - one row, one adding, or everything an import added. You see first what goes. A result you changed afterwards is kept unless you say otherwise."
3. **Who sees my file?** - "Only you. We keep it for 30 days, then it is removed; what you added stays on your profile."
4. **What about puzzles you don't have?** - "They are kept on a page only you see, with their results. They do not count in statistics or rankings until matched with a puzzle in our database - we tell you when one appears."
5. **My partner is on MySpeedPuzzling - do they get the result too?** - "If your file has their player code, yes. A plain name is a guest unless you say who it is; then they are told once and the result is on their profile too." *(ships with stage 6d; until then: "Partners are added as guests.")*
6. **My columns are in my own language and order - does that matter?** - "No. We recognise headers in six languages and ask when unsure. Next time a file with the same headers is read the same way by itself."
7. **I only know the month or the year of some results.** - "They are added as "March 2021" or "2021". A result with no date at all waits until you give it one."
8. **Do my followers get hundreds of notifications?** - "No. Nobody is notified, and results keep the day you solved them - they do not appear as new activity."
9. **I have thousands of results.** - "Up to 2,000 a day are added; the rest is ready the next day - press Add again. You can also add in parts and come back any time."
10. **Do I have to finish in one go?** - "No. Everything is saved as you go. Leave and come back - the import waits for you while the file is kept."
11. **Will a time that looks too fast be refused?** - "It is asked about, not refused. Say it is right and it is added; like every result it can be looked at by the moderators."

### Examples as real HTML, not screenshots (recommendation)

**Recommended: every example is rendered HTML from the flow's own partials**, fed with a fixed sample
(`ImportGuideSample`, a PHP value built in `ImportGuideController` - still no query, the page stays as cheap as
today). Reasons, weighed against the owner's earlier "no screenshots - they rot" for Getting started:

- true in all six languages by construction (the texts are the screens' own keys - a renamed button renames itself
  in the guide);
- can not go stale silently: a partial that changes changes the guide; a test renders the guide in six locales and
  pins that each example's root (`data-testid="guide-example-…"`) is there;
- text stays readable, zoomable, selectable and indexable; no image weight on a phone; dark-mode safe;
- pictures of invented puzzles: six small illustrations in the site's illustration style (`public/img/import-guide/
  sample-1…6.webp`, generated once, the same in every locale) - they are props, not screenshots.

Rules for the examples: inside `<figure class="guide-example">` with an "Example" badge in its top right corner; not
interactive (no forms, controls, links, ids or Turbo frames - pinned by a test; **not `inert`** since 2026-11-15: an
inert figure can not be scrolled, and a reader could not scroll the sample table on a phone); never the player's own data; the
partials get plain arrays, so the guide does not pull the flow's queries.

**Where a real screenshot would still be worth it - and the recommendation is: none in the page, one outside it.**
The only place an image beats HTML is where HTML can not be shown: the link preview (`og:image`) and a possible
newsletter / social post. For that one image per locale: a Panther script `tests/Panther/ImportGuideShotTest`
(opt-in, not in CI's suite) opens `#step-puzzles` of the guide at 390 × 844 in each locale on a seeded sandbox and
writes `public/img/import-guide/og.<locale>.<hash>.webp`; it is re-run by hand when slice 3's screens change (a
line in the release checklist of that slice). A changed picture gets a new file name (the service worker's rule).
Screenshots **of a spreadsheet app** (where "Share → Anyone with the link" is in Google Sheets) are not ours to
keep current - the three words of the path stay text.

### Links from the flow

`_flow_footer.html.twig` (every screen, under "Everything is saved as you go."): **"How this step works"** with the
info icon → the step's anchor (`#step-file` … `#step-add`; "Where you are" and Imports home → the top), opening in
the same tab with `?return=` back to the screen (the site's return-url convention), so "Back to your import" is
the guide's first line for a player who came from the flow. §12's "How this works" links use the same anchors
(`#unlisted`, `#take-back`, `#imported-result`, `#never-twice`). All anchors join
`ImportGuideControllerTest::ANCHORS`.

## As built - slice 1 (the spine)

What shipped with slice 1, and where it differs from the sections above - each with its reason. Slices 2-9 are not
started; everything below is **[UI]**: no query, message, handler or route changed, no migration.

**Built**

- `Services/DataImport/ImportFlow` (a value, not a service) with `Results/ImportFlowStep`, `ImportFlowText`,
  `ImportFlowAction`: the five step states (`done` / `open` / `working` / `ahead`), one status sentence a line, the
  ONE primary action, the "add what is ready now" line, what may be finished or discarded. Table-tested in
  `ImportFlowTest`. "Where you are" runs the same five statements the import's page ran before
  (`ImportFlowScreensTest`, measured against `main`).
- `_flow_header.html.twig` on all 19 screens of an import (instead of the breadcrumbs), `_flow_footer.html.twig` at
  their end, `_flow_text.html.twig` (numbers and dates formatted per language), `_explain.html.twig`.
- `detail.html.twig` is "Where you are". The State / Size / Uploaded card is the menu's "About this file"; the
  sheets table (`_sheets_table.html.twig`) is folded at the end of "How we read your file" - and at the bottom of a
  **finished** import's page, whose sheets can not be opened any more. `_ready_card` and `_pairing_summary` are gone.
- One `.btn-primary` a screen; every other action is an outline button or a link. The chooser has none: a tap on a
  puzzle's card is its answer (§6.2).
- §8's vocabulary in the texts these screens have, six locales (values changed, keys kept; 18 keys nothing renders
  any more were removed). Explanations in place for unlisted puzzles, guest and undo (+ finish, discard, "why it
  stays", "what happens to the file", which the new page needed).

**Differences from the specification**

| Where | As built | Why |
|---|---|---|
| §2 rule 3, the addresses `…/check`, `/people`, `/add` | The existing routes stay. **Continue** is `data_import_detail?after=<screen>` (`check`, `rows`, `people`, `puzzles`): the import's page answers with a redirect to the next screen that has something waiting (`ImportFlow::nextAfter()`), never back to the screen it came from, and to step 5 when nothing waits. | "Same routes" - and it lets every screen have a true Continue without one statement more: the target is worked out where the data is loaded anyway. Nothing is stored. |
| §3 step dots on screens inside a step | Only "Where you are" knows what is passed and what waits. On the other screens the dots show step 1 as done and the screen's own step as current; the rest are plain numbers (links), claiming nothing. | Those pages do not load the sheets / matching / add summaries. Loading them there would be new statements on every screen. |
| §3 menu on screens inside a step | "Where you are", "How we read your file", "Column set-ups we remember", "About this file". Finish / Discard / Undo-everything / "What this import added" are in the menu of "Where you are" only (and at its bottom). | They depend on what the import added, which only that page loads. |
| §5.3 step 3 "People" on "Where you are" | The line reads "Names in your file are added as guests unless you say who they are." and is never the primary action. `ImportFlow` takes the people's counts (`GetImportPeople::counts()`) and then says "Nobody else is named" / "Tell us who N people are" / "People in your file: N…" and makes "Say who they are" the primary - tested, but **no page passes them yet**. | The import's page does not count the people; counting them is one statement more on it, which this slice was not to add. One line in `ImportDetailController` switches it on. |
| §5.6 the pair sheet's question in step 3 | As 6d built it: a question of the sheet, so it counts among step 2's questions ("N questions about your file"). | Moving it is a change of 6d's screen, not of the spine. |
| §2 "no column under Please check these" | Step 2 is passed when no sheet asks, no row could not be read, no sheet failed and nothing is being worked out. Uncertain columns do not keep it open. | The sheets' overview does not say whether a sheet has such a column. |
| §5.3 step 4 "276 wait for you" | "N of M matched." + "R rows wait for you to match their puzzle." (rows, not puzzles); the step is open while rows wait for a puzzle. | The matching summary counts kept-just-for-me puzzles together with the not-found ones, so "puzzles that wait" can not be told from it; the add summary's rows can. |
| §5.3 primary "Answer 2 questions" after an Add | "Answer for N rows that wait" → the list of those rows. | The number of questions is not known on the page - the number of rows is. |
| §5.4 the one-question-a-screen sequence and the one-sentence-a-sheet summary | Not built (slice 4). The screen got its header, the counters as one number a line, one primary ("Answer the question" while a sheet asks - with "Continue - I'll answer later" as a link - else "Looks right - continue"). | Slice 4. |
| §5.10 the done page's primary | "See them on my profile" (or Continue); the group cards' buttons are outline. `not_added_times`' "Yes, these are my times" is outline too - it is a frame inside those pages. | The done page of §5.10 is slice 5; one primary a screen holds already. |
| Undo's confirm button | `btn-primary` (it was `btn-danger`). | One primary a screen; an Undo can be added again. |
| §5.2 "Bring your file", §5.1 Imports home, `places_taken`, `layouts` | Untouched (no flow header). | Not screens of one import; §5.1 is slice 5, §5.2 is not in slice 1. |
| §13 "How this step works" | No link in the footer. | The guide's route is not on `main`. |
| My data, a time that needs a word | The **cell** now says "Please check the time - if it is right, open the row and confirm it there." (`my_data.edit.time_check_in_row`); the row's sheet and the add-result form keep "…confirm it below". | A table cell has nothing below it; the add-result form's text is not touched. |

## As built - slice 2 (step 5 says everything; "What will be added")

What shipped with slice 2, and where it differs from §5.9 and §7 - each with its reason. No table, no migration:
`ImportRowAdder`, `ImportResultQuestions`, the writer, the batches and Undo are untouched. One rule of "ready" was
tightened (a sheet must be settled - below); apart from it an "Add" adds exactly what it added before.

**Built**

- **"Check and add"** (`add.html.twig`, `ImportAddReviewController`) in the order of §5.9: "Ready to add" as one
  number a line, zeros left out, with "See all N and untick any" → the list; the muted lines for rows on the profile
  already and rows that repeat another row; the daily limit's line; **"How we read your file"** - per sheet what its
  rows are *added as* (solo / pair / team results **with whom, each a guest or a player**, results whose puzzle also
  goes to the collection, puzzles owned, wishes), every reading the sheet's options hold (date order, short dates,
  the year of dates without one, how times are read - whoever settled it: the rows, a header, a known export or the
  player), the results that know only a month or a year, and **one row of the sheet as the example** with what its
  time and date cells say in the file beside what it is added as; where owned puzzles go; "Something off? Change" →
  step 2. The first results (one open, the rest unfold), the unusual-times frame, the rows that repeat, the rows on
  the profile already keep their behaviour. "Not added this time" with a verb a line (Match / Look / Check / Answer).
  "What adding does" folded - open until the import's first Add. ONE primary: "Add N to my profile".
- **`GetImportAddScreen::summary($forReview)`** **[small]**: the per-sheet line is `Results\ImportAddSheetLine` -
  sheet id and mapping version, counts per solo / pair / team, owned, both, wishes, month-or-year-only dates, the
  people named, the readings (`Services\DataImport\ImportSheetReadings`, read from `data_import_sheet.options`), the
  example row with its time and date cells. Still one statement. `GetImportPeople::whoAre()` says who the named
  people are (one statement, only when a sheet names somebody).
- **"What will be added"** - `data_import_add_list` (`GET …/import/{id}/add/list`, `ImportAddListController`,
  `add_list.html.twig`): `GetImportAddScreen::readyRows()` is `judged.taken` on top of
  `DataImportRowRepository::sqlReadyRows()` - the very CTEs "Add" queues with, never a second definition
  (`ImportAddListTest::testTheListIsExactlyWhatAnAddThenQueues()` compares the list with the batch's rows). Fifty a
  page in SQL, under each sheet's name; tabs by what a row makes (with counts), a GET search (the player's own
  text - the only thing of a file an address holds; the catalogue's names through `PuzzleTextSearch`, the file's
  name as typed), a sheet filter; each row with picture, puzzle, time, date (`result_date()`), solo / pair / team
  and people, and what it would *not* add ("On your profile already", "Repeats another row", "Kept just for you").
  The sticky bar says what an "Add" would add and leads back to the check - the Add button stays on step 5.
- **"Don't import" / "Put back"** - `data_import_add_list_change` (`POST …/add/list`,
  `ChangeImportAddListController`): CSRF, the import checked to be the player's first (404), answered with a 303
  back to the same list (page, filters, the row's anchor). **No state of its own:**
  - a row → `AnswerImportRows(leaveOut: true / false)` - `data_import_row.answers.leftOut`, the row `left_out` by
    the player: the very state of "Rows we could not read" and of the row's page, so a row left out here is listed
    there with "Import after all", and the other way round;
  - every ready row of one puzzle (offered where a search narrowed the list to one puzzle) → the same message for
    those rows (`readyRowIdsOfPuzzle()`, at most `ImportRowAnswers::MAX_ROWS`);
  - a whole sheet → `ConfirmImportMapping` with the sheet's kind "not puzzles" (the one-tap change of "How we read
    your file", built by `ImportSheetScreen::submission()`), after a confirm that names the sheet and the number.
  A row that is not imported stays in its place in the list, with what its name, time and date cells say, and
  "Put back". Without a script every tap is a page; with Turbo the list and its bar are one frame
  (`import-add-list`), so a tap swaps them and the page stays where it is.
- **A sheet must be settled for its rows to be ready** - the one engine-side change of the slice, in the shared
  definition `DataImportRowRepository::sqlReadyRows()`: a row is ready only when its sheet is parsed for its current
  mapping (`parsed_version = mapping_version`) and is of a kind whose rows are imported. A sheet the player just
  changed ("not puzzles", another column) is read again in the background; until then none of its rows is listed,
  counted in "Add N" or taken by an "Add" - the review and the list say "A sheet is being read again - a moment".
  So "Don't import this sheet" holds from the moment it is said (tested: an "Add" dispatched before the worker ran
  queues none of the sheet's rows). A single row's "Don't import" needs no such guard: the row is read again inside
  the request itself and is `left_out` when the answer comes back.
- **"Where you are"**: `ImportDetailController` passes `GetImportPeople::counts()` - step 3 says "Nobody else is
  named." or the exact counts, and is the next action while a name has no answer. One statement more on that page
  (six of its own now; `ImportFlowScreensTest`).
- **Explanations in place** (§12, the exact texts, six locales): on your profile already, rows that repeat, don't
  import, guest, a player as your partner, a result with only a month or a year, results with no date, unusual
  time, the daily limit, what an imported result is, no photo.

**Differences from the specification**

| Where | As built | Why |
|---|---|---|
| §7 the checkbox, "Save this page" without JS | The tick box of a row **is a submit button** (`role="checkbox"`): a tap saves that row at once, with and without a script. No page-wide form, no "Save this page". | One mechanism for both cases, nothing to forget to save, and no script needed for the essentials. |
| §7 "a form in a Turbo Frame per row" | One frame around the list and the bar. | The bar's number must move with every tap; a frame per row could not move it. |
| §7 per puzzle → `DecideImportPuzzles(left_out)` | The puzzle's ready rows are left out through `AnswerImportRows`, like single rows. | A puzzle decided as "left out" loses its match - "Put back" would send it to step 4 to be matched again. Rows put back are ready again as they were. One state for a row that is not imported. |
| §7 order "by puzzle name then date" | The file's order (sheet, row). | It is the order the "Add" takes rows in, and a row that is not imported keeps nothing but its place in the file - by name it could not stay "in place". |
| §7 tabs Results · Collection · Wish list | An "All" tab first; the three follow where they have something. | "All" is exactly what an "Add" queues - rows that add nothing (on the profile already, repeats) and rows kept just for the player are queued too and belong to no other tab. |
| §7 a sheet's "Don't import any" is undone in the list | Undone in step 2 (as the confirm says). | The sheet's former kind is not stored; step 2 is where a sheet's kind is chosen. |
| §5.9 "Dates: day first - 03/04/2023 is 3 April 2023" with the player's own value | The sentence names the rule with a fixed example; the player's own values are the sheet's example row ("Your file says “45:10” · “03/04/2023”, added as: …"). | A question's stored examples are gone once it is answered, and a sheet's first row need not be of the shape a rule is about - the example row is always true. |
| §5.9 "94 results with no date - Give >" | The line has its explanation, no link. | No screen lists these rows yet; each is given a date on its own row's page. |
| §5.9 "Quick questions before adding" | Not built. | Slice 6. |
| §5.9 "What adding does" open "on a player's first import" | Open until this import's first Add. | The page does not count the player's imports. |
| §7 "What this import added" with search, filters, "Undo the ticked" | Not built. | Slice 5. |

## As built - slice 3 (puzzles in bulk)

What shipped with slice 3 (3a, 3b and 3c together), and where it differs from §5.7 and §6 - each with its reason.
One table (`data_import_pile_answer`, a generated migration); the matching rules (`ImportPuzzleMatcher`, `ImportPuzzleTierRules`) and what "matched"
decides by itself are untouched - this slice only presents the piles and collects the player's decisions.

**The rule that outranks every convenience** is the server's, not the template's: a wrong match puts a real result
on the wrong puzzle's public leaderboard. Three places enforce it:

| Bulk action | What a form may send | What makes it safe on the server |
|---|---|---|
| **"Yes, these N are right"** (`tick_all`) | the tiles of one screen whose box is still ticked: id, the version and the catalogue puzzle the tile showed | `DecideImportPuzzles::$bulkConfirm` - and any message with more than one catalogue decision, flagged or not: `DecideImportPuzzlesHandler` refuses the **whole** message (`needs_a_closer_look`) unless every puzzle is part A by `ImportPuzzleBulkConfirm::isPartA()`; versions are checked as before; at most one screen (20) is taken from a form |
| **"Keep them just for me" / "Don't import them"** for "Not in our database" (`all_in_pile`, pile `not-found`) | the pile and the answer - never a puzzle | `ImportPuzzlePile` works out the pile's puzzles of **that import of that player that wait now** (`GetImportPuzzleScreen::waitingOfPile()`), 200 a chunk, each chunk one `DecideImportPuzzles`; a puzzle decided between the read and the lock is skipped and counted. **An answer is a row** (`DataImportPileAnswer`) written under the import's lock (`StartImportPuzzlePileAnswer`): one open answer per import and pile - a second is told "still being saved" and nothing of it is applied; none starts while puzzles are still looked up or on a stale screen (`revision`). Every turn re-reads the row and the import's revision (another one ends the answer as `changed`) and answers **only puzzles that were in the pile when the answer was given** (built and looked up before `started_at`). The sweeper ends an answer without a sign of life for 15 minutes. It matches nothing |
| **"Keep the other N just for me"** (`all_in_pile`, pile `choose`) | the same | the same service; the pile allows **only** "keep" (`ImportPuzzlePile::CHOICES`) - never "don't import" in bulk, and never a match |

**Part A in SQL** (`ImportPuzzleBulkConfirm::sqlPartA()`, the condition of the grid's statement - never a filter over
a page): the rules said "probably" and the puzzle waits · **the player has said no word about it** - it is not
undecided by the player's own decision (`decided_how = 'player'`: a match they took off with "Wrong puzzle"), and
no remembered "not this puzzle" names its candidate · **exactly one** candidate · found by the whole name with
the file's piece count (`name`), the barcode (`barcode`) or the brand's own code (`brand_code`) · the candidate's
`piecesDiffer` is false and the catalogue puzzle's piece count **now** is exactly the file's (499 against 500 is
part B - the rules' "one apart" suggests a puzzle, it does not confirm twenty), or the file has none - a match by name always has one · the file names **no brand at all**, or the brand it names is the
puzzle's (a brand text we do not know is not "no brand") · the puzzle may be offered to the player now
(`GetImportPuzzleCandidates::sqlCandidate()`: not hidden, not secret, not somebody else's unapproved one; a candidate
merged into another puzzle since is not part A either). The same rule in PHP is `isPartA()`;
`ImportPuzzleBulkConfirmTest` pins that both give the same answer puzzle by puzzle, and that part A never holds a
card whose brand or piece count differs.

**Built**

- **Overview** (`puzzles.html.twig`): "We matched 353 of your 786 puzzles." with a bar, "433 puzzles wait for you",
  a card per pile that waits (count, one sentence of what it costs, its explanation of §12 always visible), ONE
  primary (the first pile that waits), then as links: "Matched (N) - see them", "Matched like last time (N)", "Kept
  just for you (N) - change", "Not imported: N". `ImportPuzzlesOverview::$matched` (one more `FILTER` in the same
  statement).
- **"Not in our database"** (`_pile_not_found.html.twig`): the two radio cards ("Keep them just for me" selected on
  the screen, applied on **Continue** only), "What is not kept", the puzzles folded below (fifty a page, each with
  its way to the search). While puzzles are still looked up the button is disabled and reads "Still looking for N of
  them…".
- **The picture grid** (`_pile_probably.html.twig`, `_puzzle_tile.html.twig`, `data_import_ticks_controller.js`):
  twenty tiles a screen, 2 columns at 360 px and 3 from 576 px; a tile is the label of its box. The boxes are
  **ticked on the screen** (§6.1) - nothing is stored for a tick, an unticked tile is simply not sent; the sticky
  bar's button counts the ticks with a script and reads "Yes, the ticked ones are right" without one. A screen goes
  on after the last tile of the one before (`after`, an id), so what was unticked or left for later stays behind and
  is offered again at the pile's end.
- **The chooser** (`puzzle_choose.html.twig`): the card is the answer (no "This one" line, every card looks the
  same, none first-as-chosen); "None of these" folds the search, "Keep it just for me" and "Don't import it";
  "Later"; "Undo the last one"; "Stop here". `nextUndecided()` goes by the puzzles with the most rows, then by name.
- **The rest screen** is the pile's own screen (`_pile_choose.html.twig`, `?done=20`): "20 done.", "176 puzzles left
  in this pile.", "Rows of your file with the next 20 puzzles: 143", **Do 20 more**, "Or add what is ready now
  (614)", "Stop here - I'll come back", and "Keep the other 176 just for me" folded.
- A pile over 200 puzzles is answered on the `imports` worker (`AnswerImportPuzzlePile`, a chunk a turn, the job
  sending itself again). The pile's screen then shows "Saving your answer… Puzzles left: N" and loads that part
  again every three seconds (`data_import_pile_controller.js`; a refresh link without a script).
- Explanations in place (§12) with the exact texts: Matched, Looks like a match, Pick the right one, Not in our
  database, Another brand / another piece count, Wrong puzzle (+ unlisted under both "keep" answers).

**Differences from the specification**

| Where | As built | Why |
|---|---|---|
| §6.1 part B: boxes empty, "Match the ticked ones (N)", "None of these 20" | Part B is **cards confirmed one by one** ("Yes, this is it" per card, "It is something else"), twenty a screen - there is no button for several, and the server refuses several at once. | The slice's brief: a candidate of another brand or another piece count is never inside a bulk confirm. A brand that differs is exactly where a tap on twenty at once goes wrong. |
| §6.1 part A: `name` with the brand missing; `barcode`; `brand_code` | The same three - with two narrowings: a brand text we could not resolve to a brand is part B, and a match by name needs the file's piece count. | "The file has no brand" must be true, not "we do not know the brand it names"; a name alone is not the same puzzle. |
| §6.1 "tap-and-hold / the small i opens the chooser" | A plain link under each tile: "It is something else". | Tap-and-hold is not discoverable and needs a script. |
| §6.1 after the tap: "19 matched. 35 to go." | "19 matched." as the notice; the next screen says "2-6 of 6" itself. | One count, in one place. |
| §6.2 "Undo the last one" takes the decision back at once | It leads to the last puzzle's own screen, which says what was decided and takes it back with **the version it shows**. | Taking a decision back without the version the player saw would overwrite what another tab changed. Two taps instead of one. |
| §6.2 round header "These 20 puzzles are 143 of your rows" | On the pile's start / rest screen: "Rows of your file with the next 20 puzzles: 143". | One statement where a round starts, instead of one on every chooser screen (the chooser stays at three). |
| §6.2 rest screen "20 done - 143 rows are ready." | "20 done." and the "add what is ready now (N)" line. | Which rows a round made ready is not kept; the number that can be added is. |
| §6.3 `all_in_pile` over 1,000 puzzles on the queue | Over **200** (as the rows' group answers do it). | The brief; a chunk is 200 either way. |
| §6.3 the queued answer "with the live line" | The answer's row says that it runs (read with the overview - no statement more); the screen polls its own part every three seconds instead of following the Mercure line. | The live line follows reading and matching; a pile answer lasts seconds. |
| §6.3 "the set the player saw" | The answer stores no ids: it stores the moment it was given and the import's revision. A turn answers only puzzles built and looked up before that moment, and ends the answer when the revision is another one (which is what brings new puzzles into a pile). | A thousand ids in a row would be a second copy of the pile; the two together say the same set - `ImportPuzzlesInBulkTest` adds puzzles to a pile under a running answer and they stay as they are. |
| A match taken off | A puzzle the player said "Wrong puzzle" about is never on the grid again: it is a card of part B (or of "Matches you took off"), and the candidate they rejected stands last, marked "You said this is not it." | Else the next "Yes, these N are right" would store exactly what the player rejected. |
| §6.3 `choice = left_out` for the pile `choose` | Refused - only "keep". | §6.2: "the only bulk answer keeps things private". |
| §6.3 "guarded by the pairing revision" | As specified - and besides it the per-puzzle version inside each chunk. | Both: the revision catches a re-read file, the version a decision in another tab. |
| §5.7 "Rows already on your profile move to step 5", "rows we could not read" | Both stay on the overview as quiet lines (the second only when there are some). | Step 5 is slice 2's screen. |
| §5.7 a fourth pile | "Matches you took off (N)" appears when a matched puzzle got "Wrong puzzle" and still waits. | Those puzzles wait for the player and belonged to no pile of the mock-up. |
| The pile's list | `…/puzzles/{pile}?page=N` is the list of every card of a pile (decided ones too); the pile's screen itself has no page. "Looks like a match" links to it ("See the whole pile"). | A decided card needs a place to be changed; the grid shows only what waits. |

**Not verified:** nothing of this slice was run in a browser - no screen was looked at at 360 px, and neither
script (`data_import_ticks_controller.js`, `data_import_pile_controller.js`) was executed; both were only parsed.
The screens are covered by `ImportPuzzlesInBulkTest`, `ImportPuzzleBulkConfirmTest`, `ImportPairingScreensTest` and
`ImportFlowScreensTest` as a browser without scripts sees them.

## As built - slice 6 (a question is asked once per file)

What shipped with slice 6, and where it differs from §5.9 / §5.10 - each with its reason. **[logic]**: the engine's
question path changed *when* it asks, never *whether*; the full description is adding.md "A question is asked once"
and adding-screens.md. One generated migration (`data_import.standing_answers`, `data_import_row.open_questions`,
`data_import_group_answer.also` + `rows_still_waiting`). The add-result form and `ImportedResultWriter` are
untouched.

**Built**

- **Both group questions in one pass** - `ImportResultQuestions::judge()`: an unusual time and "the file says it is
  on MySpeedPuzzling" are looked at together; the row is told both (`open_questions`), its reason is the first.
  The card of the unusual times asks the other question for the rows that have both, on the same screen; a row's
  own card says both and its one button answers both. Answered for one, a row waits for the other only.
- **"Already on MySpeedPuzzling" before the Add** - a lazy frame of "Check and add" with the count the engine
  itself gives (`ImportSaidAlreadyCheck` → `ImportRowAdder::openQuestionsOf()` → the same `judge()`), and the two
  answers as a group answer given before the "Add" (`AnswerImportRowsGroup` with `before_add` for this reason too).
- **A standing answer per import** - `data_import.standing_answers`, written by every "add them" for a group, read
  by `ImportResultQuestions::ask()` for an "Add": the row's own waiver (with its fingerprint) and the
  `SuspiciousTimeConfirmation` are written when the row is added. Shown before every "Add" with what it covers
  this time, and "Change".
- Six locales (`data_import.once.*`), `ImportQuestionsOnceTest`, two more cases and one test in
  `ImportResultQuestionsParityTest`.

**Differences from the specification**

| Where | As built | Why |
|---|---|---|
| §5.9 "> 2 quick questions before adding" (one folded line) | Two blocks of the review, each a lazy frame: the times (as before) and "Already on MySpeedPuzzling?". No count of questions in a heading. | Each block asks the engine about rows one by one (several statements a row) - they stay off the review's own statements; the review can not know how many questions there are without them. |
| §5.10 table: standing answer `{unusual_time: right, said_already: missing}` "read by `ImportRowAdder`" | `{"unusual_time": "<when>", "said_already_on_profile": "<when>"}` (the engine's codes, the moment as the value), read in `ImportResultQuestions::ask()` with `standingAnswers: true` passed by `ImportRowAdder`. | One place asks the questions; the pairing of an unlisted puzzle calls the same method and must **not** honour it. |
| §5.10 "a standing answer" as a separate choice ("for all of them") | Every "add them" for a group **is** the standing answer; the screen says so under the buttons. "Don't import them" never stands. | A second button with nearly the same words would be one more thing to read; the group buttons already mean "all of them". Leaving out is not remembered: it removes rows, and a later row may be another case. |
| §5.10 wording "416 times are much faster or slower than your results here" / "Your file says 441 results are…" | "Unusual times: 416" stays (slice 5 owns the cards); the new block says "Your file says these results are on MySpeedPuzzling already, and we did not find them: 441". | Counts stand after a colon everywhere in the import's texts - no plural forms to get wrong in six languages. |
| A row edited after a standing answer | Covered: its waiver is written when it is added, for the row as it reads then. | The answer is to the question for the file. The per-row rule did not move: a waiver given before a change still fails closed, and without a standing answer the row is asked again. |
| "Both answers on one screen" for "don't import" | On the unusual-times card the other answer is "They are missing - add them too" or "Don't import those (N)", required for "add them"; a form without it (an older page) leaves the rows waiting for the other question. | Nothing is preselected - "add" must never be a default. |
| The done page's "Some rows can have one more question after this one." | Not on a screen (slice 5 did not ship the sentence); with this slice it would be untrue for the two group questions. A possible duplicate can still follow - it is asked first, alone. | - |
| The count before the Add | Bounded at 500 marked rows ("Of the first 500 we looked at…"); the answer itself covers every marked row. | A few statements a row. |

**Loose end closed:** "a row with two doubts is asked twice" (docs/TODO.md - the line is to be ticked by whoever
merges; this slice does not edit that file).

**Not verified:** nothing of this slice was run in a browser - no screen was looked at at 360 px; the radios'
`required` and the second button's `formaction` / `formnovalidate` were only read as markup by the tests' browser
without scripts. `partner_daily_limit` / `too_many_partners` under a standing answer are not in the new table test
(they need hundreds of rows); `ImportPeopleTest` covers them unchanged and they are asked after the questions, as
before.

## As built - slices 4 and 5 (the Check step folded; after the Add)

What shipped with slices 4 and 5 together, and where it differs from §5.1, §5.4, §5.10 and §7 - each with its
reason. No table, no migration. Nothing the import reads, matches or adds was changed: `ImportRowAdder`, the
matching, the writer, `UndoImportRowsHandler` and the add-result form are untouched. The one line of the engine's
own SQL that moved is a parameter of `DataImportRowRepository::sqlReadyRows()` (below) whose default is the SQL as
it was.

**Built - slice 4, step 2 "Check"** (`columns.html.twig`, `ImportColumnsController`)

- **The step shows only what needs the player.** While a sheet asks: **one question a screen**, across all sheets,
  the sheet with the most rows first - "Question 1 of 2", the sheet's name, the question, and each answer as a
  button with the player's own values read each way (the sheet page's question cards, the same `ImportSheetScreen`
  view). **A tap on an answer is the action**: the button is a submit of the sheet's own form
  (`ConfirmImportSheetController`, `ConfirmImportMapping` - no new message), with `back=check` and the place in the
  row of questions (`q`, a number), so the answer comes back to the next question. Nothing is preselected; the
  screen has no primary of its own. "This decides 212 rows." with the explanation of §12; **Later** (`?q=N+1` - after
  the last question, the summary); "See every sheet" (`?summary=1`).
- **The summary** - the last screen of the step, the only one when nothing is asked: ONE sentence a sheet of what
  its rows are **added as** (the sentence table of §5.4: solo / pair / team results, "as each row says", results
  whose puzzles go to the collection, puzzles owned, wishes, "a column says which", the puzzle list other sheets
  look up in), the recognised fields in one muted line (or "We know this file…" for a known export, "Read like last
  time" with its explanation for a remembered set-up), and **Change** - the sheet's own page, unchanged. A sheet
  that asks, one whose reading went wrong on our side, and one we are **not sure about** (an unsure kind, or - on a
  sheet that is imported - a column under "Please check these") stand first with "!" and a button ("Answer" /
  "Have a look" / "Tell us"). The sheets that are not puzzles are **one folded line** ("“Notes”, “Stats” + 6 more -
  Not puzzles - not imported. Show"), each with "It does hold puzzles". How dates and times are read is said once
  for the file (`ImportSheetReadings`, every reading whoever settled it). ONE primary: **Looks right - continue**;
  with something left - "Continue - I'll look at “2019” later" / "…at them later"; with a question skipped -
  "Answer N questions" (and "Continue - I'll answer later" as a link). The file's own table of sheets stays folded
  at the end.
- **`DataImportSheetOverview`** **[small]** gains `rowMeaning`, `solvingType`, `fieldsRecognised`, `columnsToCheck`,
  `kindSure`, `knownExport`, `readings` - read from the stored mapping and options **in the one statement**
  `GetDataImports::sheetsOfPlayer()` already ran (jsonb expressions; no statement per sheet). `addedAs()` is the
  sentence's key, `isUnsure()` the "!" rule. The summary reads four statements whatever the number of sheets, a
  question's screen three (the import, its sheets, the one sheet that asks) - asserted in
  `ImportColumnsScreenTest`.
- **Passed by itself:** `ImportFlow` counts the sheets we are not sure about: step 2 then says "We are not sure
  about N sheets - have a look." and is **open** - it is ticked only when every sheet is certain (§2).

**Built - slice 5, after the Add**

- **The done page** (`batch.html.twig`, `ImportBatchController`) in the order of §5.10: what was added as plain
  lines (one number a line, zeros left out); "N rows wait for your answer" with what that means; the **question
  cards** (`_group_cards.html.twig` with `cards`), ordered by what the player can do - who they solved with (one
  answer), unusual times, "the file says they are here already" (its two forms unchanged), possible duplicates
  (one by one) - each one sentence, its explanation of §12 and ONE button; **See them on my profile**; the links for what is left ("What was added - undo any row", the
  rows of puzzles still to match, what can be added tomorrow with the daily limit's explanation - or what else is
  ready); "Statistics, XP and achievements follow…"; and folded: "Not added this time", "Why some rows added
  nothing (N)" with *on your profile already*, "Undo this" with what Undo does. **ONE primary:** the first question
  card's button - else "See them on my profile" - else Continue. One statement more than before
  (`GetImportAddScreen::summary()`, only once the Add is through) for the links.
- **"What this import added"** (`added_rows.html.twig`, `ImportAddedRowsController`): tabs by what a row made (with
  counts), a GET search (the player's own text - the only thing of a file an address holds; the catalogue's names
  through `PuzzleTextSearch`, the file's name as typed), a sheet filter, fifty a page - **all in SQL**
  (`GetImportAddScreen::added()` with `makes` / `sheetId` / `search`, `addedOverview()` for the tabs and the sheets
  in one statement). Per row **Undo** (its confirm page kept). Four statements whatever the rows and the filters.
- **"Undo the ticked"**: "Select several" (`?select=1`) turns the Undo buttons into tick boxes of one plain GET form
  to the step before an Undo, which names the rows by their ids (`?rows[]=`), says what is removed, and posts them.
  **`UndoImportRows` took a list of row ids already** (scope `rows`) - nothing was added to the message or its
  handler: `StartImportUndoController` now passes every ticked id (at most 200) instead of one, so several rows are
  **one** Undo under the handler's own locks, and `GetImportAddScreen::counts()` counts a list of rows for the
  confirm page. "Remove it too" (`includeEdited`) stays a word about one named row. The form sent with no box
  ticked is never read as "everything": back to the list, saying so. A script (slice 3's
  `data_import_ticks_controller.js`) only counts the ticks on the button.
- **Imports home** (`index.html.twig`, `_import_card.html.twig`, `ImportsHomeController`,
  `Services/DataImport/ImportHomeCard` - pure, table-tested): a card is the file's name, ONE status (a sentence a
  line, the table of §5.1) and ONE button; finished imports folded. `GetImportAddScreen::addedByImports()`
  **[small]** grew what the sentences need - rows ready, rows that wait for an answer, questions, rows we could not
  read, puzzles to match, the running Add - still **one statement for all imports**. "Ready" there is the engine's
  own definition: `sqlReadyRows()` got an optional second parameter that says *whose* rows are looked at (default:
  one import, the SQL as it was); the rule itself is one text. The page reads two statements whatever the number of
  imports (`ImportAfterTheAddTest`).
- **Explanations in place** (§12, the exact texts, six locales): wait for your answer (a question's rows), the file
  says "already on MySpeedPuzzling", possible duplicate, the daily limit (and its short form on a card), 3 imports
  in progress, column set-ups we remember, undo, on your profile already. "Rows wait for your answer" after an Add
  has no text in §12; it says: "These rows are not on your profile yet - each depends on a question only you can
  answer. Everything else was added."

**Differences from the specification**

| Where | As built | Why |
|---|---|---|
| §2 "no column under Please check these" keeps step 2 open | Step 2 is open then (its dot, its line on "Where you are") - but it is **never the one primary action**: the primary stays the question / the rows / the next step. *(Since "a guessed sheet is checked first", below: a guess that decides what gets added - no header row, an unsure kind, name, time or brand - IS the primary, and the player's look is recorded. What is left here is a guess that decides nothing by itself, such as a date column.)* | Nothing records that the player has looked (§2 rule 3), and a plain Save of an unchanged sheet changes nothing - as the primary it would be asked for on every visit, for ever. |
| §5.4 "Question 1 of 2 … then the summary", addresses | One address: `…/columns` shows the first question that waits, `?q=N` the N-th, `?summary=1` (or a `q` past the last) the summary. | "Same routes"; the place is a number, never anything of the file. |
| §5.4 "Later skips the question" | "Later" moves on in the row of questions; the question still waits, and opening the step again starts with it. | Nothing is stored about a skipped question. |
| §5.4 a refused answer (a year that is none) | Shown on the sheet's own page with a 422, as before. | The answer is the sheet's own form; one place renders its refusal. |
| §5.4 "Partner: asked in step 3" on a pair sheet | The pair question is a question of the sheet (as 6d built it) and is asked here, with the partner's field. | Slice 1's table: moving it is a change of 6d's screen. |
| §5.4 file-wide line with "Change" | The readings are listed once for the file, without a link; a sheet's "Change" changes its own. | A reading belongs to a sheet - there is no file-wide setting to open. |
| §5.4 "Not imported: Show → It does hold puzzles" | A link to the sheet's page, where its kind is chosen. | The one-tap kind form left the summary with everything else behind "Change". |
| The columns screen said "We matched N of your puzzles" | Gone. | Step 2 shows only what needs the player about the reading; step 4 says it. |
| §5.10 "Some rows can have one more question after this one." | Not on the page. | Slice 6 (a question is asked once per file) merged first: the sentence was only to stand "until the [logic] rows are built". |
| §5.10 the unusual-times card | On the done page a card with one sentence and "Look at these times" (the summary page); on the list of rows that wait it stays the frame that loads the summary in place. | §5.10's mock; the done page stays light, and its first card needs one button to be the primary. |
| §5.10 wording of the two bulk cards | The done page's cards have the new sentences; the buttons of "the file says…" keep their texts ("They are missing - add all N"), and the times summary its own. | Their forms and `not_added_times.html.twig` are slice 6's. |
| §5.10 "276 puzzles still to match" | "N rows wait for you to match their puzzle." | As slice 1: rows are what the add summary counts. |
| §7 "Undo the ticked" over several pages | The ticks of one page (fifty rows) are one Undo. | A plain form without a script holds one page; nothing is stored for a tick. |
| §7 tabs | An "All" tab first, as on "What will be added". | The same reason as slice 2. |
| §5.1 "471 ready to add" | "471 rows ready to add" - ready **rows**, before the daily limit and before "on your profile already". | One statement for all imports counts the engine's ready rows; what a row would *make* is the review's own statement per import. |
| §5.1 "Added 2,000 today · 1,240 more can be added tomorrow" | Said when the results all of the player's imports made today reach the limit and rows are ready; "more" counts ready rows. | The daily limit is the player's; the exact number of results over it is the review's. |
| §5.1 "Answer 2 questions" after an Add | "Answer for N rows that wait" → the list of those rows. | As slice 1: the rows are counted, the questions are not. |
| §5.1 step 3 on a card ("Tell us who 3 people are") | Not said on a card; "Continue" leads to "Where you are", which says it. | Counting the people of every import is a statement per import. |
| §5.1 "each card's `...` has both" (finish / discard) | The card's name and "Continue" lead to "Where you are", which has both. | One button a card; the menu lives where the import's state is loaded. |
| §5.1 three in progress | The sentence replaces the button when three cards wait for the player; whether a new file would really be refused is still the upload's own check (`ImportSlots`). | An import with nothing left gives its place back before the daily sweep finishes it - the page does not ask per import. |
| §5.1 the first visit | The empty state is the sentence of §5.1 and the button; stage 8's introduction replaces the marked block in `index.html.twig`. | Another branch builds it. |
| §12 keys `data_import.explain.<term>` | So for the explanations; the other texts of these slices are in two blocks of their own, `data_import.check.*` and `data_import.after.*`. 21 keys nothing renders any more were removed in every locale, and `_status_badge.html.twig`. | Three branches write the catalogues at once. |

**Not verified:** nothing of these slices was run in a browser - no screen was looked at at 360 px, and the tick
counter of "Undo the ticked" was not executed (it is slice 3's script, unchanged). The screens are covered as a
browser without scripts sees them: `ImportColumnsScreenTest`, `ImportAfterTheAddTest`, `ImportAddScreensTest`,
`ImportGroupAnswersTest`, `ImportFlowScreensTest` (one primary a screen, the words of §8, six locales with their
plural forms), `ImportHomeCardTest`, `ImportFlowTest`.

## As built - after the first tester (the row's editor: a way back, a clear "saved", "no time")

A player's report of 2026-11-15, the part about single rows and the columns. The design of record is
[pairing-screens.md](pairing-screens.md) ("To fix", "No time", "The way back") and
[columns-screen.md](columns-screen.md) ("Saving"); in short:

- **A way back.** Every link into a row's editor carries `?return=` (the screen, its page / filter and anchor);
  `ImportRowOrigin` takes only one of the pages of this very import a player can come from (a closed list) and
  names the back link after it. It survives a 422 and
  the redirect after a save.
- **A clear "saved".** A saved row leads back to where the player came from - or, without such a screen, stays open
  while it still needs a look, else on to the next row that needs a look, or to "Rows we could not read" - and that
  page says "Row 14 is saved. We can read all of it now." One
  primary button (Save), and "Save and fix the next one" while another row needs a look.
- **Saving the columns moves on** to "Where you are", which says "Saved - your rows are being read again" and
  follows the reading live. `back=list|check` are as they were. Which step comes next is `ImportFlow`'s to say.
- **No time.** "No time - I didn't time this one" beside the time's input, and "Import these without a time (N)"
  for a group of unreadable times: the row is what an empty time cell makes it - a result without a time. Never
  offered (nor taken) for a row of a shelf whose time cell is empty: it must not say "solved" by itself.
- **Said where the player lands.** "Row 14 is saved" is fixed in view on the screen the save leads to (that screen
  is scrolled to the row or group), and anchored rows land below the sticky header.
- **One answer for every time we could not read**: "Import all of them without a time (N)" above the groups while
  more than one group is about a time.
- **No jumping on "Rows we could not read".** Group answers and "Import after all" are answered in place with Turbo
  Streams; the redirect stays as the fallback without a script.

| The report asked for | Built | Why |
|---|---|---|
| "Row 14 is saved - it will be added." | "Row 14 is saved. We can read all of it now." / "… Something in it still needs a look." | A row that reads well may still wait for its puzzle to be matched or for a question of step 5 - "will be added" would promise what the page can not know. |
| Back to the page of the puzzle after its title was fixed | The puzzles' overview, when the save made the row another puzzle | The page of the old puzzle is gone once the puzzles are built again (a puzzle of the file without rows is deleted). |

**Not verified in a browser:** the tick switching the time's input off (`data_import_no_time_controller.js`), the
editor's buttons switched off once sent with the clicked one still in the POST
(`data_import_submit_once_controller.js`), the
streams as Turbo applies them (a 422 stream replacing the group's card, the confirmation staying in view, the
`data-turbo-confirm` of "Don't import them" with a stream answer), and the anchors after a redirect
(`#group-<key>`, `#row-<id>`). Covered as the server answers them: `ImportPairingScreensTest`,
`ImportColumnsScreenTest`, `ImportRowOriginTest`, `ImportRowAnswersTest`.

## As built - answers in place (tester feedback, 2026-11)

A tester's "Looks like a match" held 30+ cards and every "Yes, this is it" loaded the page again at its top. Rule 2
of §2 now has a companion: **an answer never moves the page**. Every answer form of step 3 (People) and step 4
(Puzzles: cards, the picture grid, one answer for a pile, the chooser, take back) is answered with Turbo Streams -
the card, the pile or the screen as it stands after the answer - and what the answer did is said in a line that
stays in view at the bottom, with "Undo the last one"; the chooser's round swaps the next puzzle in place and the
address follows. Step 5's "What will be added" already swapped its list's frame for a row and a puzzle; the sheet's
"Don't import" does now too. The mechanics, the script and what is tested: pairing-screens.md "Answers in place",
people.md "Answers in place".

| Where | As built | Why |
|---|---|---|
| "the step header's status sentence updates" | The flow's header has no sentence about the step on these screens (only "Where you are" has `ImportFlow`); what updates are the screen's own counts - a list's "N of M wait", the grid's "1-20 of 34", a pile's title - and the line that stays in view says when the step is done. | Nothing to update; `ImportFlow` is another builder's. |
| "Undo the last one" for one answer for several | Not offered after "Yes, these N are right" or an answer for a pile: the screen says what was done, and each puzzle is taken back on its card ("Matched - see them", "Kept just for you - change"). | Taking twenty back in one message works, but is no undo: a match taken back is remembered as "not this puzzle" and may then only be confirmed on its own - the twenty would return as cards to look at closer. A pile's answer can run on the worker. |
| Where the round rests or ends | A redirect (a page load), as before. | It is another screen with another address; only "the next puzzle" is the same screen. |
| "What will be added" | Stays the list's `<turbo-frame>` (a redirect inside the frame), not streams. | It already did not scroll; the whole sheet's form joined the frame. Its buttons have Turbo's own waiting state only. |

## As built - a guessed sheet is checked first (the first tester's report)

**The report.** The first real player's sheet had no header row. Pieces, name and time were guessed from the values;
brand and date were not recognised. The first big button she saw was "Match your puzzles" (step 4) - a pile of
matches built on half-read columns - and she found the columns screen (step 2) only later: "That should be where
you land first! Brand was not categorised, so every puzzle asked for a match."

**The rule** (`Services/DataImport/ImportCheckFirst`, [columns.md](columns.md) "Check first"): a sheet that is
imported needs the player's eye before anything else when it has **no header row**, or one of **the columns that
decide what gets added** - the puzzle's name, the time of a results sheet, the brand - was guessed **from its values
only**, or **its kind could not be told at all**. Never a known export, never a sheet a remembered layout was laid
over. A sheet with a found header row whose columns come from header words - even `probable` ones, even with a kind
that is only probable (every list of owned puzzles) - is **not** stopped: it keeps "We are not sure about 1 sheet -
have a look" and is matched at once.

**What the player sees**

- **"Where you are"**: the one primary button is **"Check how we read your file"**, leading straight to **that
  sheet's own columns screen** (`data_import_sheet`). Step 2's sentence says why in plain words: "Your file has no
  header row, so we guessed what each column is - please check." (a header row, but a deciding column guessed from
  its values or an unknown kind: "We are not sure we read 1 sheet right - please check before we match your
  puzzles."). Step 4 says "1 sheet waits for you to check its columns - we
  match its puzzles right after." - never a spinner.
- **Where it sits in the order** (`ImportFlow::of()`): finished → a batch is running → **questions** (of the sheets
  we are sure about) → **check how we read your file** → rows that need a look → a sheet that failed → people →
  puzzles → rows not added → ready to add. After the questions, because a question of a sure sheet is one tap and
  its screen exists; before rows, people and puzzles, because all three are built on the columns. A held sheet's own
  questions (a date order, a time shape) are not counted as questions: they wait with the check, on the sheet's page.
- **Step 2's summary** (`columns.html.twig`): the sheet stands first with "!", "No header row - we guessed what each
  column is." / "Waits for you to check its columns", what we guessed ("Our guess: Solved on, Brand, …"), and the
  screen's one primary is the same "Check how we read your file". "Continue - I'll check it later" stays a link.
- **The sheet's page** (`sheet.html.twig`): a notice at the top - "Your file has no header row. We guessed the
  columns from what is in them - tell us what each column is." and "Until you have looked, we match no puzzles of
  this sheet and add nothing from it." - with the one-tap **"Yes, this is right"**. Every guessed column carries an
  "Our guess" badge (a brand guessed from its values: "These look like puzzle brands we know."), and - as before -
  up to three values of the column ("For example: …"). A sheet that names puzzles and has no brand column says:
  "No column of this sheet is the brand. We find your puzzles much more surely with it - if a column holds the
  brand, choose “Brand” for it below."
- **Sheets we are sure about keep everything as it was**: they are read, matched and can be added at once - "Or add
  what is ready now (N)" counts them and never a row of a held sheet.

**How it is held** - no new table, no new column, no migration. The sheet's proposal carries one more question,
`check_columns` ("did we read your columns right?"). While it is asked, the sheet is parsed like any other (so its
page shows the first rows as we understood them), but **every row of it waits**, stored without a puzzle key, a
source key and people (`PendingQuestionRows`, `ParsedImportRow::heldForColumnCheck()`). So `BuildImportPuzzles`
finds no puzzle in it, nobody of it is asked about on step 3, and "ready to add" can not hold a row of it - by
construction, not by a filter somebody could forget. Being a question, everything that already knows "a sheet waits
for the player" treats it right: the sheet is *parsed* (`myspeedpuzzling:read-waiting-imports` and the stuck-import
guards see nothing to queue), its state is `waiting`, the import keeps its place.

**What clears it**: the player's word on the columns - "Yes, this is right" (the answer `check_columns = yes`; the
sheet's form carries it hidden, so any Save from that page counts) or a changed column, kind or row meaning
(`ConfirmImportMapping` with a mapping). The sheet gets its next version, is read again - now with keys - and its
puzzles are built and matched as for any confirmed sheet. Any Save on the sheet's own page releases it, whatever
was changed there (a sheet-wide setting too): the form carries the answer. Only a message that carries neither the
answer nor a mapping - an answer given on a one-question screen - leaves it asked (`ConfirmImportMappingHandler`).
Answered, **the sheet reads as sure**: its guessed columns and its kind are certain, by the player - no "We are not
sure about 1 sheet" line, no "!" on step 2, no "Our guess" badge; it is never asked twice. The player goes on to
the next sheet that asks for the check, else to "Where you are". "Start over for this sheet" asks again (a fresh
guess is not a confirmation). A row of a held sheet opened on its own page says it waits for the columns check,
with the button to its sheet.

**Also built**: the brand is guessed from the values with the catalogue's help, and a date typed as text is
recognised on a sheet without a header row ([columns.md](columns.md) "The brand by its values", "Certainty").

**Tests**: `ImportFlowTest` (each state of a sheet → the primary action, the sentences, the order),
`ImportCheckFirstTest` (the rule; a header-less sheet shaped like the tester's; the brand's floor; a held row),
`ImportCheckFirstScreensTest` (the real handlers: held, not matched, not ready, not "stuck", the three screens,
"Yes, this is right" and a Save from the sheet's page release it and make it sure, a message without the answer
does not; the next held sheet; start over asks again; the row's page).

**Not verified in a browser**: the notice and the badges at 390 px, the tap on "Yes, this is right" under Turbo
(a plain form POST answered by a redirect - no script of its own).

**Imports home** (`ImportHomeCard`, `GetImportAddScreen::addedByImports()`): the card says "1 sheet to check - we
guessed its columns" - never "1 question about your file" for a sheet that only asks for the check; "Continue"
leads to "Where you are", which has the button.

**The header follows the live re-read** on "Where you are": the step dots and "Step N of 5 · …" sit in a frame of
their own (`import-flow-header-live`, `data-import-live`, only while the import is set up), loaded again together
with the list of steps - after a Save that sends the player there, the header moves on with the reading instead of
keeping the old step until a reload. Not looked at in a browser.

**After the walk in a real browser (2026-11-16)** - what changed on the flow's own screens:

- **Puzzles (step 4) of an import whose puzzles wait for the check**: "1 sheet waits for you to check its columns
  - we match its puzzles right after." and the button "Check how we read your file" - never "We matched 0 of your 0
  puzzles." with a "Continue" (`ImportPuzzlesController` passes the held sheets; one statement more).
- **While rows are read (again)**: step 2 says "Reading your rows…" ("We are working out what your sheets hold…"
  only while a sheet has no proposal yet), and step 4 is neither passed nor ticked - "Waiting for your rows to be
  read." instead of a green "No puzzles to match." before the matching ran.
- **"Saved - your rows are being read again."** goes when the live line reports the reading done
  (`data_import_setup_controller.js` removes the flash it was given the text of).
- **Several sheets**: "Where you are" says "One sheet of your file has no header row…", the sheet's notice "This
  sheet has no header row." and "Sheet 1 of 2 to check".
- **"We are not sure…"** names what the numbers can tell: "We are not sure what 2 columns of your file are - have a
  look." where every such sheet's kind is sure; "…about 1 sheet" otherwise. A column's own name is not in it: a
  sentence of the flow never carries anything of the file (`ImportFlowText`).
- **"We read N rows"** does not count the rows of an imported, read sheet that are no puzzle (its header row, a
  totals line): the number goes with "20 of 40 matched".
- **"Or add what is ready now"** on step 2's summary only when something is ready.
- **Times are read as**: "As written: hours:minutes:seconds" (was "Hours and minutes, as the cell stores it").
- **The row's page of a held row** leads back to its sheet; the footer's back link stands on a line of its own.

