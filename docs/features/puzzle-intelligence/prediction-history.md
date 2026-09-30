# Prediction history - storing the prediction on every solving time

Status: **design, not built** (2026-09-30). Scope of the first delivery: store the prediction when a time is
added or edited + a one-time (re-runnable) backfill. The UI comes later - see [`../../TODO.md`](../../TODO.md)
§"Prediction history".

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
| `live` | the time is added (or edited in a way that invalidates the prediction) | the insights tables as they are at that moment, before the new time reaches them; prior attempts limited by the rule above |
| `reconstructed` | backfill (and the self-healing cron) | today's model fed only with data that existed before the solve (details in §Backfill) |

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

- Enums `TimePredictionMethod`, `TimePredictionSource` in `src/Value/`.
- Readonly value object `SolvingTimePrediction` with named constructors `predicted(TimePredictionResult, source, computedAt)`,
  `notPredictable(source, computedAt)`.
- `PuzzleSolvingTime` gets the nine properties (flat columns, the project has no embeddables) changed only through
  two named methods:
  - `recordPrediction(SolvingTimePrediction $prediction): void` - **records no domain event**. Important: a
    `PuzzleSolvingTimeModified` per row would run the incremental insights recalculation 450k times in the backfill.
  - `forgetPrediction(): void` (private use) - back to NULL = "evaluate again".
- The entity invalidates by itself: `modify()` calls `forgetPrediction()` when the puzzling type, the solve date
  (`finished_at`) or "has a time" (`seconds` null ↔ set) changed; `replaceTeam()` / `transferOwnership()` when the
  puzzling type or the owner changed. Changing only the seconds, comment, photo, competition, first-attempt flag
  keeps the prediction (that is the whole point - the outcome follows the new seconds).
  `migrateToPuzzle()` (duplicate merge) keeps it: the survivor is the same puzzle.

## Live recording

New service `SolvingTimePredictor::predict(PuzzleSolvingTime $time): null|SolvingTimePrediction`:

1. not solo or no seconds → null (nothing to record, the row stays NULL);
2. otherwise `GetPlayerPrediction::forPuzzle($playerId, $puzzleId, excludeTimeId: $id, before: SolveMoment)` →
   `predicted` or, when it returns null, `notPredictable`;
3. any `Throwable` → log **warning** with `'exception' => $e` and return null (status stays NULL, the cron fills
   it). Storing a prediction must never cost the player their time.

`GetPlayerPrediction` gets an optional `null|SolveMoment $before` (value object: `solvedAt` + `trackedAt`):

- personal query adds `AND (COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at) < (:solvedAt, :trackedAt)`;
- the gap bucket is measured from the last prior solve to `$before->solvedAt` instead of `clock->now()`;
- without `$before` it behaves exactly as today (puzzle detail page, bulk queries untouched).

Call sites:

| Handler | When |
|---|---|
| `AddPuzzleSolvingTimeHandler` | after `new PuzzleSolvingTime(...)`, before `persist()` - the new row is not flushed yet and the sync `PuzzleSolved` recalculation has not run, so the insights tables are still "before this solve" |
| `EditPuzzleSolvingTimeHandler` | after `modify()`, only if the entity forgot its prediction |
| API `CreateSolvingTimeProcessor` | dispatches `AddPuzzleSolvingTime`, nothing extra |

Cost per add: the same 2-4 small indexed queries the recap already runs.

**Back-dated adds are not predicted live.** 27 % of all times are entered more than a day after the solve
(143,598 rows). For them today's tables would leak everything that happened after the solve date - including the
player's own later solves in the baseline and the ratios. So: when the solve date is before the tracking day
minus one, the handler records nothing and dispatches `BackfillSolvingTimePredictions($playerId)` **async** - the
very same message the backfill uses - and the row gets a `reconstructed` prediction a few seconds later. The same
applies to an edit that moved the solve date. `live` therefore always means "solved today or yesterday, predicted
from the state the site had right then".

## Backfill

### Command + message (the house pattern)

`myspeedpuzzling:backfill-solving-time-predictions [--player=<uuid>] [--limit=<players>]`

- The command only lists players and dispatches; the logic is in the handler (tests test the handler).
- `GetPlayersWithPendingPredictions` → `SELECT DISTINCT player_id FROM puzzle_solving_time WHERE predictable IS NULL AND puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL ORDER BY player_id` (the handler loads with the same condition).
- For each player: `dispatch(new BackfillSolvingTimePredictions($playerId))`. The message implements
  `RequiresFreshEntityManagerState`, so `ClearEntityManagerMiddleware` clears the entity manager *before* each
  player - the identity map never grows past one player's times (worst case 2,346 entities in one flush, which is
  fine for the unit of work). Belt and braces like `SyncWjpfIdentitiesConsoleCommand`: the command also calls
  `$entityManager->clear()` + `gc_collect_cycles()` every 50 players and prints memory in the progress bar.
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

Loads the player's pending times as **objects** (`PuzzleSolvingTimeRepository::findWithPendingPrediction($playerId)`),
computes, calls `recordPrediction(...)` with source `reconstructed`; the `doctrine_transaction` middleware flushes.
No DQL `UPDATE`, no domain events.

The inputs are loaded **once per player** and everything else is PHP (`PredictionReconstructor`, a service with
`ResetInterface` for the memoised global data):

| Input | How | Fidelity |
|---|---|---|
| Player's times | 1 query: all their times (id, puzzle, pieces, seconds, solved_at, tracked_at, type, suspicious, unboxed, first_attempt) ordered by the solve tuple | exact |
| Prior attempts, gap | filtered in PHP per time | exact |
| Player improvement ratio | the player's own transitions dated before the solve, same median + min 3 samples (reuse `ImprovementRatioCalculator`'s in-PHP transition code) | exact |
| Global improvement ratios | all transitions preloaded once per process (~150k rows), a snapshot per calendar month computed lazily and memoised ("data before the 1st of the solve month") | month granularity |
| Player baseline for the piece count | the player's first attempts dated before the solve, weights relative to the solve date (not `now`), ≥ 5 → direct, else interpolated/extrapolated from the other piece counts' *as-of* direct baselines | exact |
| Scaling exponent (extrapolation) | today's value, 1 query, memoised | approximation |
| Puzzle difficulty | 1 query per player: first attempts of *other* players on the puzzles this player solved, with their index `seconds / baseline` and the solve date; per time the indices dated before the solve (≥ 5, ceiling 5.0) → median, p25, p75 | the indices use **today's** baselines of those players (the live model does that too) |

**"First attempt" as of the solve.** Baseline and difficulty pick a player's first attempt of a puzzle as
"`first_attempt = true` first, else the oldest". Reconstructed, that choice is made **only among solves dated
before the solve being predicted** - otherwise a later solve flagged `first_attempt` would pull the future in.

**Global ratio snapshots are cached**, not only memoised: a month's snapshot goes into a Symfony cache pool with a
24 h TTL. The messenger worker resets services after every message, so an in-memory memo would reload ~150k
transition rows for every async back-dated add; the command benefits from the memo, the worker from the cache.

The two approximations are documented and small; the one thing that matters - **nothing from the solve itself or
after it leaks in** - holds. Personal predictions (150k) are exact apart from the monthly global ratio.

The pure pieces must be extracted, not copied, so live and reconstructed use one model:

- `PlayerBaselineCalculator`: the weighted-median-with-decay over a list of solves and a reference date becomes a
  public pure method (`calculateForPlayer()` keeps working on top of it).
- `PuzzleIntelligenceRecalculator`: the bracketing interpolate/extrapolate choice (Pass 2) moves into a pure method
  on `PlayerBaselineCalculator`.
- `PuzzleDifficultyCalculator`: median/percentile over an index list becomes public and pure.
- `ImprovementRatioCalculator`: global and player medians from a transition list become public and pure.
- `TimePredictionCalculator` is already pure and is used as is.

Guard test: reconstructing "as of now" for a fixture player must equal `GetPlayerPrediction` on the live tables.
If someone changes the model in one place only, that test fails.

### Performance estimate

Per player: 2 queries (the heaviest player's difficulty query reads ~150k rows, most players a few hundred) + PHP
that is O(n²) per piece count in the worst case (2,346 times → a few million cheap operations) + one flush.
Expect roughly 20-60 minutes for all 7,339 players; measure first with `--player=<heaviest>` on a local copy. If
the heaviest players turn out slow, chunk the message (`BackfillSolvingTimePredictions($playerId, max: 500)` and
loop in the command while the handler reports a full chunk) - not before it is measured.

## Rollout

1. Migration (generated) + entity + enums + value object + `forgetPrediction()` invariants.
2. `GetPlayerPrediction` `$before` parameter, `SolvingTimePredictor`, the three handlers. Deploy - from now on
   every new time carries a `live` prediction.
3. Refactor the calculators into pure methods (no behaviour change, existing insights tests are the gate), add
   `PredictionReconstructor`, message, handler, command, the "as of now" guard test.
4. Deploy, run the backfill once on the box (nohup), check counts:
   `SELECT puzzling_type, predictable, prediction_source, count(*) FROM puzzle_solving_time GROUP BY 1, 2, 3` - no solo row with a time may stay NULL.
5. Add the daily cron to lily.srv (`apps/myspeedpuzzling/cron.d`).

## Tests

- `GetPlayerPrediction` with `$before`: later solves ignored, same-day order by `tracked_at`, gap from the solve date.
- `AddPuzzleSolvingTimeHandler`: predicted (personal + statistical), not predictable, team → NULL; a
  throwing predictor still saves the time with NULL.
- `EditPuzzleSolvingTimeHandler`: date / type / time-presence change → recomputed; seconds-only change → kept.
- `BackfillSolvingTimePredictionsHandler`: fills only NULL rows, leaves `live` rows alone, records no domain
  events, second run is a no-op, a super-fast later solve does not change an earlier reconstructed prediction.
- Guard test "reconstruction as of now == live tables".

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
