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
| 1a | Custom fields on results | not started | | | |
| 1b | Custom fields on collection items, export | not started | | | |
| 3 | Import: queue, upload, reading | not started | | | |
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
9. **This file updated**, the feature's entry in CLAUDE.md and the docs it names, `docs/TODO.md` for loose ends.

Rules that never bend: **the add-result form is never put at risk** (Jan, 2026-11-14: "the most important part of
the whole app … we must never break it at any point") - no stage changes its messages or handlers, anything shown in
it is additive, fails open, has a switch, and is tried in a real browser on the branch before the merge; no real
player data in the repository (fixtures copy shapes with invented puzzles); nothing
committed with `git add -A`; one destructive command per call, never the dev database; production data is only read.

## Log

- 2026-11-14 - a fourth file (Puzzle Tracker's export): known exports as built-in layouts, the source's row id as
  identity, the wish list as a target, words with a meaning, ratings and four more ready-made fields.
- 2026-11-14 - Jan: the add form must never break - stage 1a saves fields after the result, stage 6a no longer
  touches the add form's handlers.
- 2026-11-14 - design written, reviewed twice, revised; third real file analysed; Jan decided every question
  (README "Decided by Jan"). Third review of the revised plan: 3 blockers and 11 major points, all folded into the
  design and the plan before any code.
