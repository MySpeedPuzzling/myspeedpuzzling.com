# Prediction history - storing the prediction on every solving time

Status: **live 2026-09-30** - stored on add/edit, production backfilled (393,074 times), self-healing cron 4x a day. The UI comes later - see [`../../TODO.md`](../../TODO.md) §"Prediction history".

## Goal

For every solo solving time keep *what we predicted for that solve at that moment*: the predicted time, the
range (from-to), how it was predicted (personal / statistical), or that it **could not be predicted** (not enough
data). The outcome ("12 % faster than expected", "inside the range") is then a comparison of the stored
prediction with `seconds_to_solve` - on the puzzle page history, the profile, the recap, per-player accuracy
statistics, "beat the prediction" streaks/badges later.

## What the code does today (findings)

- Prediction math lives in `TimePredictionCalculator` (pure), inputs are fetched by `GetPlayerPrediction`
  (one puzzle) and `GetPlayerPredictions` (bulk). Personal prediction = the player's own earlier solo solves of the
  puzzle + improvement ratios; statistical = `player_baseline` × `puzzle_difficulty` (p25/p75 as the range).
- Nothing is stored. The recap page (`AddedTimeRecapController`) and the API POST (`CreateSolvingTimeProcessor`)
  compute the prediction **after** the time is saved, with `excludeTimeId`. That is subtly wrong in two ways:
  1. `PuzzleSolved` is routed `sync` and `RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange` has already
     folded the new time into `player_baseline`, `puzzle_difficulty` and `player_improvement_ratio` by the time the
     recap renders - the statistical prediction "saw" the result it predicts.
  2. `excludeTimeId` excludes only this time. For a back-dated entry, *later* solves of the same puzzle are still
     used as "prior attempts", and the gap is measured to `now`, not to the solve.
- **`finished_at` is a date, not a moment**: on production 527,321 of 527,587 rows are exactly midnight. Solves
  on the same day are only ordered by `tracked_at`. 143,598 times are back-dated by more than a day.
- `player_skill_history` only goes back to 2026-03 (feature launch), so there are no historical snapshots of the
  model inputs to replay - a backfill has to *reconstruct* them from raw times.

Production size (2026-09-30): 529,077 times, 456,765 solo, 439,032 solo usable for insights (time known, not
suspicious, not unboxed), 7,339 players with a solo time (median 11 solo times, max 2,346). Of the usable solo
times 288,915 are a first attempt (→ statistical) and 150,118 a repeat (→ personal).

## Definitions

**"At that moment"** means: the inputs are only the solves that happened *before this one*, where "before" is
`(COALESCE(finished_at, tracked_at), tracked_at) < (this.solved_at, this.tracked_at)` - the same order every
insights query already uses, and the tuple is what keeps two same-day solves of one puzzle in order.

Two sources, stored so the UI can say which one it is:

| Source | When | Model state used |
|---|---|---|
| `live` | the time is added for today or yesterday | the insights tables as they are at that moment, before the new time reaches them; prior attempts limited by the rule above |
| `reconstructed` | backfill, the self-healing cron, back-dated adds, edits that invalidated the prediction | today's model fed only with data that existed before the solve (details in §Backfill) |

`live` is "what the site would have told you"; `reconstructed` is "what today's model would have told you with
what it knew back then". Both are honest - they just must not be mixed silently.

## Data model

Columns on `puzzle_solving_time` (all nullable, so the migration is metadata-only in Postgres - instant on 529k
rows, and old blue-green containers keep inserting without them):

| Column | Type | Meaning |
|---|---|---|
| `predictable` | bool | `true` = predicted, `false` = could not be predicted (not enough data then); **NULL = not evaluated** |
| `prediction_method` | varchar | `personal` / `statistical` (only when predicted) |
| `predicted_seconds` | int | the point prediction |
| `predicted_range_low_seconds` | int | range from |
| `predicted_range_high_seconds` | int | range to |
| `predicted_attempt_number` | smallint | personal only: which attempt was predicted (`personalSolveCount + 1`) |
| `prediction_last_time_seconds` | int | personal only: the attempt before it (the API's `last_time_seconds`) |
| `prediction_source` | varchar | `live` / `reconstructed` |
| `prediction_computed_at` | timestamp | when it was stored |
| `prediction_model_version` | smallint | `TimePredictionCalculator::MODEL_VERSION` at that time - accuracy statistics must never mix two models silently |

Three states, one column:

- `true` - the columns below are filled.
- `false` - a solo time with a known time, but no prior attempt **and** (no baseline for the piece count **or**
  fewer than 5 difficulty indices for the puzzle) at that moment. Expected for plenty of old times - early solves
  of a player, puzzles nobody had solved yet.
- `NULL` - not evaluated. Two kinds: **not predictable by definition** (duo/team, no `seconds_to_solve` - relax
  tracking, unfinished competition results with `pieces_placed`) which stay NULL forever and are never picked up,
  because the UI can tell them apart by `puzzling_type` / `seconds_to_solve` alone; and **pending** solo times with
  a time, which the backfill/cron picks up. An error while predicting leaves the row NULL (retried on the next run
  once fixed) - it never becomes `false`, `false` is reserved for "the data was not there".
- Unboxed and suspicious solo times **are** predicted and stored (the recap shows a prediction for them too). They
  stay excluded as *inputs*, exactly like today. The UI decides whether to show the outcome for them.

**The outcome is not stored.** It is derived on read, so editing `seconds_to_solve` never leaves a stale
percentage behind:

```sql
ROUND(100.0 * (pst.predicted_seconds - pst.seconds_to_solve) / pst.predicted_seconds, 1) AS faster_than_predicted_percent,
pst.seconds_to_solve BETWEEN pst.predicted_range_low_seconds AND pst.predicted_range_high_seconds AS within_predicted_range
```

No index is needed: every read goes through a time the page already has; the backfill's "which players have a
pending row" is one sequential scan per run.

### PHP side

- Enums `TimePredictionMethod`, `TimePredictionSource`, value objects `SolveMoment`, `SolvingTimePrediction`
  (`predicted(TimePredictionResult, source)` / `notPredictable(source)`, carries the model version) and
  `PredictionInputSolve` (the reconstructor's input rows) in `src/Value/`.
- `PuzzleSolvingTime` gets the ten properties (flat columns, `#[Immutable(PRIVATE_WRITE_SCOPE)]`) changed only
  through named methods:
  - `recordPrediction(SolvingTimePrediction $prediction, DateTimeImmutable $computedAt)` - **records no domain
    event**: a `PuzzleSolvingTimeModified` per row would run the incremental insights recalculation 450k times in
    the backfill (pinned by `BackfillSolvingTimePredictionsHandlerTest::testRecordsNoDomainEvents`).
  - private `forgetPrediction()` - back to NULL = "evaluate again".
  - `isPredictionPending()` - solo, with seconds, not evaluated.
- Reading: `GetSolvingTimePrediction::byTimeId()` (null = not evaluated).
- The entity invalidates by itself: `modify()` calls `forgetPrediction()` when the puzzling type, the solve date
  (`finished_at`) or "has a time" (`seconds` null ↔ set) changed; `replaceTeam()` / `transferOwnership()` when the
  puzzling type or the owner changed. Changing only the seconds, comment, photo, competition, first-attempt flag
  keeps the prediction (that is the whole point - the outcome follows the new seconds).
  `migrateToPuzzle()` (duplicate merge) keeps it: the survivor is the same puzzle.

## Live recording

`SolvingTimePredictor::predictAddedTime(PuzzleSolvingTime $time)` (called by `AddPuzzleSolvingTimeHandler`
right before `persist()`):

1. not pending (group, no seconds) → nothing;
2. solved before yesterday → nothing now, the player's reconstruction is queued (see below);
3. otherwise `GetPlayerPrediction::forPuzzle($playerId, $puzzleId, excludeTimeId: $id, before: SolveMoment)` →
   `predicted` or, when it returns null, `notPredictable`, source `live`;
4. the reads run in a **savepoint**: a failing statement would otherwise abort the handler's transaction. Any
   `Throwable` → rollback to the savepoint, **warning** with `'exception' => $e`, row left NULL, reconstruction
   queued. Storing a prediction never costs the player their time (pinned by a test that renames a table).

`SolvingTimePredictor::reconstructIfPending()` (called by `EditPuzzleSolvingTimeHandler` after `modify()`) queues
the reconstruction when the edit made the time pending, **naming the time** (`reevaluateTimeIds`): a
reconstruction of the player that was already running may have written a prediction for the time's old data,
and a named time is re-evaluated even when it is no longer NULL. The handler loads its rows `FOR UPDATE`, so two
reconstructions of one player never interleave and an edit waits until the running one has committed. **Edits
are never predicted live**: the edited time is already inside the insights tables itself.

Queueing = `BackfillSolvingTimePredictions($playerId, $reevaluateTimeIds)` with `DispatchAfterCurrentBusStamp`
(the time must be committed first) and `TransportNamesStamp(['async'])` - the message is unrouted, so the command
handles it synchronously and only these dispatches go through the worker. The message deliberately does **not**
implement `RequiresFreshEntityManagerState`: that middleware would clear the entity manager in the middle of the
add/edit web request (it runs on sending too). The command clears after every player, the worker after every
message (`DoctrineClearEntityManagerWorkerSubscriber`).

`GetPlayerPrediction` gets an optional `null|SolveMoment $before` (value object: `solvedAt` + `trackedAt`):

- personal query adds `AND (COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at) < (:solvedAt, :trackedAt)`;
- the gap bucket is measured from the last prior solve to `$before->solvedAt` instead of `clock->now()`;
- without `$before` it behaves exactly as today (puzzle detail page, bulk queries untouched).

Call sites:

| Handler | When |
|---|---|
| `AddPuzzleSolvingTimeHandler` | after `new PuzzleSolvingTime(...)`, before `persist()` - the new row is not flushed yet and the sync `PuzzleSolved` recalculation has not run, so the insights tables are still "before this solve" |
| `EditPuzzleSolvingTimeHandler` | after `modify()`: queues the reconstruction if pending |
| Recap page, API `CreateSolvingTimeProcessor` | `GetSolvingTimePrediction::resultForTime()`: the stored prediction; while pending (back-dated) computed now with `$before` - personal part exact, baseline/difficulty of today |

Cost per solo add: a savepoint pair + the 2-5 small indexed queries the recap used to run (the API query budget
test counts them as `LIVE_PREDICTION_RECORDING`).

**Back-dated adds are not predicted live.** 27 % of all times are entered more than a day after the solve
(143,598 rows). For them today's tables would leak everything that happened after the solve date - including the
player's own later solves in the baseline and the ratios. So: when the solve date is before the tracking day
minus one, the handler records nothing and dispatches `BackfillSolvingTimePredictions($playerId)` **async** - the
very same message the backfill uses - and the row gets a `reconstructed` prediction a few seconds later.
`live` therefore always means "solved today or yesterday, predicted from the state the site had right then".

## Backfill

### Command + message (the house pattern)

`myspeedpuzzling:backfill-solving-time-predictions [--player=<uuid>] [--limit=<players>]`

- The command only lists players and dispatches; the logic is in the handler (tests test the handler).
- `GetPlayersWithPendingPredictions` → `SELECT DISTINCT player_id FROM puzzle_solving_time WHERE predictable IS NULL AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL ORDER BY player_id` (the handler loads with the same condition).
- For each player: `dispatch(new BackfillSolvingTimePredictions($playerId))`, then `$entityManager->clear()` -
  the identity map never grows past one player's times (worst case 2,346 entities in one flush) - and
  `gc_collect_cycles()` every 50 players; the progress bar prints memory.
- Per-player `try/catch`, warning in the output, abort after 10 consecutive failures.
- **Resumable and idempotent**: only NULL rows are touched, `live` rows never. Kill it any time, run it again.
- Run on the box detached (`nohup … exec -T web php bin/console …`). Allowed to run for hours.
- Then keep it as a **daily cron** - it only has work when something is NULL: rows inserted by an old container
  during a blue-green deploy, a live prediction that failed, a `DeletePlayer` group change that turned a team time
  into a solo one. Cheap self-healing instead of edge-case code in every handler.

Optional later: `--recompute-reconstructed` (bulk `UPDATE … SET predictable = NULL WHERE prediction_source =
'reconstructed'`, a genuine bulk operation that says so in a comment) if the model changes and we want to
re-reconstruct. `live` rows are never recomputed - they are history.

### Handler `BackfillSolvingTimePredictionsHandler`

Runs sync from the command and async from the add/edit handlers (back-dated times).

Loads the player's pending times as **objects** (`PuzzleSolvingTimeRepository::findWithPendingPredictionOfPlayer()`),
computes, calls `recordPrediction(...)` with source `reconstructed`; the `doctrine_transaction` middleware flushes.
No DQL `UPDATE`, no domain events. Returns how many times it evaluated (the command sums it).

The inputs are loaded **once per player** and everything else is PHP (`PredictionReconstructor`, a service with
`ResetInterface` for the memoised global data):

| Input | How | Fidelity |
|---|---|---|
| Player's times | 1 query: all their solo times with seconds, ordered by the solve tuple, timestamps as epoch floats | exact |
| Prior attempts, gap | filtered in PHP per time | exact |
| Player improvement ratio | the player's own transitions dated before the solve, same median + min 3 samples (reuse `ImprovementRatioCalculator`'s in-PHP transition code) | exact |
| Global improvement ratios | `ImprovementRatioCalculator::globalRatiosBefore()`: the batch's transition SQL with a cutoff, one query per calendar month ("transitions whose later attempt was before the 1st of the solve month"), memoised + cached | month granularity |
| Player baseline for the piece count | the player's first attempts dated before the solve, weights relative to the solve date (not `now`), ≥ 5 → direct, else interpolated/extrapolated from the other piece counts' *as-of* direct baselines | exact |
| Scaling exponent (extrapolation) | today's value, 1 query, memoised | approximation |
| Puzzle difficulty | 1 query per player (chunks of 1000 puzzles), only for puzzles with a statistical target: every qualifying solve on them with its solver's baseline; per time each solver's first attempt before the solve → `PuzzleDifficultyCalculator::calculateFromSolves()` | the indices use **today's** baselines of those players (the live model does that too) |

**"First attempt" as of the solve.** Baseline and difficulty pick a player's first attempt of a puzzle as
"`first_attempt = true` first, else the oldest". Reconstructed, that choice is made **only among solves dated
before the solve being predicted** - otherwise a later solve flagged `first_attempt` would pull the future in.

**Global ratio snapshots are cached**, not only memoised: a month's snapshot goes into the
`global_improvement_ratio_snapshot_cache` pool with a 7-day TTL (a snapshot ends before the 1st of a month, only
back-dated solves still change it). The messenger worker resets services after every message, so an in-memory memo
alone would rerun the query (~0.6 s each on the production copy) for every async back-dated add.

The two approximations are documented and small; the one thing that matters - **nothing from the solve itself or
after it leaks in** - holds. Personal predictions (150k) are exact apart from the monthly global ratio.

The pure pieces are extracted, not copied, so live and reconstructed use one model:

- `PlayerBaselineCalculator::weightedBaselineSeconds($solves, $now)` (under `calculateForPlayer()`) and
  `gapBaseline()` (the interpolate/extrapolate choice, now also used by `PuzzleIntelligenceRecalculator` Pass 2).
- `PuzzleDifficultyCalculator::calculateFromSolves()` (under `calculateForPuzzle()`).
- `ImprovementRatioCalculator::transition()` (one step, used by the batch preload too),
  `playerRatiosFromTransitions()` (under the batch path) and `globalRatiosBefore()` (pinned equal to the batch
  ratios when nothing is cut off).
- `TimePredictionCalculator` is already pure and is used as is.

Guard tests (`PredictionReconstructorTest`): reconstructing "as of now" equals `GetPlayerPrediction` on freshly
recalculated tables, personal and statistical - if someone changes the model in one place only, they fail. The
no-leak tests were mutation-checked (they fail when the "before" rule is removed).

### Performance (measured 2026-09-30 on the local production copy)

- Heaviest player (2,292 solo times): 25 s, 317 MB peak - most of it the ~38 month snapshots (~0.6 s each), paid
  once per process/week.
- `--limit=300`: 300 players, 50,229 times, 2 min 25 s, no failure → the full ~440k times ≈ 20-30 minutes.
- Run it with `php -d memory_limit=1G`. No chunking needed.

## Rollout

1. ✅ Migration `Version20260930130108` + entity + value objects + invalidation.
2. ✅ `GetPlayerPrediction` `$before`, `SolvingTimePredictor`, add/edit handlers, recap + API read the stored value.
3. ✅ Calculators refactored into pure methods, `PredictionReconstructor`, message, handler, command, guard tests.
4. ✅ 2026-09-30: heaviest player 35 s; full run 27 min, 0 failures (run with `-e SENTRY_DSN=` - the first attempt was
   OOM-killed by Sentry console tracing, command excluded since 7581129d). Counts:
   `SELECT puzzling_type, predictable, prediction_source, count(*) FROM puzzle_solving_time GROUP BY 1, 2, 3` - no solo row with a time may stay NULL.
5. ✅ Cron `34 1,7,13,19 * * *` Prague in lily.srv (c32e756), Sentry monitor `backfill-solving-time-predictions`.

First calibration numbers (2026-09-30, all stored predictions): personal 151,574 - 74.2 % inside the range, median
-0.3 % faster; statistical 191,679 - 32.1 % inside the p25-p75 range, median 9.8 % faster than predicted. The
statistical model looks biased slow on first attempts and its range too narrow - see TODO before building the UI.

## Tests

- `tests/Entity/PuzzleSolvingTimePredictionTest` - what is recorded, what forgets it (date, solo/group, time
  presence, owner) and what keeps it (seconds, comment, flags).
- `tests/Query/GetPlayerPredictionTest` - `$before`: later solves ignored, same-day order by `tracked_at`.
- `tests/MessageHandler/SolvingTimePredictionRecordingTest` - live personal = what the puzzle page showed before,
  not predictable, group untouched, yesterday live, back-dated queued, failing read keeps the time, edits.
- `tests/Services/PuzzleIntelligence/PredictionReconstructorTest` - the two "as of now == live" guards, no leak
  from later solves (personal, statistical, later `first_attempt` flag), same-day order, unboxed = predicted but
  no input, first solve of a piece count = not predictable.
- `tests/MessageHandler/BackfillSolvingTimePredictionsHandlerTest` - only pending rows, never a live one, no domain
  events, second run no-op, every fixture player backfillable.
- `tests/Services/PuzzleIntelligence/ImprovementRatioCalculatorTest` - `globalRatiosBefore()` = batch ratios.
- `CreateSolvingTimePredictionEndpointTest` - budgets include the live recording; the statistical case now needs
  5 difficulty indices *before* the solve (it passed before only because the new time counted itself).

## Decisions (Jan, 2026-09-30)

1. **Players who opted out of predictions**: backfilled and recorded like everybody else, the UI does not show them.
2. **Non-members**: stored for everybody, the UI gates like all insights.
3. **Unboxed / suspicious**: stored, the UI decides later whether to show the outcome.
4. **Outcome**: derived on read, not stored.
5. **`predictable` bool** (nullable) instead of a status enum - "not applicable" is readable from `puzzling_type`
   and `seconds_to_solve`, so it does not need its own value.

## Review 2026-09-30 - gaps found and how the design closes them

| Gap | Resolution |
|---|---|
| Back-dated adds (27 % of times) would be predicted from today's tables → future leaks in | not predicted live; async reconstruction via the backfill message (§Live recording) |
| A later solve flagged `first_attempt` would become the "first attempt" of an earlier reconstruction | first-attempt choice limited to solves before the one predicted (§Backfill) |
| The messenger worker resets services per message → the global-ratio memo would reload 150k rows per async message | monthly snapshots in a cache pool, 24 h TTL |
| Tuning the model later mixes old and new predictions in accuracy statistics | `prediction_model_version` column |
| An error while predicting could be mistaken for "not enough data" | errors leave NULL (retried), only missing data writes `false` |
| Recap page + API POST keep showing their recomputed (leaky) prediction, which will differ from the stored one | switch both to the stored value as part of the first delivery - it is a correctness fix, not UI work (TODO) |
| A time added or deleted *earlier* in the history does not update predictions stored for later solves | accepted: stored predictions are frozen history - `live` by definition, `reconstructed` by choice (`--recompute-reconstructed` exists for model changes) |
| Puzzle piece count changed after the solve, duplicate merges | accepted: reconstruction uses today's piece count; merges keep predictions (same puzzle) |
| Test DB cache holds the old schema | `rm tests/.database.cache` after the migration |

### Code review 2026-09-30 (after building)

| Finding | Outcome |
|---|---|
| Recap/API fallback for a pending time ignored the solve's moment | fixed: `resultForTime()` passes `$before` |
| A running reconstruction could write a prediction for data an edit had just changed, and the edit's own message then skipped the time | fixed: edits name their time (`reevaluateTimeIds`), rows are locked `FOR UPDATE` |
| Date-only edit form re-saving an API time of day counted as a move | fixed: the invalidation compares days |
| `RequiresFreshEntityManagerState` on the message cleared the entity manager inside the add/edit web request | fixed: marker removed, the command clears itself (guarded by a test) |
| Month snapshots re-queried daily | TTL 7 days |
| 7 extra queries per solo add, also for non-members | accepted: the savepoint is the price of "never lose the time", the reads are the ones the recap used to run |
| A merge keeps the stored prediction although the attempt history changes | accepted (decided above: frozen history) |
