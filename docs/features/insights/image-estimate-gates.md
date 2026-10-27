# Image estimates - evaluation and gates

Status: **built 2026-10-19 (stream 5d), gate v2 2026-10-27, nothing shown.** The gate decides where Phase 6c may show a
picture-based estimate (`implementation-plan.md` §5.4, §6.2). Jan's decision (README §3, "Picture-only estimates"):
members get them, clearly marked as estimated, **switched on once the out-of-sample gates pass on the backfill**, and
picture-only estimates show only where the gate passes, even after `INSIGHTS_PUBLIC` is gone. Until the launch only
admins see Insights (`INSIGHTS_PUBLIC` off), so the gate decides nothing a player or guest sees today - it decides the
launch.

Where the numbers come from: the image pipeline's `difficulty_prior` derive pushes one estimate per puzzle
(`puzzle_difficulty_estimate`, `image-pipeline.md` §7); the app predicts every first try three ways side by side -
statistical (shown), image, blend - and stores them (`docs/features/puzzle-intelligence/prediction-history.md`, "Image
and blend predictions"). The Mac's own model record (`POST /internal-api/puzzle-difficulty-estimates/model`) measures
the model on labels; **this** record measures the predictions the app actually made with it, as of each solve.

## Why gate v2 (2026-10-27)

The 2026-10-26 model review (four independent reviews, time model §5 and image model F1-F3) found the first gate
measured the wrong thing:
- **Exact tier is capped by label noise.** A measured difficulty is itself the median of n noisy indices; even a perfect
  estimator lands in another tier than the label now and then - 0.80 at 10+ indices, 0.86 at 20+. The 0.56 bar was in
  effect "error SD ≤ 0.10", and 500 pc flat failed it by 0.005 on 276 puzzles = 0.4 standard errors: a coin flip.
- **The window bars were in-sample.** The picture-only window's scale (`imageVarianceScales`) is fitted on the same
  12 months the gate measured, so "50 % of solves land inside" was true by construction.
- **No hysteresis**: a daily re-run over ~15 groups flaps on noise.
- **Nothing gated skill**: whether the picture beats what the app would say without it (the brand × pieces prior) at
  k = 0, or whether the blend ever makes k = 1-4 worse.

## The evaluation - `myspeedpuzzling:evaluate-image-estimates`

Command → `EvaluateImageEstimates` → `EvaluateImageEstimatesHandler` (read only apart from the record it appends).
Options: `--newer-than-months=12`, `--json`. A few seconds (one streamed statement, `GetImageEstimateEvaluationRows`;
the evaluator itself 1.5 s / 33 MB on the 19,765 production rows of 2026-10-27).

**What it reads**: every stored `image` row of the current model version that rested on an estimate (`image_model`
set - a live row written without one is no image prediction), live or reconstructed (always out of fold), with the
`blend` and `statistical` rows of the same time beside it (their windows too), when the puzzle was solved
(`finished_at`, else `tracked_at`) and the puzzle's current estimate interval:
- first tries at **k = 0..4** difficulty indices when predicted (`ImageEstimateEvaluator::MAX_K`) - the puzzles
  picture-only estimates are for;
- on **newer puzzles**: added in the last 12 months (`puzzle.added_at`), the population the killer feature serves
  (76 % of first solves of a puzzle are logged within 10 minutes of its creation, `prediction-research.md` Q1);
- like the calibration report: solo, not suspicious, not unboxed, a real time;
- **never a secret puzzle** (`hide_until` / `hide_image_until` ahead), whatever rows it has from before it was hidden.

**What it measures** (`ImageEstimateEvaluator`, pure), per **size group** (`PiecesGroup`: ≤300 / 500 / 1000 / 1500+ /
odd) × **image kind** (`ImageKind`: flat / box / assembled / other / **unknown** - the picture's `artwork` analysis as
the pipeline groups it; no analysis = `unknown`, a kind of its own, never pooled), plus `overall` and `byK` (0 / 1-2 /
3-4) for people. The first numbers stay as they were (old records keep reading the same):

| Number | How |
|---|---|
| `spearman` | Spearman of the estimate (the latest image row of each puzzle) against the puzzle's measured log difficulty **now**, on puzzles measured from **10 indices** on (`MEASURED_MIN_INDICES`: reliability 0.96 there, `prediction-research.md` Q3) |
| `tierExact`, `tierWithinOne`, `alwaysAverage` | `DifficultyTier::fromScore()` of exp(estimate) vs of the measured score, same puzzles; what always saying "Average" would get right |
| `maeImage`, `maeBlend`, `maeStatistical` | mean \|ln(actual ÷ predicted)\| on the rows all three predicted (`pairedRows`); `maeImageAll` on every image row |
| `window50Hit` / `window80Hit`, `…Width` | the picture-only windows' **in-sample** hit rate and median relative width; the same for the blend (`blendWindow…`) |
| `rows`, `puzzles`, `measuredPuzzles`, `estimateModels` | what the numbers rest on - a puzzle's puzzle-level numbers count in its **latest** row's group only; image rows per estimate generation `"<model> <model version>"` |

Gate v2 adds (the shared parts live in `src/Services/PuzzleIntelligence/Evaluation/`):

| Number | How |
|---|---|
| `calibration` | **Honesty out of period** (`OutOfPeriodCalibration`) of the picture-only windows (every image row, k = 0..4 - all use the k = 0 window): the record's split moment T = the median solve; the solves from T on are tested in 30-day blocks, each with window scales fitted on every solve before the block (`WindowScaling`: a window is centre × exp(q × σ), so a fitted scale stretches its log offsets; the scale whose windows hold exactly 50 % / 80 % of the earlier solves); `hit50` / `hit80` pooled over the blocks with their 90 % **Wilson** interval (`hit50Low`…), `inSample50/80` = the stored windows' own hits, `scale50/80` = the last block's fit, `previouslyOpen` = the hysteresis state (below) |
| `skill` | a list of k buckets (`PairedSkill`): at **k = 0** the picture-only prediction (= the blend there) against the statistical one, which at k = 0 is the brand × pieces prior: `maeCandidate` / `maeReference`, `errorRatio`, and the **80 % window width at equal coverage** (`width80Candidate` / `width80Reference`: each predictor's windows scaled to hold exactly 80 % of these solves, then the median log width - a narrower window that holds fewer solves is not sharper); at **k = 1-2 / 3-4** the blend against the statistical: `meanDifference` of the paired errors and its standard error `differenceSe` |
| `slices` | `k0` = every k = 0 row (all newer puzzles), `k0Measured` = only those of puzzles measured from 10 indices (the population the puzzle-level numbers see), `live` / `reconstructed` - each with the picture-only windows' hits and the paired errors (image or blend vs statistical) |
| `tierExactSe`, `tierExactCeiling`, `tierExactOfCeiling` | (`TierScores`) the exact tier's standard error √(p(1−p)/n), and its **label-noise ceiling**: the share a perfect estimator would get, each label's noise 0.054 ÷ n indices (review image F5: E[r²] = 0.0107 + 0.054 ÷ n), plugged in at the measured value |
| `tierRangeCoverage` (+ `…Se`, `tierRangeMeanTiers`) | the tiers the estimate's 80 % interval spans (the puzzle's current estimate interval around the row's estimate): how often the measured tier lies inside, and how many tiers the range has |
| `rpss` | ranked probability skill score of the estimate's tier probabilities (split normal from its 10 / 90 % quantiles) against the group's own tier mix |

**The record**: `image_estimate_evaluation` (entity `ImageEstimateEvaluation`), append-only, one row per run: when, the
model version measured, the newer-than cut, the measured threshold, `groups` / `overall` / `byK` as JSON, and since
gate v2 `gate_version` (rows from before = 1), `subject` (`image`; candidate time models below) and `split_at`. The
latest row of the subject is the current record (`GetLatestImageEstimateEvaluation::latest()`). Thresholds are not
stored - the gate applies them on read, so a record keeps meaning the same when they change.

## The gate v2 - `ImageEstimateGate::passesFor(PiecesGroup, ImageKind, estimateGeneration)`

Reads the current record once per request (`ResetInterface`) and applies, to that group's numbers
(`ImageEstimateGate::failures()`, which also says why):

1. **Honesty** (`honestyFailures()`): for both windows, the out-of-period hit rate's 90 % Wilson interval lies within
   **target ± 3 points** (47-53 %, 77-83 %) - two one-sided tests at 5 %, the usual equivalence test: the group shows
   the windows hold, not merely that they might. **Hysteresis**: a group whose honesty the previous record opened, for
   the same estimate generations, stays open while both shares themselves stay within **± 5 points**
   (`OutOfPeriodCalibration::CLOSE_TOLERANCE`) - a daily re-run does not flap on noise. The state rides on the record
   (`calibration.previouslyOpen`, set by the handler from the previous record's numbers judged with today's rules,
   `ImageEstimateGate::honestyOpenGroups()`); a new estimate generation or model version earns the opening again.
2. **Skill** (`skillFailures()`): at **k = 0** the picture-only error ≤ **0.90 ×** the brand × pieces prior's on the
   same solves (`K0_ERROR_RATIO`) **and** a narrower 80 % window at equal coverage; at **k = 1-2 and 3-4** the blend
   never **measurably** worse than the statistical prediction - per bucket of at least 30 paired solves
   (`MIN_SKILL_ROWS`), its mean excess error minus 1.645 standard errors must not be above 0 (a point rule would flip
   on noise: on production a bucket at +0.0008 ± 0.0012 is the blend equal to the statistical); k = 1-4 together need
   30 paired solves, k = 0 alone 30.
3. **Ranking** (`rankingFailures()`): Spearman ≥ 0.6 on **≥ 50 measured puzzles** - kept from the first gate.

**Reported, never gating**: the exact tier with its standard error next to its label-noise ceiling, the tier range's
coverage (a gate rule if Jan picks the range display - B1), the RPSS, the k = 0 numbers of all newer puzzles next to
the measured ones, live next to reconstructed rows.

**Fails closed**: no record yet, a **gate v1** record (`gate_version` 1 - every row from before 2026-10-27; still
readable, never enough), a record of another model version (`TimePredictionCalculator::MODEL_VERSION`), no numbers for
the group, a missing number. **And per estimate generation**: the shown estimate's `"<model> <model version>"`
(`ImagePriorInput::generation()`) must be among the group's `estimateModels` - a record vouches only for the estimates
it measured (pinned by `EvaluateImageEstimatesHandlerTest` and
`PersonalEstimatesDisplayTest::testAPassingRecordOfAnotherEstimateGenerationKeepsThePictureClosed`). Size groups and
kinds are never pooled to pass.

## Measured on production 2026-10-27 (read-only replica)

The evaluation's own SQL run read-only on production (19,765 image rows: puzzles added since 2025-10-27, k = 0..4,
11,783 at k = 0, 197 live; estimate generations `ridge-siglip2-dinov2 1.1.0` 19,591, others 174), fed to the PHP
evaluator of this branch; split moment T = 2026-05-17, six 30-day blocks.

**No group passes gate v2 today** - the first gate passed none either, for different reasons:

| Group | Measured / ρ | Honesty 50 % (Wilson 90 %) | Honesty 80 % | k = 0 ratio, 80 % width (pic / prior) | k = 1-2 / 3-4 blend − stat | Gate v2 | First gate |
|---|---|---|---|---|---|---|---|
| 500 flat | 273 / 0.72 | 0.478 (0.458-0.499) | 0.801 (0.784-0.817) | 0.754, 0.455 / 0.578 | −0.0026 ± 0.0010 / −0.0016 ± 0.0006 | **fails honesty 50 % only** | exact 0.542 < 0.56 |
| 500 box | 97 / 0.68 | 0.529 (0.511-0.547) | 0.837 (0.823-0.850) | 0.779, 0.457 / 0.572 | −0.0056 / −0.0012 | fails honesty (too wide) | exact 0.515 < 0.56 |
| ≤300 flat | 58 / 0.71 | 0.513 (0.486-0.539) | 0.802 (0.780-0.822) | 0.887, 0.483 / 0.555 | −0.0023 / +0.0008 ± 0.0012 | **fails honesty 50 % only** (upper bound 0.539) | exact 0.603 < 0.65; in-sample 50 % 0.466 |
| ≤300 box | 32 / 0.45 | 0.510 (0.491-0.528) | 0.818 (0.803-0.832) | **0.935** | −0.0019 / −0.0007 | ranking, honesty 80 %, skill | measured, ρ |
| 500 assembled | 33 / 0.88 | 0.518 | 0.826 (0.800-0.849) | 0.768 | −0.0071 / −0.0018 | measured, honesty | measured |
| 1000 flat / box | 13 / 0 measured | 0.537 / 0.580 | 0.806 / 0.841 | 0.772 / 0.883 | | measured, honesty | measured |
| overall | 546 / 0.68 | 0.518 (0.509-0.526) | 0.819 (0.813-0.825) | 0.837, 0.504 / 0.576 | −0.0035 / −0.0015 | (not gated) | |

What it says:
- **Skill passes almost everywhere**: the picture-only prediction's error at k = 0 is 0.158 against the brand prior's
  0.189 overall (−16 %), with an 80 % window 12 % narrower at equal coverage; only ≤300 box misses the 10 % bar
  (−6.5 %). The blend is never measurably worse at k = 1-4.
- **Honesty is the binding rule.** The picture-only windows' hit rate drifts by more than ± 3 points between periods
  (500 box in-sample by month: 80 % window 0.75-0.85), and the drift is real, not noise or composition: the scale fitted
  on the earlier solves makes 500 box too wide (0.837) and 500 flat's 50 % window too narrow (0.478) for the later ones.
  A single fit tested half a year ahead was a little worse than the rolling 30-day refit (500 box 0.849, ≤300 box
  0.826 at 80 %) - the daily production refit is what members meet. Heteroscedastic intervals (image review P4) and
  a recency-weighted scale fit are the levers; both are side-by-side work.
- **500 flat and ≤300 flat pass everything else** - with hysteresis, once open they would stay open at today's numbers
  (both within ± 5).
- Tiers (reported): exact 0.551 ± 0.021 overall against a ceiling of 0.801 (69 % of what a perfect estimator gets; 500
  flat 0.542 ± 0.030 vs 0.816); the tier range of the 80 % interval holds the measured tier 93 % of the time but spans
  2.55 tiers on average - too wide to be a useful display as it is; RPSS 0.30 (500 assembled 0.53, 500 flat 0.34,
  ≤300 box −0.07).
- k = 0 rows of all newer puzzles vs the measured ones: picture error 0.158 vs 0.126, brand prior 0.189 vs 0.163 - the
  puzzles that reach 10 indices are the easier ones to predict (review image F3), so the row numbers on all newer
  puzzles are the honest ones. Live rows (197) 0.122 vs 0.134, hits 0.467 / 0.797; reconstructed 0.140 vs 0.158,
  0.490 / 0.781 - too few live rows to tell them apart yet.

## Candidate time models - `myspeedpuzzling:evaluate-prediction-candidate`

The same machinery for any prediction stored side by side under its own predictor / model version (a next
`MODEL_VERSION` written as `statistical` / `blend` rows of version 4 before its launch - prediction-history.md "Side by
side"): `--candidate=statistical:4 --reference=statistical:3` [`--solved-since-months=12`] [`--newer-than-months=N`]
[`--json`] → `EvaluatePredictionCandidate` → `EvaluatePredictionCandidateHandler` (rows: `GetPredictionPairRows`, first
tries and repeats, live and reconstructed, puzzles of every age unless `--newer-than-months`) →
`PredictionCandidateEvaluator`: per size group (kind `any`) the candidate windows' honesty out of period (as above, with
hysteresis per group) and the skill against the reference per k bucket (0 / 1-2 / 3-4 / 5-9 / 10-19 / 20+ / repeat),
live and reconstructed slices. The record goes to the same table under the subject `"<candidate>@<v> vs
<reference>@<v>"`; `PredictionCandidateGate::failuresFor()` reads it (nothing shown asks it - the launch decision does).
Its rule (`PredictionCandidateEvaluator::failures()`): honesty as above; no k bucket of ≥ 30 paired solves measurably
worse than the reference by more than 0.002 (one-sided 95 % - the time review's acceptance rule "no group worse by
> 0.002": with 10k+ solves per bucket a 0.0003 difference is significant and nothing a member notices); the
candidate's error over all paired solves no larger than the reference's.

Measured 2026-10-27 on production as a dry run with the only side-by-side pair that exists, `blend@3 vs statistical@3`
(= what the launch bump turns the statistical predictor into; 150,072 first tries solved in the last 12 months, 12 s /
123 MB): passes for 500 (honesty 0.503 / 0.803, k = 0 error 0.141 vs 0.184, k = 1-2 0.109 vs 0.114) and ≤300; 1000
fails only without the margin (k = 10-19 +0.0003 ± 0.0001) and passes with it; 1500+ and odd fail honesty (168 and
2,431 rows).

**The candidate model v4 (`--candidate=candidate:4 --reference=statistical:3`): judge it on its live slice.** Its
reconstructed rows are predicted with a fit that has already seen those solves - in-sample, and the candidate has many
more fitted parameters than v3 (offsets, terms, quantile tables), so the reconstructed slice flatters it. Out-of-sample
evidence is the time split, `evaluate-predictions --fit-before=<date> --candidate` (fitted before the date, scored after
it - README "The candidate model (v4)"), and the live rows written after its fit once there are a few thousand.

**Pair/team predictions (`--candidate=group:1 --reference=group_baseline:1`).** The same machinery on pair/team
results (`GetPredictionPairRows` reads duo/team times for the group predictors, solo times for every other): the
reference is the **existing coordination rule** (GroupExpectation: c(size, first try / repeat) × difficulty ÷ Σ 1/pace,
as of the solve), stored as `group_baseline` where that rule can predict at all - every member registered with a pace
before the solve and a rated puzzle (about a third of the first tries); elsewhere the reference has no row, so those
results count for the honesty of the windows but not for the skill (`PairedSkill` pairs only). Extra slices per size
group: `size 2/3/4`, `experience 0 / 1-2 / 3-9 / 10+` (the pair's/team's earlier results), `unknown members 0/1/2`,
`own earlier time` (from the row's window bucket). As for the candidate model, the reconstructed rows come from a fit
that has seen them - the evidence is the time split (`evaluate-group-predictions --fit-before=`) and the live slice
(puzzle-intelligence/README.md "Pair and team predictions").

## When it runs

- After the deploy: nothing to evaluate until image rows exist (the prediction backfill writes them as the derive pushes
  out-of-fold estimates - `prediction-history.md` "Production run for the image rows"). The first run after the gate v2
  deploy stores the first v2 record; until then every group is closed (the v1 records fail closed).
- Before the launch decision (`implementation-plan.md` §8): final derive on the complete data →
  `fit-prediction-model` (fits the picture-only window's scale on the new estimates - README "Step 8") →
  `backfill-solving-time-predictions --recompute-image` → `evaluate-image-estimates`.
- Daily afterwards (a cron row after the 4×-daily backfill, e.g. `5 8 * * *`) so the gate follows the live rows and the
  hysteresis has a history; the orchestrator adds it to lily.srv when Phase 6c ships.

### After a `TimePredictionCalculator::MODEL_VERSION` bump

The gate closes at once: the current record measured the old model (`failuresFor()` says so), and the new model has no
image rows yet. Before any estimate shows again, in this order:
1. `myspeedpuzzling:fit-prediction-model` (the new version's fit - the backfill writes no side rows before it);
2. `myspeedpuzzling:backfill-solving-time-predictions --recompute-image` (detached, ~1 h: the new version's image /
   blend rows; `--recompute-image` also remakes any written before the final derive);
3. `myspeedpuzzling:evaluate-image-estimates` - a record of the new version; the gate opens where it passes (the
   hysteresis starts afresh).

The same after a new estimate generation worth judging on its own (a model change in the derive): steps 2-3.

## Inputs pushed from outside never cost a time

The estimate is the only prediction input pushed from outside. The internal API bounds it (values within ±5, the 80 %
interval at most 1.5 wide - `PuzzleDifficultyEstimateInput::MAX_INTERVAL_WIDTH`), every predicted second and window end
is clamped to the int columns (`TimePredictionCalculator::seconds()`, again in `LivePredictionRowsWriter`), so no
number can make the add-time INSERT fail and roll the player's time back
(`ImagePredictionsSideBySideTest::testAnExtremeEstimateNeverCostsTheTime`, with an estimate written past the API).

## Read by Phase 6c

`ResolvePersonalEstimates` asks `passesFor()` once per listed puzzle nobody has measured that has a picture estimate
(the record is read once per request), and `ShelfPicks` for shelf puzzles - [`estimates.md`](./estimates.md). The
stored `blend_prior_log` is not read by 6c: shown predictions with solves stay the statistical ones until the launch
`MODEL_VERSION` bump; the picture-only number is computed from the estimate itself (the `image` side row's number).

## For readers of the stored blend prior

`difficulty_prior_puzzle.blend_prior_log` / `puzzle_difficulty.blend_prior_log` are written by the daily fit, which
leaves secret puzzles out - but a stored value outlives a hide (`hide_until` / `hide_image_until` set after the fit)
until the next fit. **Every reader filters secret puzzles itself**, like the Picker does for `prior_log`
(`CASE WHEN StatisticalInputsSql::NOT_SECRET THEN pd.prior_log END`), and keys the gate by the puzzle's size group and
`PuzzleDifficultyEstimateResult::$imageKind`.

## Tests

Every rule on synthetic numbers or rows: `WindowScalingTest` (scale intervals incl. a window off its centre, the fitted
scale, Wilson), `OutOfPeriodCalibrationTest` (calibrated windows pass, a later spread fails, rolling blocks, hysteresis),
`PairedSkillTest` (errors, paired difference, width at equal coverage), `TierScoresTest` (boundaries = `DifficultyTier`,
label-noise ceiling, tier range, RPSS), `ImageEstimateGateRulesTest` (each v2 rule fails on its own, the exact tier
never gates, hysteresis, the open groups a record carries), `ImageEstimateEvaluatorTest` (v2 numbers, slices,
hysteresis per estimate generation), `PredictionCandidateEvaluatorTest`, and on the database
`EvaluateImageEstimatesHandlerTest` (v1 records fail closed, another subject is not the record, the hysteresis carried
by the handler) and `EvaluatePredictionCandidateHandlerTest` (any predictor / model version, its own subject).
