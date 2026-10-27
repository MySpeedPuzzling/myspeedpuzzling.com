# Puzzle image analysis (vision) - research + PoC

Status: research done 2026-10-18, PoC in `.claude/worktrees/insights-vision-lab/` (git-excluded, local only).
Feeds the personal **Insights** page ("you are 9 % slower on puzzles where one colour mass dominates"). This doc covers
artwork detection + crop, colour and structure features, their correlation with puzzle difficulty, measured
throughput on Jan's MacBook (M3 Max, 36 GB), the rerunnable local pipeline and the JSON payloads for the internal API.
**Theme/style categories are not here** - the vocabulary lives in [`image-taxonomy.md`](image-taxonomy.md).

## 1. Summary + recommendation

- **Artwork detection** (stage `artwork`): a cheap **kind gate** (SigLIP2 zero-shot, 0.14 s/img) sends flat artwork
  straight to a deterministic **border trim** (OpenCV, ~5 ms); everything else (box photos, angled/3D boxes, assembled
  puzzles) goes to **Qwen3-VL-4B-Instruct (4-bit MLX)** for the picture's box + corners (2.7 s/img), snapped to an
  OpenCV quadrilateral when one agrees (gives exact edges + perspective corners). Result: mean IoU **0.915** vs a
  hand-labelled ground truth (0.842 on the 40 non-flat images, vs 0.70 for "use the whole image"); the VLM is skipped
  for ~37 % of the sample (~24 % of the catalogue, which has more phone photos).
- **Colours** (stage `colors`) and **structure traits** (stage `traits`) are plain numpy/OpenCV/scikit-image + Apple
  Vision OCR on the crop: deterministic, CPU only, **0.42 s/img per core**.
- **What relates to difficulty** (160 rated puzzles, Spearman with `difficulty_score`): a regular/geometric layout
  (ρ = −0.40), text in the picture (−0.33), large flat colour fields (−0.22), colourfulness (−0.21) and distinct
  piece-sized patches (−0.20) go with **easier** puzzles; busy fine detail (edge density +0.24) and one colour mass
  dominating the picture (+0.20) with **harder** ones. Darkness, gradients and small-scale uniform areas show no
  clear effect in this sample. Small sample, tier-stratified, brand house styles confound - evidence, not proof (§6).
- **Backfill** of 41.3k images ≈ **25-27 h GPU** (three nights or one weekend), daily ≈ 75 images ≈ **4 min**.
  Peak memory per stage ≤ 7.3 GB, one model loaded at a time.
- **Licences**: every component used is commercial-OK (Apache 2.0 / MIT / BSD / macOS framework) - table in §7.
- **API contract**: fits as designed; proposals in §9.4 (composite versions so `colors`/`traits` go stale when the crop
  changes, hidden images excluded, version compare = string equality).

## 2. How puzzle images are stored and served

- `puzzle.image` is the S3 object key; `puzzle.image_ratio` the displayed width/height (EXIF-aware,
  `ImageOptimizer::getImageRatio()`).
- Original: `https://img.myspeedpuzzling.com/original/{image}` (`UploaderHelper::getPublicPath()`), Cloudflare
  `cache-control: public, max-age=31536000, immutable`, ETag = content MD5. Thumbnails through imgproxy presets
  `/preset:{puzzle_small|puzzle_medium|puzzle_large|avatar}/plain/{image}` (`ImageCachePurgeList::PRESETS`).
- **A replaced image always gets a new key.** `PuzzleImageNamer::generateFilename()` builds
  `{brand}-{name}-{pieces}-{shortId}-{random}.{ext}` with 4 random bytes - "a name never written before", because the
  image caches key on the name. Secret puzzles get a random 32-hex name (`secretFilename()`); `Puzzle::moveImageTo()`
  renames a secret puzzle's object when it is revealed (same picture, new key - a harmless re-analysis). So **the image
  key is a natural fingerprint**: equal key = same bytes. (Older keys like `galison-spot-of-tea-500.jpg` predate the
  random suffix but were unique when written.)
- Resolution: uploads are scaled to ≤ 2000 px since `ImageOptimizer` (`MAX_DIMENSION`); older ones are larger. Sample
  of 300: longest side median 1500 px (min 225, max 7698), mean file 1.09 MB; jpg 82 %, png/webp/jpeg ~9 % each.
- Local MinIO has no real images (dev is standalone); the PoC downloaded originals from the public host at ≤ 4 req/s.

## 3. Sample + ground truth

- **300 images**, picked by read-only SQL on the dev DB (a production copy, data until 2026-09-28):
  - 160 **rated** puzzles with `confidence IN ('medium','high')`, stratified by tier (20 of tier 1, 28 each of tiers
    2-6; no puzzle in tier 7), at most 3 per brand per tier;
  - 140 **unrated**, one per brand (random). 218 brands in total; pieces 500 (179), 1000 (52), 300 (16), 100 (13),
    the rest 50-10000. Puzzles with `hide_until` / `hide_image_until` in the future skipped.
- **Ground truth**: 78 images hand-labelled from 10 %-tick contact sheets: kind + the artwork's axis-aligned box
  (≈ ±3 % of the side). Kinds: flat 38, box_front 18, box_angled 3, box_3d 2, assembled 14, other 3. Box rule: the
  printed picture incl. logos/badges printed over it, excluding box edges/sides, title strips outside the picture,
  table and background; round puzzles = the circle's bounding box.
- **Real variety** (Qwen3-VL-4B over all 300): flat 45 %, box front 25 %, assembled photo 25 %, angled/3D box 4 %,
  other 1 %. Rated (popular) puzzles are mostly flat art (gate: 53-63 % flat), unrated ones mostly phone photos
  (15-23 % flat). Odd cases seen: shrink-wrapped boxes, a box held in a hand, a puzzle pouch render, box backs with two
  images, a leaflet, assembled puzzles held up or rotated 90°, letterboxed art on white, round puzzles, a 10000-piece
  puzzle photographed half-done.

## 4. Artwork detection - comparison

IoU of the predicted axis-aligned box vs the ground truth (78 images; "non-flat" = the 40 images that are not flat
artwork). Speed per image on the M3 Max (MPS / MLX, image ≤ 1024 px), measured without other GPU work.

| Method | mIoU all | IoU ≥ 0.8 | mIoU non-flat | IoU ≥ 0.8 non-flat | Speed | Peak memory | Licence |
|---|---|---|---|---|---|---|---|
| Whole image (baseline) | 0.837 | 65 % | 0.698 | 38 % | - | - | - |
| Border trim (OpenCV, uniform-colour margins) | 0.849 | 71 % | 0.710 | 42 % | ~5 ms | - | Apache 2.0 |
| OpenCV trim + largest quadrilateral | 0.869 | 77 % | 0.759 | 55 % | 11 ms | - | Apache 2.0 |
| Apple Vision `VNDetectRectanglesRequest` (largest) | 0.710 | 56 % | 0.674 | 42 % | 99 ms | - | macOS framework |
| BiRefNet salient object, mask bbox | 0.700 | 51 % | 0.754 | 53 % | ~670 ms | 1.2 GB | MIT |
| BiRefNet, used only when the mask is rectangular | 0.842 | 69 % | 0.763 | 53 % | ~670 ms | 1.2 GB | MIT |
| OWLv2 base "a jigsaw puzzle" (best of 5 prompts) | 0.686 | 44 % | 0.635 | 30 % | 790 ms | 1.2 GB | Apache 2.0 |
| Grounding DINO tiny "puzzle box" (best of 3) | 0.570 | 44 % | 0.583 | 35 % | 770 ms | 1.5 GB | Apache 2.0 |
| **Qwen3-VL-4B-Instruct 4-bit (mlx-vlm)** | 0.913 | 83 % | 0.843 | 68 % | **2.7 s** | 4.3 GB | Apache 2.0 |
| Qwen3-VL-8B-Instruct 4-bit (mlx-vlm) | 0.907 | 86 % | 0.834 | 72 % | 3.4 s | 6.8 GB | Apache 2.0 |
| **Chosen: SigLIP2 gate → trim, else Qwen3-VL-4B + quad snap** | **0.915** | 85 % | 0.841 | 70 % | 0.14 s + 2.7 s on ~78 % | 7.3 / 4.3 GB (separate runs) | Apache 2.0 |

Findings:

- **Open-vocabulary detectors and salient-object models do not understand "the picture on the box"**: OWLv2 / Grounding
  DINO return the box, a subject inside the picture or the whole image; BiRefNet segments the box (good for assembled
  puzzles, 0.94) but cuts subjects out of flat artwork. Not worth their cost.
- **Classic OpenCV is a good cheap first step** (trim removes letterboxing; the largest-quad search finds box edges and
  assembled puzzles), but cannot separate the picture from a box's title strip, and the picture inside a box often has
  no clean edge.
- **The VLM is the only method that gets the box photos right** (box front 0.83, angled 0.78, 3D render 0.88). The 4B
  model is as accurate as the 8B at 80 % of the time and 63 % of the memory. Kind classification: 4B 61/78 exact
  (box_front vs angled confusions; 6 flat images called "assembled"), the 8B called 32 of 38 flat images "assembled" with
  the same prompt - unusable for kind without prompt work.
- **SigLIP2 so400m zero-shot is the better kind gate**: flat vs not-flat right on 74/78 (95 %) at margin 0, and with a
  0.01 margin no non-flat image is ever sent to the trim path by mistake while 37 % of the sample skips the VLM, with
  identical IoU.
- **Corners / perspective**: the VLM's corners are mostly an axis-aligned rectangle; real perspective comes from the
  OpenCV quad snapped inside the VLM box (52 of 165 non-VLM-flat images in the hybrid run, accepted when IoU with the VLM box > 0.75).
  34 of 300 crops were perspective-warped. Worst cases left: a white box whose picture has no visible border, a pouch
  render, box backs, a box whose title strip the VLM included.
- Measured once under GPU contention (another agent's model on the GPU): the 8B ran 3× slower (10 tok/s instead of
  34). The pipeline must hold the GPU alone (§8.6).

### 4.1 Artwork 1.2 (2026-10-27) - fewer cuts, the hypotheses stored

From the 2026-10-26 model review (artwork report F2, proposals 5-9). Code: `stages/artwork_refine.py`
(`NO_EDGE_FALLBACK`, `PriorAnswers`, `SAM_*`), `stages/artwork_sam.py`, `stages/artwork.py` (`rules_from`,
`flat_probe`). **The rules switch on with the profile's version**: a profile still on `1.1.x` stores exactly what 1.1
stored (`ArtworkStage.v12`), so the code can be deployed before the version is bumped.

All numbers below come from one harness: `artwork_eval --candidate memo` = the current code on the stored first pass with
the VLM's answers of earlier eval runs (no model; it reproduces 1.1 on all 453 labelled pictures without one missing
answer). Counts on the sets; "weighted" = catalogue rates (`artwork_eval` weighting). `tuning` is the only stratified
random sample, so its weighted rates are the best estimate of the catalogue until Jan's random test set is marked.

| Version | validation (`reference`, 238): cut / big cut / print / within 5 % / mIoU | tuning + feedback (215): cut / big / print / mIoU | catalogue (tuning-weighted): cut / big / print / within 5 % |
|---|---|---|---|
| 1.1 | 45 / 22 / 58 / 179 / 0.914 | 34 / 16 / 55 / 0.933 | 14.2 % / 6.8 % / 18.5 % / 82.8 % |
| fallback on upright faces only (the review's measurement) | 35 / 16 / 61 / 181 / 0.919 | 31 / 15 / 63 / 0.928 | 13.0 % / 6.7 % / 21.4 % / 80.4 % |
| **1.2** (fallback on every face) | **22 / 6 / 69 / 175 / 0.911** | **23 / 7 / 71 / 0.921** | **8.4 % / 3.4 % / 24.4 % / 81.2 %** |

(validation-weighted: cut 17.6 → 8.8 %, big cut 9.1 → 2.6 %, print 22.1 → 26.4 %.)

- **No-edge fallback (proposal 6, in).** A face-pass side taken on the VLM's line with no colour step under it (cut
  13 % of the time in 1.1) goes no deeper than the first pass's crop on that side (`trims_in_face`: the first pass's
  box mapped into the straightened face, its outer corner per side). Applied to tilted faces too: the review measured
  upright faces only (cuts 45 → 35, the trade Jan approved); on every face cuts halve and big cuts fall by 3-4× for
  more print (58 → 69 on validation). Side midpoints instead of outer corners: cuts 29, big 10, print 69 - worse.
  Jan's call to make: the upright-only rule is the approved trade; 1.2 as built goes further.
- **Hypotheses stored (proposal 5, in).** `signals.decidedBy` (per side: trim / quad / fit / tilt / VLM box / widened
  (no edge) / edge / VLM line / first pass / SAM + step + VLM line / photo border), `signals.conservative` (per side the
  outer of the first pass's crop and the final one), `firstPass.vlmQuad` (the VLM's own corners - only for pictures
  whose first pass runs now, the stored first passes never kept them), `signals.face.beforeGrow` (the face question
  before a grow, so a later re-run asks neither again).
- **Re-used VLM answers.** A 1.2 run on a picture with a stored 1.1 result re-uses the stored kind check and face
  answer when it asks the same question of the same pixels (same face outline) - `signals.vlmReused`. With the
  fallback alone no outline changes, so the 1.2 re-run of `artwork` needs the VLM only for grown faces (~350) and new
  pictures.
- **Skewed outlines only when two hypotheses agree (proposal 8, out).** Measured on top of the fallback: keeping a
  tilt / fit outline only when the box-edge fit and the straight-line angles (or the 1.0 snap) agree within 1-3° keeps
  3-7 of ~50 skewed boxes and is neutral to worse (validation big cuts 4 → 5-6, print 55 → 58-59 on the box photos;
  tuning + feedback cuts 18 → 20-22). Turning tilt / fit off altogether: validation cuts 22 → 23-28. Once the
  fallback holds the no-edge sides, the skewed paths are no longer where the cuts come from.
- **SAM 2.1 (proposal 7, built, off: `SAM_SIDES = False`).** `facebook/sam2.1-hiera-small` (Apache-2.0) via
  transformers, asked once per face and only when a side would be held; the VLM's line is kept where SAM's mask edge, a
  colour step (≥ 0.35 of the line) and the line agree within 1 % of the face. Prompted with the VLM's own box, the mask
  echoes that box: on a CPU sample it agreed with the VLM on a picture inside the picture and cut 41 %. Prompted with
  the first pass's box (`SAM_PROMPT = "held"`, independent of the VLM's line) it never added a cut but it barely
  matters: tuning print 49 → 48, mIoU +0.0018; feedback no change; validation 12 sides agreed, no count changed, mIoU
  +0.0003 (CPU, the 47 / 68 pictures with a held side). SAM costs ~3 s a face on the CPU (MPS faster) - not worth a
  model in the run; switch it on only if a later measurement on the random test set says otherwise.
- **Whole-image probe (proposal 9, a signal only).** `signals.flatProbe` = a logistic probe on the stored
  `embed_siglip2_full` vector (`artwork_flat_probe.json`, trained on production outside the labelled sets). As a
  decision it cannot be validated: above a confident 2 it flips no tuning / feedback picture and one validation
  picture; at 0-1 it flips 7 of 20 missed flats with 1 wrong on tuning + feedback, 2 of 3 right on validation. 153
  production photos score > 2, 61 > 3 - a list for Jan to look at, never the kind.
- **The difficulty estimate does not improve (measured 2026-10-27, so 1.2 stays dormant).** 1,196 crops 1.2 moves (puzzles
  with ≥ 3 measured first tries) + 150 unchanged controls were re-cropped and re-embedded exactly like the pipeline
  (SigLIP 2, DINOv2 cls + patches, segmentation, vision labels, colors, traits) and the picture estimate's model of
  record compared paired, same folds, old vs new crop inputs:

  | moved crops | n | MAE old → new | Δ (95 % CI) | Spearman old → new | tier exact old → new |
  |---|---|---|---|---|---|
  | ≥ 5 indices, today's model, inputs swapped | 471 | 0.1005 → 0.1019 | +0.0014 (−0.0017, +0.0045) | 0.668 → 0.666 | 0.501 → 0.507 |
  | ≥ 5, refit on the new inputs | 471 | 0.1005 → 0.1019 | +0.0014 (−0.0017, +0.0045) | 0.668 → 0.666 | 0.501 → 0.516 |
  | ≥ 5, older 70 % → newer 30 % | 237 | 0.0973 → 0.0983 | +0.0009 (−0.0029, +0.0047) | 0.623 → 0.613 | 0.498 → 0.523 |
  | ≥ 10, inputs swapped / refit / time split | 147 / 147 / 85 | | +0.0007 / +0.0013 / +0.0014 (CIs ±0.006) | | |

  Controls (stored crop re-embedded, inputs swapped): Δ 0.0000 - the re-embedding reproduces the stored vectors.
  Whole model after the refit: MAE 0.0887 → 0.0888 on 6,601 rows. No gain, if anything a hair worse. This is the
  review's F6 again: the crop's exact edges barely move the estimate (an embedding of the whole picture with a sliver
  of box print carries the same difficulty signal). **So 1.2 is better crops for marking, display and crop proposals,
  not for the estimate.** It stays dormant (profiles on 1.1.x): switching it on costs the artwork re-run (~4-6 h, mostly
  CPU) plus re-embedding the moved crops (image-pipeline.md §4), for no measured estimate gain. Do it when the crops
  themselves are the point (crop proposals, the review queue, Jan's display), ideally together with another reason to
  re-embed. Scripts and rows: `~/puzzle-vision-review/model-review-2026-10-26/artwork-1-2/` (`recrop_embed.py`,
  `difficulty_compare.py`, `out/difficulty_compare.log`).
- **What changes in the catalogue.** 10,634 of the 43,481 current crops (24.5 %) move (IoU of old vs new crop < 0.995
  or a side > 0.5 % of the image; median IoU 0.934, a side moves a median 4.2 %), all of them face-pass crops
  (vlm+widen+face 7,268, vlm+fit+face 1,392, vlm+face 1,159, vlm+quad+face 419, kind+face 235, grown 161). Computed from
  the stored face-pass signals and checked against a full image replay (`artwork_replay`) on 1,043 pictures: 1,036
  agree, 7 boxes differ slightly, 3 (grown faces) need the VLM.

## 5. Colour + structure features

Computed on the artwork crop (box crop, or perspective warp for skewed quads, inset 1 %), resized to a longer side of
**768 px** so values are comparable across originals. Colour maths in **OKLab** (perceptual, L 0..1). Code:
`.claude/worktrees/insights-vision-lab/scripts/features.py` (`colors()`, `traits()`).

### 5.1 `colors`

| Key | Definition |
|---|---|
| `palette` | k-means (k = 8, 40k sampled pixels, OKLab, seed 0): `hex`, `share`, `family`, `oklab`, sorted by share |
| `families` | shares summed per colour family (red, orange, yellow, green, teal, blue, purple, pink, brown, black, grey, white) from OKLab lightness/chroma/hue rules |
| `dominantShare` | share of the largest palette cluster |
| `paletteEffectiveColors` | exp(Shannon entropy of the 8 shares) - 1 = one colour, 8 = eight equal colours |
| `colorEntropyBits` | Shannon entropy of a fixed OKLab histogram (L 10 × a 12 × b 12 bins) |
| `colorfulness` | Hasler & Süsstrunk (2003): √(σ_rg² + σ_yb²) + 0.3·√(μ_rg² + μ_yb²), rg = R−G, yb = ½(R+G)−B |
| `lightnessMean`, `lightnessStd` | mean / std of OKLab L (brightness, global contrast) |
| `darkShare`, `lightShare` | share of pixels with L < 0.35 / L > 0.85 |
| `chromaMean`, `mutedShare` | mean OKLab chroma √(a² + b²); share with chroma < 0.04 (greys, near-monochrome) |

### 5.2 `traits`

| Key | Definition |
|---|---|
| `edgeDensity` | share of Canny edge pixels (Gaussian 3×3, thresholds 40/100 on L) - busy fine detail |
| `detail` | mean \|Laplacian\| of L ×100 - high-frequency energy |
| `gradientMean` | mean OKLab gradient magnitude (Sobel on L, a, b - colour edges of equal lightness count) |
| `flatShare` | share of 16 px blocks with mean gradient < 0.06 (no texture) |
| `uniformShare`, `largestUniformRegion`, `uniformRegions` | flat blocks joined into regions (4-connectivity, ≥ 0.5 % of the area); a region whose colours spread < 0.06 ΔE (p95−p5) is uniform: total share, largest share, count |
| `smoothGradientShare`, `gradientRegions` | the same regions with spread ≥ 0.06: smooth gradients (sky, "gradient puzzles") |
| `largestFlatRegion` | largest flat region of either sort |
| `colorRegions`, `colorRegions80`, `largestColorRegion` | SLIC superpixels (400 at 384 px) merged by mean colour (RAG cut at ΔE 0.04): number of macro colour regions, how many cover 80 % of the picture, share of the largest - "one colour mass dominates" (textured skies and seas included, unlike `flatShare`) |
| `textShare`, `textChars` | Apple Vision text recognition (fast level, confidence ≥ 0.4): share of the area in text boxes, characters read |
| `pieceGrid` | the crop cut into ≈ `pieces` cells (cols = round(√(pieces·w/h))) - one cell ≈ one piece |
| `pieceFlatShare` | share of cells with no texture (mean gradient < 0.06 and L std < 0.03) - featureless pieces |
| `pieceLookalikeShare` | share of cells whose nearest other cell (descriptor: mean + std OKLab, texture, 6-bin gradient orientation histogram) is closer than 0.035 - pieces with a look-alike |
| `pieceDistinctiveness` | median nearest-neighbour distance of the cells - how distinct a typical piece is |
| `regularity` (PoC name `repetition`) | strongest off-centre peak of the normalised autocorrelation of the high-passed L at 256 px (central ±5 % masked). High for geometric/graphic layouts (grids of tiles, stripes, concentric shapes, gradients with few straight edges), low for organic, evenly busy pictures (flowers, birds, foliage). It does **not** find "the same motif repeated" as such |

Bucketed labels (PoC thresholds from the sample's quartiles; production buckets should be catalogue terciles computed
server-side from the numbers, §10): busy fine detail (`edgeDensity` ≥ 0.21), calm/sparse (≤ 0.11), large flat colour
areas (`largestFlatRegion` ≥ 0.10 or `flatShare` ≥ 0.30), one colour mass dominates (`largestColorRegion` ≥ 0.40),
smooth gradients (`smoothGradientShare` ≥ 0.15), text in the picture (`textChars` ≥ 20), dark (`lightnessMean` < 0.45
or `darkShare` ≥ 0.35), low contrast (`lightnessStd` < 0.13), muted/near monochrome (`colorfulness` < 35), vivid
(≥ 95), geometric/regular layout (`regularity` ≥ 0.20), many look-alike pieces (`pieceLookalikeShare` ≥ 0.35).

## 6. Correlation with difficulty

`difficulty_score` = median over solvers of time / the solver's own baseline for that piece count
(`docs/features/puzzle-intelligence/README.md`), so it is already piece-count neutral. 160 rated puzzles (medium/high
confidence), features on the final crops. Spearman ρ with a 2000× bootstrap 95 % CI, Benjamini-Hochberg q over 41
features. Negative = easier.

| Feature | ρ | 95 % CI | q |
|---|---|---|---|
| `regularity` | −0.40 | [−0.53, −0.25] | < 0.001 |
| `textChars` | −0.33 | [−0.48, −0.18] | < 0.001 |
| family orange share | −0.27 | [−0.42, −0.12] | 0.005 |
| `textShare` | −0.27 | [−0.42, −0.11] | 0.005 |
| `edgeDensity` | +0.24 | [+0.08, +0.40] | 0.017 |
| `flatShare` | −0.22 | [−0.36, −0.06] | 0.043 |
| `colorfulness` | −0.21 | [−0.36, −0.04] | 0.053 |
| `pieceDistinctiveness` | −0.20 | [−0.35, −0.04] | 0.054 |
| `largestColorRegion` | +0.20 | [+0.04, +0.35] | 0.058 |
| `chromaMean` | −0.18 | [−0.34, −0.02] | 0.098 |
| `pieceFlatShare` | −0.16 | [−0.32, −0.00] | 0.13 |
| `colorRegions80` | −0.15 | [−0.29, +0.01] | 0.18 |
| `colorEntropyBits` | −0.13 | [−0.28, +0.02] | 0.26 |
| `darkShare` | +0.11 | [−0.06, +0.26] | 0.34 |
| `smoothGradientShare` | −0.10 | [−0.25, +0.05] | 0.36 |
| `pieceLookalikeShare` | −0.02 | [−0.19, +0.14] | 0.80 |
| `largestUniformRegion` | +0.01 | [−0.15, +0.15] | 0.95 |

The same as labels (median difficulty with vs without the label, Mann-Whitney p):

| Label | n | With | Without | Δ | p |
|---|---|---|---|---|---|
| text in the picture | 25 | 0.84 | 1.19 | −29 % | < 0.001 |
| geometric / regular layout | 21 | 0.83 | 1.17 | −28 % | 0.027 |
| large flat colour areas | 31 | 0.90 | 1.17 | −23 % | 0.007 |
| busy fine detail | 31 | 1.33 | 1.09 | +22 % | < 0.001 |
| muted / near monochrome | 9 | 1.28 | 1.10 | +16 % | 0.033 |
| one colour mass dominates | 15 | 1.28 | 1.11 | +15 % | 0.014 |
| many look-alike pieces | 22 | 1.26 | 1.10 | +14 % | 0.11 |
| vivid | 56 | 1.03 | 1.18 | −13 % | 0.029 |
| smooth gradients | 21 | 0.98 | 1.13 | −13 % | 0.17 |
| dark | 10 | 1.21 | 1.10 | +10 % | 0.078 |

Reading:

- **"Large uniform areas are hard" splits in two.** Flat colour *fields bounded by edges* (graphic design, cartoon
  shapes) make puzzles easier - every piece's colour tells where it belongs. *One colour mass covering much of the
  picture* (sky, sea, foliage, a single red apple - "Flat Apple", the hardest puzzle in the sample at 3.03) makes them
  harder. `largestColorRegion` captures the second, `flatShare` the first; both are needed.
- Text, structure and colour variety help; dense organic detail, muted palettes and a dominating colour mass hurt.
  Gradients and darkness: not clear at this sample size.
- **Caveats**: n = 160; tiers 1 and 6 oversampled (good for ranking, medians are not population medians); brand house
  styles confound (text-rich graphic brands are easy *and* popular with speed puzzlers - the orange signal is likely
  that); `difficulty_score` describes the catalogue, not a person. 41 features tested: trust the ones with q < 0.06.
  Rerun on all ~6.3k rated puzzles after the backfill before the Insights page claims anything.

## 7. Chosen model stack + licences

| Component | Role | Licence | Measured on M3 Max |
|---|---|---|---|
| `google/siglip2-so400m-patch14-384` (transformers, fp16, MPS) | kind gate (flat vs photo/render) | Apache 2.0 | 141 ms/img, peak RSS 7.3 GB |
| `mlx-community/Qwen3-VL-4B-Instruct-4bit` (mlx-vlm 0.7.8, MLX 0.32) | artwork box + corners + kind for non-flat images | Apache 2.0 (base `Qwen/Qwen3-VL-4B-Instruct`) | 2.7 s/img at 672 px (≈ 600 prompt tokens at ~400 tok/s, ~80 output tokens at ~60 tok/s), peak 4.3 GB |
| OpenCV 5 (headless) | trim, quad snap, perspective warp, edges | Apache 2.0 | 5-11 ms |
| scikit-image / scikit-learn / numpy / scipy | SLIC, RAG, k-means, nearest neighbours | BSD | 0.42 s/img/core for colours + traits |
| Apple Vision (pyobjc) | text recognition | macOS framework, on-device | included above |
| PyTorch, transformers, mlx, mlx-vlm | runtimes | BSD / Apache 2.0 / MIT / MIT | - |

Checked and rejected: Qwen2.5-VL-3B (Qwen research licence - **forbidden**), FLUX.2 klein 9B (non-commercial -
forbidden); Qwen2.5-VL-7B is Apache 2.0 but superseded by Qwen3-VL (not tested); OWLv2 / Grounding DINO (Apache 2.0)
and BiRefNet (MIT) allowed but not good at this task; Florence-2 (MIT) not tested; Apple's DFN5B CLIP has a sample-code
licence - avoid.

### Throughput, backfill, daily volume

- Catalogue: 41,282 puzzles, 41,266 with an image. New puzzles: 4,640 in the last 90 days of the copy (≈ 52/day
  average, ≈ 70/day in full recent weeks: 400-550 a week). Image replacements via change requests: ~150 proposed in 90
  days (≈ 2/day).
- VLM share: gate skips ~53 % of rated and ~19 % of unrated images → ≈ 31k VLM calls for the catalogue.
- **Backfill**: gate 41.3k × 0.14 s ≈ 1.6 h + VLM 31k × 2.7 s ≈ 23.5 h + model loads → **≈ 25-27 h GPU**. Colours +
  traits ≈ 4.8 h CPU-core, 6 workers ≈ 50 min wall, overlapped. Download ≈ 45 GB total at ≤ 4 req/s (≥ 2.9 h,
  overlapped; delete each original after analysis). Three nights of 9 h, or one weekend.
- **Daily**: ≈ 75 images → gate 10 s + VLM ~2.5 min + loads ~30 s + features 30 s ≈ **4 min**.

## 8. Pipeline design

A single Python CLI `msp-vision` (lab code turned into a package; lives outside the app, e.g. `tools/image-analysis/`
or its own repo), run by launchd on Jan's Mac.

### 8.1 Stages

1. **sync** - `GET /internal-api/puzzle-images?analyzer=…&version=…&status=missing|stale&limit=500&after=…` for each
   analyzer, merged per puzzle into a local SQLite state (`~/Library/Application Support/msp-vision/state.db`).
2. **download** - fetch `imageUrl` (≤ 4 req/s, `User-Agent: msp-vision/<version>`), EXIF-transpose, flatten alpha on
   white, keep a ≤ 1600 px copy keyed by fingerprint in a temp cache; originals are never stored.
3. **gate** (GPU, SigLIP2) - kind scores for every image not yet gated for this artwork version. Unload.
4. **vlm** (GPU, Qwen3-VL-4B) - only images the gate did not call flat (margin ≤ 0.01). Unload.
5. **artwork** (CPU) - trim or VLM box + quad snap → normalised box + optional corners.
6. **features** (CPU, multiprocessing) - crop, `colors`, `traits`.
7. **upload** - `POST /internal-api/puzzle-image-analyses` in batches of ≤ 200 (≈ 3 KB per puzzle and analyzer).

Stages are resumable: each writes its result into SQLite before the next starts; a crash loses at most the image in
flight. GPU stages run one model per process, the process ends after the stage (memory returned to the system).

### 8.2 Versioning per analyzer

- `artwork-<major>.<minor>`: gate model + margin, VLM model + prompt hash + image side, trim/snap parameters.
- `colors-<v>@artwork-<v>` and `traits-<v>@artwork-<v>`: a composite string - a new crop makes colours and traits
  stale without the API knowing about dependencies (§9.4).
- Bump on any change of model, prompt, threshold or formula (`FEATURES_VERSION` in code). VLM at temperature 0,
  k-means seeded: same input + version = same output (re-runs are byte-stable apart from MLX float noise in rare ties).

### 8.3 Idempotency

Key = (puzzle id, `imageFingerprint`, `analyzerVersion`). The local state skips anything already uploaded with the
same key; the API stores one row per puzzle and analyzer and refuses (409) a write whose fingerprint is no longer the
puzzle's image - the pipeline then drops it and the next sync brings the new image.

### 8.4 Batching + retries

Downloads concurrent (4 in flight, rate-limited), GPU stages batch-of-one (MLX generation is sequential anyway),
features in a pool of 6. HTTP: retry network errors / 5xx / 429 with exponential backoff (1, 2, 4 … 300 s, 8 tries);
409 = drop; other 4xx = stop the run and log (a contract bug). Images failing to decode or failing the VLM JSON parse 3
times are marked `failed` locally with the error and retried after a version bump only.

### 8.5 Schedule (launchd)

`~/Library/LaunchAgents/com.myspeedpuzzling.vision.plist`: `StartCalendarInterval` 03:30 daily, runs
`caffeinate -i msp-vision run --max-hours 8`, logs to `~/Library/Logs/msp-vision.log`, exits early when on battery
(`pmset -g batt`). The backfill is the same command: it simply has more to do for a few nights. The internal API token
comes from the macOS Keychain (`security find-generic-password -s msp-internal-api -w`), never from the plist.

### 8.6 Coexistence with ComfyUI and other GPU jobs

- Before each GPU stage: take the machine-wide lease (`mkdir /tmp/msp-model-lock`, retry every 30 s, never remove
  someone else's - the convention the lab agents used; the badge lab's `gpu_lock.py` is the richer version), require
  `memory_pressure` ≥ 40 % free, and check ComfyUI (`GET http://127.0.0.1:8000/system_stats`, or a `main.py` process
  with large RSS): if ComfyUI holds models, wait or skip tonight's GPU stages (CPU stages still run).
- Peaks: gate 7.3 GB, VLM 4.3 GB (MLX), features < 1 GB per worker - far under the 24 GB ceiling; never both models
  at once. GPU contention made the 8B 3× slower in the lab, so the lease matters for throughput too.
- A watchdog thread aborts the stage (and releases the lease) when free memory drops under 25 %.

## 9. Payloads for the internal API

All three are per puzzle, `result` objects well under 64 KB (median: artwork ~0.3 KB, colors ~1.1 KB, traits ~0.6 KB).
Coordinates are normalised to the **EXIF-oriented** (displayed) original: x right, y down, 0..1.

### 9.1 `artwork` - `analyzerVersion: "artwork-1.0"`

```json
{
  "kind": "box_front",
  "box": {"x": 0.12, "y": 0.10, "w": 0.75, "h": 0.61},
  "corners": [[0.13, 0.10], [0.87, 0.11], [0.86, 0.71], [0.12, 0.70]],
  "method": "vlm+quad",
  "confidence": 0.86,
  "kindScores": {"flat": 0.04, "box": 0.13, "assembled": 0.08, "other": 0.02},
  "source": {"width": 1500, "height": 1125}
}
```

`kind`: `flat | box_front | box_angled | assembled | other`. `corners` (top-left, top-right, bottom-right,
bottom-left) only when the quad is skewed (> 2 % off axis), else `null`. `method`: `trim | vlm | vlm+quad`.
`confidence`: gate margin for `trim`, IoU of VLM box and quad for `vlm+quad`, 0.5 for plain `vlm`. `source`: pixel
size analysed (lets the server sanity-check against `image_ratio`).

### 9.2 `colors` - `analyzerVersion: "colors-1.0@artwork-1.0"`

```json
{
  "palette": [{"hex": "#1f3a5c", "share": 0.31, "family": "blue", "oklab": [0.33, -0.02, -0.07]}],
  "families": {"blue": 0.42, "white": 0.18, "orange": 0.11},
  "dominantShare": 0.31, "paletteEffectiveColors": 6.8, "colorEntropyBits": 5.4, "colorfulness": 69.0,
  "lightnessMean": 0.62, "lightnessStd": 0.19, "darkShare": 0.10, "lightShare": 0.21,
  "chromaMean": 0.07, "mutedShare": 0.33
}
```

(`palette` holds 8 entries, sorted by share.)

### 9.3 `traits` - `analyzerVersion: "traits-1.0@artwork-1.0"`

```json
{
  "edgeDensity": 0.152, "detail": 3.5, "gradientMean": 0.37,
  "flatShare": 0.072, "uniformShare": 0.03, "largestUniformRegion": 0.02, "uniformRegions": 1,
  "smoothGradientShare": 0.0, "gradientRegions": 0, "largestFlatRegion": 0.015,
  "colorRegions": 133, "colorRegions80": 60, "largestColorRegion": 0.125,
  "textShare": 0.0, "textChars": 0,
  "pieceGrid": [27, 19], "pieceFlatShare": 0.028, "pieceLookalikeShare": 0.18, "pieceDistinctiveness": 0.06,
  "regularity": 0.082
}
```

No labels in the payload: the server buckets the numbers (§10), so changing a threshold never needs a re-analysis.

### 9.4 Proposed contract changes (for the API agent)

1. **Composite versions for dependent analyzers** - `colors`/`traits` versions embed the artwork version
   (`colors-1.0@artwork-1.0`). Needs nothing from the API if `status=stale` means "stored version string ≠ requested
   version" (string equality, not semver ordering) - please make that the documented rule.
2. **Hidden images**: `GET /internal-api/puzzle-images` should leave out puzzles whose image is hidden
   (`Puzzle::isImageHiddenAt(now)`) - the analysis of a secret competition puzzle must not exist before its reveal, and
   the reveal renames the object anyway (new fingerprint).
3. **Fingerprint** = the image key (or a hash of it): unique per upload by construction (§2). No content hash needed.
4. Optional: a `processedImageUrl` (e.g. imgproxy `preset:puzzle_large`) to cut the ~45 GB backfill download; the
   features are computed at 768-1600 px anyway. The original stays the default.
5. Optional later analyzer `tags` (owned by the taxonomy work, see `image-taxonomy.md`) - same envelope.

## 10. How Insights uses each output

- **traits** (main input): for each player, take the residual of each solo time against its stored prediction
  (`puzzle_solving_time` prediction history, `docs/features/puzzle-intelligence/prediction-history.md`) and group by
  trait bucket (catalogue terciles of each number, thresholds snapshotted per traits version). "You are 9 % slower on
  puzzles where one colour mass dominates" = median residual in the top tercile vs the player's overall median, shown
  only with ≥ 8 solves in the bucket and a bootstrap CI that excludes 0. Candidate traits ranked by §6: text, regular
  layout, flat colour fields, busy detail, dominant colour mass, muted palette, colourfulness, piece distinctiveness.
- **colors**: "the colours you solve most" (families weighted by solves), palette swatches on the puzzle page, later a
  colour filter in search; colour families as extra Insights buckets (blue-heavy, grey/white-heavy).
- **artwork**: an imgproxy crop for cleaner thumbnails/share images without storing a second file:
  `crop:{w}:{h}:fp:{x+w/2}:{y+h/2}` (imgproxy treats crop sizes < 1 as fractions and focus-point gravity takes relative
  coordinates - verify on our imgproxy version). Perspective cannot be done by imgproxy, so skewed photos get the
  axis-aligned box. `kind ≠ flat` also gives moderators a list of puzzles that would benefit from a flat image.
- Catalogue level: members could see why a puzzle rates hard ("busy fine detail, one colour mass dominates").

## 11. Open questions

1. Rerun the correlation on all ~6.3k rated puzzles after the backfill (and with brand as a covariate) before any
   wording goes live.
2. Kind prompt: the 8B misread flat art as assembled; if the 4B's 6/38 flat→assembled errors matter (only for `kind`,
   not for the box), tune the prompt or rely on the gate's kind.
3. Rotated assembled photos (90°) are cropped fine but not rotated; features are rotation-insensitive except
   `regularity`/orientation histograms - acceptable?
4. Text OCR depends on Apple Vision (the pipeline is Mac-only by design); an open OCR model would be needed if it ever
   moves to Linux.
5. Should `kind ≠ flat` images feed a moderator queue ("needs a flat picture")?
6. Puzzle-level traits explain catalogue difficulty; per-player effects need enough solves per bucket - how many
   players qualify is a question for the Insights page design.

## 12. Lab artefacts (local, git-excluded)

- `.claude/worktrees/insights-vision-lab/` - `sample/` (300 images' metadata `sample.tsv`, `ground_truth.tsv`), `img/`
  (326 MB originals), `scripts/` (detectors, `features.py`, `crop.py`, `correlate.py`, `report.py`), `out/` (all raw
  results), `report.html` (contact sheet: original with detected/true boxes | crop | palette | traits | labels).
- venv `.claude/worktrees/insights-vision-lab/.venv` (Python 3.12, 1.6 GB).
- Models in `~/.cache/huggingface/hub/` (≈ 14.6 GB): Qwen3-VL-8B-Instruct-4bit 5.8 GB, siglip2-so400m-patch14-384
  4.5 GB, Qwen3-VL-4B-Instruct-4bit 3.1 GB, grounding-dino-tiny 0.7 GB, owlv2-base-patch16-ensemble 0.6 GB, BiRefNet
  0.4 GB. Only the 4B and SigLIP2 are needed for the pipeline.
