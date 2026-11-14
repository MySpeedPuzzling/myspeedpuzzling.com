# Data import - the reviews of the design (2026-11-14)

The first draft of [README.md](README.md) and [implementation-plan.md](implementation-plan.md) was read by two
reviewers: one **independent** (only the documents and the code, no knowledge of how the design was made) and one
**with context** (the two real files, the production numbers, Jan's brief and every later message). Both checked the
draft's claims against the code. This file records what they found and what changed.

Mock-ups (13 screens, phone first): https://claude.ai/artifact/PdC6VNTTKy6Ft5EUg7TcvC - private to Jan. They were
drawn **before** the reviews; what differs from the revised design is listed at the end.

## Found by both

| Finding | Evidence | Change |
|---|---|---|
| `tracked_at` = solve date has side effects the draft did not know: Night Owl counts every imported result of a European player (midnight UTC is 01:00-02:00 local), old results get a "live" prediction, the duplicate cleaner's tier "identical, saved within 10 s" **deletes** two results the import added on purpose, XP uses the history formula (so "the same as a typed result" was wrong) | `AchievementSqlRules.php:37-43`, `SolvingTimePredictor.php:189`, `DuplicateClassifier.php:82`, `XpFormulaCutoff.php:47` | D5 rewritten (noon in the player's zone + row offset) with a table of every reader of `tracked_at` and its rule for imports; Q4 says what XP an import really earns |
| A month stored as the 1st is an invented fact: Weekend Puzzler, streaks, the calendar, the duplicate check's "same day", and the baseline's "which solve was first" all read the day | `GetPlayerStatsSnapshot.php:119,335`, `GetPlayerActivityCalendar.php:34`, `PlayerBaselineCalculator.php:198` | D13 + stage 6c: precision is stored and day-based rules skip imprecise results; until then only exact days are added |
| All import work on the shared `async` queue delays sign-in e-mails; one consumer | `config/packages/messenger.php:86-87`, `compose.yml` | D9: its own transport and consumer, the first task of stage 3 |
| "Never twice" had holes: the same row on two sheets became a second result; wiping an import's rows erased its memory; the deterministic id clashed with tombstones and with an edited result | the sequences in both reports | D6 rewritten: exact keys, equal rows = one result with a question, the comparison with all the player's results settled per batch, no derived ids; Q9: keys and outcomes are never wiped |
| The row sheet of stage 5 needs stage 2b; stage 1 feeds stage 4 | plan diagram | Dependencies drawn; the row sheet is built by whichever comes first |
| The handlers to switch off for imports were incomplete | `RecalculatePuzzleStatisticsOnSolvingTimeChange`, `DetectDuplicateResultsOnSave` | All five handlers of `PuzzleSolved` listed with their rule |
| A puzzle merged while an import waits | `PuzzleMergeForeignKeyCoverageTest` | The three new references to `puzzle` move to the survivor; candidates follow `puzzle_redirect` |

## Found by the independent reviewer

| Finding | Change |
|---|---|
| One message per row is fragile: retries at 30 s … 30 min, redelivery after a killed worker only after the transport's timeout, "the last row done" undefined, Undo of 2,000 results in one transaction | One chunk message that continues by the rows' state, a failing row marked `failed` and not retried, an idempotent finish, a sweeper, Undo chunked and only on finished or cancelled batches |
| A hand-typed result dated 14 Sep and the sheet's `Sept 2020`; a registered partner against a guest name; another answer to the day/month question | The comparison at "Add" works within the row's precision, ignores people, and treats "same puzzle + same time" or "same puzzle + same day" as a question |
| The unlisted `puzzle_key` contained the barcode and raw brand text: adding an EAN column doubled every unlisted puzzle | The key is name + pieces + resolved brand; unlisted results have their own "already yours" |
| "Add a photo" would mass-produce public catalogue duplicates (a third of "not in the catalogue" has a similar puzzle) and could create brands | D17: one at a time through the normal add-puzzle path, after "Is it one of these?", 10 a day |
| No versioning of decisions: a changed file asked 300 answers again | `import_puzzle_decision` per player; reading again is a new version of the same import |
| `ParticipantFileReader` reads with styles off - a date cannot be told from a duration; 10,000 × 60 cells would need about 1 GB | D14: the reader is chosen by a measurement at the start of stage 3; a cell limit (Q8) |
| The phone journey asked over a hundred decisions before the first "Add" | Principle 3 "value first": confident columns in one line, "We found N - add them now" after the first layout, "To fix" never in the way, the Google link first and in stage 3, a notice of what becomes public, a bell when background work is done |
| My data's bulk changes shipped before the log existed, so they could not be undone | 2c moved after 6b; the batch and the log are general (`data_change_batch`, `data_change`) |
| What Undo cannot take back was not said | Listed in "Audit and Undo" |
| The wishlist's "remove when solved" is the player's own opt-in | D18: honoured, logged, restored by Undo |
| Translations, CLAUDE.md, docs and cron rows were all left to the last stage | A rule for every stage |

## Found by the reviewer with context

| Finding | Change |
|---|---|
| The groups are puzzles but "Add" counts rows; what happens to a found puzzle's row with an unreadable time was not said | The rule is stated: a row is added when its puzzle is decided and nothing about the row is unclear |
| Players who back-filled by hand often typed without the real date - "puzzle + day + time" misses them | "Same puzzle + same time on another date" is a question |
| `AddPuzzleToCollectionHandler` overwrites the comment, makes its own id, returns nothing; the system collection has no uniqueness in the database; a row could only hold one collection item | The import has its own collection writer; `data_import_row_outcome`; the check under the lock (production holds 46 doubled items today) |
| Results without a time go through a second handler (`AddPuzzleTrackingHandler`) - file 2 has no time column at all | 6a extracts the writer from both |
| A new player's whole history judged by the community's scale could flood the moderators' queue and the player's inbox | "Time verification": judged in the import first, the scan's rules settled in 6b |
| `13x99` offered "one result each" - one row cannot become 13 results | Dropped |
| A date format that hides the year was trusted (a sheet named 2019 holds 2020 dates) | Asked when the sheet's name disagrees |
| `45:30` typed for 45 minutes is stored by Excel as 45 hours and counted as "certain" | Durations of 24 h or more, and `:00` seconds in a column that has seconds, are "two readings" |
| Jan asked about XML; the fixtures promised our own export "in each format" | Our own export is read in all four formats; other XML and JSON are refused with a clear message |
| Nobody is told when background work finishes | Bell notifications |
| Small ones: rows hidden by a filter; rows with brand and pieces but no name; a word that means "not owned"; a free player losing the pile's name; one puzzle spelled two ways; the daily cap's wording | Each has its line in the README |

## Open points the reviews closed

| Point | Answer |
|---|---|
| API v1 sync and an old `tracked_at` | Not affected: `GET /me/results` is the full list, lookup is by id, `deleted` pages by `deleted_at` |
| XP for a back-dated result | History is keyed on `tracked_at` (`XpFormulaCutoff::isHistory`), `earned_at` on the solve date; `XpRecomputer::recomputeForPlayer` is the per-player entry point |
| Badges once per batch | Safe (`BadgeEvaluator::recalculateForPlayer`, player lock); new tiers are dated now |
| Which date is read where | `GetStatistics`, feeds, moments, digest: `tracked_at`. Calendar, streak, snapshot: the solve date first |
| Building a result with a chosen `trackedAt` | The constructor takes it and `createdVia`, and records `PuzzleSolved` always |
| Locks | The lock middleware runs for consumed messages; a locked message dispatched inside another handler releases before the outer commit - so pairing an unlisted puzzle is one locked message calling the writer as a service |

## What the reviewers said to keep

Own tables for unlisted puzzles; the distinct puzzle as the unit of pairing; no fuzzy auto-pairing; private notes by
default; guests instead of registered co-puzzlers; first try off; cached formula values only; the writer extracted in
its own change with a parity test; no follower notifications; the remembered layout with "Review"; a re-import never
removes.

## Jan's answers (2026-11-14)

1. **Q2** - unlisted results stay out of charts and rankings until paired: **yes**.
2. **Q3 / D13** - date precision: **store it**.
3. **Q4** - imported history earns the history XP formula: **yes**.
4. **Q5** - two rows that are exactly the same are one result unless the player says two: **yes**.
5. **Q6** - a container on lily for the import's queue: **yes, and more containers or tools where they clearly help**.
6. A cheap model (Claude Haiku / Sonnet): **possible, but not at first** - only for a really hard problem.

Second round, the same day:

7. **Q1** - free or members: **everything free** - the import, custom fields (up to 20), My data including bulk
   changes. (Proposed: bulk changes for members.)
8. **Q7** - the name: **Unlisted puzzles**.
9. **Q8** - limits: **25 MB, 100 sheets, 50,000 rows**. (Proposed: 10 MB, 40 sheets, 10,000 rows.) The design gained
   "Large imports": a streaming reader, every background job under ten seconds, the upload straight to object storage.
10. **Q9** - what is kept: the file 30 days, the rows' content until the player wipes it.
11. **Q10** - who gets it first: **everybody from day one**, built and tested in public, relying on the log and Undo.
    (Proposed: an invited group first.) The design gained "Opening to everybody at once": Undo for chosen rows, a
    switch that pauses adding, an admin view of running imports, warnings.
12. Build order: **custom fields, then the import**; My data follows.
13. Background work is followed **live (Mercure) and ends with an e-mail** carrying the outcome and a link.

No question is open.

## The third file (after the reviews)

A training workbook of 20 sheets from an active MySpeedPuzzling player ([research.md](research.md) "The third file")
changed the design in six places:

| What it showed | Change |
|---|---|
| 1,101 of 1,927 results were already on MySpeedPuzzling; "same puzzle, day and time" finds 97 % of them once the puzzle is known, but a fifth are invisible while the spreadsheet's name is not the catalogue's | The player's own results (same day + time + pieces) are a pairing step of their own; the comparison is described as the heart of the import |
| One puzzle solved 13 times a day, 13 real pairs of equal results - with different attempt numbers | The attempt number is part of a row's identity; "never twice" pairs rows off with existing results one to one and asks once per puzzle, only when the profile has results left over |
| A puzzle list that the results sheets point to; results sheets without a brand | Sheet kinds and a lookup of brand and pieces in another sheet |
| Excel tables under a dashboard of formulas; twenty tables on one sheet; rows of unticked checkboxes; template rows | Excel's own table and freeze marks find the data; default-only and template rows are empty |
| Formula columns for pace, place and percentages; `attempt`, `No box?`, `Entered on MSP?`; tags; status words | New fields, computed columns preset to "Don't import", a "tags" custom field, "not owned" words |
| Drill logs, statistics, goals, a brand list, another person's sheet | "Not puzzles" and "somebody else's" as sheet kinds |

## Third review (2026-11-14, of the revised plan, before building)

One reviewer, the documents and the code, only what the first two had not seen. Three blockers, eleven major points.

| Finding | Change |
|---|---|
| **"Never twice" failed in five sequences:** the attempt number inside the key split one result written on two sheets (the third file's 144); equal rows were collapsed before pairing, so two real equal results on the profile produced a false question; a month-only row could take the partner of an exact row; a hand-typed result dated on its entry day was added silently; "already yours" stuck after an undo, and two waiting imports did not see each other | "Never twice" rewritten as five steps: what exists now (queued rows of other imports included), pairing off with the exact rows first, equal rows across sheets = one, close ones asked per puzzle whatever the date, the rest new. The attempt number left the key |
| **The "own result" hint could move a whole puzzle onto the wrong catalogue puzzle** (one coincidence among 13 solves a day, a rounded time, a pair result matching a solo row) - and the decision is remembered | Ticked only under seven conditions (exact day, real seconds, same type, one-to-one, all rows agree, no conflict, not hidden), otherwise "Probably"; taking results back clears the decision |
| **Custom values inside the result's edit form** would notify the whole team, recalculate everybody's achievements and let member B overwrite A's values | Values have their own form and message; never joined into a shared read (stage 1a) |
| "The page saw the end" could not be built as written | A delayed message checks `seen_at`; the visible page reports; the bell follows the same rule |
| A Mercure topic per import is dropped when another request rewrites the subscription cookie; "after the commit like the round clock" does not carry over to a worker | A topic per player through `MercureTopicCollector`; published by the worker after the write |
| A streaming reader gives no number formats, tables or panes; the measurement was sized for the old limits; chunks would re-open the file | An own pass over the workbook's parts; the measurement on a 25 MB fixture; the file unpacked once |
| The photo upload's class cannot take a spreadsheet (images only) | Presigner and bucket rules reused, the rest the import's own; the hash comes from the worker |
| Notification and e-mail were under-specified; a bounced address would still get a transactional e-mail | The pieces listed; the e-mail on the `notifications` stream with its own switch |
| Two partners importing the same pair results would leave permanent public duplicates | A row problem |
| The row offset in seconds crossed midnight at 50,000 rows; the player's time zone was ignored | Microseconds; `player.timezone` first |
| A switch in an environment variable needs restarts | In the database |
| `AddImportRows` would block a web worker on the lock | On the `imports` queue |
| Parse jobs could overwrite each other; the lookup sheet's id travelled into a remembered layout | `mapping_version`; signature and title |
| The export's XML cannot take a field's name as an element | `<custom name="…">` |
| Stage notes: deploy order of the queue, an entry point before stage 8, the tests each stage trips, the source's name | In the plan's stages |

## Mock-ups against the revised design

The canvas shows the first draft's six steps. Not redrawn yet:

- "Values to check" is no longer a step of its own - the same screen is the "To fix" group, opened from Puzzles.
- "Sheets" and "Columns" are one screen per layout, and columns we are sure about collapse into one line.
- "We found N - add them now" after the first layout is missing.
- "Probably" is drawn like "Found"; in the revised design nothing in it is ticked until the player looked.
- The notice of what becomes public before the first "Add" is missing.
- Bring the file: the Google link comes first.
