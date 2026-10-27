# Finished-puzzle photos - a second view of the puzzle's picture

Built 2026-10-27 (workstream `finished-photo-stage`, prediction-model review 2026-10-26: data-mining F9 + proposal 9,
plan C4). **Nothing here is shown anywhere** - not the photos, not what was measured from them. The photos feed one
number: the picture-based difficulty estimate (`difficulty_prior`, [`estimates.md`](./estimates.md)), which itself is
side by side only until the launch.

## 1. Why

- 85 % of puzzles (91 % of the 1000-piece puzzles players own) have fewer than 5 measured first tries, so the
  picture estimate decides their prediction (k = 0) or pulls it (k = 1-4, shrinkage).
- Most of those catalogue pictures are **photos of the box** (17,121 box-front puzzles with < 5 indices), and the
  estimate is worse on them: difficulty_prior 1.1.0 well-measured MAE box 0.097 / assembled 0.097 vs flat 0.083. A box
  photo is the box's print (glare, a slice of the box, sometimes not 1:1 the artwork), at a low resolution.
- Players' photos of the finished puzzle (69,413 on 19,368 puzzles; 9.7k under-measured box/assembled puzzles have at
  least one) show **the real print, assembled** - and sometimes what no catalogue picture or box text can: the cut
  (grid vs random, data-mining F5: cut words on < 1 % of boxes) and the finish (glare).

## 2. Privacy rules (Jan's brief: never expose player identity in the pipeline; never show the photos)

- The pipeline knows a photo only as **(puzzle, `photoFingerprint`)** - 16 hex of md5 of the stored path. No endpoint
  answers a player id, a solving time id or the path (`players/<playerId>/…` names the player). The photo itself comes
  **through the internal API** (`GET /internal-api/puzzles/{id}/finished-photos/{fingerprint}`), which reads the image
  proxy's `puzzle_large` rendition: ≤ 1200 px JPEG with **all metadata stripped** (no GPS, no camera serials) and
  streams it - the path never leaves the app. ~0.3 MB instead of the 2-3 MB original.
- **A private player's photo never leaves the app** (work list, download, store and bulk read all require a public
  player). A hidden puzzle's (secret competition puzzle, placeholder) neither.
- What was measured goes when the photo goes: the row cascades with its solving time; a removed or replaced photo and a
  player who went private are deleted by the daily `myspeedpuzzling:prune-finished-photo-analyses`.
- The Mac keeps the renditions only in its cache (`~/Library/Caches/puzzle-vision/finished-photos/<fp>.jpg`, deletable
  any time), never in the repository, logs or reports.

## 3. Storage - why a table of its own

`finished_photo_analysis`: one row per photo and analyzer (`ImageAnalyzer::FinishedPhoto`, unique `(analyzer,
solving_time_id)`), `photo_fingerprint`, `image_fingerprint` (the catalogue picture the photo was matched against),
`analyzer_version`, `result` jsonb, `analyzed_at`, `received_at`.

- **Not `puzzle_image_analysis`**: that table holds one row per puzzle and analyzer, keyed to the catalogue picture's
  fingerprint - a puzzle has many photos, and a new photo must not look like a new catalogue picture (or the other way
  round). The catalogue endpoints refuse `finished_photo` (`ImageAnalyzer::catalogue()`).
- **Per photo, not one aggregate per puzzle**: a photo is analysed once per version (expensive: SIFT, the piece grid,
  two GPU models); the per-puzzle aggregate (best photo, quality-weighted mean) is a modelling choice that changes with
  the estimate - it is computed in `derive` from the stored rows, so changing it never re-runs the photos.
- **Keyed internally to the solving time** (FK, cascade): deleting a result deletes what was measured from its photo;
  a merge moves the result - and with it the row - to the surviving puzzle, where it reads as stale (another catalogue
  picture) and is analysed again. No foreign key to `puzzle` (`PuzzleMergeForeignKeyCoverageTest` unchanged).
- **Stale** = the result carries another photo now, or the puzzle has another catalogue picture, or (asked with a
  version) another analyzer version. The work list lists exactly those; the bulk read says `current`.

## 4. The analyzer (`tools/puzzle-vision/puzzle_vision/finished_photo.py`, `finished_photo_stage.py`)

`uv run --extra ml puzzle-vision finished-photos --profile m3-solo [--limit N] [--dry-run]` - a command of its own,
not a phase of the claim runner (its unit is a photo, one GPU machine runs it, the work list's `status=stale` is the
to-do list). Profiles with a `[finished_photo]` table (m3-solo, m3-max; the same version on both - test-pinned):
`version = "1.0.0+sift-siglip2-dinov2"`, `per_puzzle = 4`, `workers = 3`.

Order: puzzles whose catalogue picture is a box / assembled photo and that have < 5 measured first tries first (the
second view is for them), then the other under-measured ones, then the rest. Per photo:

1. **Find the puzzle** - cheapest first, no VLM:
   - `register`: SIFT features of the catalogue's artwork crop (cut by its stored `artwork` result) matched into the
     photo (ratio test 0.75, RANSAC homography; ≥ 25 inliers and ≥ 15 % of the matches, a sane quad). The artwork's
     rectangle through the homography is the puzzle's outline - exact even when the photo is skewed, a timer or a hand
     covers part of it, or the puzzle runs out of the frame. It is also the identity check: a team photo, the box or
     another puzzle does not register.
   - `quad`: the largest edge quadrilateral (the artwork stage's `largest_quad`) with the artwork's aspect ratio
     (either way round, ±15 %) - for pictures too plain to register. **No identity check of its own** (a colour
     histogram was tried: 58 % of the right photos kept at 17 % of wrong ones accepted), so the estimate uses a
     quad-path photo only when its SigLIP 2 crop vector agrees with the catalogue crop's.
   - `none` (+ `reason`): nothing measured; stored, so it is not tried again for the version.
   Measured on 1,022 real photos: **94.5 % register, 0.5 % quad, 5 % none** (team photos, the box, a part of the
   puzzle too small) - ~1 s a photo on one core.
2. **Straighten** (perspective warp; registered photos in the artwork's orientation and aspect), keep the part inside
   the photo (`visibleShare`).
3. **Measure** (CPU): `pieceScale` (the piece_scale block for the real piece count, on the real print), `glare`
   (specular highlights - finish and light; with registration also where the artwork is not white), `seams` (the dark
   gaps between pieces projected on each axis; ln(power at the piece frequency / around it): a grid cut lines its seams
   up, a random cut does not; the same number on the catalogue crop as the artwork's own periodicity), `quality`
   (sharpness, `printCorrelation` = how closely the photo's colours follow the catalogue picture). `pieceScale` and
   `seams` only when the whole puzzle is in the frame.
4. **Embed** (GPU, model lock): SigLIP 2 + DINOv2-L of the straightened crop - the same models, preprocessing and code
   as the catalogue crop's `embed_siglip2` / `embed_dinov2`, so the two views compare.
5. **Store** in batches; `stale_fingerprint` / `not_found` are skipped; a photo failing 3 times waits for the next
   version.

Payload: [`image-taxonomy.md`](./image-taxonomy.md) §11.1 `finished_photo`. API: [`../internal-api.md`](../internal-api.md)
"Finished-puzzle photos".

## 5. Measurements

Offline, 2026-10-27, on the 10,406 puzzles the 1.2.0 estimate trains on (labels and pictures of 2026-10-24): up to 3
photos of each (the oldest results first, public players) = 17,653 photos on 8,122 puzzles, fetched as the 1200 px
rendition the endpoint hands over, measured with this module. Every comparison is **newer-from-older**: fit on the
oldest 70 % by `addedAt`, test on the newer puzzles.

**Finding the puzzle** (17,597 photos with a current `artwork` crop): register 16,725 (95.0 %), quad 108 (0.6 %),
none 764 (4.3 %). Usable for a second view (registered, ≥ 60 % of the puzzle in the frame, not the catalogue picture
itself): 7,849 puzzles. ~1 s a photo on one core.

**The cut measure is real** (no labels exist, so: does it behave like a property of the puzzle?):
- two photos of the same puzzle agree: `gridX` r = 0.58, `gridY` r = 0.56, `gridX − artworkGridX` r = 0.61
  (4,868 puzzles with two photos); the photo's grid barely follows the artwork's own periodicity (r = 0.21);
- brands cut one way: 21 % of its variance lies between brands (500 pieces, 45 brands, 3,760 puzzles; 1000: 22 %,
  13 brands) - brand means range from 0.95 to 2.94 (ln power ratio), as grid-cut and random-cut makers would.
- But it barely tells the estimate something new: Spearman with the 1.2.0 residual +0.00 (500), +0.05 (1000) - the
  1.2.0 brand × size offset already carries the cut of a known brand. Kept for new brands and for the taste model.

**The CPU numbers as a correction** of the 1.2.0 estimate (ridge on the residual, fitted on the older puzzles with a
usable photo; `photo` = the photo's piece-scale block + glare + seams + quality; `catalogue piece-scale` = the
`piece_scale` analyzer's numbers of the catalogue crop, for comparison; MAE of log difficulty, paired bootstrap 95 %
CI of the difference):

| Newer puzzles with a usable photo | n | 1.2.0 | + photo numbers | + catalogue piece-scale | photo vs 1.2.0 |
|---|---|---|---|---|---|
| ≥ 10 indices, all | 704 | 0.0772 | 0.0758 | 0.0752 | −0.0014 [−0.0030, +0.0004] |
| ≥ 10, box + assembled | 204 | 0.0805 | 0.0787 | 0.0806 | −0.0018 [−0.0049, +0.0015] |
| ≥ 10, 500 box | 94 | 0.0817 | 0.0786 (exact 0.564 → 0.617) | 0.0823 | −0.0031 [−0.0074, +0.0013] |
| ≥ 5, all | 1,273 | 0.0845 | 0.0831 | 0.0833 | −0.0014 [−0.0027, −0.0001] |
| ≥ 5, box + assembled | 500 | 0.0899 | 0.0882 | 0.0890 | −0.0017 [−0.0035, +0.0003] |
| ≥ 5, 500 box | 208 | 0.0836 | 0.0819 | 0.0847 | −0.0017 [−0.0047, +0.0010] |

Reading it: the real print's piece-scale numbers help box puzzles where the box photo's do not (500 box: photo −2 %
vs catalogue piece-scale +0.7 %; photo better than the catalogue's own, CI [−0.0054, −0.0002]), and the gain over
everything is ~2 %, at the edge of significance. The numbers alone are not worth a model version; the embeddings of
the straightened crop are the bigger part of the second view.

**The second view with the embeddings - four ways to use it** (16,833 crops embedded with SigLIP 2 + DINOv2 on the
M3, 0.26 s a crop; 7,845 puzzles with a view; difficulty_prior's own learners - 0.5 ridge + 0.5 RBF kernel ridge -,
folds and the 1.2.1 inputs incl. piece_scale, no brand × size offset in any arm; newer puzzles with a near-duplicate
among the older left out; every test prediction is picture-only = the k = 0 situation):
- `base` the catalogue inputs; `photo` a model on the photo view alone (+ pieces); `fusion` base + w × (photo − base)
  with w per catalogue kind from the older puzzles' out-of-fold predictions (flat 0.27, box 0.44, assembled 0.61);
  `joint` the catalogue inputs + the photo blocks in one model (a puzzle without a view: the views' mean + a has-view
  indicator).

| Newer puzzles (MAE / Spearman / tier exact) | n | base | photo alone | fusion | joint | joint − base, 95 % CI |
|---|---|---|---|---|---|---|
| ≥ 20 indices, with a view (the push guard's population) | 416 | 0.0799 / 0.756 / 0.579 | 0.0877 / 0.716 / 0.529 | 0.0795 / 0.767 / 0.575 | **0.0769 / 0.770 / 0.608** | [−0.0052, −0.0006] |
| ≥ 20, box with a view | 53 | 0.0830 / 0.664 / 0.509 | 0.0834 | 0.0807 | **0.0762 / 0.710 / 0.604** | [−0.0141, −0.0000] |
| ≥ 10, all newer (view or not) | 759 | 0.0838 / 0.740 / 0.581 | - | 0.0822 / 0.753 / 0.582 | 0.0824 / 0.751 / **0.594** | [−0.0031, +0.0004] |
| ≥ 10, with a view | 686 | 0.0809 / 0.743 / 0.579 | 0.0853 / 0.719 / 0.541 | 0.0791 / 0.758 / 0.580 | **0.0785 / 0.758 / 0.605** | [−0.0041, −0.0006] |
| ≥ 10, box with a view | 137 | 0.0862 / 0.646 / 0.533 | 0.0843 | **0.0820** / 0.672 / 0.577 | 0.0825 / 0.673 / **0.628** | [−0.0082, +0.0003] |
| ≥ 10, 500 box with a view | 94 | 0.0917 / 0.594 / 0.479 | 0.0861 | 0.0861 / 0.617 / 0.521 | 0.0872 / 0.624 / **0.585** | [−0.0094, +0.0007] |
| ≥ 10, ≤ 300 with a view | 134 | 0.0789 / 0.746 / 0.649 | 0.0812 | 0.0768 | **0.0743 / 0.764 / 0.687** | [−0.0090, −0.0004] |
| ≥ 10, 1000 with a view | 27 | 0.0771 / 0.789 / 0.556 | 0.0779 | 0.0752 | 0.0770 | [−0.0077, +0.0074] |
| ≥ 5, with a view | 1,237 | 0.0887 / 0.702 / 0.551 | 0.0930 | 0.0871 / 0.718 / 0.554 | **0.0867 / 0.718 / 0.569** | [−0.0034, −0.0006] |
| ≥ 5, 500 box with a view | 207 | 0.0895 / 0.675 / 0.536 | 0.0899 | **0.0854** / 0.696 / 0.560 | 0.0863 / 0.691 / **0.599** | [−0.0066, −0.0001] |
| ≥ 5, 1000 with a view | 73 | 0.0818 / 0.642 / 0.562 | 0.0816 | **0.0782** | 0.0807 / 0.668 / 0.575 | [−0.0055, +0.0035] |

- **The photo alone is worse than the catalogue picture** (flat: 0.0848 vs 0.0783) and only about even on box photos -
  a player's photo is a second, noisier view, not a better first one. Replacing the catalogue crop's embedding with the
  photo's would lose.
- **Joint wins** where the push guard and the gate look (≥ 20 / ≥ 10 indices, with a view: MAE −3.8 % / −3.0 %, both
  CIs below 0; tier exact +2.9 / +2.6 points) and on box photos for the tier (+9.5 points at ≥ 20 and ≥ 10); `fusion`
  has a slightly lower MAE on box photos at ≥ 5 / ≥ 10 but loses everywhere else and needs per-kind weights. Built:
  joint (`difficulty_prior` 1.3.0, below).
- **A puzzle's first photo only** (what it has after its first solves, the k = 1-4 situation, 654 puzzles ≥ 10): joint
  0.0815 → 0.0792 (CI [−0.0042, −0.0003]), 500 box 0.0917 → 0.0860, exact 0.479 → 0.585 - one photo is enough.
- **1000 pieces**: too few newer puzzles with a view (27 / 73) to say; no harm measured.

**`difficulty_prior` 1.3.0 in the module's own train + validate** (`derive/difficulty_prior.py`, joint as above, the
brand × size offset on top as always; the same 10,406 training puzzles, 7,845 of them with a view; 1.2.1 = 1.2.0 +
the piece_scale block, what main runs once piece_scale covers 90 %; every number out of the run's evaluation record):

| Slice | n | Spearman | MAE (log) | Tier exact | 50 % window | 80 % window | 80 % width |
|---|---|---|---|---|---|---|---|
| **Newer from older** (n ≥ 20, `holdoutSpearman`) | 433 | 0.745 → **0.758** | 0.0823 → **0.0797** | 0.575 → **0.601** (Avg 0.416) | 0.510 → 0.513 | 0.790 → 0.815 | 0.246 → 0.242 |
| newer 500 flat | 284 | 0.759 → 0.762 | 0.0811 → 0.0800 | 0.574 → 0.585 | 0.507 → 0.482 | 0.764 → 0.789 | 0.235 → 0.233 |
| newer 500 box | 49 | 0.712 → **0.766** | 0.0816 → **0.0743** | 0.490 → **0.592** | 0.551 → 0.571 | 0.816 → 0.878 | 0.278 → 0.270 |
| newer 500 assembled | 23 | 0.889 → 0.910 | 0.0982 → 0.0913 | 0.478 → 0.565 | 0.478 → 0.522 | 0.739 → 0.783 | 0.282 → 0.281 |
| newer ≤300 flat | 34 | 0.782 → 0.771 | 0.0706 → **0.0641** | 0.647 → 0.706 | 0.500 → 0.647 | 0.882 → 0.882 | 0.251 → 0.235 |
| **Newest 30 %, n ≥ 10** (the gate's population) | 759 | 0.745 → **0.761** | 0.0802 → **0.0785** | 0.590 → **0.614** (Avg 0.435) | 0.503 → 0.507 | 0.793 → 0.800 | 0.234 → 0.233 |
| newest 500 box | 117 | 0.697 → 0.720 | 0.0873 → 0.0851 | 0.590 → 0.598 | 0.521 → 0.513 | 0.778 → 0.812 | 0.241 → 0.248 |
| newest 500 assembled | 47 | 0.865 → 0.891 | 0.0797 → 0.0777 | 0.574 → **0.681** | 0.532 → 0.553 | 0.830 → 0.830 | 0.262 → 0.259 |
| newest 500 flat | 394 | 0.758 → 0.769 | 0.0783 → 0.0777 | 0.589 → 0.581 | 0.500 → 0.492 | 0.777 → 0.784 | 0.224 → 0.224 |
| newest ≤300 (all kinds) | 145 | 0.733 → 0.752 | 0.0754 → **0.0707** | 0.648 → 0.703 | 0.510 → 0.545 | 0.821 → 0.814 | 0.241 → 0.235 |
| newest 1000 (all kinds) | 30 | 0.807 → 0.801 | 0.0804 → 0.0791 | 0.467 → 0.600 | 0.367 → 0.400 | 0.800 → 0.800 | 0.227 → 0.213 |
| Out of fold, n ≥ 20 (all ages) | 2,142 | 0.799 → 0.806 | 0.0748 → 0.0733 | 0.600 → 0.620 | 0.509 → 0.506 | 0.812 → 0.817 | 0.227 → 0.224 |
| Brands held out, n ≥ 20 | 2,142 | 0.724 → 0.733 | 0.0886 → 0.0869 | 0.558 → 0.561 | 0.490 → 0.517 | 0.826 → 0.815 | 0.272 → 0.261 |

The record's `secondView` block (the same older model refitted without the photo blocks, the same 433 newer puzzles,
no offset): all 0.0823 → 0.0797; the 416 with a view 0.0799 → 0.0770, Spearman 0.756 → 0.770, exact 0.579 → 0.608;
the 82 box / assembled ones with a view 0.0871 → 0.0809, Spearman 0.751 → 0.779, exact 0.524 → 0.610.

Reading it: MAE −3 % overall, −9 % on 500 box photos (exact +10 points), Spearman +0.013 at the guard's population;
no slice worse beyond its noise (≤300 flat Spearman −0.01 on 34 puzzles while its MAE falls 9 %). The 80 % window
covers a little more (0.79 → 0.82) at the same width - the app refits its window scales daily anyway. Cost: the
nightly run 2,100 s train + 1,700 s validate on a loaded M3 (1.2.1: 1,444 + 1,000 s), peak memory 5.3 GB (the extra
~2,200 columns of the design and the second-view refit).

**Not verified offline**: the production view will hold up to 4 photos per puzzle (offline ≤ 3) and every puzzle with
photos, not only the training ones; the app's gate record (`evaluate-image-estimates`) on the new generation; how the
k = 1-4 blend uses it (the estimate is its shrinkage target there - the first-photo-only numbers above say one photo is
enough).

## 6. Runbook

- First run (after the deploy, on the M3 when `describe` is done or paused): `uv run --extra ml puzzle-vision
  finished-photos --profile m3-solo --dry-run --limit 50`, look at the summary (`methods`), then without `--dry-run`.
  ~34k photos at `per_puzzle = 4`: ~1.2 s a photo on 3 CPU workers + GPU → ~12-15 h; resumable (the work list is the
  state).
- Daily: new photos arrive with new results (~150 a day) - run it after `describe` in the nightly window, or by hand.
- The estimate: the nightly `derive` reads the stored analyses itself; once ≥ 2,000 training puzzles have a view it
  records and pushes `difficulty_prior` **1.3.0** - a new estimate generation, so the app's image gates close until
  `myspeedpuzzling:evaluate-image-estimates` has measured it (image-estimate-gates.md). The paired push guard skips that
  first run (the inputs changed shape - by design); the record guard compares like with like (holdoutSpearman 0.745 →
  0.758 offline).
- Production cron (lily.srv): `0 4 * * * docker compose exec web php bin/console myspeedpuzzling:prune-finished-photo-analyses`.
