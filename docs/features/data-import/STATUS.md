# Data import - status (the living record)

Design: [README.md](README.md) · stages: [implementation-plan.md](implementation-plan.md) · reviews:
[reviews.md](reviews.md). **This file is the record of what is built, shipped and checked** - whoever continues the
work reads it first and updates it last.

Jan, 2026-11-14: the whole plan is built, verified, reviewed and delivered to production autonomously, stage by stage,
public from the first stage. The four real files (in Jan's `~/Downloads`, never in the repository - three spreadsheets and one export of the
app Puzzle Tracker) are tried on the
sandbox from the stage that can read them.

## Where we are

| Stage | What | State | Pull request | On production | Checked on production / sandbox |
|---|---|---|---|---|---|
| 0 | Plan reviewed (third review, of the revised plan) | done - reviews.md "Third review" | - | - | - |
| 1a | Custom fields on results | **live 2026-11-14** | #344 | yes (deploy green, migration ran, 0 new Sentry issues in the hour after) | sandbox, in Chrome as a made-up player: the add form without fields carries nothing of the feature; with a field the result is saved and the value shows on the result. Before the merge, on the branch: invalid value dropped and named, a refused submit keeps the values, no-time mode, collection mode hides the block, the values form in the modal |
| 1b | Custom fields on collection items, export, ratings | **live 2026-11-14** | #346 | yes (deploy green, migration ran) | sandbox, in Chrome: a rating and a note saved with a new result; the results export carries `custom` for every row. Before the merge, on the branch: the stars in the add form, the item's edit form (comment untouched), nothing of the values on the collection page |
| 3 | Import: queue, upload, reading | groundwork live 2026-11-14: the transport `imports` (#345) and its consumer on production (`imports-consumer`, lily D91) and on the sandbox; nothing is routed to it yet. **The reader merged** (#347, nothing calls it). **3a live** (#350: upload + pages, unlinked), **3b live 2026-11-15** (#351: reading + live progress); **3c live 2026-11-15** (#352: Google Sheets link). Stage 3 is complete | #345, #347, #350, #351, #352 | queue + consumer | consumer up and waiting on both |
| 4 | Columns and values | **4a value readers live 2026-11-14** (#348; nothing calls them yet). **4b-1 live 2026-11-15** (#353: recognise + parse, known exports); **4b-2 live 2026-11-15** (#354: the screen); 4c (memory) not started | #348, #353, #354 | yes | deploy green |
| 5 | Pairing and review | not started | | | |
| 6a | The import's writer beside the add form's (handlers untouched) | **merged 2026-11-15, dormant** - nothing calls it | #355 | yes | parity test in CI |
| 6b | Add, never twice, Undo | not started | | | |
| 6c | Date precision | not started | | | |
| 7 | Unlisted puzzles | not started | | | |
| 2a-c | My data | not started | | | |
| 8 | Entry points and the guide | not started | | | |

## How every stage is built

1. **A brief** from the design: scope, files, what is out, the tests that prove it, the exit of the stage.
2. **Built in its own worktree** under `.claude/worktrees/` on a branch `data-import/<stage>` cut from `origin/main` -
   never in the shared checkout (other sessions work there). `vendor/`, `node_modules/` and `public/build/` come from
   the main checkout, mounted read-only.
3. **Gates in one container at a time** (`docker run --rm` on the compose network, the worktree at `/app`; the Docker
   VM is small): `composer run phpstan`, `composer run cs-fix`, `cache:warmup`, the tests the change touches with
   plain `phpunit` on a test database of its own named `speedpuzzling_<stage>_test`. The full suite runs in CI.
4. **Migrations are generated, never written:** a scratch database built from the committed migrations
   (`speedpuzzling_migcheck`), `doctrine:migrations:diff` against it, the file read line by line. Never a diff against
   the dev database (it drifts), never a `DROP` of anything not created by the stage. Custom indexes by hand, `custom_`
   prefix, mirrored in `tests/bootstrap.php`, registered in `docs/database-indexes.md`.
5. **Texts in all 6 locales** in the same pull request, key parity checked by script.
6. **A pull request**, CI green (3 test shards, phpstan, migrations-up-to-date).
7. **An independent code review** of the diff by an agent that did not write it, and the diff read by the
   orchestrator; a client-side path is not "done" until it ran in a real browser.
8. **Squash merge**, one stage at a time. Then: the deploy job, the site answers, Sentry `firstSeen:-1h` on
   production and sandbox, the new pages opened in Chrome on production (own account) and sandbox, the migration ran.
9. **This file is kept by the orchestrator only** - a stage's branch never edits it (three merge conflicts taught
   that); the feature's entry in CLAUDE.md and the docs it names, the feature's entry in CLAUDE.md and the docs it names, `docs/TODO.md` for loose ends.

Rules that never bend: **the add-result form is never put at risk** (Jan, 2026-11-14: "the most important part of
the whole app … we must never break it at any point") - no stage changes its messages or handlers, anything shown in
it is additive, fails open, has a switch, and is tried in a real browser on the branch before the merge; no real
player data in the repository (fixtures copy shapes with invented puzzles); nothing
committed with `git add -A`; one destructive command per call, never the dev database; production data is only read.

## Log

- 2026-11-15 - **stage 6a merged, dormant** (#355): `ImportedResultWriter` + `ImportedResultsFollowUp` beside the add
  form's handlers (those untouched - empty diff), held together by `ImportWritePathParityTest` (every column of
  `puzzle_solving_time` accounted for). Review before the merge: a row delivered twice could have written two
  results (now a fresh read under the lock, a compare-and-set link, a unique index on the link); "today" could be
  dated in the future (clamped); the daily duplicate job could auto-remove equal imported rows (never the certain
  tier for an imported row; rows 11 s apart); the first-try check assumed a signed-in viewer (`assessAsActor`, an
  optional viewer id on `GetFirstTryTimes` - the one change in code the form shares, default path unchanged, read by
  the orchestrator); XP through `AwardXpForSolvingTime` per result instead of a full rebuild that would have wiped
  settlements. `tracked_at` = noon + the row's position x 11 s (the column holds whole seconds).
- 2026-11-15 - **stage 4b-2 live** (#354): the Sheets and columns screen (`…/import/{id}/columns`, one page per
  sheet, the need-a-look list). Tried at 390 px with the real files on the branch: questions with the sheet's own
  examples, save → re-read → counts. Changed after that test: a question no longer blocks its sheet - only the rows it
  decides wait (the ten-sheet workbook: 3,584 rows ready at once instead of 2,059); readings over 99 hours are not
  offered. On the sandbox the earlier upload shows 3,227 ready / 39 need a look / 8 sheets left out. Not editable
  yet: "keep as my own field", another table or header row, the lookup sheet.
- 2026-11-15 - a test of 3b was flaky in a parallel run (the shared temp folder asserted empty while another
  worker read) - failed once on main after #354, fixed on main (7d88f523e). Two pull requests stalled silently on a
  `docs/TODO.md` conflict: stage branches no longer edit `docs/TODO.md` either - they report loose ends, the
  orchestrator writes them.

- 2026-11-15 - **stage 4b-1 live** (#353): the recognisers (regions, columns with certainty, sheet kind, rows that
  are not data, the puzzle-list lookup), known exports (Puzzle Tracker's CSV, our own results export), the mapping
  as data on the sheet with its questions, `ParseImportSheet` (typed values, problems, keys, equal rows linked),
  `ConfirmImportMapping` (no screen yet). Review: no blocker, nine fixes before the merge (date order guessed on a
  one-row majority; duplicate parse jobs; a repeated header in a known export blocked the import; unbounded reads).
  The real files on the branch, locally: Puzzle Tracker's CSV 458 of 460 rows understood, no question; the ten-sheet
  workbook 2,059 understood / 54 need a look / 4 sheets waiting for an answer; the training workbook 3,227 / 39, eight
  sheets "not puzzles", one sheet of 113 rows left out as unsure. Whether "understood" is understood *correctly* is
  judged with the screen (4b-2, building). Left: a row that is both a result and an owned puzzle carries one key
  (docs/TODO.md).

- 2026-11-15 - **stage 3c live** (#352): a Google Sheets link on "Bring the file" (link first): the worker fetches the
  xlsx export from docs.google.com and Google's `doc-…-sheets.googleusercontent.com` only, every redirect hop checked,
  the address pinned, 25 MB counted while streaming, a sign-in page never stored; failures told apart (not shared, no
  such sheet, too large, refused by Google, our side) with "Try again". Security review: merge, four small fixes done
  first. Tried on the branch and on the sandbox with Google's public example sheet (fetched, stored, read); on the
  branch also a Docs link (refused at the form) and an unknown id. Not tried: a sheet that is not shared.

- 2026-11-15 - **stage 3b live** (#351): `ReadImportFile` on `imports` (one streaming pass per sheet, rows in batches
  of 500, budget 60 s CPU / 300 s wall, the cap on distinct blank runs), same file again → the first import, live
  progress on the player's private topic with polling as the fallback, "Try again", the safety net
  `myspeedpuzzling:read-waiting-imports` (cron every 5 min at :02 on production and the sandbox, lily 9b05ae5).
  Measured through the whole handler at 512 MB: 50,000 x 20 in 11.4 s wall / 6.1 s CPU, +13.6 MiB. Review: no
  blocker; fixed before the merge - records worked on a stale import (a discard during a read could come back as
  failed), the safety net queued duplicates, the download had no time bound, the page polled forever. Left as is
  by Jan's earlier word (data in Sentry is wanted): a failed batch write carries its rows into the Sentry event.
  Tried in Chrome on the branch (three of the sample files: 20 sheets / 5,209 rows; 10 sheets; the CSV 460 rows; the
  same file twice; the 3-unfinished refusal) and on the sandbox (the 4.5 MB workbook read in under 6 s, the page
  moved to the sheet list by itself). Not seen: whether that was the live event or the 4 s poll.
- 2026-11-15 - **the test suite's memory**: ParaTest's main process built the test database in-process and kept
  ~210 MB of it; main stood at 523 of 512 MB on a cold runner. `tests/bootstrap.php` now builds it in a child process
  (cold peak 200 MB). Shipped with #351.
- 2026-11-15 - lily cron rows take effect when pushed, not with the app's deploy: the safety-net row ran once before
  the image had the command and once before the sandbox's migration (exit 1 / 7, 01:22 and 01:27 UTC). Next time the
  cron row follows the deploy.
- 2026-11-15 - building in parallel: 4b-1 (recognising columns, parsing rows - no screen) and 3c (Google Sheets link).

- 2026-11-14 - **stage 3a live** (#350): the three import tables, upload straight to storage (fallback form reuses
  the started import), Imports home / Bring the file / one import / Discard, daily prune (cron 03:44 on production and
  the sandbox, lily f0eea94 - installed with the next deploy). Review: no blocker, five fixes before the merge (a
  discarded file could be put back through the still-valid signed URL; the "3 unfinished imports" rule could be
  bypassed by parallel starts; the prune held a transaction across storage calls; a failed confirmation left a
  hidden import; the fallback cost two imports). Tried in Chrome on the branch (direct upload, fallback with the
  store blocked, discard) and on the sandbox (a real upload to the real bucket). NOT tried at phone width (the test
  browser would not resize). The pages are linked from nowhere yet. One shard failed on main after the merge on
  `AssetFailureScriptTest` (a timing test of the self-heal script, untouched) and passed on re-run.
- 2026-11-14 - stage 3b (reading with live progress; CPU-time budget, the blank-run cap, one pass per sheet - the
  plan's 2,000-rows slicing is dropped: a streamed sheet can not be resumed) is being built.

- 2026-11-14 - **the reader is merged** (#347) after four security fix rounds; the reviewer's last verdict: merge,
  no blocker (the processing-instruction bypass closed, 60,000 seeded documents of its own without a difference
  between the scanner's and the parser's view). Nothing calls it yet. Left for stage 3b (docs/TODO.md): the 60 s
  budget is wall-clock - on a loaded host the largest allowed file took 18-41 s, so the job's budget must be set with
  the consumer's real load in mind; 1.5 million distinct blank texts are stopped only by the clock.
- 2026-11-14 - stage 3 is split: 3a (tables, upload straight to storage, the imports pages, prune - building),
  3b (reading jobs + live progress), 3c (Google Sheets link).

- 2026-11-14 - **the empty-photo fix is live** (#349, deployed 20:42 UTC; Jan: merge it, he could not reproduce it
  either). A photo the phone hands over empty or unreadable is left out with a note, the result is saved. Checked on
  the sandbox in Chrome (an empty pick dropped with the note; a real photo uploaded and saved); no new Sentry issue.
  Not tried on a real phone. The one affected player gets a mail from Jan (draft in MSP Mailer).
- 2026-11-14 - Jan: form data in Sentry is fine and wanted (the fields' scrubber stays as shipped, nothing more is
  hidden); the mock-ups canvas is gone and not needed.
- 2026-11-14 - the reader (#347): third security review found a way to hide markup from the scanner inside a
  processing instruction; fourth fix round running (any `<?` after the declaration refused, one sequential tokenizer).

- 2026-11-14 - **stage 4a merged** (#348). Its review's blockers, fixed first: a slow hobbyist's `h:mm` column was
  resolved as `mm:ss` without asking; the keys depended on the catalogue's state and on a fold that changes; `Jan 23`
  became an exact day. Keys are now pure functions of the row, `1:<hash>`, one per created thing.
- 2026-11-14 - alert MspPhotoSavesFailing: NOT ours - one player's phone handing over a 0-byte photo, 65 minutes
  before the first deploy of the day. Fix on its own branch (#349, the add form's upload script): reviewed, tried in
  desktop Chrome; held for Jan's word on a real-phone test before the merge.
- 2026-11-14 - the reader (#347): second security review - memory and unmetered access closed, time not bounded on
  libxml 2.9.14 (three crafted XML shapes); second fix round running.

- 2026-11-14 - the reader (#347) is NOT merged: the security review found three ways a hostile file could exhaust
  the worker (parts opened that were never size-checked, no cap on a single text value / shared string / CSV
  line, unbounded work per sheet entry). Fix round: OpenSpout leaves the runtime (test-only, `require-dev`), one
  checked door to every zip entry, caps inside the streaming loops, server faults no longer reported as a damaged
  file. Sheet width is a sanity cap (1,000); a region is read through a window.
- 2026-11-14 - stage 4a (value readers: durations, dates, pieces, yes/no, headers in 6 languages, the keys) started
  on its own branch - pure logic, independent of the reader.

- 2026-11-14 - **stage 1b live** (#346). The independent review found that Sentry attaches a POST's body to every
  event - a player's own values would have gone with any warning during a submit (stage 1a included); fixed in the
  same pull request (`OwnFieldsSentryScrubber`). Own fields are `custom:own:<name>` in the export, ready-made ones
  `custom:<key>`. "Sold for" was taken out of the ready-made fields: marking a puzzle sold deletes the item and its
  values (TODO: carry them into the sold/swapped history).
- 2026-11-14 - the reader is being built on `data-import/3-reader` (OpenSpout added there; the dependency lookup
  needed GitHub access - done by the orchestrator, the lock changed by that one package).
- Sandbox, not ours: adding a result for a puzzle whose picture is not in the sandbox bucket logs
  `UnableToReadFile` (Sentry WEB-FQ, environment sandbox) - seen during the sandbox checks.

- 2026-11-14 - stage 3 groundwork: the import's own queue (#345) and consumer container (lily.srv D91, journal
  2026-11-14 16:33) are live on production and the sandbox, in that order.

- 2026-11-14 - **stage 1a live** (#344): independent review found no blocker and three should-fix items (the resend
  path of the add form, the privacy guard's reach, values lost when duplicate copies are removed) - fixed before the
  merge together with two findings of the browser test. Stage 1b started.


- 2026-11-14 - stage 1b built (`docs/features/custom-fields.md` "Collection items", `docs/features/data-export.md`
  "Own fields"). Settled while building: a collection item's values are a **private property** of the entity with one
  viewer-bound reader - never on a collection page, not for the owner either; the export names a ready-made field by
  its key (`custom:price_paid`) and an own field by its name (`custom:own:Shelf`), since the export's columns are
  English and fixed; a move into a collection that already holds the puzzle fills the staying item's gaps like the merge does.
  Found in the codebase: the collection item's edit form is a full page - it has no modal variant; marking a puzzle
  as sold/swapped removes it from the collections, so that item's values ("Sold for" included) go with it - the
  sold/swapped history keeps its own price.
- 2026-11-14 - review round of 1b (#346). **Found: the Sentry SDK attaches the request body to every event, so a
  warning during any POST with `my_fields[…]` sent the typed values - true for stage 1a in production too.** Closed by
  `OwnFieldsSentryScrubber` (body, frames) and `GenericObjectSerializer` (the two messages). Also: the export's label
  of an own field is `custom:own:<name>`; the guard scans templates and refuses wildcard reads of `collection_item`;
  the item's edit form reads failing open, checks first, saves the comment and then the fields; U+FFFE / U+FFFF are
  refused and left out of XML; "Sold for" is not offered until its value survives "mark as sold".
- 2026-11-14 - a fourth file (Puzzle Tracker's export): known exports as built-in layouts, the source's row id as
  identity, the wish list as a target, words with a meaning, ratings and four more ready-made fields.
- 2026-11-14 - Jan: the add form must never break - stage 1a saves fields after the result, stage 6a no longer
  touches the add form's handlers.
- 2026-11-14 - design written, reviewed twice, revised; third real file analysed; Jan decided every question
  (README "Decided by Jan"). Third review of the revised plan: 3 blockers and 11 major points, all folded into the
  design and the plan before any code.
- 2026-11-14 - stage 1a built (`docs/features/custom-fields.md`). Changed against the plan while building, by the
  owner's word: **the add-result form is never part of it** - the values do not travel inside `AddPuzzleSolvingTime` /
  `AddPuzzleTracking` (both untouched) but are saved after the result by a step that can not fail it, the fields are
  plain inputs beside the Symfony form, a value that can not be stored is left out and never a 422, kill switch
  `CUSTOM_FIELDS_IN_ADD_FORM`. Found in the codebase: a table named `custom_field` can not exist (Doctrine ignores
  `custom_*`) - it is `player_custom_field`; the values column is `custom_values` (`values` is a reserved word).
