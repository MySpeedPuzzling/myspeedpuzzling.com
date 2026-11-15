# Adding an import's rows to the profile (stage 6b-1)

The engine of "Add": the ready rows of a player's import become results, collection puzzles and wish list puzzles -
in the background, never twice, with Undo, a safety switch and an audit page. **No player-facing button calls it
yet** (stage 6b-2 wires the screens); what exists here is messages, handlers, the worker's jobs, the admin page and
the tests.

The add-result form is not touched: results are written only by stage 6a's `ImportedResultWriter` and what is owed
for them only by `ImportedResultsFollowUp` ([writer.md](writer.md)); a result is deleted only through
`PuzzleSolvingTimeRepository::delete($time, SolvingTimeDeletedVia::ImportUndo)`.

| Piece | What it is |
|---|---|
| `AddImportRows` → `AddImportRowsHandler` | "Add" from the request: checks, queues the ready rows for a batch, hands it to the worker. Answers an `AddImportRowsOutcome` |
| `data_import_batch` (`DataImportBatch`) | One "Add": its request id, how far it is, what came of it - kept for good |
| `ProcessImportBatch` → `ProcessImportBatchHandler` | The worker's turn (`imports` queue, outside a transaction): the first pass, then row after row |
| `ApplyImportRow` → `ImportRowAdder` | One row, one transaction |
| `ImportNeverTwice` | What the player already has, asked per row under the player's lock |
| `WriteImportRowCustomValues` | The player's own fields of a row, after the row |
| `FinishImportBatch` | The follow-up once, the counts, ONE notification, ONE e-mail |
| `UndoImportRows` → `data_import_undo` → `ProcessImportUndo` → `UndoImportRow` | Undo of rows / a batch / the import |
| `data_import_settings`, `ChangeImportAddingPaused` | The safety switch |
| `ContinueImportBatches`, `myspeedpuzzling:continue-import-batches` | The sweeper |
| `/admin/imports`, `/admin/imports/batches/{id}` | The audit page and the admins' Undo |
| `GetImportAdding`, `ImportProgress::publishAdding()` | Progress for the import's page (`type: import_adding` on the player's topic) |

## Ready

A row is **ready** - derived, never stored (`DataImportRowRepository::queueReadyRows()`):

- it is data (`kind` result / owned / both / wish_list), without a problem, on a sheet that is in;
- its distinct puzzle is decided as a **catalogue** puzzle (the rules' "found", the player's choice, a remembered one);
- a row with a result has an **exact day** (`parsed.datePrecision = day`);
- it waits (`state = waiting`), was not refused before (`failure IS NULL`) and no Undo holds it - **or** it was
  settled as "already yours" with a result that is gone since (undone, deleted): "already yours" is always what
  exists now.

Not ready, and said so in the outcome's `skipped` (the screens word it):

| Code | Why |
|---|---|
| `unlisted_not_yet` | The puzzle is kept as an unlisted puzzle - stage 7. The row is **not** added and stays waiting |
| `needs_exact_day` | A result known to the month or year only, or undated - stage 6c. A month is never stored as a day |
| `over_daily_limit` | More than `DataImportLimits::MAX_RESULTS_ADDED_PER_DAY` (2,000) results of the player's imports today (UTC); the rest is ready tomorrow. Owned and wish-list rows are not counted |

A row that was **refused** before (below) is left alone by "add everything that is ready" and taken again only when
it is asked for by its id or its puzzle's - the player fixed or answered something.

## The flow

1. **`AddImportRows`** (request id, import, player, selection: everything ready / row ids / import-puzzle ids) -
   serialized per player (`SerializedByLock`, `data-import-add-<player>`), one transaction, every check before the
   one statement that queues:
   the import is the player's (else "not found") → the same request id answers the same batch → the safety switch
   (`paused`, nothing queued) → the import can add (`setting_up` / `reviewing`, content not wiped) → no other "Add"
   of the player has queued rows (`already_running`) → rate limit `data_import_add` (120 an hour) → the daily guard
   → `queueReadyRows()` marks the rows `queued` with the batch's id → the batch is saved and `ProcessImportBatch`
   goes to the `imports` queue. Nothing is added in the request.
2. **`ProcessImportBatch`** - a turn of at most 50 rows or 8 seconds (`DataImportLimits::ROWS_PER_TURN`,
   `SECONDS_PER_TURN`), then the message sends itself again, so other players' jobs take their turns in between:
   - `BeginImportBatchTurn`: the batch runs and adding is not paused, else the turn ends;
   - **the first pass** (once per batch; its place is `data_import_batch.settled_up_to`): every queued row with a
     result is asked whether the player already has exactly that result (`ImportRowAdder::settle()`);
   - **adding**: `ApplyImportRow` per row - its own transaction; then `WriteImportRowCustomValues` for a row that
     was added and carries values of the player's own fields;
   - a row that throws: its transaction is rolled back, `RecordImportRowFailed` marks it `failed` / `error`, the
     batch goes on. The first exception of the turn in which a batch's first rows fail is logged **once**, at
     error, with the count; the message names ids only;
   - no queued row left → `FinishImportBatch`.
3. **`FinishImportBatch`** - one transaction under the batch's lock, so two deliveries finish it once:
   `ImportedResultsFollowUp::afterResultsAdded()` once for the batch (statistics per puzzle, `AwardXpForSolvingTime`
   per result, `RecalculateBadgesForPlayer` per registered person, one `BackfillSolvingTimePredictions` per player),
   the batch's counts from its rows, ONE `ImportRowsAdded` notification, ONE `SendImportAddedEmail` when
   `player.import_emails` is on. More than a tenth of the rows failed on our side → a warning.

The rows are taken in the order of their ids (uuid v7, given as the file was read) - about the file's order.
`tracked_at` does not depend on it (the writer uses the row's number).

## The states of a row

`waiting` (ready is derived) → `queued` (an "Add" took it; `batch_id`) →

| State | When |
|---|---|
| `added` | The row made something: `solving_time_id` + `result_made`, `collection_item_id`, `wish_list_item_id`, `made` |
| `already_yours` | Everything the row says is the player's already - `already_yours_how`: `profile` (a result on the profile), `this_row` (its own result is still there), `earlier_import` (the same data in another import), `equal_row` (the same result as another row of the file), `owned` (in the collection / on the wish list / owned or solved, for a wish). `already_yours_solving_time_id` names the result |
| `waiting` + `failure` | **Refused** by a rule: the row waits again with the reason code |
| `failed` + `failure = error` | The row threw - ours. Never tried again by itself; asked for by id it is |

After an Undo a row is `waiting` again (the README's "ready"; `DataImportRowState::Ready` and `Undone` stay unused).

### Refusals

`ImportedResultRefusal` (the writer's: `puzzle_not_found`, `puzzle_not_revealed`, `empty_group`, `first_try_taken`,
`too_fast`, `time_too_long`, `comment_too_long`) and `ImportRowRefusal`:

| Code | Rule |
|---|---|
| `not_ready` | Not ready any more when its turn came (the decision taken back, a problem since) |
| `unlisted_not_yet`, `needs_exact_day` | As above, met at the row's turn |
| `puzzle_not_available` | The catalogue puzzle may not be chosen any more (hidden, secret) - `GetImportPuzzleCandidates::choosable()`, the rule the decision passed |
| `people_do_not_fit_type` | The row says pair/team and names nobody (or says solo and names people): the writer would make a result of another kind - on another leaderboard |
| `similar_result_exists` | The profile has a result of the puzzle that no row speaks for with the same time on another day, or the same day with another time (README step 4) |
| `somebody_elses_result` | A pair's/team's row: somebody else already has a pair/team result of the puzzle with this day and time |
| `said_already_on_profile` | The file says "already on MySpeedPuzzling" and no such result was found |
| `equal_row_not_added` | The row it is the same result as was not added |
| `comment_too_long` | An owned puzzle's comment over 500 characters |

The three questions of "never twice" (`similar_result_exists`, `somebody_elses_result`,
`said_already_on_profile`) are waved through per row by the player's answer `answers.addAnyway` (a list of the
codes) - **6b-2 writes it**; nothing here does.

**A row is all or nothing**: everything that can refuse it is checked before anything is created; a row that is a
result and an owned puzzle whose result is refused adds no collection item.

## A row, step by step (`ImportRowAdder::add()`)

1. `ImportWriteLock::lockUntilCommit(player)` - the writer's own lock, first statement. The row is read after it;
   only a row still `queued` in this batch is touched.
2. Ready still? Its puzzle decided as a catalogue puzzle, no problem. The puzzle still choosable for the player and
   writable (`SecretPuzzleAccess`). **A puzzle merged since the pairing is the survivor already**: a merge moves
   `data_import_puzzle.puzzle_id` (`puzzleMergedInto()`, stage 5a), so no `puzzle_redirect` is followed here.
3. **The result** (kind result / both):
   - an equal row (`same_as_row_id` without `add_as_separate`): the row that stands for the group is added first if
     its turn has not come, and this row is that result (`equal_row`);
   - the row's own result still exists (`this_row`) / a row with the same `source_key` made one in any import of
     the player (`earlier_import`) - the writer's questions, asked first;
   - the player has the same result on the profile, not spoken for (`profile`);
   - the questions above; then `ImportedResultWriter::write()` - `groupPlayers` are the row's people, one per
     entry, **a `#code` is a registered player as in the add form**; no time = no first try; the row's comment is
     the public comment;
   - two equal rows the player confirmed as two (`add_as_separate`) get a `ResultDuplicatePrevention`
     (`saved_anyway`, via `spreadsheet_import`).
4. **The collection puzzle** (kind owned / both): the player's **system collection**; an item that is there makes
   that part "already yours" and is never touched (not its comment, not its values). The new item's
   `PuzzleAddedToCollection` is taken off (its one reaction removes a wish list item behind Undo's back) and the
   wish list's "remove when added to my collection" is honoured here and written down.
5. **The wish list puzzle** (kind wish_list): unless it is on the list, owned in any collection, or solved.
6. The row is `added` with what it made, or `already_yours`.

### What a row remembers

`solving_time_id` (+ `result_made`: the link alone stays after an Undo), `collection_item_id`, `wish_list_item_id` -
only what the row **created** - and `made`:

```json
{"result": {"puzzleId": "…", "seconds": 1800, "day": "2024-01-01", "teamId": null},
 "collection": {"collectionId": null}, "wishList": true,
 "removedWishListItems": [{"id": "…", "puzzleId": "…", "addedAt": "…", "removeOnCollectionAdd": true, "why": "solved | collection"}],
 "customFields": {"result": ["<field id>"], "collectionItem": [], "leftOut": 1},
 "keptByUndo": "<undo id>"}
```

Ids and numbers only - never a cell's content, never a value of a field.

## Never twice - each step with what holds it

| Step | Held by |
|---|---|
| A resent "Add" (double tap, retry, second tab) answers the same batch | `data_import_batch.request_id` unique + `AddImportRows::lockKey()` per player (`AddImportRowsHandler`, step 2) |
| Two "Add"s at once | One statement queues a row (`queueReadyRows()`: `UPDATE … WHERE state IN (waiting, failed)`); one "Add" of a player at a time (`already_running`) |
| A job delivered twice, a restarted worker, two workers | A row leaves `queued` in the transaction that writes it, under the player's lock; `ImportRowAdder::queuedRow()` reads it after the lock - the second finds it not queued (`skipped`) |
| A batch that is not the row's | `DataImportRow::isQueuedIn($batchId)` |
| The row already made a result that exists | `ImportRowAdder::knownResult()` and again the writer (step 2 of its own list): `this_row` |
| The same data in another import (same file again, renamed, re-saved, reordered) | The row's `source_key` against every row of the player's imports, under the lock, at write time: `knownResult()` + the writer (step 3): `earlier_import`. 5a's hint is not trusted - it is asked again |
| The link row → result | The writer's compare-and-set and the unique index on `solving_time_id` (6a) |
| Equal rows of one file | `same_as_row_id` → one result (`equal_row`), unless `add_as_separate` |
| A result typed by hand before | `ImportNeverTwice`: the player's results of the puzzle (tracked, or a registered member), the same day + time + solo/pair/team, **one row per result** (`solvingTimeIdsSpokenFor()`); the batch's first pass settles them before anything is added, so the rows' order changes nothing (13 solves of a day, 5 typed before → 5 pair off, 8 new) |
| A result that may be the row written differently | Not added by itself: `similar_result_exists` |
| Two partners importing the same evening | `somebody_elses_result` |
| A result that was undone or deleted | Does not count anywhere above: existence is always asked of the results as they are now |
| Collection puzzles | The check under the player's lock (the system collection has no uniqueness in the database) |

Of the README's five steps: 1, 2 (exact days), 3 and 5 are built as written; **step 4 is a refusal with a code
instead of one question per puzzle** (the question's screen is 6b-2's); the piles of step 2 for month-only, year-only
and undated rows wait for 6c with those rows.

## The player's own fields

`parsed.custom` (by ready-made field key) and `parsed.notes` (as private notes) are written **after** the row is
committed (`WriteImportRowCustomValuesHandler`), through THE two writers - `SetSolvingTimeCustomValues` for the
result the row made, else `SetCollectionItemCustomValues` for the item it created; never on something that was
there before. A ready-made field the player lacks is added for them (`AddCustomField::readyMade()`). A value that
cannot be stored (no room for a field, a value the type refuses, nothing to put it on) is left out and counted
(`made.customFields.leftOut`); it never fails the row. Nothing of a value is logged: a failure names the exception's
class only.

## Telling the player

One `ImportRowsAdded` notification per batch (`notification.target_data_import_batch_id`, its own branch of
`GetNotifications`, the row in `notifications/_rows.html.twig`) and one e-mail `emails/import_added.html.twig`
("N added, M already yours, K could not be added" + the link to the import) on the `notifications` stream, with a
signed one-click unsubscribe (`ImportEmailsUnsubscribeUrl`, `/{_locale}/import-emails/unsubscribe/{playerId}`) of
`player.import_emails` (default on; the switch is in "Messaging & Notifications" of the profile settings). All 6
locales.

Nobody else is told anything: the writer takes `PuzzleSolved` off every result (no follower notification, no
duplicate case, no per-result statistics), nothing is published per row but the progress to the player's own
private topic, and a collection item's event is taken off too.

## Undo

`UndoImportRows(requestId, import, by, scope: rows | batch | import, …)` - serialized per import; the rows of the
selection that are `added` are marked with the Undo's id (`data_import_row.undo_id`, one statement) and
`ProcessImportUndo` goes through them in turns like an "Add" - resumable, each row once. A batch that still runs is
stopped first (its queued rows wait again, it is finished with what it added). **Undo is never paused by the safety
switch.**

**Restores / removes** (`UndoImportRowHandler`):

- the result the row made: deleted through `PuzzleSolvingTimeRepository::delete(…, ImportUndo)` - graveyard,
  tombstones for API clients (`GET …/deleted`; a re-added result has a new id, so no tombstone names a living
  result), the puzzle's statistics by the delete's own event - then as `DeletePuzzleSolvingTimeHandler`:
  `CompensateXpForDeletedSolve` per result, `RecalculateBadgesForPlayer` (partners per result, the owner once at
  the end);
- the collection item and the wish list item the row created;
- wish list items the row removed ("remove when solved / when added to my collection"): back with their id and
  date, unless the puzzle is on the list again;
- the values of the player's own fields go with the result / item they were written on;
- the row waits again and can be added again.

**Keeps, on purpose:**

- **a result changed since the import** - its puzzle, time, day or people are not what the row wrote
  (`made.result`): the whole row stays `added`, counted as `results_kept_edited`, marked `made.keptByUndo`. For a
  batch, an import and an admin always; for chosen rows too **unless** the player sends `includeEdited: true` -
  6b-2 decides whether to ask ("this result was changed since - remove it too?"); the outcome exposes the fact
  either way;
- a collection item moved to another collection since (`collection_items_kept`);
- a result that is gone already (nothing to take back);
- a ready-made field the import added, and empty pairs/teams (the existing cleanup removes them).

Who, when, why and what happened stay on `data_import_undo` for good.

## The switch

`data_import_settings.adding_paused` (+ who / when / why), flipped on `/admin/imports`
(`ChangeImportAddingPaused`; pausing needs a reason; every flip is a warning in the log). Paused: no "Add" starts,
running batches stop after the row they are at and wait, the sweeper leaves them. Reading, mapping, pairing and
Undo stay open. On again: the sweeper continues the waiting batches. `docs/features/feature_flags.md`.

## The audit page

`/admin/imports` (admins only - under `^/admin`, `ADMIN_ACCESS`; English only): the switch; the latest batches
across players (player, file, state, rows, added / already theirs / refused / failed), the latest Undos (who, why,
what happened), the latest imports. `/admin/imports/batches/{id}`: the batch's numbers, its rows by outcome with the
reason code and what each made by id (a link to the result) - **never a cell**, and **"Undo this batch"**: the
players' own `UndoImportRows`, credited to the admin, reason required.

What is auditable for good: every batch (request, counts, times), every Undo (who, why, counts), on each row its
last batch, its state, its reason and what it made; every deleted result whole in `solving_time_graveyard`
(`deleted_via = import_undo`); every imported result by `created_via = spreadsheet_import`.

## What is never deleted

An import that ever queued a row cannot be discarded (`DiscardImportHandler` → `ImportCanNotBeDiscarded`, 409): its
rows' keys and links are what "never twice" and Undo rest on.

## What 6b-2 calls

- **Add**: `AddImportRows(Uuid::uuid7() per press - in a hidden field, kept across a re-render, $importId,
  $playerId, rowIds: …, importPuzzleIds: …)`; read the `AddImportRowsOutcome` (`status`, `id`, `rows`, `skipped`).
- **Progress**: `GetImportAdding::ofPlayersImport()` and the `import_adding` messages on the player's topic.
- **The questions**: write `data_import_row.answers.addAnyway` (a list of refusal codes), then "Add" the row by id.
- **Undo**: `UndoImportRows(Uuid::uuid7(), $importId, $playerId, DataImportUndoScope::Rows|Batch|Import, batchId:,
  rowIds:, includeEdited:)`.
- **Discard**: hide it for an import with a batch (the handler refuses).
- The wording for `skipped` and every refusal code.

## The emergency runbook

1. **Pause**: `/admin/imports` → "Pause adding" with a reason. (Or SQL: `INSERT INTO data_import_settings (id,
   adding_paused, adding_paused_reason, adding_paused_changed_at) VALUES (1, true, '…', now()) ON CONFLICT (id) DO
   UPDATE SET adding_paused = true`.) Running batches stop within a row.
2. **Find** the batch on `/admin/imports` (or `SELECT * FROM data_import_batch ORDER BY started_at DESC`).
3. **Undo a batch**: its page → "Undo this batch" with a reason. It works while adding is paused. Results the
   player changed since are kept and counted.
4. **A player's imported results**: `SELECT * FROM puzzle_solving_time WHERE player_id = '…' AND created_via =
   'spreadsheet_import'`; which row made which: `SELECT id, batch_id, solving_time_id, made FROM data_import_row
   WHERE solving_time_id = '…'`. A deleted one: `solving_time_graveyard WHERE deleted_via = 'import_undo'`.
5. **A batch that stands still** (a row that can not even be marked failed stays queued and keeps the player's next
   "Add" answering `already_running`): Undo the batch - its queued rows wait again and it is finished.
6. **Switch adding on again** on the same page; the sweeper continues waiting batches within 5 minutes.

Cron (lily): `*/5 * * * * … php bin/console myspeedpuzzling:continue-import-batches`.

## The tests

`tests/MessageHandler/DataImport/`: `ImportAddingTest` (results - solo, a pair with a `#code` partner, without a
time -, owned, wish list, both; not ready; a merged puzzle; a hidden puzzle; a first-try conflict; people that do
not fit; the player's own fields; paused; another player; one at a time + the daily guard; the e-mail once and not
when switched off; discard refused), `ImportNeverTwiceTest` (the same file twice, two more rows, a resent "Add", a
job delivered again, a row applied twice, a rival batch, a killed worker, pairing off one to one, similar results,
equal rows, a partner's result), `ImportUndoTest` (a batch with wish-list restore, the edited-since case for batch /
import / row, a row and the whole import, owner and admin, a running batch while paused), `ImportBatchLargeTest`
(2,000 rows: flat memory, ≤ 45 statements a row all in). `tests/Controller/Admin/ImportsControllerTest`.

## Where the README / plan and the code disagreed

| README / plan | Built | Why |
|---|---|---|
| `data_change_batch` + append-only `data_change` log + `data_import_row_outcome` | `data_import_batch`, `data_import_undo`, and what a row made **on the row** (the stage's brief) | Undo is exact and bounded by the row; the audit is the batches, the Undos and the graveyard. No per-change log - a row re-added after an Undo overwrites what it made before (its earlier result is in the graveyard) |
| `ContinueImportBatch` / `ApplyImportRow` / `FinishImportBatch`, 25 rows a turn; "never twice" settled set-based when the batch is created | A first pass per batch + checks per row under the player's lock, 50 rows a turn | The per-row check is the one that holds at write time; the first pass makes it independent of the rows' order |
| Step 4: one question per puzzle | A refusal code per row, waved through by `answers.addAnyway` | The question needs a screen (6b-2). A row not added can be added later |
| Co-puzzlers of a file are guests, never registered (D12) | A `#code` in the row's people is a registered player, as in the add form (the brief asks for it) | **A decision for Jan**: it puts a result on another player's profile from a file. Guests only = strip the `#` in `ImportRowAdder::people()` |
| Owned rows go to the collection the sheet names / a member picks | Always the system collection | Choosing a collection needs a screen and the membership check; `parsed.collection` stays on the row |
| A row both result and owned: several collections per row | One item | As above |
| Rows return to "ready"; states `ready`, `undone` | `waiting` (ready is derived) | A stored "ready" would go stale with every decision |
| A failed row "shows up in To fix"; a refusal "goes back to To fix" | Refused = `waiting` + `failure` (can be parsed again); threw = `failed` | A `failed` row is never parsed again (`canBeParsed()`), so a rule's refusal must not be `failed` |
| The e-mail a minute later, not when the page saw the end (`seen_at`) | Sent when the batch finishes | No page exists yet to report "seen" - 6b-2 |
| A notification and an e-mail for a finished Undo | None | Not in this stage's scope |
| XP "once per batch"; "`RecalculateBadges` once" | As 6a's follow-up does it | writer.md "XP" |
| "Follow `puzzle_redirect`" before a row is written | Not needed: the decision's foreign key moves with the merge | 5a |
| Time verification: every row judged by the scan's classifier before "Add" | **Not built** | Not in this stage's scope - must be settled before 6b-2 opens the button (below) |
| Night Owl / hour-of-day chart leave imports out | **Not built** | The same |
| Taking back "landed on the wrong puzzle" clears the remembered decision | **Not built** | Undo keeps the decision; the player changes it on the pairing screen |

## Loose ends

- **Before 6b-2 opens "Add" to players**: the time verification's judgement of rows before "Add" and the scan's
  rules for imported results (a 2,000-row import must not open hundreds of cases and e-mails); the readers of
  `created_via` (Night Owl - an import run at night of a result solved today is tracked "a second ago" -, the
  hour-of-day chart).
- The cron row for `myspeedpuzzling:continue-import-batches` on lily (every 5 minutes) - without it a paused or
  interrupted batch waits for ever.
- D12 (`#code` partners) - Jan's word.
- `DataImportRow::parsedAs()` does not clear `failure`: a refused row that is parsed again keeps its reason until
  it is asked for by id.
- An Undo tells nobody (no notification, no e-mail).
- The 2,000-a-day guard counts by UTC day.
- `ImportProgress::publishAdding()` publishes a new message type; the screens that read it are 6b-2's.
