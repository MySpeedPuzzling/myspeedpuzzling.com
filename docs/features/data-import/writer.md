# The import's writer of results (stage 6a)

`ImportedResultWriter` makes a `puzzle_solving_time` row of one row of a player's spreadsheet. It stands **beside**
the add form's handlers (`AddPuzzleSolvingTimeHandler`, `AddPuzzleTrackingHandler`): it never calls them, they never
call it, and nothing of the add form was changed to build it (README D4 - Jan, 2026-11-14). The two are held together
by a test, not by shared code: `ImportWritePathParityTest`.

Nothing calls the writer yet. Stage 6b does ("What 6b calls").

| Piece | What it is |
|---|---|
| `Value\DataImport\ImportedResult` | The input: import row id, source key, player, puzzle, seconds or none, solve day + zone, first try yes / no / not said, the other people as the add form passes them, comment, the row's position |
| `Services\DataImport\ImportedResultWriter` | `write(ImportedResult): ImportedResultOutcome` - persists, never flushes; called inside a handler's transaction |
| `Value\DataImport\ImportedResultOutcome` | Added (the new id + what is owed for it) / already added (by this row or by its source key) / refused (a reason code) |
| `Services\DataImport\ImportedResultsFollowUp` | What the add form does per result and an import does once per batch |
| `Services\DataImport\ImportWriteLock` | One writer per player at a time (a transaction-scoped advisory lock) |
| `data_import_row.solving_time_id` | The link row → result, unique |
| `FirstTryAssessor::assessAsActor()`, `GetFirstTryTimes::ofPlayersOnPuzzle(viewerPlayerId:)` | The first-try rules for a given player where nobody is signed in |
| `DuplicateCandidateTime::$createdVia`, `DuplicateClassifier::isCertain()` | An imported result is never removed automatically as a copy |

## The same as the add form

The writer goes the add handler's steps in the handler's order, with the same building blocks:

1. the player and the puzzle are loaded (`PlayerRepository::get()`, `PuzzleRepository::get()`);
2. a secret competition puzzle before its reveal takes nothing (`SecretPuzzleAccess::assertPuzzleWritableBy()`);
3. the people are assembled as `PuzzlersGrouping::assembleGroup()` assembles them - the same `#code` lookup, the
   player first, themself left out - by the writer's own copy, because of one difference (an entry is one person,
   below);
4. a first try is assessed by `FirstTryAssessor` with the same `FirstTryEntry` (as the importing player - "First
   try");
5. the pace limit: 100 pieces a minute per person or more is refused (`SolvingTime::calculatePpm()`);
6. the entity's own constructor builds the result - `puzzlers_count`, `puzzling_type`, `pieces_count_snapshot` come
   from it, as for a typed result;
7. the pair/team is resolved last, once nothing can refuse the result (`PuzzlingTeamResolver::resolve()` - the team
   is created outside the unit of work), so `team` and `puzzling_team_id` are always set together.

Everything that can refuse a row is checked **before** anything is created: a refused row leaves nothing in the
entity manager and no pair/team behind.

## The differences

Every difference between an imported and a typed result. The parity test names each; a column of
`puzzle_solving_time` that is in none of its lists fails it.

| | Typed result | Imported result | Why |
|---|---|---|---|
| `created_via` | `form` / `stopwatch` / `api` | `spreadsheet_import` (`SolvingTimeSource::SpreadsheetImport`) | Readers that need a rule for imports (Night Owl, the hour-of-day chart - stage 6b) tell them apart by it |
| `tracked_at` | now | noon of the solve day in the player's zone + the row's position in steps of 11 s, never in the future, never within 10 s of another result the player tracked of that puzzle (below) | "Now" would put ten years of puzzling into this week's statistics, feeds, digest and moments (README D5) |
| `finished_at` | the picked day, or none ("today") | always the solve day, at midnight like the form's date | An imported result always has a day; only exact days are added until stage 6c |
| Prediction columns | stored live for a solo result of today or yesterday; else left for the backfill | never live: left "not evaluated", the backfill reconstructs it | The insights tables already hold everything after an old solve's date; README "always reconstructed" |
| Photo | optional | none | A spreadsheet has no photo |
| `unboxed` | as ticked | always off | No column for it (yet) |
| First try | as ticked; a conflict is an exception (422), or moved here with the player's "move" answer | off unless the file says so (D15); a conflict is a **refusal outcome** with the assessment, never moved | The player answers the row's question in the import, not here |
| The people | the guest box is split at commas: "Smith, John" is two guests | **an entry is one person**: "Smith, John" is one guest of that name | The import has decided who the people of a row are; splitting again would turn a pair into a team. The parity test saves `['Smith, John']` through the form and `['Smith', 'John']` through the import and gets the same team |
| Longest time | 999:59:59 (the form's fields) | the same, as a refusal (`time_too_long`) | - |
| Event link | competition, round or series as picked; the round derived | none - `competition_id`, `competition_round_id`, `competition_series_id` stay null | The file's "event" column is not a pick from our events |
| Team name | may name an unnamed pair/team | never | Not in a file |
| Mistyped year (`MistypedYearNormalizer`) | 0026 → 2026 | not applied | The import's own date reader decides the year, with the player's answers |
| Resend guards (the id in the form, the 10-second twin check) | yes | replaced by "Never twice" below | An import has no form id; its rows are settled before "Add" |
| `PuzzleSolved` | dispatched on flush | taken off the entity | See "Reactions" |
| `AwardXpForSolvingTime`, `RecalculateBadgesForPlayer` | queued per result by the handler | queued by the follow-up: the award per result, the achievements once per person | See "XP" |

### `tracked_at`, exactly

`ImportedResult::trackedAt($now)`, then `ImportedResultWriter::freeMoment()`:

1. `<solve day> 12:00:00` in the zone - `ImportedResult::zoneOf($player)`: `player.timezone`, else the main zone of
   `player.country` (`CountryCode::defaultTimezone()`), else UTC;
2. plus `(position % 327) × 11` seconds - the row's place among the rows, inside the hour after noon;
3. **never in the future**: at most the second before now. A day solved today is hours ahead at its noon for a
   player in the west, and a player in the east may already be in tomorrow; such a result would stand on top of
   every feed until its noon came;
4. **moved back in steps of 11 s** until no result the player tracked of the same puzzle (saved, or written earlier
   in the same transaction) stands within 10 seconds of it;
5. converted to the server's zone (UTC) like every `tracked_at`.

Why 11 seconds and step 4: to the duplicate rules, two equal results of one player saved ≤ 10 s apart are "the same
form sent twice" - the certain tier, removed automatically (`DuplicateClassifier::CERTAIN_MAX_GAP_SECONDS`). Two
equal rows of a spreadsheet that the player confirmed as two must never look like that, whatever their positions
(next to each other, a whole turn of the 327 steps apart) and however step 3 capped them.

The README says "plus the row's position in **microseconds**". `puzzle_solving_time.tracked_at` is `TIMESTAMP(0)` -
whole seconds. So the order is kept in steps of seconds inside the hour after noon: rows of one day keep their order
while fewer than 327 rows separate them; beyond that it wraps (two rows of the same day more than 327 rows apart).
Only the order of same-day results of different puzzles can be off by that; nothing else reads it. Changing the
column to microseconds would change the heart table for an ordering detail - a decision for Jan.

### The daily duplicate job

`DuplicateClassifier::isCertain()` is false whenever either result has `created_via = spreadsheet_import`
(`DuplicateCandidateTime::isFromSpreadsheetImport()`): an imported result's `tracked_at` is made up, so "saved
seconds apart" says nothing. A pair with an imported result is at most a case the player decides (tier B/C) - it is
never removed by itself. Belt and braces with step 4 above.

## First try

Nobody is signed in where an import is written (a worker), and the first-try read model decides by the signed-in
viewer who may be named (`HiddenPlayers`, `PrivateProfileAccess`): with nobody signed in, a player the importer
blocks would be named, and a private partner who put the importer on their allow list would be hidden. So the
writer calls `FirstTryAssessor::assessAsActor()` - the same assessment with the entry's actor as the viewer **by
id** (`GetFirstTryTimes::ofPlayersOnPuzzle(..., viewerPlayerId:)`: the two rules written out for that id in the
statement). The add form's calls are unchanged - the new parameter defaults to "whoever is signed in" and the SQL of
the default is byte-identical.

As in the form (docs/features/first-try-integrity.md): a co-member hidden from the player is **left out
completely** - no refusal, no line, no name in the outcome; the duplicate first try lands on *their* review page.

## Never twice (the writer's part)

Which rows are added is settled by stage 6b when the batch is created (README "Never twice"). The writer holds the
last line, whatever called it:

0. The writer refuses to run outside a transaction (`LogicException`): the lock below is transaction-scoped and
   would be given back at once.
1. `ImportWriteLock::lockUntilCommit($playerId)` - its first statement. A second delivery of the row, or the same
   data arriving through another import of the player, waits until the first commits.
2. **This row made a result that still exists** → *already added, by the row*. The link is read **from the
   database, after the lock** (`DataImportRowRepository::linkedSolvingTimeId()`) - never from the loaded row: 6b
   loads the row before it calls the writer, and a loaded row is as old as that look.
3. **Another row of the player with the same `source_key` made a result that still exists** - in any of their
   imports → *already added, by the source key*. The row is not linked: the result is the other row's.
4. A result that is gone (deleted by the player, undone) does not count: the row may make another, and the link then
   names the new one. Whether a deleted result *should* come back ("stays deleted unless the player asks") is 6b's
   question, asked before it calls the writer.
5. **The link is written first, as a compare-and-set** (`linkSolvingTime()`: `UPDATE … WHERE solving_time_id IS NOT
   DISTINCT FROM <what step 2 read>`, one row changed or nothing written) - before the pair/team or the result is
   created. Whoever loses answers *already added* and has written nothing. Native SQL, the exception CLAUDE.md
   allows, with its reason at the method.
6. **The database's last word**: a unique index on `data_import_row.solving_time_id` - two rows can never name the
   same result. (No such index is possible for "one result per player and source key": the player is on the import,
   not on the row. Steps 1-3 hold that.)

Because the link is in the database at once, the same row and the same key **twice in one transaction** are seen
too; "does the result exist" looks at the database and at what this unit of work is about to insert
(`resultExists()`).

No raw SQL reads `puzzle_solving_time` here: existence and the moments of the player's results of a puzzle are DQL,
the links are DQL on the rows - nothing for the suspicious-time or trackerless-time coverage guards to list.

## Refusals

A refusal is an outcome, never an exception: the row goes back to "To fix" with the reason code
(`ImportedResultRefusal`), nothing else of the batch is affected.

| Code | The add form's rule |
|---|---|
| `puzzle_not_found` | `PuzzleNotFound` - deleted, or merged and the row did not follow `puzzle_redirect` |
| `puzzle_not_revealed` | `PuzzleNotRevealedYet` |
| `empty_group` | `CanNotAssembleEmptyGroup` - people were named and all of them are the player |
| `first_try_taken` | `FirstTryAlreadyTaken` - the outcome carries the `FirstTryAssessment` (who holds it) |
| `too_fast` | `SuspiciousPpm` |
| `time_too_long` | the form's hours field ends at 999 - above 999:59:59 |
| `comment_too_long` | the form's `Length(max: 500)` on the comment |

Bugs throw: a row that does not exist or is not the player's (`LogicException`), a player that does not exist
(`PlayerNotFound`), an `ImportedResult` that is not one (`InvalidArgumentException`: no real day, an unknown zone,
a time of zero, no source key).

## Reactions

The entity records `PuzzleSolved` in its constructor; five handlers run on it at the flush. The README's rule:
the handlers do not branch for imports - **the writer takes the event off the entity** (`popEvents()`; anything else
the entity may record one day is put back) and what an imported result needs is done by the writer or once per
batch. No handler was changed.

| Reaction to a new result | Typed result | Imported result | Where |
|---|---|---|---|
| `NotifyWhenPuzzleSolved` - followers | one notification each | **none** | event taken off |
| `RemoveFromWishListWhenPuzzleSolved` | the player's item with "remove when solved" goes | **the same, done by the writer**; the outcome carries the removed item (id, player, puzzle, added at) for the log, so Undo can put it back (D18) | `ImportedResultWriter::removeFromWishList()` |
| `DetectDuplicateResultsOnSave` | cases opened at once | **not run** - the import settled its rows before; the daily `detect-duplicate-results` still sees imported results like any other | event taken off |
| `RecalculatePuzzleStatisticsOnSolvingTimeChange` | per result | **once per puzzle touched** | `ImportedResultsFollowUp` |
| `RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange` | per solo result | **not run** - the 15-minute batch folds the results in | event taken off |
| `AwardXpForSolvingTime` (queued by the handler) | per result | **the same message, per result** - see "XP" | `ImportedResultsFollowUp` |
| `RecalculateBadgesForPlayer` (queued by the handler) | per registered person per result | **once per registered person** | `ImportedResultsFollowUp` |
| Prediction (`SolvingTimePredictor::predictAddedTime()`) | live, or `BackfillSolvingTimePredictions` for a back-dated one | **`BackfillSolvingTimePredictions` once per player** with solo results waiting - the same message and stamps | `ImportedResultsFollowUp` |
| Feed, digest, moments, monthly statistics | read `tracked_at` | read `tracked_at` - an old solve is in its own month, not "recent" (D5) | nothing to do |
| E-mails | none per result | none | - |

## XP

The README says "once per player per batch (`XpRecomputer::recomputeForPlayer`)". **Not done**: that rebuild wipes
the whole ledger first - settled bonuses (`difficulty_settlement`, `speed_settlement`) and the audit entries of
deleted solves (`solve_compensation` and what they mirror) are not written again by a replay. It would do that to
the importer and to every registered partner (`#code`) of every imported pair/team result.

So the follow-up queues **`AwardXpForSolvingTime` per added result** - the message the add form queues, the
incremental path (`XpChainRecomputer::awardForNewSolve()`): for every registered person of the result, the chain of
that result's puzzle (and the weekly boost of its week) is brought to what a replay writes; nothing else of their
ledger is touched. `ImportedResultsXpTest` proves it: a player with typed results, a settled bonus and a deleted
solve's audit entries imports a solo result and a pair's result with a registered partner who has a ledger of their
own - every entry either of them had is there afterwards with the same id, amount, reason and moment.

What an imported result earns: its `tracked_at` is its solve day, so a solve before the full formula started is
history (`XpFormulaCutoff::isHistory()`) - **the base, no speed or difficulty bonus, no weekly boost** (README Q4). A
solve after the cutoff earns the full formula, like a typed back-dated one.

**Co-members, plainly:** a registered partner of an imported pair/team result gets the result on their profile and
earns XP for it, exactly as when the importer types it into the form and adds them. Their achievements are
recalculated once. Nothing they had changes - except, as with any back-dated typed result, the chain of that one
puzzle: a partner who already has later results of the same puzzle sees those move one place down the repeat ladder.
**Not reachable from an import (stage 6b-1):** README D12 stands - co-puzzlers of a file are guests. The import
passes every named partner as a guest's name (`ImportRowAdder::people()` takes the `#` off, so the writer never
looks a code up); an imported pair/team result has exactly one registered member, the importer. The writer keeps
the capability (the parity test above uses it) - nothing of an import can call it with a `#code`.

The cost, for 6b to weigh: one job per result, each loading the person's history once - what 2,000 typed results
cost. A batch entry on `XpChainRecomputer` (one scope for all puzzles of a batch) would be the cheap way; it would
change the XP service, so it is not done here.

## What 6b calls, in order

Per row, in `ApplyImportRow` - one row, one transaction:

1. (6b) the import's lock `import:<playerId>` is held by the batch; the row is `queued` and belongs to the batch.
2. Build the `ImportedResult` from the row's `parsed`: `groupPlayers` are **names, one person per entry** (D12 -
   co-puzzlers of a file are guests; a `#code` would add a registered player, exactly as in the form; an entry is
   never split at commas - 6b's parser decides what "Anna, Ben" in a cell means), `timezone` =
   `ImportedResult::zoneOf($player)`, `position` = the row number, `sourceKey` = the row's result key.
3. `ImportedResultWriter::write()`.
4. By the outcome: *added* → the row `added`, `added_at`, `data_change` "created" with the result's id and, when
   `removedWishListItem` is set, "deleted" with the item as `before`; *already added* → `already_yours`;
   *refused* → back to "To fix" with `refusal->value` (and the first-try choices from `firstTry`).
5. (6b, same transaction, with the new id) the form's two answers an import can carry:
   `SuspiciousTimeConfirmation` for "it is right" and `ResultDuplicatePrevention` (`SavedAnyway`, via
   `SpreadsheetImport`) for rows confirmed as two. The writer does neither - it knows no such answer.

(As built in 6b-1 the follow-up is done in chunks - `forResults()` per hundred results with the XP awards on the
`imports` queue, `forPeople()` once when the batch ends; `afterResultsAdded()` is the two in one - see
[adding.md](adding.md) "The end of a batch".)

Once per batch, in `FinishImportBatch`, **in a transaction after the rows are committed**:
`ImportedResultsFollowUp::afterResultsAdded($outcomes)` - puzzle statistics per puzzle, `AwardXpForSolvingTime` per
result, `RecalculateBadgesForPlayer` per registered person, `BackfillSolvingTimePredictions` per player with pending
solo results. 6b keeps what it needs of the outcomes (puzzle id, member ids, pending) or rebuilds it from the log. Calling
it twice repeats nothing harmful.

Still 6b's, not in the writer: the readers of `created_via` (Night Owl, hour-of-day chart), the time verification's
judgement before "Add" and the scan's rules for imported results, the 2,000-a-day guard, following `puzzle_redirect`
before a row is written.

## What Undo needs

- **Which result a row made**: `data_import_row.solving_time_id` (and 6b's `data_change` "created" entry). It is a
  plain id without a foreign key: it stays when the result is deleted - Undo need not clear it (a row whose result
  is gone may make another; the compare-and-set replaces exactly the id it read).
- **Deleting**: `PuzzleSolvingTimeRepository::delete($time, SolvingTimeDeletedVia::ImportUndo)` (the enum case comes
  with 6b) - graveyard and tombstones as always. Only if the result still is what the batch left.
- **The wish list item** the result removed: its id, player, puzzle and `added_at` are in the outcome
  (`removedWishListItem`) - 6b logs them; Undo creates the item again with "remove when solved" on.
- **A first try**: the writer never takes the tag off another result, so there is nothing to give back.
- **Pair/team rows** the result created stay (the existing cleanup removes empty unnamed ones).
- After an Undo: statistics and achievements again for the puzzles and people touched; XP through the deletion's
  own path (`CompensateXpForDeletedSolve`, as every deleted result).

## What a collection writer will need (6b)

Not built here. From reading `AddPuzzleToCollection`'s path for this stage: its own ids the log can name; never
overwrite a comment that is there; check a custom collection is the player's own and that they may use it; "already
yours" when the item exists - under the import's lock, because the system collection has no uniqueness in the
database; the wish list's "remove when added to my collection" honoured and logged like the writer's "remove when
solved"; `custom_values` only through `CollectionItem::changeCustomValues()` (the custom fields' coverage test lists
every caller).

## The tests

`tests/Services/DataImport/ImportWritePathParityTest.php`. For each input it saves the same thing through the real
add path (`AddPuzzleSolvingTime` / `AddPuzzleTracking` on the bus) for one new player and through the writer for a
twin, and compares. The inputs: solo with a time, solo without, a pair with a registered partner, a team with a
guest, a pair of guests without a time, a first try (solo, of a pair, after an earlier untagged result), a comment,
back-dated a year, solved yesterday, solved today, a puzzle the player already solved, a partner who holds an
earlier result, a player with a time zone, two guests typed with a comma, the longest time the form takes.

- **the two rows, every column**: read with `SELECT *`, each column in exactly one of `SAME` (equal), `TWIN` (equal
  once the twin's own id is taken out: `id`, `player_id`, `team`, `puzzling_team_id`) or `DIFFERENT` (the table
  above: `created_via`, `tracked_at`, the twelve prediction columns - each asserted by value). **A new column of
  `puzzle_solving_time` fails the test** until it is put in a list;
- the pair/team (`puzzling_team_member`: the same people in the same places, the twin aside);
- notifications to a follower (1 / 0), the wish list item (gone both ways), tombstones (none);
- the async queue: per-result jobs from the form, nothing from the writer, the follow-up's jobs;
- the puzzle's statistics: the same step, at once for the form, with the follow-up for the import;
- XP: with the typed row's `tracked_at` set to the imported one's (the one input of the formula they differ in),
  a full rebuild writes the same ledger for both;
- **refusals**: too fast, a second first try, a group of nobody but oneself - the form's handler throws its
  exception, the writer answers the matching code, and nothing is saved of either;
- the one difference in reading people: `['Smith, John']` is a team of three for the form, a pair for the import.

`ImportedResultWriterTest`: never twice (the row, the source key, another player's key, a result that is gone, a
loaded row older than the database, the same row and key twice in one transaction, the compare-and-set, the unique
index, no transaction), refusals writing nothing, `tracked_at` (zone, steps, never in the future - west and east,
equal rows never within 10 s, the daily duplicate job removing none), the first-try rules as the importing player
(a public partner named, a blocked or private one left out, a private one who allows the player named).
`ImportedResultsXpTest`: "XP" above. `DuplicateClassifierTest`: a copy from a spreadsheet import is never certain.

## Where the README and the code disagreed

| README / plan | The code | What was done |
|---|---|---|
| `tracked_at` + the row's position in microseconds | the column is `TIMESTAMP(0)` | steps of 11 seconds inside the hour after noon (above) |
| XP "once per player per batch (`XpRecomputer::recomputeForPlayer`)" | that rebuild wipes settled bonuses and the audit entries of deleted solves - of partners too | `AwardXpForSolvingTime` per result ("XP") |
| "The same building blocks (`PuzzlersGrouping`)" | `assembleGroup()` splits an entry at commas | the writer's own copy without the split |
| - | the first-try read model decides by the signed-in viewer; a worker has none | `FirstTryAssessor::assessAsActor()` ("First try") |
| - | the daily duplicate job removes equal results saved ≤ 10 s apart | never for an imported result; steps of 11 s ("The daily duplicate job") |
| `SolvingTimeSource::Import` (README) / `SpreadsheetImport`, `spreadsheet_import` (plan) | - | the plan's: it sits next to `event_import` |
| The link row → result lives in `data_import_row_outcome` (6b) / "on `data_import_row`, columns exist" (6a's brief) | no such column existed | `data_import_row.solving_time_id` added (generated migration); 6b's outcome table may take it over for collection items |
| "The first-try rule" for every result | `AddPuzzleTrackingHandler` stores `first_attempt = false` for a result without a time, whatever was ticked | the writer too: no time, no first try |
| "The same building blocks … `SolvingTimeRoundResolver`" | the resolver answers null without a competition | not called - an imported result has no event |
| Suppress reactions "in the handler by `created_via`" (plan, 6b) / "the writer takes the event off" (README) | `popEvents()` is public and the constructor records only `PuzzleSolved` | the event is taken off; no handler branches. The plan's 6b line "the five handlers check `created_via`" is not needed any more |
| "One backfill job per result" for a typed back-dated result | the message is per player (`BackfillSolvingTimePredictions($playerId)`) | queued once per player by the follow-up |
