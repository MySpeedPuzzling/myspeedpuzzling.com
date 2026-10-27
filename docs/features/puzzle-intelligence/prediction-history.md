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
| `predicted_range_low_seconds` | int | range from - since model v3 the calibrated 50 % "likely" window |
| `predicted_range_high_seconds` | int | range to |
| `predicted_range80_low_seconds` | int | model v3 on: the 80 % range from (NULL on older models' predictions) |
| `predicted_range80_high_seconds` | int | the 80 % range to |
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

## How windows are shown

The stored (and the live) `range_low/high` is the window players see - since Insights Phase 3 the calibrated **50 %
"likely" window** (`docs/features/insights/README.md` §3 "Prediction windows"). It is never shown raw:

- **Rounding** - `Value\PredictionWindow::rounded(low, high, centre)`, the one rule:
  - step: 10 s when the prediction is under 3 min; else 1 min when the raw window is under 20 min wide; else 5 min
    when the prediction is under 1 h, 10 min from 1 h on (one step for both ends, chosen by the centre - like
    `prediction-research.md` §12 measured it);
  - each end to the **nearest** step, a tie goes inward (low up, high down). "Never outward" = never floor the low
    end and ceil the high end (that turned the 50 % window into a 76 % one); nearest keeps it at 51 %;
  - at least one step wide (a window that rounds to nothing becomes the step holding the point prediction, kept
    within the raw window - a 2:56-3:04 window around 2:59 shows 2:50-3:00) and the low end is never 0. Examples in the class docblock, edge cases in `PredictionWindowTest` (incl. a sweep proving no end
    moves more than half a step outward).
- **Text** - `Services\PredictionWindowFormatter` (Twig: `prediction_window(prediction)|prediction_window_range`,
  `seconds|prediction_margin`): "57–64 min" when the window starts under an hour and ends under 90 min (also past
  the hour: "50–70 min"), otherwise in hours ("50min – 2h 20min", "1h 50min – 2h 20min"), "30s – 1min" on the 10-second grid; margins "20s" / "3 min" / "1h 5min".
- **Wording** (`puzzle_intelligence.prediction.window.*`, all 6 locales, `PredictionWindowMessagesTest`):
  "Likely 57–64 min · half of solves land inside" (`likely_half`; cards: `likely` = "Likely 57–64 min").
- **Outcome** (recap) - read against the **shown** window, so "inside" agrees with the numbers on the card:
  inside = "Inside your likely window – only half of solves do"; outside = side + margin to the nearer shown end,
  "3 min faster than likely" / "5 min slower than likely" - never failure wording. A first try (statistical
  prediction) beating the window is framed as the player's margin: "Your margin: 3 min faster than likely" (README
  §3 "First-try predictions"). The old "12 % faster than predicted" / "Right on target!" lines are gone.
  A prediction stored by a model before the calibrated windows (`prediction_model_version` <
  `TimePredictionCalculator::FIRST_CALIBRATED_WINDOW_MODEL_VERSION` = 3, `TimePredictionResult::$calibratedWindow`
  false, set by `GetSolvingTimePrediction`) never claims "half of solves": its range reads "Likely 57–64 min" and a hit
  "Inside your likely window" (`hit_uncalibrated`) - v1/v2 ranges held 29 % of first tries and 76 % of repeats.
- **Surfaces**: the add-time recap (`added_time_recap/_performance_summary.html.twig`, stored prediction + outcome),
  the puzzle page Insights (`puzzle/_difficulty_section.html.twig`, live), the Puzzle Picker card
  (`puzzle_picker/_card.html.twig`), the collections "+ predictions" pill tooltip (`_puzzle_library_item.html.twig`);
  while Insights are shown to the viewer (`INSIGHTS_PUBLIC`, admins), the personal estimate card and list lines take
  the puzzle page's, the Picker card's and the pill's place with the same numbers (`docs/features/insights/estimates.md`).
  The add-time pace check shows a point "usual time" only, the result detail no prediction. API v1 keeps the raw
  seconds: `range_low_seconds` / `range_high_seconds` = the 50 % window, `range80_low_seconds` /
  `range80_high_seconds` (additive, 2026-10) = the 80 % range, null on rows stored before model v3
  (`docs/features/api/README.md`). The point estimate keeps its existing format (`~57min 23s`, `compactTime`, not
  rounded). The 80 % range is not shown on these surfaces - it belongs in the Insights detail sheet (Phase 6), which
  gets it rounded the same way through `prediction_range80(prediction)` (null when the prediction has none).
- **Methodology page**: `puzzle_intelligence.methodology.prediction_statistical_range` describes the calibrated likely
  window (was P25-P75 + safety bounds, all 6 locales).

## Backfill

### Command + message (the house pattern)

`myspeedpuzzling:backfill-solving-time-predictions [--player=<uuid>] [--limit=<players>]`

- The command only lists players and dispatches; the logic is in the handler (tests test the handler).
- `GetPlayersWithPendingPredictions` → the players with a solo time with seconds that is pending (`predictable IS NULL`)
  **or has no side-by-side row of the current model** (`solving_time_prediction`, model v3 on; only once the model is
  fitted) - with `--recompute-reconstructed` also those with a reconstructed prediction, shown or side by side (the
  handler loads with the same condition).
- For each player: `dispatch(new BackfillSolvingTimePredictions($playerId))`, then `$entityManager->clear()` -
  the identity map never grows past one player's times (worst case 2,346 entities in one flush) - and
  `gc_collect_cycles()` every 50 players; the progress bar prints memory.
- Per-player `try/catch`, warning in the output, abort after 10 consecutive failures.
- **Resumable and idempotent**: only NULL rows and missing side rows are written, `live` shown predictions never. Kill
  it any time, run it again.
- Run on the box detached (`nohup … exec -T web php bin/console …`). Allowed to run for hours.
- Then keep it as a **daily cron** - it only has work when something is NULL: rows inserted by an old container
  during a blue-green deploy, a live prediction that failed, a `DeletePlayer` group change that turned a team time
  into a solo one. Cheap self-healing instead of edge-case code in every handler.

`--recompute-reconstructed` (model v3 on): every reconstruction computed no later than the current fit
(`prediction_model_fit.fitted_at`) is replaced by one of the current model and fit - a shown prediction reconstructed by
any model version, and the current model's side-by-side row whose source is `reconstructed` - selected by the
handler's query (no bulk `UPDATE … SET predictable = NULL`, so a run that stops halfway leaves nothing pending, and
running it again continues with what is still older than the fit). `live` rows - shown or side by side - are never
recomputed: they are history.

**Before the model's first fit** (no `prediction_model_fit` row of the current version) the backfill writes no
side-by-side rows at all, and `--recompute-reconstructed` does nothing (the command says so and succeeds): a side row
of v3 computed from the defaults without priors would look fitted, and nothing would ever replace it. Pending times
still get their shown prediction - the post-deploy `--recompute-reconstructed` run replaces those too.

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
| Puzzle difficulty | 1 query per player (chunks of 1000 puzzles), only for puzzles with a statistical target: every qualifying solve on them; 1 query for those solvers' pace history (`PaceTimelineLoader::forPlayers()`); per time each solver's first attempt before the solve, divided by the solver's pace-history row just before *that* solve (`PaceTimeline::paceAt()`, the live difficulty's lookup) → `PuzzleDifficultyCalculator::calculateFromSolves()` | exact since 2026-10 (as-of indices, README "Pace history"): only rows dated before the predicted solve count, also for a solver's "first pace"; the rows themselves pick each solver's first attempts by today's flags |

**"First attempt" as of the solve.** Baseline and difficulty pick a player's first attempt of a puzzle as
"`first_attempt = true` first, else the oldest". Reconstructed, that choice is made **only among solves dated
before the solve being predicted** - otherwise a later solve flagged `first_attempt` would pull the future in.

**Global ratio snapshots are cached**, not only memoised: a month's snapshot goes into the
`global_improvement_ratio_snapshot_cache` pool with a 7-day TTL (a snapshot ends before the 1st of a month, only
back-dated solves still change it). The messenger worker resets services after every message, so an in-memory memo
alone would rerun the query (~0.6 s each on the production copy) for every async back-dated add.

The approximations are documented and small; the one thing that matters - **nothing from the solve itself or
after it leaks in** - holds. Personal predictions (150k) are exact apart from the monthly global ratio.

**Since 2026-10 (Insights stream 3a)** the difficulty indices no longer use the other players' baselines of today:
each index divides by the solver's `player_pace_history` row valid just before that solver's solve, in the live batch
and here alike, so "as of now" still equals live. The reconstruction passes the predicted solve as `knownBefore`: a
solver's *first pace* (the fallback for solves made before they had any pace) counts only when its row is dated before
the predicted solve. Pinned by `PredictionReconstructorTest::testAFirstPaceFormedAfterThePredictedSolveStaysOut`
(mutation-checked). Two small leftovers: a pace row picks the solver's first attempts by today's `first_attempt` flags
(the target player's own baseline is still built as of the solve, below), and its decay is measured to the row's own
solve, not to the predicted one. `TimePredictionCalculator::MODEL_VERSION` is 2 from this change
on (the statistical inputs changed), so accuracy statistics keep the two apart; stored predictions are not recomputed
(frozen history, see the review table).

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

## Model v3 and predictions side by side (Insights stream 3b, 2026-10)

`TimePredictionCalculator::MODEL_VERSION` 3: drift, a shrunk difficulty for puzzles with 1-4 indices, the 500-scaled
pace for thin piece counts and calibrated 50 / 80 % windows (README "Step 8: Time Prediction"). The columns on
`puzzle_solving_time` stay **the shown prediction**; next to them every predictor's prediction is kept per model
version in `solving_time_prediction` (Insights plan §3.5, research §5):

| Column | Meaning |
|---|---|
| `id` | UUIDv7 |
| `time_id` | the solving time (FK, `ON DELETE CASCADE` - it goes with its time; a merge moves the time and keeps it) |
| `predictor` | `statistical` (the shown model), `image`, `blend` (Insights 5d, first tries only - "Image and blend predictions" below; `PredictorKind`) |
| `model_version` | the model version that made it; unique `(time_id, predictor, model_version)` |
| `source` | `live` / `reconstructed` |
| `predictable` | false = no prediction possible from what there was |
| `method` | `personal` (a repeat) / `statistical` (a first try) - an addition to the plan's column list: without it a repeat and a first try without difficulty data could not be told apart |
| `predicted_seconds`, `range50_low/high`, `range80_low/high` | the centre and the two windows |
| `difficulty_used` | first tries: the difficulty (index scale) the centre was built with |
| `difficulty_source` | `solves` (5+ indices) / `shrunk` (1-4 indices, or none and the brand prior alone) / `image` (the picture estimate alone: every `image` row, a `blend` row at k = 0) |
| `k_indices`, `prior_log` | the puzzle's index count then and the prior (log) the difficulty was shrunk toward |
| `pace_source` | first tries: what the pace rested on - `own` (a direct baseline, with its drift) / `interpolated` / `scaled_500` (the 500-piece pace of a thin-size rule) - an addition: the time verification leaves `scaled_500` out |
| `window_bucket` | the calibration bucket of the windows (`WindowBucket::key()`) - an addition: what the calibration report groups by |
| `image_model`, `image_kind` | `image` / `blend` rows: the estimate's model (`ridge-siglip2-dinov2` / `ridge-siglip2-full`) and what the picture shows (`ImageKind`: flat / box / assembled / other / unknown, from its `artwork` analysis) - null on a live row written while the puzzle had no estimate (Insights 5d) |
| `computed_at` | when it was written |

Rules:
- **Live**: `SolvingTimePredictor` computes the statistical predictor's prediction once (prior-only predictions
  allowed) inside its savepoint, records `forDisplay()` of it as the shown prediction (a prior alone is not shown:
  `predictable = false` there) and writes the side rows right after the handler's flush - a plain insert of computed
  values (the reads that could fail ran inside the savepoint). +1 INSERT per solo add, whatever the number of rows
  (since Insights 5d one multi-row INSERT by `LivePredictionRowsWriter`, a `postFlush` listener: three rows through the
  unit of work would be three statements).
- **Reconstructed**: the backfill handler writes the side row of every solo time with seconds that has none for the
  current model version, and replaces it when the time was re-evaluated (an edit). A time whose shown prediction is
  live keeps it and gets a reconstructed side row.
- No domain events (the existing rule).
- Undo of an automatic removal (`RemovedResultSnapshot`) restores the shown prediction (with the 80 % range) but not
  the side rows - the backfill cron writes a reconstructed one again (`PuzzleSolvingTime::restore()` says so).
- The time verification keeps using only predictions v2 would have made, on a rated puzzle and the player's own pace
  (or a personal one): the scan reads the stored prediction unless its side row says `difficulty_source = 'shrunk'` or
  `pace_source = 'scaled_500'`, the form check ignores such a live one.

### Production run for v3 (after the deploy)

In this order - the backfill writes no side-by-side rows until the model is fitted:

1. Fit the model once: `docker compose exec -T web php bin/console myspeedpuzzling:fit-prediction-model` (~7 s the
   first time on the copy of the data, ~5 s afterwards; it writes the prior groups and shares, the fit, and drift +
   spread on every pace-history row, under the pace history writer's lock). Until then predictions use the default
   parameters. Then add its daily cron row (`11 4 * * *`) and the calibration report's (`51 4 * * *`).
2. Then once, detached, right after a slot of the 4×-daily backfill cron (both lock a player's times, so they may
   overlap, but the cron would then take part of the work):
   `nohup docker compose exec -T -e SENTRY_DSN= web php -d memory_limit=1G bin/console myspeedpuzzling:backfill-solving-time-predictions --recompute-reconstructed > /tmp/backfill-v3.log 2>&1 &`
   It writes the v3 side row of every solo time with seconds and replaces every shown prediction reconstructed before
   the fit (v1/v2, and v3 ones written before it); live ones stay. Measured on a copy of the dev data (laptop, no
   debug): 440,716 times of 7,212 players in 56 min (20 of them with a test run alongside), peak 392 MiB, 0 failures.
   Resumable: kill it and run it again - it continues with what is still older than the fit.
3. The 4×-daily cron (`34 1,7,13,19 * * *`) keeps going as before; from then on it finds only new gaps (an old
   container's insert during a deploy, a failed live write, a restored result): a run with nothing to do took 0.7 s
   on the copy (the work list query), so its Sentry monitor is not in danger. Before step 1 it writes only the shown
   predictions of pending times.

## Image and blend predictions (Insights 5d, 2026-10)

Two more predictors side by side with the statistical one, for **first tries** (a repeat's personal prediction does
not use the puzzle's difficulty - no rows), from the puzzle's picture-based difficulty estimate
(`puzzle_difficulty_estimate`, pushed by the image pipeline - `docs/features/insights/image-pipeline.md` §7):

- `image` - pace × drift × exp(estimate): the picture and the pieces alone, whatever the solves say. Window: the
  estimate's own asymmetric 50 % / 80 % intervals combined with the player's part, scaled per window, size group × kind
  × the puzzle's index count then (k = 0 / 1-4) - `TimePredictionCalculator::imageWindow()`, README "Step 8" Prior. `difficulty_source = image`, `prior_log` = the
  estimate, `k_indices` = the puzzle's index count then (what the evaluation groups by).
- `blend` - the statistical model shrunk toward the estimate (`ImageEstimatePrior` in front of brand × pieces): the
  estimate alone at k = 0 (= the image prediction), `shrunk` toward it at 1-4, the solves' median from 5 indices on.

Neither is shown anywhere: the columns on `puzzle_solving_time` stay the statistical prediction, `GetPlayerPrediction`
/ `GetPlayerPredictions` / the Picker never put the estimate into the statistical predictor's prior (README "Step 8",
Prior). Same model version as the statistical rows (`TimePredictionCalculator::MODEL_VERSION`); the estimate's own
model is on the row (`image_model`).

**Which estimate - the as-of rule**:
- **Live** (`GetPlayerPrediction::sideBySide()`, the statistical statement with two more LEFT JOINs - no statement of
  its own): the estimate of the puzzle's current picture, in-sample or not - it was pushed before the solve existed, so
  the solve cannot be in it.
- **Reconstructed** (`PredictionReconstructor::reconstructSideBySide()`, the prior statement with the same joins):
  **only an out-of-fold estimate** (`out_of_fold = true`: the cross-fitted model that made it never saw the puzzle's
  own difficulty label - so no solve of the puzzle, the reconstructed one included, reached it). `out_of_fold = false`
  is pushed only for a puzzle that had no label when the model was trained (the full model, which never saw it either -
  `docs/features/insights/image-pipeline.md` §7), so it would leak nothing today; it is kept out all the same,
  conservatively - the flag is the only evidence the app has of what the model saw, and the next derive re-pushes the
  puzzle out of fold once it is labelled (the pipeline's labelled puzzles with 1+ indices are). Until then the time
  waits, without a row. There
  is no estimate history - a reconstruction uses the out-of-fold estimate current when it runs (like it uses today's
  fit). Pinned by `ImagePredictionsSideBySideTest::testAnInSampleEstimateNeverFeedsAReconstruction` (mutation-checked:
  it fails when the reconstruction takes in-sample estimates).
- **Secret puzzles** (`hide_until` / `hide_image_until` ahead): no estimate, ever - `ImageEstimateSecretPuzzleCanaryTest`.
- Live == reconstructed as of now, statistical, image and blend alike: `ImagePredictionsSideBySideTest`.

**Writing them**:
- Live: a first try always gets both rows. Without an estimate (a puzzle added together with its first time never has
  one) they say not predictable and name no estimate (`image_model` NULL) - the backfill's cue to reconstruct them once
  the puzzle has an out-of-fold estimate. A live row that rested on an estimate is history, like every live row.
- Backfill (`BackfillSolvingTimePredictionsHandler`): the work list adds first tries - their statistical row of the
  current model is a statistical prediction or none was possible - on a puzzle with an out-of-fold estimate of its
  current picture and no image row yet, or only a live one without an estimate (`GetTimesAwaitingImagePredictions`).
  Times on puzzles without an out-of-fold estimate are not listed: a missing estimate never becomes "not predictable"
  in a reconstruction. The work list judges "first try" by the stored statistical row; a time that reconstructs as a
  repeat now (a merge or an earlier solve back-dated in front of it - they do not re-evaluate later times, docs/TODO.md)
  gets reconstructed "not predictable" rows without an estimate, which close it - never listed twice
  (`testATimeThatBecameARepeatLeavesTheWorkListAfterOneRun`). An edit that makes the time no first try any more (or
  leaves it without an out-of-fold estimate) removes its image / blend rows; `--recompute-reconstructed` replaces reconstructed ones from before the fit, like the
  statistical rows.
- `--recompute-image`: also every reconstructed image / blend row older than the out-of-fold estimate its puzzle has
  now (`computed_at < received_at` - a newer derive pushed it). The derive re-pushes every estimate nightly, so this is
  "recompute them all" - the one-off before an evaluation of the estimates, not the cron's.

Measured on a copy of the dev data with a synthetic estimate for every pictured puzzle: 300 players, 52,378 times (all
three predictors written from scratch, 36,224 + 1,637 image rows) in 275 s; the work list's image condition over all
523k times 1.2 s (the "nothing to do" cron run ~0.7 → ~2 s).

### Production run for the image rows (after the deploy)

1. Nothing before the estimates exist: the 4×-daily backfill cron (`34 1,7,13,19 * * *`) picks up each puzzle's first
   tries as soon as the nightly derive has pushed an out-of-fold estimate for it, and the live rows start at once.
2. One-off fill while the image backfill on the Macs runs - optional, the cron gets there too, a night's new estimates
   at a time:
   `nohup docker compose exec -T -e SENTRY_DSN= web php -d memory_limit=1G bin/console myspeedpuzzling:backfill-solving-time-predictions > /tmp/backfill-image.log 2>&1 &`
   (after the running `--recompute-reconstructed` one-off of v3 has finished - both lock a player's times; never two
   at once). Resumable.
3. Before the gate decision (launch, `implementation-plan.md` §8), after the final derive on the complete data:
   `myspeedpuzzling:backfill-solving-time-predictions --recompute-image` (detached, as above - every reconstructed
   image / blend row is made again from the final estimates; on prod expect the length of the v3 fill, ~1 h), then
   `myspeedpuzzling:evaluate-image-estimates` (a few seconds) stores the gate record.

## The candidate model (v4, 2026-10)

The prediction model review's proposals (README "Step 8", "The candidate model (v4)") built as a **candidate**: computed
for every solo time next to the shown model and **never shown**. One more predictor in `solving_time_prediction`:

- `predictor = candidate`, `model_version = CandidatePredictionCalculator::MODEL_VERSION` (7 since "Candidate v7", 6 since
  "Candidate v6", 5 with the taste factor, 4 before - "The taste factor (v5)", "Candidate v6", "Candidate v7" below; all
  2026-10-27) - its own model version,
  so no reader of the v3 rows (calibration report, image evaluation, time verification, Insights) ever sees it;
- first tries and repeats alike (a repeat: the shown centre with the candidate's gap-aware window, `method =
  personal`); `image_model` names the estimate a first try rested on;
- its fit: the `prediction_model_fit` row of its model version (`CandidateModelParameters`), written by its **own**
  command `myspeedpuzzling:fit-candidate-prediction-model` (message `FitCandidatePredictionModel`,
  `CandidateModelRecalculator::fit()`) - never by the shown fit, which is exactly main's: `fit-prediction-model` commits
  v3's row, priors and drift without knowing the candidate exists, so a candidate that fails or runs out of memory
  costs only its own row (`CandidatePredictionsSideBySideTest::testTheShownFitAndTheCandidateFitAreSeparate`). The
  candidate keeps its own tables: the puzzles' evidence in `candidate_puzzle_evidence` (kept current every 15 minutes by
  its own command `myspeedpuzzling:refresh-candidate-prediction-evidence` - only changed rows), its brand prior in
  `candidate_puzzle_prior`. Nothing of v3's tables or columns changes; `puzzle_difficulty` (scores, tiers) is untouched.
- **Switch** `PREDICTION_CANDIDATE_ROWS` (default `0`, docs/features/feature_flags.md): while off no candidate row is
  written anywhere - not by the add form, not by the cron backfill; only an explicit
  `backfill-solving-time-predictions --candidate` writes them. The fits and the evidence refresh run regardless (they
  touch only the candidate's own tables).

**Writing it**:
- Live (`SolvingTimePredictor`, only while `PREDICTION_CANDIDATE_ROWS=1`): after the shown and side-by-side
  predictions are computed, **one statement of its own** (`GetCandidatePrediction::forAddedTime()`: the candidate's fit,
  the puzzle's evidence and brand prior, the player's first tries so far - first tries; the fit and the gap - repeats)
  in **a savepoint of its own** with its own try/catch: a failure there is a warning and a missing candidate row (the
  backfill writes it later), never a lost time or a lost shown prediction
  (`CandidatePredictionsSideBySideTest::testAFailingCandidateCostsNeitherTheTimeNorItsShownPrediction`). The shown
  path's statements are exactly main's - `GetPlayerPrediction` is untouched. Its cost while switched on: three
  statements per added time (the savepoint, the read, its release) and its row in the same INSERT; switched off,
  nothing (the query budget tests run with it off, like production until the fill). The time's own conditions go with it
  (`SolveConditions`: unboxed, at an event).
- The size curve's inputs (README "The candidate's pace: the size curve"): live, two more columns on the candidate's
  own statement (`StatisticalInputsSql::candidatePaceColumns()` - the player's latest pace-history row at every piece
  count, and, only while they have no pace-history row at the puzzle's size, their at most 5 first tries there with each
  puzzle's current candidate difficulty); reconstructed, the player's rows before the solve from the timeline it loads
  anyway, and one more statement only for a first try at a size without an own pace (the few puzzles' stored evidence,
  brand prior and out-of-fold estimate).
- Reconstructed (`PredictionReconstructor::reconstructSideBySide()`): the shown and side-by-side rows exactly as on
  main, then the candidate in a pass of its own wrapped in a try/catch - a failing candidate leaves the shown rows as
  they are (`testAFailingCandidateReconstructionKeepsTheShownPredictions`). Its inputs are the same as live, as of the solve - the evidence
  from the puzzle's solvers' first attempts before it (their pace-history row's drift and spread, its place in the
  pair + 5 as the qualifying count), the current fit's brand prior (like the shown prior's sums), the out-of-fold
  estimate only. Live == reconstructed as of now for first tries
  (`CandidatePredictionsSideBySideTest::testLiveAndReconstructedCandidatePredictionsAgreeAsOfNow`); a repeat's candidate
  equals the shown repeat with the candidate's window on both sides (the shown repeat itself differs by its documented
  month-snapshot approximation).
- Backfill (while the switch is on, or with `--candidate`): once the candidate is fitted, the work lists add every solo
  time without a candidate row of the current model version
  (`GetPlayersWithPendingPredictions::all(withCandidateRows:)`, `findForPredictionOfPlayer(withCandidateRows:)`); the
  handler writes it where missing and replaces it on a re-evaluated time; `--recompute-reconstructed` also replaces
  candidate rows reconstructed before the candidate's fit. Before the first candidate fit nothing is written.

### Production run for the candidate (after the deploy)

1. The migration (on boot) only **creates two new tables** (`candidate_puzzle_evidence`, `candidate_puzzle_prior`) - no
   `ALTER` of an existing table, so it takes no lock anything else waits for (the 04:11 fit included).
2. Two new cron rows on lily.srv, each its own process, so neither can take the shown fit or the 15-minute batch down
   with it (same container and memory limit as their neighbours):
   ```
   41 4 * * *    docker compose run --rm --no-deps messenger-consumer php -d memory_limit=1G bin/console myspeedpuzzling:fit-candidate-prediction-model
   7-59/15 * * * *  docker compose run --rm --no-deps messenger-consumer php -d memory_limit=1G bin/console myspeedpuzzling:refresh-candidate-prediction-evidence
   ```
   The fit 30 minutes after the shown one (04:11, a few minutes long - it reads v3's committed drift); the refresh a few
   minutes after each `recalculate-puzzle-intelligence` run. Then run the fit once by hand (the same command with
   `exec -T web` works too) - without it the candidate does nothing (no fit = no rows, the refresh skips).
3. The candidate rows of every past solo time, detached, resumable (~the length of the v3 fill; never next to another
   backfill run - both lock a player's times):
   `nohup docker compose exec -T -e SENTRY_DSN= web php -d memory_limit=1G bin/console myspeedpuzzling:backfill-solving-time-predictions --candidate > /tmp/backfill-candidate.log 2>&1 &`
   Then `PREDICTION_CANDIDATE_ROWS=1` (new times get their candidate row live, the 4×-daily cron keeps the rest).
4. **Proof is the time split, not the stored rows.** `myspeedpuzzling:evaluate-predictions --fit-before=<date>
   --candidate` (and `--ablation`) fits both models on solves before the date and scores both on the solves after it -
   read only, ~1-2 GB, a few minutes, on a copy or off-peak. `evaluate-prediction-candidate --candidate=candidate:4
   --reference=statistical:3` (docs/features/insights/image-estimate-gates.md "Candidate time models") scores the
   stored rows - and the **reconstructed** ones are in-sample: they come from a fit that has already seen those solves
   (the shown v3 reconstructions are equally in-sample, but v4 has more fitted parameters to profit from it). Read it as
   a consistency check only; as evidence count only its **live** slice (the gate prints live and reconstructed apart -
   rows predicted at the add, after the fit that made them), once there are a few thousand of them.

### The taste factor (v5, 2026-10-27)

The candidate's model version 5 = v4 + each player's taste (README "Step 8", "The taste factor (v5)"). For the rows:

- a v5 row is written exactly where a v4 row was (live behind `PREDICTION_CANDIDATE_ROWS`, the backfill with
  `--candidate`); the v4 rows already stored stay as they are, under model version 4 - nothing deletes them, so v4 and
  v5 can be compared on the times both predicted (`evaluate-prediction-candidate --candidate=candidate:5
  --reference=candidate:4`);
- its inputs: the player's taste state in `candidate_player_taste` (rewritten by every candidate fit: the state after
  each of the player's first tries of the fit window, valid from that first try's moment) and the picture factors of
  the puzzle's current picture (`puzzle_difficulty_estimate.picture_factors` of its fingerprint; never for a secret
  puzzle - the estimate join's rule). Live: two more columns on the candidate's own statement (the player's latest
  state - one primary-key step - and the picture's factors), no more statements. Reconstructed: the latest state valid
  **strictly before** the solve (two statements per run in the candidate's pass: the player's states, the target
  puzzles' factors). A first try added after the last fit enters the player's state with the next fit;
- before the first v5 fit nothing is written (no fit = no rows), like v4.

### Production run for the taste factor (v5)

1. The migration (on boot) only **creates one table** (`candidate_player_taste`) - nothing waits for it.
2. No new cron: the daily `fit-candidate-prediction-model` (04:41) fits the taste with the rest (~1 more minute, its
   peak resident memory unchanged - README "The taste factor (v5)"). Run it once by hand after the deploy:
   `docker compose run --rm --no-deps messenger-consumer php -d memory_limit=1G bin/console myspeedpuzzling:fit-candidate-prediction-model`
   - the summary shows `taste_rows`, `taste_players`, `taste_lambda_u` and `taste_inner_mae_*` (each λ against
   `taste_inner_mae_none`: the taste must lower it), and `taste_states_written`. Until it runs the candidate writes no
   rows (its v5 fit does not exist yet); the v4 rows stay.
3. The v5 rows of every past solo time (resumable, detached, never next to another backfill run):
   `nohup docker compose exec -T -e SENTRY_DSN= web php -d memory_limit=1G bin/console myspeedpuzzling:backfill-solving-time-predictions --candidate > /tmp/backfill-candidate-v5.log 2>&1 &`
4. Proof stays the time split (`evaluate-predictions --fit-before=<date> --candidate`, and `--candidate-off=taste,competition_player`
   for v4 on the same code). The stored reconstructed rows are in-sample for the taste too (today's W).

### Candidate v6 (2026-10-27)

The candidate's model version 6 = v5 + the image prior's own variance from the estimate's interval, the k = 0 picture
term of the window and the unboxed window as a variance of its own (README "Step 8", "Candidate v6"). For the rows:

- a v6 row is written exactly where a v5 row was (live behind `PREDICTION_CANDIDATE_ROWS`, the backfill with
  `--candidate`); the v4 / v5 rows stay under their model versions (`evaluate-prediction-candidate --candidate=candidate:6
  --reference=candidate:5` on the times both predicted);
- its inputs: what v5 reads, plus the estimate's interval (already on every candidate statement) and the look-alike
  numbers of the puzzle's current picture - the `traits` and `piece_scale` analyses of the estimate's fingerprint
  (`PictureLookalikes`; never for a secret puzzle - the estimate join's rule). Live: five more columns on the
  candidate's own statement, no more statements. Reconstructed: the same lookup that carries the picture factors
  (`PictureFactorsLookup`), no more statements. No table and no column changes - no migration;
- before the first v6 fit nothing is written (no fit = no rows), like v4 / v5.

### Production run for v6

1. Nothing to migrate. No new cron: the daily `fit-candidate-prediction-model` (04:41) fits v6. Run it once by hand
   after the deploy (its peak resident memory ~310 MB - README "Candidate v6"):
   `docker compose run --rm --no-deps messenger-consumer php -d memory_limit=1G bin/console myspeedpuzzling:fit-candidate-prediction-model`
   - the summary shows `image_interval_reference`, `picture_dispersion_weight` / `picture_dispersion_rows` and
   `unboxed_variance` (`unboxed_scale` 1). Until it runs the candidate writes no rows (its v6 fit does not exist yet);
   the v5 rows stay. The picture term needs the `traits` and `piece_scale` analyses of the current pictures (on prod
   2026-10-27: 43,497 `traits` and 43,375 `piece_scale` rows of ~43,600 pictures); a puzzle without them gets no term.
2. The v6 rows of every past solo time (resumable, detached, never next to another backfill run):
   `nohup docker compose exec -T -e SENTRY_DSN= web php -d memory_limit=1G bin/console myspeedpuzzling:backfill-solving-time-predictions --candidate > /tmp/backfill-candidate-v6.log 2>&1 &`
3. Proof stays the time split (`evaluate-predictions --fit-before=<date> --candidate`, and
   `--candidate-off=image_interval,picture_dispersion,unboxed_variance` for v5 on the same code).

### Candidate v7 (2026-10-27)

The candidate's model version 7 = v6 + the difficulty-adjusted pace (estimated jointly with the puzzles' difficulty)
and the player's own difficulty slope (README "Step 8", "Candidate v7"). For the rows:

- a v7 row is written exactly where a v6 row was (live behind `PREDICTION_CANDIDATE_ROWS`, the backfill with
  `--candidate`); the v4-v6 rows stay under their model versions (`evaluate-prediction-candidate --candidate=candidate:7
  --reference=candidate:6` on the times both predicted);
- its inputs: what v6 reads, plus the players' difficulty-adjusted pace offsets in the new table `candidate_pace_offset`
  (one row per player × piece count where the offset moved, valid from the first try after which it holds - rewritten
  by every candidate fit, ~223k rows / 31 MB on the production copy) and five more numbers in each taste state (the
  slope's sums, inside the existing `candidate_player_taste.state` jsonb). Live: one more value per pace row on the
  candidate's own statement (the pair's latest offset, a primary-key step), no more statements. Reconstructed: one more
  statement in the candidate's pass (the offsets of the player and of the solvers of the target puzzles, as of each
  solve). The 15-minute evidence refresh reads every stored offset once (its peak 272 MB). The shown tables, columns
  and statements are untouched;
- before the first v7 fit nothing is written (no fit = no rows), like v4-v6.

### Production run for v7

1. The migration (on boot) only **creates one table** (`candidate_pace_offset`) - nothing waits for it.
2. No new cron: the daily `fit-candidate-prediction-model` (04:41) fits v7 and writes the offsets. Run it once by hand
   after the deploy (its peak resident memory ~310 MB, ~4-5 minutes - README "Candidate v7"):
   `docker compose run --rm --no-deps messenger-consumer php -d memory_limit=1G bin/console myspeedpuzzling:fit-candidate-prediction-model`
   - the summary shows `adjusted_pace_rows_2` (every pace row), `adjusted_pace_mean_2` / `_sd_2` / `_p10_2` / `_p90_2`
   (production copy: +0.035 / 0.052 / -0.014 / +0.086), `adjusted_pace_change_2` (how much the second round moved the
   offsets, ~0.018) and "adjusted pace offsets written". Until it runs the candidate writes no rows (its v7 fit does not
   exist yet); the v6 rows stay. The next `refresh-candidate-prediction-evidence` after it writes nothing (the fit wrote
   the evidence from the very offsets it stored).
3. The v7 rows of every past solo time (resumable, detached, never next to another backfill run):
   `nohup docker compose exec -T -e SENTRY_DSN= web php -d memory_limit=1G bin/console myspeedpuzzling:backfill-solving-time-predictions --candidate > /tmp/backfill-candidate-v7.log 2>&1 &`
4. Proof stays the time split (`evaluate-predictions --fit-before=<date> --candidate`, and
   `--candidate-off=adjusted_pace,player_slope` for v6 on the same code; the output's `selection decile …` groups are
   the players by what they pick, `size … · k …` the size × k cells).

## Pair and team predictions (2026-10-27)

The pair/team model (README "Pair and team predictions") - **never shown**; pair/team times keep `predictable = false`
on `puzzle_solving_time`. Two more predictors in `solving_time_prediction`, both of model version
`Group\GroupPredictionModel::MODEL_VERSION` (1):

- `group` - the model: first tries and repeats (`method = personal` for a repeat that starts from the same pair's/team's
  own earlier time on the puzzle, else `statistical`); `k_indices` = the puzzle's index count for a first try, null for
  a repeat (the gate's "repeat" bucket); `difficulty_used` / `difficulty_source` (solves / shrunk / image), `prior_log`
  = the picture estimate the difficulty rested on, `window_bucket` = `kind|e<experience>|u<unknown members>|source|size`
  (the gate slices by it);
- `group_baseline` - the reference it is gated against: GroupExpectation's coordination rule as of the solve, only where
  that rule can predict (every member registered with a pace before the solve, a rated puzzle) - `predictable = false`
  elsewhere.

**Written only by the daily command** `myspeedpuzzling:fit-group-prediction-model` (message `FitGroupPredictionModel`,
`Group\GroupModelRecalculator`), its own process: nothing on the add-time or edit path - adding a pair/team time costs
no statement, switched on or off - and nothing of the shown model's jobs. While `PREDICTION_GROUP_ROWS=1` (or with
`--rows`) one run:
1. predicts every time without rows, or whose **fingerprint** changed (`group_prediction_input`: pair/team, puzzle, solve
   moment, first-try flag, unboxed, a secret puzzle revealed - an edit moving a time to another pair/team, a guest
   merge), with **yesterday's fit** - a time tracked after that fit is written `live`: the fit has not seen it, its
   inputs are as of the solve, so it is out of sample (no row is made on the add itself; "live" here means "the first
   daily run after the add, with the fit made before it"); everything else `reconstructed`;
2. refits the model on everything (`group_prediction_fit`, one row per model version);
3. on the first fill or with `--recompute`: makes every reconstructed row again with the new fit (live rows stay);
4. drops the rows of times that are no pair/team result to predict any more (now solo, suspicious, no seconds).

Writes are multi-row upserts of 500 times (`Group\GroupPredictionRowsWriter` - a documented bulk operation, the times
are never loaded as entities); the rows go with their time (cascade), like every side-by-side row. Readers of the other
predictors filter by predictor and never see these rows; the candidate gate reads them on pair/team results only
(`GetPredictionPairRows`, `PredictorKind::isGroup()`).

### Production run for the pair/team rows (after the deploy)

1. The migration (on boot) only creates two new tables (`group_prediction_fit`, `group_prediction_input`).
2. A cron row on lily.srv, its own process, after the shown fit (it reads the shown model's τ):
   ```
   56 4 * * *    docker compose run --rm --no-deps messenger-consumer php -d memory_limit=1G bin/console myspeedpuzzling:fit-group-prediction-model
   ```
3. The first fill, once, by hand (measured on the production copy: 68 s, 74,354 times = 148,708 rows, 293 MB peak
   resident with prod settings; the daily runs after it the same load and fit, ~1 min, writing only new times): `docker compose exec -T -e SENTRY_DSN= web php -d memory_limit=1G bin/console myspeedpuzzling:fit-group-prediction-model --rows`,
   then `PREDICTION_GROUP_ROWS=1` - from the next day on the cron writes the new times live.
4. Proof is the time split (`myspeedpuzzling:evaluate-group-predictions --fit-before=<date>`, read only); the stored
   rows: `myspeedpuzzling:evaluate-prediction-candidate --candidate=group:1 --reference=group_baseline:1` - like the
   candidate model, count only its **live** slice as evidence once there are a few thousand rows.

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
  from later solves (personal, statistical, later `first_attempt` flag, a solver's first pace formed after the solve),
  same-day order, unboxed = predicted but no input, first solve of a piece count = not predictable.
- `tests/MessageHandler/BackfillSolvingTimePredictionsHandlerTest` - only pending rows, never a live one, no domain
  events, second run no-op, every fixture player backfillable.
- `tests/Services/PuzzleIntelligence/ImagePredictionsSideBySideTest` - the three live rows in one INSERT (the estimate
  read by the statistical statement), the shown prediction untouched, no image rows for repeats, live == reconstructed
  for image / blend, in-sample estimates never reconstructed, live rows without an estimate reconstructed later,
  `--recompute-image`, the fit's blend prior equal to the readers' chain.
- `tests/ImageEstimateSecretPuzzleCanaryTest` - no reader gets a secret puzzle's estimate (read side, live, reconstructed,
  work list, the fit's priors, the evaluation).
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
