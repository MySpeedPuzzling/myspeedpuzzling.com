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
| `data_import_row.solving_time_id` | The link row → result |

## The same as the add form

The writer goes the add handler's steps in the handler's order, with the same building blocks:

1. the player and the puzzle are loaded (`PlayerRepository::get()`, `PuzzleRepository::get()`);
2. a secret competition puzzle before its reveal takes nothing (`SecretPuzzleAccess::assertPuzzleWritableBy()`);
3. the people are assembled by `PuzzlersGrouping::assembleGroup()` - same splitting of names, same `#code` lookup;
4. a first try is assessed by `FirstTryAssessor::assess()` with the same `FirstTryEntry`;
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
| `tracked_at` | now | noon of the solve day in the player's zone + the row's position in seconds (below) | "Now" would put ten years of puzzling into this week's statistics, feeds, digest and moments (README D5) |
| `finished_at` | the picked day, or none ("today") | always the solve day, at midnight like the form's date | An imported result always has a day; only exact days are added until stage 6c |
| Prediction columns | stored live for a solo result of today or yesterday; else left for the backfill | never live: left "not evaluated", the backfill reconstructs it | The insights tables already hold everything after an old solve's date; README "always reconstructed" |
| Photo | optional | none | A spreadsheet has no photo |
| `unboxed` | as ticked | always off | No column for it (yet) |
| First try | as ticked; a conflict is an exception (422), or moved here with the player's "move" answer | off unless the file says so (D15); a conflict is a **refusal outcome** with the assessment, never moved | The player answers the row's question in the import, not here |
| Event link | competition, round or series as picked; the round derived | none - `competition_id`, `competition_round_id`, `competition_series_id` stay null | The file's "event" column is not a pick from our events |
| Team name | may name an unnamed pair/team | never | Not in a file |
| Mistyped year (`MistypedYearNormalizer`) | 0026 → 2026 | not applied | The import's own date reader decides the year, with the player's answers |
| Resend guards (the id in the form, the 10-second twin check) | yes | replaced by "Never twice" below | An import has no form id; its rows are settled before "Add" |
| `PuzzleSolved` | dispatched on flush | taken off the entity | See "Reactions" |
| `AwardXpForSolvingTime`, `RecalculateBadgesForPlayer` | queued per result | not queued - once per player by the follow-up | 2,000 rows would queue 4,000 jobs |

### `tracked_at`, exactly

`ImportedResult::trackedAt()`: `<solve day> 12:00:00` in the zone, plus `position % 3600` seconds, converted to the
server's zone (UTC) like every `tracked_at`. The zone is `ImportedResult::zoneOf($player)`: `player.timezone`, else
the main zone of `player.country` (`CountryCode::defaultTimezone()`), else UTC.

The README says "plus the row's position in **microseconds**". `puzzle_solving_time.tracked_at` is
`TIMESTAMP(0)` - it keeps whole seconds (and Doctrine writes none). So the order is kept in **seconds, inside the
hour after noon**: rows of one day keep their order as long as fewer than 3,600 rows separate them; beyond that the
order wraps (two rows of the same day, the same sheet and more than 3,600 rows apart - not seen in the three files).
An hour after noon is still far from the night-time rules and inside the day in every zone the day is inside at
noon. Changing the column to microseconds would change the heart table for an ordering detail - not done here;
it is a decision for Jan if the wrap ever matters.

## Never twice (the writer's part)

Which rows are added is settled by stage 6b when the batch is created (README "Never twice"). The writer holds the
last line, whatever called it:

1. `ImportWriteLock::lockUntilCommit($playerId)` - its first statement. A second delivery of the row, or the same
   data arriving through another import of the player, waits until the first commits.
2. **This row made a result that still exists** (`data_import_row.solving_time_id`) → *already added, by the row*.
3. **Another row of the player with the same `source_key` made a result that still exists** - in any of their
   imports → *already added, by the source key*. The row is not linked: the result is the other row's.
4. A result that is gone (deleted by the player, undone) does not count: the row may make another, and the link then
   names the new one. Whether a deleted result *should* come back ("stays deleted unless the player asks") is 6b's
   question, asked before it calls the writer.

Step 3 reads the links from the database: two rows with the same key written in **one** transaction without a
flush between them would not see each other. 6b writes one row per transaction (`ApplyImportRow`), so they do.

No raw SQL reads `puzzle_solving_time` here: whether a result exists is asked through
`PuzzleSolvingTimeRepository::findById()`, the links through a DQL query on the rows - nothing for the
suspicious-time, trackerless-time or blocklist coverage guards to list.

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
| `AwardXpForSolvingTime` (queued by the handler) | per result | **`RecalculateXpForPlayer` once per registered person** - the whole ledger; a per-result award and a full replay end at the same ledger. An old solve is history by its `tracked_at` (`XpFormulaCutoff::isHistory()`): the core formula, no bonuses (Q4) | `ImportedResultsFollowUp` |
| `RecalculateBadgesForPlayer` (queued by the handler) | per registered person per result | **once per registered person** | `ImportedResultsFollowUp` |
| Prediction (`SolvingTimePredictor::predictAddedTime()`) | live, or `BackfillSolvingTimePredictions` for a back-dated one | **`BackfillSolvingTimePredictions` once per player** with solo results waiting - the same message and stamps | `ImportedResultsFollowUp` |
| Feed, digest, moments, monthly statistics | read `tracked_at` | read `tracked_at` - an old solve is in its own month, not "recent" (D5) | nothing to do |
| E-mails | none per result | none | - |

## What 6b calls, in order

Per row, in `ApplyImportRow` - one row, one transaction:

1. (6b) the import's lock `import:<playerId>` is held by the batch; the row is `queued` and belongs to the batch.
2. Build the `ImportedResult` from the row's `parsed`: `groupPlayers` are **names** (D12 - co-puzzlers of a file are
   guests; a `#code` would add a registered player, exactly as in the form), `timezone` =
   `ImportedResult::zoneOf($player)`, `position` = the row number, `sourceKey` = the row's result key.
3. `ImportedResultWriter::write()`.
4. By the outcome: *added* → the row `added`, `added_at`, `data_change` "created" with the result's id and, when
   `removedWishListItem` is set, "deleted" with the item as `before`; *already added* → `already_yours`;
   *refused* → back to "To fix" with `refusal->value` (and the first-try choices from `firstTry`).
5. (6b, same transaction, with the new id) the form's two answers an import can carry:
   `SuspiciousTimeConfirmation` for "it is right" and `ResultDuplicatePrevention` (`SavedAnyway`, via
   `SpreadsheetImport`) for rows confirmed as two. The writer does neither - it knows no such answer.

Once per batch, in `FinishImportBatch`, **in a transaction after the rows are committed**:
`ImportedResultsFollowUp::afterResultsAdded($outcomes)` - puzzle statistics per puzzle, `RecalculateXpForPlayer` +
`RecalculateBadgesForPlayer` per registered person, `BackfillSolvingTimePredictions` per player with pending solo
results. 6b keeps what it needs of the outcomes (puzzle id, member ids, pending) or rebuilds it from the log. Calling
it twice repeats nothing harmful.

Still 6b's, not in the writer: the readers of `created_via` (Night Owl, hour-of-day chart), the time verification's
judgement before "Add" and the scan's rules for imported results, the 2,000-a-day guard, following `puzzle_redirect`
before a row is written.

## What Undo needs

- **Which result a row made**: `data_import_row.solving_time_id` (and 6b's `data_change` "created" entry). It is a
  plain id without a foreign key: it stays when the result is deleted.
- **Deleting**: `PuzzleSolvingTimeRepository::delete($time, SolvingTimeDeletedVia::ImportUndo)` (the enum case comes
  with 6b) - graveyard and tombstones as always. Only if the result still is what the batch left.
- **The wish list item** the result removed: its id, player, puzzle and `added_at` are in the outcome
  (`removedWishListItem`) - 6b logs them; Undo creates the item again with "remove when solved" on.
- **A first try**: the writer never takes the tag off another result, so there is nothing to give back.
- **Pair/team rows** the result created stay (the existing cleanup removes empty unnamed ones).
- After an Undo: the follow-up's work again for the puzzles and people touched (statistics, XP, achievements).

## What a collection writer will need (6b)

Not built here. From reading `AddPuzzleToCollection`'s path for this stage: its own ids the log can name; never
overwrite a comment that is there; check a custom collection is the player's own and that they may use it; "already
yours" when the item exists - under the import's lock, because the system collection has no uniqueness in the
database; the wish list's "remove when added to my collection" honoured and logged like the writer's "remove when
solved"; `custom_values` only through `CollectionItem::changeCustomValues()` (the custom fields' coverage test lists
every caller).

## The parity test

`tests/Services/DataImport/ImportWritePathParityTest.php`. For each input - solo with a time, solo without, a pair
with a registered partner, a team with a guest, a pair of guests without a time, a first try (solo, and of a pair), a
comment, back-dated a year, solved yesterday, solved today - it saves the input through the real add path
(`AddPuzzleSolvingTime` / `AddPuzzleTracking` on the bus) for one new player and through the writer for a twin, and
compares:

- **the two rows, every column**: read with `SELECT *`, each column in exactly one of `SAME` (equal), `TWIN` (equal
  once the twin's own id is taken out: `id`, `player_id`, `team`, `puzzling_team_id`) or `DIFFERENT` (the table
  above: `created_via`, `tracked_at`, the twelve prediction columns - each asserted by value). **A new column of
  `puzzle_solving_time` fails the test** until it is put in a list;
- the pair/team (`puzzling_team_member`: the same people in the same places, the twin aside);
- notifications to a follower (1 / 0), the wish list item (gone both ways), tombstones (none);
- the async queue: per-result jobs from the form, nothing from the writer, the per-player jobs from the follow-up;
- the puzzle's statistics: the same step, at once for the form, with the follow-up for the import;
- XP: with the typed row's `tracked_at` set to the imported one's (the one input of the formula they differ in),
  a full rebuild writes the same ledger for both.

`ImportedResultWriterTest` holds what only the writer does: never twice (row, source key, another player's key, a
result that is gone), refusals writing nothing, `tracked_at` by zone and position, the zone's fallbacks.

## Where the README and the code disagreed

| README / plan | The code | What was done |
|---|---|---|
| `tracked_at` + the row's position in microseconds | the column is `TIMESTAMP(0)` | seconds inside the hour after noon (above) |
| `SolvingTimeSource::Import` (README) / `SpreadsheetImport`, `spreadsheet_import` (plan) | - | the plan's: it sits next to `event_import` |
| The link row → result lives in `data_import_row_outcome` (6b) / "on `data_import_row`, columns exist" (6a's brief) | no such column existed | `data_import_row.solving_time_id` added (generated migration); 6b's outcome table may take it over for collection items |
| "The first-try rule" for every result | `AddPuzzleTrackingHandler` stores `first_attempt = false` for a result without a time, whatever was ticked | the writer too: no time, no first try |
| "The same building blocks … `SolvingTimeRoundResolver`" | the resolver answers null without a competition | not called - an imported result has no event |
| Suppress reactions "in the handler by `created_via`" (plan, 6b) / "the writer takes the event off" (README) | `popEvents()` is public and the constructor records only `PuzzleSolved` | the event is taken off; no handler branches. The plan's 6b line "the five handlers check `created_via`" is not needed any more |
| "One backfill job per result" for a typed back-dated result | the message is per player (`BackfillSolvingTimePredictions($playerId)`) | queued once per player by the follow-up |
