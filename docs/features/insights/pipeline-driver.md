# Pipeline driver - artwork v1.1 and the road to launch (handoff 2026-10-19)

The brief for the Claude Code session that drives the image pipeline from here to the launch. It was written by the
orchestrator after reviewing 178 `artwork` results by eye, plus 60 through a refine prototype. `STATUS.md` (row
"Backfill") stays the living record; this file is the plan and the findings behind it.

**Your role:** you drive the image pipeline to the launch, on **one Mac**: the M3 Max MacBook Pro 36 GB, worker
`jans-macbook-pro-2-m3-max`. The M1 Air is paused. You own all of it:
- running the analyses;
- the artwork fix, validation and the estimates;
- the launch check;
- keeping `STATUS.md` current.

Jan (owner) does what you ask. Ask him only for decisions that are his.

## Read first
- CLAUDE.md in full.
- In this folder:
  - `README.md`;
  - `implementation-plan.md` (§8 launch, §9);
  - `STATUS.md` (row "Backfill" in full);
  - `image-pipeline.md` (runbook, profiles incl. `m3-solo`, dependency table);
  - `vision-analysis.md` (§4 artwork, §6 correlations, §9 design);
  - `image-estimate-gates.md`.
- `tools/puzzle-vision/`:
  - `puzzle_vision/stages/artwork.py`;
  - `imaging.py` (`crop()`, `artwork_crop()`, `is_skewed`);
  - `proposals/crops.py` (edge measures);
  - `worker.py`, `runner.py`;
  - `profiles/m3-solo.toml`;
  - `derive/difficulty_prior.py` (what the estimates read: the `artwork` kind, `embed_siglip2` + `embed_dinov2` on
    the crop, `embed_siglip2_full`).
- `docs/features/feature_flags.md` §`INSIGHTS_PUBLIC`.
- Memory: `reference_production_access.md`, `project_insights_page.md`.

## State on 2026-10-19 evening
- **Running:** `puzzle-vision run --profile m3-max --phase artwork`, started 19:05 Prague from the main checkout.
  - Log: `~/Library/Logs/puzzle-vision/run-artwork-only-20261019.log`, plus `m3-max.log`.
  - Pid file: `~/Library/Application Support/puzzle-vision/run-m3-max.pid`.
  - Rate: ~1,850/h. It ends by itself when `artwork` is complete, ETA ≈ 2026-10-20 06:00 Prague. Nothing runs after
    it.
  - Stop it only with SIGTERM: it stores what is done and releases its claims.
  - It runs the old code: the fixes below need a restart from your own worktree.
- **Production at 18:47:** 43,280 puzzles with a picture. Count by `analyzed_at`; the Macs push in batches.
  - `artwork` 23,049
  - `embed_siglip2_full` 18,285
  - `barcode` 14,798
  - `ocr` 10,802
  - nothing else; no difficulty estimates pushed.
- **M1 Air:** asked to stop. Verify that no `macbook-air-3-m1-air` writes appear after ~19:30 on the box:
  `docker compose logs web | grep internal_api_audit` (the summary names the worker).
- **On main already:**
  - `8676afa97`: profile `m3-solo`. Phases artwork → embed → apple → features → describe; the estimate's inputs are
    ready after ~30 h, the whole run takes ~7 days, `describe` alone ~128 h.
  - `362dec250`: two fixes.
    - A worker no longer ends with "no more work" when a claim returns only images it already handled. That was the
      cause of `barcode` stopping at 14,800: released or expired images come back first in the claim order.
    - A 60 s watchdog on Apple Vision calls (`ocr`/`barcode`/`vision_labels`). The text call inside `traits` is not
      covered yet.
- **Image server:** served HEIC from a 240×320 preview; fixed 2026-10-19 (lily.srv `616bfc3`). The analysis reads
  originals and was never affected.
- **Review material:** everything is in `~/puzzle-vision-review/2026-10-19/`.
  - `sample` - `sample5`: `review.tsv` + `ann/` + `sheet-*.jpg`;
  - `refine/`: prototype sheets + TSVs;
  - `review-sheets.sh <dir>`: renders every result in `<dir>/review.tsv` as original with bbox + corners | the crop
    **exactly as `imaging.crop` makes it**.
  - Never judge a crop from an imgproxy rectangle link.

## Findings (what v1.1 must fix)
Base rates from a **uniform random sample of 48** (`sample4`):
- flat: 23/24 fine;
- box: ~7/13 artwork only, ~4/13 keep box print;
- assembled: 9/11 fine, 2/11 artwork cut.

Overall ≈ 81 % good, ≈ 4 % artwork cut. **Jan's rule: never cut real artwork. Box print left in is bad; cut artwork
is worse.**

1. **Wrong corner quads cut artwork and shear the picture - the worst problem.**
   - What happens: the OpenCV quad snap (`artwork.py`, accepted at IoU > 0.75 with the VLM box) locks onto lines
     *inside* the picture or the table, so the perspective warp cuts triangles off and shears the picture. Mostly on
     assembled photos.
   - Examples: Home for Christmas, Art of the Table, Garden Cats, Wild & Whimsical, Tom and Mia.
   - Scale: 784 of 1,277 assembled quads have a skew > 0.05, and in 540 of them the VLM box already covered ~the whole
     photo. Of 12 such results reviewed (`sample5`), 3 lost large triangles, 3 lost slivers and 6 were real
     perspective. Box quads too (138 with skew > 0.05 and a full VLM box).
   - Fix: accept a quad only when each of its four edges lies on a real edge along most of its length (the gradient
     along the line; `proposals/crops.py` has the measure) and the picture does not continue across it. Otherwise
     fall back to the VLM bbox.
   - This needs no model: it re-checks the stored corners on CPU.
2. **Box photos: the crop is the box face, not the artwork.**
   - Title bands, brand strips, piece-count bands, frames and side panels stay in about a third to a half of them.
   - The 4B VLM at 672 px ignores the prompt's "exclude title strips": on Pasta its own bbox already held the lid.
   - Examples: Pasta (Piatnik header), Good Nabot Store (Springbok frame + band), Model T (Coca-Cola frame - the
     artwork is the inner photo), Key West, Botany, Fruit Platter, Hatber, 3D shop renders.
   - It matters for the estimates too: the crop embeddings see the print.
   - The classic refine prototype (branch `worktree-agent-a088805aeaa482092`, `ceb48d6d0`, `puzzle_vision/refine.py`
     + `scripts/refine_eval.py` + tests, not on main) is **safe but weak**: 0 artwork cut, but it changed only 4 of 60
     fresh boxes.
   - Fix: **a second VLM pass on the straightened face**:
     - straighten as `imaging.crop` does;
     - the same Qwen3-VL-4B with a face-only prompt: the picture only; title bands, brand strips and printed frames
       out; logos printed over the picture stay in; "the whole face" when the picture fills it;
     - map back with the prototype's `map_rect()`;
     - keep the classic refine as pre-pass and **veto** (no trim on a side where art crosses the line), max ~35 %
       per side.
   - Cost: one VLM call per box result, ~2 s → ~7 h for ~13k boxes.
3. **Type (`kind`) wrong in about 1 in 5.**
   - `other` was wrong in 8 of 9 reviewed (really flat or assembled).
   - flat ↔ box ↔ assembled swaps (Pop Tarts "assembled", Friends box "flat", a photo of a printed picture "flat" -
     a gate false positive that sends a photo down the trim path, so its table stays).
   - 3D renders and tilted photos called `box_front`.
   - It matters for the launch: estimate intervals and the gates are per size group × kind.
   - Fix: a second opinion for every `other` result and for every disagreement between the SigLIP gate
     (`kindScores`) and the VLM kind. Store front/angled from the geometry as signals; the prototype's rule agreed
     with the VLM label only 16/30, so let crops use the geometry.
4. **VLM-only crops can be too tight.**
   - Example: "I Love Summer", an assembled photo whose VLM box missed the right/bottom of the puzzle.
   - Fix: the same edge check - a crop edge that is not a picture edge is widened (to the accepted quad, else the
     photo).
5. **Confidence is unusable as stored.**
   - Values: `vlm` a constant 0.5, `flat/trim` the gate margin (~0.02), `vlm+quad` high even on failures.
   - Fix: store one built from the agreement and edge signals, so crops and proposals can be filtered.
6. **Small originals:** 1,013 (4 %) have a long side under 500 px, mostly shop renders.
   - Fine for embeddings; never a crop proposal.
   - Fix: store a flag.
7. **Low priority:**
   - round and shaped puzzles keep background corners;
   - assembled photos rotated 90° are not rotated;
   - a photo of a box lying on the assembled puzzle crops the box picture.

**Flat pictures (trim path) are reliable.** v1.1 leaves them alone, apart from the kind re-check.

## Plan, in this order
1. **Tonight:** only `artwork` runs. Check it is alive and claiming. One GPU holder at a time.
2. **Build `artwork` v1.1** as a pass over the stored v1.0 results: it re-uses bbox, corners and kind, with no fresh
   first pass for unchanged parts. Items 1-6 above, with tests.
   - Run the pipeline from your own worktree (e.g. `.claude/worktrees/pipeline-run`, `uv sync --extra ml`), never
     from or in the main checkout.
   - Make sure every crop-based analyzer goes stale on the new artwork version: `embed_siglip2`, `embed_dinov2`,
     `segmentation`, `vision_labels`, `colors`, `traits`, `describe` (composite versions - check, and bump where
     needed).
3. **Validate before any full run.**
   - **Reference set**, held out from tuning: the lab's 78 hand-marked images
     (`.claude/worktrees/insights-vision-lab/sample/ground_truth.tsv`, read-only) + the reviewed samples
     (`~/puzzle-vision-review/2026-10-19/sample*` - 178 results with known verdicts) + the 60 fresh boxes in
     `refine/`.
   - **Measure:**
     - new artwork cuts (must be 0);
     - IoU;
     - boxes still keeping print;
     - wrong quads accepted;
     - kind accuracy.
     Make it one command that every future artwork version runs first.
   - **A pilot of ~200 random results:** look at every contact sheet yourself (original | old crop | new crop), then
     show Jan with your verdict per image.
   - **Jan's yes before the full run.**
4. **Full v1.1 pass:** the quad re-check on CPU over every quad result, the face pass on the box results, the kind
   re-check on `other` + disagreements.
5. **Launch path, `m3-solo` phase `embed`:**
   1. `embed_siglip2_full` (finish), `embed_siglip2` + `embed_dinov2` on the v1.1 crops.
   2. `derive`.
   3. `docker compose exec -T web php bin/console myspeedpuzzling:evaluate-image-estimates` on prod.
   4. Report the gates per size group × kind.
   5. **ASK JAN before removing `INSIGHTS_PUBLIC`. Never remove the flag without his explicit yes.**
6. **Before the launch, after `embed`:** phases `apple` (`ocr`, `barcode`) and `features` (~8.5 h) - the image traits
   of the Insights page come from them. **`describe` is not waited for** (Jan 2026-10-23: launch before it, Jan's UX
   feedback is fixed meanwhile).
7. **After the launch:**
   - phase `describe` (~6 days), last; a `derive` once it is done (tags);
   - the M1 Air can rejoin then (`--phase A`, later B). Ask Jan.
   - Before any crop proposal (`propose-crops`, source `image_pipeline`): the confidence threshold, the never-cut
     edge check, no small originals, a reviewed sample.

## Rules
- **Commit and push every change** (Jan's instruction).
  - Stage explicit paths, never `git add -A`.
  - Code goes through worktree branches merged to main when the tests pass.
  - `STATUS.md`: fetch, rebase origin/main, `git commit -- docs/features/insights/STATUS.md`, push HEAD:main. Keep
    the "Backfill" row current: counts, rate, ETA, stalls over 3 h.
- **Production:**
  - `ssh -o IdentitiesOnly=yes -i ~/.ssh/id_rsa root@lily.srv.thedevs.cz`, `cd /srv/myspeedpuzzling`.
  - DB: `docker compose exec -T db psql -U speedpuzzling speedpuzzling`, SQL from a local file on stdin.
  - Originals: `https://img.myspeedpuzzling.com/original/<puzzle.image>`, EXIF auto-orient. Stored coordinates are
    relative to the upright image.
- **Never:**
  - `doctrine:migrations:migrate`;
  - dropping the dev DB `speedpuzzling`;
  - the full test suite locally.

  Docker always `--rm`.
- **Secret puzzles never leak:** a `hide_until` / `hide_image_until` in the future means never listed, sampled or
  shown. No player names in the repo (it is public).
- **Look at the images yourself** before telling Jan anything about crops. Show him tables with the original URL, the
  crop as the pipeline makes it, and your verdict.
