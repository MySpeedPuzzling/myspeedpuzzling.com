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

(filled in below as the offline experiments finish - every number newer-from-older: fit on the oldest 70 % of the
1.2.0 training puzzles by `addedAt`, test on the newer)

## 6. Runbook

- First run (after the deploy, on the M3 when `describe` is done or paused): `uv run --extra ml puzzle-vision
  finished-photos --profile m3-solo --dry-run --limit 50`, look at the summary (`methods`), then without `--dry-run`.
  ~34k photos at `per_puzzle = 4`: ~1.2 s a photo on 3 CPU workers + GPU → ~12-15 h; resumable (the work list is the
  state).
- Daily: new photos arrive with new results (~150 a day) - run it after `describe` in the nightly window, or by hand.
- Production cron (lily.srv): `0 4 * * * docker compose exec web php bin/console myspeedpuzzling:prune-finished-photo-analyses`.
