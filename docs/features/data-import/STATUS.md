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
| 3 | Import: queue, upload, reading | groundwork live 2026-11-14: the transport `imports` (#345) and its consumer on production (`imports-consumer`, lily D91) and on the sandbox; nothing is routed to it yet; reader: pull request open #347 ([reader.md](reader.md)) | #345 | queue + consumer | consumer up and waiting on both |
| 4 | Columns and values | not started | | | |
| 5 | Pairing and review | not started | | | |
| 6a | The import's writer beside the add form's (handlers untouched) | not started | | | |
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
