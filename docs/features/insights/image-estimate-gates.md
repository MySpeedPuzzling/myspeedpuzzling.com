# Image estimates - evaluation and gates

Status: **built 2026-10-19 (stream 5d), nothing shown.** The gate decides where Phase 6c may show a picture-based
estimate (`implementation-plan.md` §5.4, §6.2). Jan's decision (README §3, "Picture-only estimates"): members get them,
clearly marked as estimated, **switched on once the out-of-sample gates pass on the backfill**, and picture-only
estimates show only where the gate passes, even after `INSIGHTS_PUBLIC` is gone.

Where the numbers come from: the image pipeline's `difficulty_prior` derive pushes one estimate per puzzle
(`puzzle_difficulty_estimate`, `image-pipeline.md` §7); the app predicts every first try three ways side by side -
statistical (shown), image, blend - and stores them (`docs/features/puzzle-intelligence/prediction-history.md`, "Image
and blend predictions"). The Mac's own model record (`POST /internal-api/puzzle-difficulty-estimates/model`) measures
the model on labels; **this** record measures the predictions the app actually made with it, as of each solve.

## The evaluation - `myspeedpuzzling:evaluate-image-estimates`

Command → `EvaluateImageEstimates` → `EvaluateImageEstimatesHandler` (read only apart from the record it appends).
Options: `--newer-than-months=12`, `--json`. A few seconds (one streamed statement, `GetImageEstimateEvaluationRows`;
1.3 s on a copy of the dev data with 10k image rows).

**What it reads**: every stored `image` row of the current model version that rested on an estimate (`image_model`
set - a live row written without one is no image prediction), live or reconstructed (always out of fold), with the
`blend` and `statistical` rows of the same time beside it:
- first tries at **k = 0..4** difficulty indices when predicted (`ImageEstimateEvaluator::MAX_K`) - the puzzles
  picture-only estimates are for;
- on **newer puzzles**: added in the last 12 months (`puzzle.added_at`), the population the killer feature serves
  (76 % of first solves of a puzzle are logged within 10 minutes of its creation, `prediction-research.md` Q1);
- like the calibration report: solo, not suspicious, not unboxed, a real time;
- **never a secret puzzle** (`hide_until` / `hide_image_until` ahead), whatever rows it has from before it was hidden.

**What it measures** (`ImageEstimateEvaluator`, pure), per **size group** (`PiecesGroup`: ≤300 / 500 / 1000 / 1500+ /
odd) × **image kind** (`ImageKind`: flat / box / assembled / other / **unknown** - the picture's `artwork` analysis as
the pipeline groups it; no analysis = `unknown`, a kind of its own, never pooled - most `ridge-siglip2-full` fallback
estimates land there), plus `overall` and `byK` (0 / 1-2 / 3-4) for people:

| Number | How |
|---|---|
| `spearman` | Spearman of the estimate (the latest image row of each puzzle) against the puzzle's measured log difficulty **now**, on puzzles measured from **10 indices** on (`MEASURED_MIN_INDICES`: reliability 0.96 there, `prediction-research.md` Q3 - 5 noisy indices would hide a good model) |
| `tierExact`, `tierWithinOne` | `DifficultyTier::fromScore()` of exp(estimate) vs of the measured score, same puzzles |
| `alwaysAverage` | the share of those puzzles whose measured tier is Average - what always saying "Average" would get right |
| `maeImage`, `maeBlend`, `maeStatistical` | mean \|ln(actual ÷ predicted)\| on the rows all three predicted (`pairedRows`); `maeImageAll` on every image row |
| `window50Hit` / `window80Hit`, `…Width` | how often the actual time landed inside the image prediction's 50 % / 80 % window, and the median relative width (high − low) ÷ predicted; the same for the blend (`blendWindow…`) |
| `rows`, `puzzles`, `measuredPuzzles` | what the numbers rest on |

**The record**: `image_estimate_evaluation` (entity `ImageEstimateEvaluation`), append-only, one row per run: when, the
model version measured, the newer-than cut, the measured threshold, `groups` / `overall` / `byK` as JSON. The latest
row is the current record (`GetLatestImageEstimateEvaluation`). Thresholds are not stored - the gate applies them on
read, so the history keeps meaning the same when they change. A table of its own rather than the pipeline's model
record: that one is the Mac's numbers on labels and drives the push guard; this one is the app's on stored predictions.

## The gate - `ImageEstimateGate::passesFor(PiecesGroup, ImageKind)`

Reads the current record once per request (`ResetInterface`) and applies, to that group's numbers
(`ImageEstimateGate::failures()`, which also says why):

1. **≥ 50 measured puzzles** in the group;
2. **Spearman ≥ 0.6**;
3. **tier within ±1 ≥ 80 %**;
4. **exact tier ≥ max(46 %, the group's always-"Average" share) + 10 points** - "clearly above": at the minimum of 50
   puzzles one standard error of a share near 50 % is 7 points (√(0.25 ÷ 50)), so 10 points is ~1.4 of them there and
   ~3 at 250 puzzles; anything smaller is within the noise of the groups that only just pass rule 1. The image pilot
   cleared it on a random set (60 % vs 46 %, `prediction-research.md` Q3). Compared with the group's own always-Average
   share too, because a group where most puzzles are Average makes 46 % too easy a bar;
5. **calibrated windows**: the 50 % window's hit rate within 47-53 %, the 80 % within 77-83 % (± 3 points, the
   calibration monitor's tolerance).

**Fails closed**: no record yet, a record of another model version (`TimePredictionCalculator::MODEL_VERSION`), no
numbers for the group, a missing number, or fewer than 50 measured puzzles = no. Size groups and kinds are never
pooled to pass: 1500+ and odd sizes will mostly stay closed (too few measured puzzles), and so will `unknown` unless the
fallback model earns it on its own.

MAE is in the record but no gate: the image predictor at k = 0 competes with a prior-only statistical prediction that is
not shown either; what members will see is the estimate and its window, which rules 2-5 judge.

## When it runs

- After the deploy: nothing to evaluate until image rows exist (the prediction backfill writes them as the derive pushes
  out-of-fold estimates - `prediction-history.md` "Production run for the image rows").
- Before the launch decision (`implementation-plan.md` §8): final derive on the complete data →
  `backfill-solving-time-predictions --recompute-image` → `evaluate-image-estimates`. Expect 500 pc flat / box to be the
  first to pass.
- Daily afterwards (a cron row after the 4×-daily backfill, e.g. `5 8 * * *`) so the gate follows the live rows; the
  orchestrator adds it to lily.srv when Phase 6c ships.

## Measured on synthetic data (only that the machinery works)

A copy of the dev data with a made-up estimate for every pictured puzzle (the measured difficulty ± 0.15, interval
−0.25 / +0.3) and 300 players backfilled: 10,382 image rows, every group listed, every gate failing for a stated reason
(too few measured puzzles outside 500; at 500 the windows, too wide for estimates that close - 62 % / 90 % hit -
because the made-up interval is wider than the made-up error). The real numbers come from the backfill.
