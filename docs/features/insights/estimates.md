# Personal time estimates - option C (Phase 6c)

Built by stream **6c** behind `INSIGHTS_PUBLIC` (admins always). Decisions: [`README.md`](./README.md) §3 "Estimates",
"Picture-only estimates", "Predicted difficulty", "Prediction windows", "Display rollout". Plan:
[`implementation-plan.md`](./implementation-plan.md) §6.2. Design of record: [`prototype.html`](./prototype.html), Plan
tab → "Image prediction" (option C, mobile first). Gates: [`image-estimate-gates.md`](./image-estimate-gates.md).

## What a member sees

| Where | What |
|---|---|
| Puzzle page | The card above the actions (`insights/estimate/_card.html.twig`): what it rests on ("20 solves · measured"), "≈ 3h for you", the certainty bar, "Likely 2h 40min – 3h · half of solves land inside", "8 in 10 solves: 2h 30min – 3h 10min · Quite sure", what it rests on in a sentence, the tier chip, and a context line from the player's collection ("The 2nd-hardest 1000-piece puzzle in your collection"). The head's tier shows the estimated tier ("Hard?", dashed) in place of "not enough data" |
| Collections (system + custom), wishlist, unsolved | One line under the pieces (`insights/estimate/_line.html.twig`): **≈ time · tiny certainty bar · tier chip**. In the collections' "times + predictions" display mode the line stands in for the prediction pill, so `ResolveCollectionDisplay` does not load `GetPlayerPredictions` a second time (`estimatesShowTimes`). In selection mode a tap on the line opens the sheet and never ticks the card |
| Puzzle Picker card | The same line in place of the card's prediction row. The "vs your best" line stays: measured from the raw prediction (never the rounded time) and said only when the difference is at least the shown time's rounding step - "≈ 3h" never "beats" a 3:04:00 best by 30 seconds (`PuzzlePickerCardEstimateTest`) |
| Marketplace card | The same line under the puzzle's link; an estimated tier on the picture's corner (dashed ring + "?") |
| The sheet | A tap on a line opens `#estimate-sheet` (one per page, `base.html.twig`): the time, the 50 % likely window, the 80 % range, what it rests on, how sure, the tier. A bottom sheet on phones |

Free players (flag on): the puzzle page shows the lock "≈ ? for you" (opens `#membersExclusiveModal`); every other
surface stays as it is today (its own lock where it has one: the list's locked difficulty icon, the Picker's locked
prediction row). Members who switched time predictions off: a note with the settings link on the puzzle page, nothing
on lists. Guests: nothing.

## Who sees what - `ResolvePersonalEstimates::forViewer(?PlayerProfile, puzzleIds)`

The one call of every surface (`src/Services/Insights/ResolvePersonalEstimates.php`):

1. `InsightsFeature::isVisibleTo()` false (flag off and not an admin) or a guest → **null**: the templates render
   exactly what they did before (whitespace-identical - every new part sits behind `{% if estimates … %}` on a line that
   already ended in a tag), and **nothing is queried**;
2. no active membership (a free trial counts as one) → `PersonalEstimates::locked()`, nothing queried;
3. `time_predictions_opted_out` → `optedOut()`, nothing queried;
4. else `GetPersonalEstimates::forPuzzles()` - **one statement for the whole list** - plus the gate record once per
   request when a listed puzzle nobody has measured has a picture estimate (`ImageEstimateGate`, `ResetInterface`).

The viewer decides, never whoever the list belongs to: the times are always the viewer's own.

## Which number - the one rule (`GetPersonalEstimates`)

A puzzle shows **the same time on every surface**: the card, the lines, the sheet, the Insights panel and the Picker /
collections prediction rows (while the flag hides the estimates), the recap's live prediction. Pinned by
`GetPersonalEstimatesTest::testEveryPuzzleShowsTheTimeEveryOtherSurfaceShows` (every fixture player × puzzle equals
`GetPlayerPrediction::forPuzzle()`) and on the production copy (1,822 player × puzzle pairs equal to
`GetPlayerPredictions`, 0 different).

| Case | Time | Basis (`EstimateBasis`) | Marked estimated |
|---|---|---|---|
| The player solved it before (solo, valid, boxed) | the personal (repeat) prediction | `your_solves` | no |
| 5+ difficulty indices | the statistical prediction (pace × measured difficulty × drift) | `measured` | no |
| 1-4 indices | the statistical prediction, difficulty shrunk toward brand × pieces | `first_solves` | yes |
| 0 indices, a picture estimate of the current picture, **its gate passed** (size group × image kind × the estimate's generation - a record vouches only for the estimate generation it measured, `image-estimate-gates.md`) | pace × drift × exp(estimate) - the stored `image` side row's number, window from the estimate's interval | `picture` | yes |
| 0 indices otherwise | nothing (the prior-only number is never shown) | - | - |
| The player has no baseline at any piece count (and no repeat) | the community's median direct pace at the piece count (≥ 20 puzzlers), clearly labelled "Typically ≈ …", no window, no bar | `community` | no |
| Secret puzzle (`hide_until` / `hide_image_until` ahead) | nothing, whatever it has | - | - |

**Why not the blend for k = 1-4?** The shown prediction for a puzzle with solves stays the statistical one (shrunk
toward brand × pieces) until the launch `MODEL_VERSION` bump lets the image prior into it (STATUS 5d, plan §8).
Showing the blend here would put a second number next to the one the recap, the Insights page and the API v1
already show for the same puzzle. At k = 0 there is no shown number to disagree with, and the picture-only number
equals the `image` side row the gates measured. After the launch bump the statistical predictor *is* the blend - this
rule then needs no change.

**The tier** is the puzzle's, never personal (README: a hard puzzle is 1.49-1.54× an easy one in every speed band):
measured from 5 indices on (solid chip); at 1-4 `DifficultyTier::fromScore(exp(shrunk log difficulty))`; at 0 the
picture's tier where its gate passed (both dashed + "?").

## How it reads

- **Time** (`Value\EstimateTime`): 5 minutes under an hour, 10 minutes from an hour on, the minute under 10 minutes
  (deviation from the plan: 5-minute steps turned a 7-minute 300 into "5 min"), never 0; "≈" in front everywhere.
  It never contradicts the window next to it: when the step would put it outside the shown 50 % window (a narrow
  window on the minute grid), it rounds on the window's step ("≈ 1h 5min" in a 1h 1min – 1h 9min window).
- **Windows**: Phase 3c's `PredictionWindow` / `PredictionWindowFormatter` (nearest step, ties inward); the 80 % range
  only in the sheet and on the card. Shown together, both go through one formatting decision
  (`PredictionWindowFormatter::ranges()`, Twig `prediction_window_ranges()`): minutes only when both qualify, else both
  in hours - "1h – 1h 9min" with "57min – 1h 14min", never next to "57–74 min".
- **Certainty bar**: the 80 % range drawn on a scale of the time × 0.5 … × 1.5, the time in the middle - wide = unsure.
  No numbers on it, hidden from screen readers.
- **How sure** (`EstimateCertainty`, from the 80 % range ÷ the time): ≤ 30 % "Quite sure", ≤ 55 % "Fairly sure", wider
  "A rough estimate" (a measured 500 of a steady player ~25 %, a picture-only estimate ~45-60 %).
- **Estimated vs measured is never colour alone**: dashed outline + "?" after the tier + "(estimated)" in the accessible
  name (chip, corner); the card's outline is dashed too and its time carries a hidden "(estimated)" (it already says
  "for you"); the line's hidden text says "for you, estimated". The sheet gives the focus back to the line it was opened
  from.
- **Context line** on the card: "The 2nd-hardest 1000-piece puzzle in your collection" on a measured tier, "Likely the
  2nd-hardest …" on an estimated one.
- **One line** (`.est-line`): `white-space: nowrap`, the time never shrinks, the chip's label is dropped by a container
  query once the line is narrower than the longest time + bar + label needs (14.5 rem, German 16.5 rem - measured in all
  6 locales: 206-216 px, German 240 px), the chip clips as the last guard. In a library row the column holding the
  line takes the row's width (`.est-host`). **Checked 2026-10-19** in a real Chromium on the production copy: 6 locales ×
  wishlist / collection / marketplace / two puzzle pages × 320, 360, 375, 390, 414 px - 150 page × width checks, 41,550
  lines: no sideways scroll, no clipped time or icon, no wrapped line, no clipped label, card and sheet inside the
  viewport.
- **Wording** `insights.estimate.*`, all 6 locales, the register of `puzzle_intelligence.*` (Czech "vy", French
  "vous", German "du", Spanish "tú"); neutral ("A rough estimate", never "you will be slow").

## Cost (statements)

| Page | Flag off / non-admin | Flag on, member |
|---|---|---|
| Puzzle page | unchanged | `GetPlayerPrediction` (2-4 statements) replaced by 1; + 1 collection-rank statement when the puzzle is in the player's collection; + 1 gate record when the puzzle has a picture estimate and nobody measured it (pinned by `testTheStatementBudgetsWithTheFlagOn`) |
| Collections, wishlist, unsolved, marketplace | unchanged | **+ 1** (pinned by `PersonalEstimatesDisplayTest::testListsShowOneLinePerRowForOneStatementMore` and, for a custom collection, `testTheStatementBudgetsWithTheFlagOn`), + 1 gate record only with a k = 0 picture candidate; the "+ predictions" display mode no longer loads `GetPlayerPredictions` (up to 4 fewer) |
| Picker | unchanged | `GetPlayerPredictions::forPuzzles()` for the picked cards (up to 4, unless the pick memoised them) replaced by 1 (+ gate), pinned by `testTheStatementBudgetsWithTheFlagOn`. The Picker's own SQL is untouched - no re-measure needed |
| Insights page, shelf picks (`ShelfPicks`) | - | 4 + gate (was 7: `GetPlayerPredictions` replaced by `GetPersonalEstimates`) |

**Measured 2026-10-19** on a copy of the dev database (production data, 41k puzzles, 523k times; migrated and fitted
with this branch), warm, median of 5, `GetPersonalEstimates::forPuzzles()` (statement + PHP):

| List | Puzzles | Time |
|---|---|---|
| a page of 21 (marketplace, first rows) | 21 | 3-8 ms |
| a wishlist | 99 / 559 / 655 | 9 / 18 / 22 ms |
| the biggest collections | 455 / 679 / 761 / 1,310 / 1,351 | 16 / 22 / 43 / 38 / 50-83 ms |

Two plan fixes found there: the listed ids drive the statement (`FROM listed JOIN puzzle`; a semi join at the end let
the planner join the whole catalogue first - 83 ms for 21 puzzles), and the player's part (model parameters ~6 KB,
ratios, repeats) rides on one row only (on every row it cost 55 ms of fetching for a 1,350-puzzle list).

## Shelf picks (`ShelfPicks::estimates()`, computation.md §4c.7)

The Insights page's "Try next from your shelf" now reads `GetPersonalEstimates` too: rated shelf puzzles keep their
statistical expected time (`ShelfPickSource::Statistical`), and a shelf puzzle nobody has solved gets its picture-only
estimate where its gate passed (`ShelfPickSource::Image`, `isEstimated()`); puzzles with 1-4 indices stay out as
before. The rendering of the picks is 6b's.

## Tests

- `tests/Query/GetPersonalEstimatesTest.php` - parity with `GetPlayerPrediction` for every fixture pair, one statement
  for a whole list, the picture estimate only where the gate passes (and the gate asked with the size group × kind),
  the picture never replacing a prediction from solves, secret puzzles never, the community time for a player without
  a pace, the collection rank.
- `tests/Controller/PersonalEstimatesDisplayTest.php` - flag off: no markup and **no estimate statement** on the puzzle
  page, the lists, the Picker and the marketplace for a non-admin member, no lock for a free player; admins always;
  flag on: the card (same window wording, the Insights panel without its own prediction), the estimated chip (dashed,
  "?", "(estimated)"), the free player's lock, opted out, one line per row for exactly one statement more, the sheet
  once per page, the Picker card, gate pass vs fail on a k = 0 puzzle (gate record read once), a secret puzzle on a
  list.
- `tests/Value/EstimateTimeTest.php` - rounding, never outside the shown window (a sweep).
- `tests/Results/PersonalEstimateTest.php` - bar, certainty, community, collection context.
- `tests/Services/Insights/ResolveRadarAndShelfPicksTest.php` - shelf picks statements (4) and the picture pick behind
  its gate; `InsightsLazyPartsBudgetTest` shelf budget 7 → 5.

Screenshots (320 / 390 / 1200 px, puzzle page card in each basis, the lists, the sheet, the free lock) were reviewed
on the production copy with synthetic picture estimates; they are not in the repository (real puzzle names).

## At the launch (plan §8)

- Remove the flag: the "while hidden" branches go with it - the Insights panel's own prediction block
  (`_difficulty_section.html.twig`), the Picker card's old prediction row and its `GetPlayerPredictions` call, the
  collections "+ predictions" pill (or keep the pill for the "my times" display mode only), the `GetPlayerPrediction`
  call of `PuzzleDetailController`.
- The `MODEL_VERSION` bump that lets the image prior into the statistical predictor closes every gate until the new
  evaluation (`image-estimate-gates.md`); `first_solves` estimates then rest on the blend automatically.
- The Picker's time-budget / gap filters still use the statistical centre in SQL (k ≥ 1): a picture-only estimate shows
  on a card but never makes a puzzle match a budget filter. Decide at the launch whether the filter should read
  `blend_prior_log` for k = 0 (measure the Picker then).

## Picture estimate 1.2.0 (`difficulty_prior`, 2026-10-27)

The image pipeline's picture estimate behind the `picture` basis and the side-by-side `image` / `blend` predictors
(runbook: [`image-pipeline.md`](./image-pipeline.md) §7, code `tools/puzzle-vision/puzzle_vision/derive/difficulty_prior.py`).
Built from the prediction-model review of 2026-10-26 (image-model P1/P3/P4/P6, data-mining F1/F3, artwork proposal 3).
**Nothing shown changes**: the estimates are side by side only; a new estimate generation closes every image gate
(`image-estimate-gates.md`) until `myspeedpuzzling:evaluate-image-estimates` measured it.

What changed and why (every number below: the module's own `validate` on the 2026-10-24 data, the same 10,406
training puzzles for both versions; 1.1.0 = the committed module, which reproduces its production record exactly):

- **Ridge + kernel ridge** (P1): 0.5 × the 1.1.0 ridge + 0.5 × an RBF kernel ridge on the unit-normed embedding blocks
  and the standardised numbers / pieces (bandwidth 2, alpha 0.1 - chosen by the review on the older puzzles, fixed
  and recorded). Newer from older alone: Spearman 0.704 → 0.721, MAE 0.0885 → 0.0854; paired bootstrap ρ +0.018
  [+0.005, +0.030], MAE −0.003 [−0.005, −0.001]. Above 20,000 training puzzles a seeded Nyström kernel with 6,000
  landmarks takes over (measured on the same split: correlation with the exact kernel 0.998, ensemble ρ 0.7215 vs
  0.7209).
- **Brand × size offset** (settles the two reviews' disagreement): a shrunk offset Σ w r / (Σ w + 10) of the
  out-of-fold residuals per manufacturer × piece count, on top of the ensemble. It helps at every time cut, the
  bootstrap CIs exclude zero: 60 % cut MAE −0.0029 [−0.0043, −0.0014], ρ +0.015; 70 % −0.0030 [−0.0047, −0.0014],
  ρ +0.011; 80 % −0.0031 [−0.0050, −0.0013], ρ +0.016 (n ≥ 20; n ≥ 10 the same). Brand alone helps less (70 %: MAE
  0.0830 vs 0.0824); a hierarchical brand → brand × size shrinkage did not beat it. The image-model reviewer's "no
  signal" measured brand × pieces as a *feature or prior* (Spearman of the prior alone 0.04-0.15), not as a
  correction of the picture's residual - both are right. Out-of-fold estimates take the offset from "pair models"
  trained without their fold and without the residual's own fold: an out-of-fold estimate never depends on its own
  label (test-pinned). A new brand or size gets no offset.
- **Heteroscedastic intervals** (P4): σ̂ (Huber on |residual|: the estimate, the 10 nearest training pictures'
  similarity and label spread, |estimate − their labels|, crop flags, ln pixels per piece, size, kind) × normalised
  split-conformal quantiles per size group, σ̂ shrunk 0.75 toward its mean. Against the 1.1.0 table on the same
  predictions (n ≥ 10 newer): Winkler-80 0.4038 → 0.3964 (60 % cut 0.4048 → 0.3966, 80 % 0.4264 → 0.4159), the 50 %
  window 0.473 → 0.511 hit (it under-covered), 80 % coverage by doubt tercile 0.83 / 0.75 / 0.74 → 0.78 / 0.76 / 0.82
  (the table is too wide where the picture is easy and too narrow where it is doubtful). Unshrunk σ̂ over-corrects
  (0.75 / 0.78 / 0.85). The pipeline now pushes its own 50 % window (`logP25` / `logP75`) next to the 80 %.
  Crop trust is deliberately **not** a σ̂ input (artwork review proposal 3: it did not improve the interval on a time
  split); it is stored with the estimate for a re-test at ≥ 300 measured new photo puzzles.
- **Paired push guard** (P3) and **`newest30`** in the record (P6) - runbook §7.
- **Stored now for later models** (nullable columns of `puzzle_difficulty_estimate`, unused by any prediction):
  `logP25` / `logP75`, `pixelsPerPiece`, `cropTrust` (the `crop_trust` analyzer's, null until it exists),
  `pictureFactors` (16) + `pictureFactorsVersion` (frozen PCA basis 1.0.0 of the SigLIP 2 crop, 35.6 % of its variance -
  the taste model's puzzle side). The optional `piece_scale` analyzer joins the numbers automatically once it covers
  90 % of the training puzzles (estimate generation 1.2.1).

| Slice | n | Spearman | MAE (log) | Tier exact | 50 % window | 80 % window | 80 % width |
|---|---|---|---|---|---|---|---|
| **Newer from older** (n ≥ 20, the guard's number) | 433 | 0.704 → **0.732** | 0.0885 → **0.0824** | 0.550 → **0.584** (Avg 0.416) | 0.492 → 0.533 | 0.787 → 0.801 | 0.256 → 0.257 |
| newer 500 flat | 284 | 0.715 → **0.744** | 0.0882 → **0.0824** | 0.539 → **0.578** (Avg 0.408) | 0.465 → 0.496 | 0.768 → 0.778 | 0.256 → 0.245 |
| newer 500 box | 49 | 0.701 → 0.696 | 0.0832 → **0.0778** | 0.571 → 0.531 (Avg 0.388) | 0.673 → 0.673 | 0.898 → 0.857 | 0.327 → 0.297 |
| newer 500 assembled | 23 | 0.887 → 0.886 | 0.0924 → 0.0988 | 0.565 → 0.609 (Avg 0.261) | 0.478 → 0.522 | 0.783 → 0.826 | 0.293 → 0.294 |
| newer ≤300 flat | 34 | 0.670 → **0.757** | 0.0841 → **0.0649** | 0.588 → **0.647** (Avg 0.471) | 0.471 → 0.618 | 0.794 → 0.853 | 0.262 → 0.245 |
| **Newest 30 %, n ≥ 10** (out of fold - the gate's population) | 759 | 0.692 → **0.736** | 0.0886 → **0.0798** | 0.564 → **0.597** (Avg 0.435) | 0.505 → 0.524 | 0.785 → 0.802 | 0.255 → 0.247 |
| newest 500 flat | 394 | 0.699 → **0.756** | 0.0867 → **0.0771** | 0.553 → **0.584** (Avg 0.416) | 0.502 → 0.520 | 0.756 → 0.789 | 0.255 → 0.237 |
| newest 500 box | 117 | 0.651 → **0.697** | 0.0915 → **0.0860** | 0.573 → **0.598** (Avg 0.496) | 0.504 → 0.547 | 0.838 → 0.769 | 0.301 → 0.252 |
| newest 500 assembled | 47 | 0.856 → 0.852 | 0.0884 → **0.0828** | 0.638 → 0.638 (Avg 0.404) | 0.532 → 0.532 | 0.830 → 0.872 | 0.270 → 0.273 |
| newest ≤300 flat | 81 | 0.640 → **0.703** | 0.0899 → **0.0742** | 0.568 → **0.630** (Avg 0.432) | 0.481 → 0.543 | 0.765 → 0.827 | 0.271 → 0.245 |
| newest 1000 flat | 24 | 0.756 → 0.813 | 0.0766 → 0.0825 | 0.542 → 0.542 (Avg 0.250) | 0.500 → 0.375 | 0.792 → 0.750 | 0.256 → 0.225 |
| Out of fold, n ≥ 20 (all ages) | 2,142 | 0.762 → **0.791** | 0.0815 → **0.0752** | 0.575 → **0.606** (Avg 0.454) | 0.506 → 0.525 | 0.813 → 0.826 | 0.255 → 0.239 |
| Brands held out, n ≥ 20 | 2,142 | 0.686 → **0.704** | 0.0935 → **0.0909** | 0.534 → **0.542** (Avg 0.454) | 0.528 → 0.504 | 0.814 → 0.821 | 0.281 → 0.280 |

Reading it: the honest gain is the newer and newest rows - MAE −7 % / −10 %, which by the review's rule of thumb
(+0.04 exact per 10 % less MAE) is the tier-exact gain seen (+0.03). The 500 flat gate group, the one 1.1.0 missed by
0.4 SE (prod 0.547 vs the 0.56 bar), now reads 0.578 / 0.584 offline. Groups under ~50 puzzles move within their
noise (500 box newer n = 49 exact −0.04, but the newest-30 % 500 box with 117 puzzles +0.025; 1000 flat n = 24). The
newer 500 box 80 % window narrows from an over-covering 0.90 to 0.86 (newest: 0.84 → 0.77 - watch it in the gate
record; the app's per size × kind window scale is refitted from the gate rows anyway).

Cost: the nightly run is about 2.5 × 1.1.0's (12 more kernel fits and the 10 pair models of the offset): 50 min on the
review machine under heavy load from other jobs (load average ~100; train 31 min, validate 19 min, estimates 25 s) -
expect ~20-25 min on a quiet M3. The paired-guard file is 135 MB. Not verified offline: the effect on the app's
per-solve picture predictions and the gate record (needs the push and `evaluate-image-estimates` on production -
the orchestrator's step after merge).

## Not built / follow-ups

(Also in `docs/TODO.md`, "Insights page".)

- Estimated tiers on the other thumbnail corners (profile results, recent activity, ladders, most solved, round
  results, result detail, calendar): only the marketplace cards carry them (they have the estimates anyway).
  `ResolveDifficultyTiers` would need its own estimated-tier read.
- Solved puzzles, sell/swap and lend/borrow lists get no line (not in the plan's list).
- A list row whose puzzle has a tier but no time (e.g. no pace at that size) shows no line - and so no estimated tier.
