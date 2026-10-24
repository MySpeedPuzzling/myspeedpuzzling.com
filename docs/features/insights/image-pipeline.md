# Image pipeline - runbook

The Mac pipeline `tools/puzzle-vision/` analyses every puzzle box image with self-hosted open models and pushes the
results through the internal API. Decisions: [`README.md`](./README.md) §3 "Image intelligence"; plan:
[`implementation-plan.md`](./implementation-plan.md) Phase 2; payload schemas: [`image-taxonomy.md`](./image-taxonomy.md)
§11 + §18; measured resources and the machine split: `image-taxonomy.md` §19; the API:
[`../internal-api.md`](../internal-api.md) § "Puzzle image analysis".

- **Production is the master copy.** Every result goes through `/internal-api/puzzle-image-*` into
  `puzzle_image_analysis`. The Macs keep only a temporary work state (SQLite + an image cache) - deleting it loses
  nothing but time.
- **Claims** (leases) guarantee two machines never take the same image for the same analyzer. `requires` hands out
  only pictures whose *current* image already has the results a stage needs.
- **Nothing is shown** from these results on day one; they feed Insights and the estimates. A cleaner catalogue
  picture is only ever a **change request** (crop proposals, below) - the pipeline never changes a puzzle itself.

Claude Code skills wrap everything here: `/image-pipeline-setup`, `/image-pipeline-run`, `/image-pipeline-status`,
`/image-pipeline-derive`, `/image-pipeline-add-analyzer`, `/image-crop-review`.

## 1. The two machines and why

| Machine | Profile | Runs | Expected (41k images) |
|---|---|---|---|
| MacBook Pro **M3 Max** 36 GB | `m3-max` | phase `artwork` (SigLIP 2 gate, then Qwen3-VL-4B on the non-flat pictures + OpenCV quad snap), then phase `describe` (Qwen3.5-9B 4-bit) | artwork ~24 h, describe ~123 h (10.75 s/img idle; 20-50 % slower with other GPU work) |
| MacBook Air **M1** 16 GB, fanless, nonstop | `m1-air` | phase `A`: `ocr`, `barcode`, `embed_siglip2_full`; phase `B` (needs artwork): `embed_siglip2`, `embed_dinov2`, `segmentation`, `vision_labels` + `colors`/`traits` in CPU workers alongside; then helps with `describe` after the parity check | A + B ≈ 2.4 days |
| MacBook Pro **M3 Max** 36 GB **alone** | `m3-solo` | `artwork` → `embed` (`embed_siglip2_full`, `embed_siglip2`, `embed_dinov2`) → `apple` (`ocr`, `barcode`) → `features` (`segmentation`, `vision_labels` + `colors`/`traits` in CPU workers alongside) → `describe` | ≈ 7 days for 43k (below) |

### One Mac (`m3-solo`, since 2026-10-19)

The M1 Air is out for now, so the M3 runs every analyzer with `m3-solo` (same versions as the two-machine profiles -
the tests pin it; never run `m3-solo` and `m3-max`/`m1-air` with different versions). The phases follow the launch:
the picture-based difficulty estimate (`difficulty_prior`, §7) reads only `artwork`, the two crop embeddings and the
whole-image SigLIP 2, so those come first (`artwork` ~25 h, `embed` ~5 h - the launch path is ready after ~30 h), then
Apple Vision in its own phase (~3.3 h; `describe` needs `ocr`), the crop features (~5.3 h, colours/traits alongside
in 3 + 3 CPU workers) and `describe` last (~128 h). Estimates for ~43k images from the §19 M3 numbers on an idle
machine (DINOv2-L and barcode alone are not measured; everything after `artwork` reads the image cache `artwork`
filled). Apple Vision stays out of the GPU phases: next to a GPU-saturating model it took 14.8 s/img instead of 0.13
(§19), and the runner allows only CPU stages as a sidecar - the profile says what a measurement would have to show.
`run --profile m3-solo --phase embed` runs one phase; the nightly launchd window and `--daily` run every phase, then
`derive` and the crop proposals, like `m3-max`.

Total ≈ **5.5 days** wall clock with both machines (≈ 6.1 without the Air on describe). Why this way round
(§19): `describe` is ~80 % of all compute and must be one model for the whole catalogue - on the Air it is ~57 s/img,
so the M3 owns it. `artwork` gates both `describe` and the Air's phase B, so the M3 does it first while the Air runs its
dependency-free phase A. **Apple Vision never shares a machine with a GPU-saturating model** (0.13 → 14.8 s/img when it
did), so all Apple Vision work is on the Air, and there in its own stage, never next to a GPU stage.

### Dependency table (enforced by the profiles; the API filters claims with `requires`)

| Analyzer | requires | Machine | Unit |
|---|---|---|---|
| `ocr`, `barcode` | - | M1 (phase A) | Apple Vision (Neural Engine) |
| `embed_siglip2_full` | - | M1 (phase A) | GPU |
| `artwork` | - | M3 | GPU |
| `colors`, `traits` | `artwork` | M1 (phase B, CPU sidecar) | CPU, single-threaded workers |
| `segmentation`, `embed_dinov2`, `embed_siglip2` | `artwork` | M1 (phase B) | GPU |
| `vision_labels` | `artwork` | M1 (phase B, last) | Apple Vision |
| `describe` | `artwork`, `ocr` | M3 (+ M1 after parity) | GPU |
| `box_text`, `zeroshot`, `neighbours`, `tags` (derived) | their raw inputs, no image | M3 (`derive`) | CPU (+ SigLIP text encoder once) |

`vision_labels` looks at the artwork crop, so it needs `artwork` and runs in phase B (§11 / plan §1.2), not in phase A
as the first sketch of §2.3 had it. `ocr` runs on the full image before `artwork` exists; its lines' `inArtwork` is then
null, and `describe` / `tags` compute "inside the crop" themselves from the line boxes and the `artwork` bbox.

## 2. Setup on a new Mac

Prerequisites: macOS on Apple Silicon, Homebrew, `brew install uv git`, ≥ 60 GB free disk (image cache ~40 GB + models).

```sh
git clone https://github.com/MySpeedPuzzling/myspeedpuzzling.com.git ~/msp   # or `git pull` in an existing checkout
cd ~/msp/tools/puzzle-vision
uv sync --extra ml          # Python 3.12 + the pinned model stack (uv.lock): mlx 0.32.4, mlx-vlm 0.7.8, torch 2.14.1, ...
```

**Token** - the internal API token goes into the Keychain; Jan pastes it at the prompt himself, it is never printed,
logged or written to a file (the pipeline reads it at call time for every request):

```sh
security add-generic-password -U -s msp-internal-api -a "$USER" -w     # prompts for the token
```

`MSP_INTERNAL_API_TOKEN=… uv run …` overrides it for one run. `--base-url http://localhost:8080` points at a local dev
API (testing only; production is the default and the only real target) - **any other base URL takes the token from
`MSP_INTERNAL_API_TOKEN` only**: the production token in the Keychain is never sent anywhere but production.

**Models** - download only what the profile runs (Hugging Face cache `~/.cache/huggingface/hub/`; all Apache-2.0 / MIT):

| Model | Size | Used by | m3-max | m1-air | m3-solo |
|---|---|---|---|---|---|
| `google/siglip2-so400m-patch14-384` | 4.5 GB | artwork gate, `embed_siglip2(_full)`, zero-shot text side, crop proposals | yes | yes | yes |
| `mlx-community/Qwen3-VL-4B-Instruct-4bit` | 3.1 GB | `artwork` (VLM box + corners) | yes | - | yes |
| `mlx-community/Qwen3.5-9B-MLX-4bit` | 6.0 GB | `describe` | yes | only to join describe | yes |
| `facebook/dinov2-large` | 1.2 GB (2.4 GB with both weight formats) | `embed_dinov2` | - | yes | yes |
| `tue-mps/coco_panoptic_eomt_large_640` | 1.3 GB | `segmentation` | - | yes | yes |

```sh
uv run --extra ml hf download google/siglip2-so400m-patch14-384
uv run --extra ml hf download mlx-community/Qwen3-VL-4B-Instruct-4bit        # M3
uv run --extra ml hf download mlx-community/Qwen3.5-9B-MLX-4bit              # M3 (M1 only before joining describe)
uv run --extra ml hf download facebook/dinov2-large                         # M1
uv run --extra ml hf download tue-mps/coco_panoptic_eomt_large_640            # M1
```

Apple Vision (OCR, barcodes, labels) is part of macOS - nothing to download.

**Check** - everything above in one go (models are looked up in the cache, never loaded):

```sh
uv run --extra ml puzzle-vision setup-check --profile m3-max     # or m1-air, m3-solo; PASS/FAIL per line
```

## 3. Running

```sh
uv run --extra ml puzzle-vision run --profile m3-max                 # backfill: every phase until nothing is left
uv run --extra ml puzzle-vision run --profile m3-max --hours 6       # stop cleanly after 6 h (Jan needs the machine)
uv run --extra ml puzzle-vision run --profile m1-air --phase A       # one phase
uv run --extra ml puzzle-vision run --profile m1-air --follow        # never ends (launchd KeepAlive)
uv run --extra ml puzzle-vision run --profile m3-max --daily         # one pass over new/stale images, then derive + crops
```

- **One stage = one process = one model.** The runner starts each stage as its own process (memory goes back to the
  system between stages); the CPU sidecar stages run alongside in their own single-threaded workers
  (`OMP_NUM_THREADS=1`, `workers` per profile). `caffeinate -i` keeps the Mac awake while it runs.
- Each stage first checks read-only whether there is anything it could do (stale, `requires` met, not given up here,
  not held by another worker). Then it claims, and only when the claim brings a usable picture does it take the
  **model lock** (below) and load its model - a model is never loaded for nothing. It loops: claim → the next claim and
  its downloads go out when ~80 % of the batch is done → analyse → store in batches of ≤ 200 (slow stages, > 5 s an
  image like `describe`, store every result at once) → per-image failure: recorded + claim released → at the end (or
  on SIGTERM / Ctrl-C / the memory watchdog / `--hours`) store what is done and release every own claim. A store that
  fails keeps its results for the next try. A released (failed / skipped) or expired image stays stale, so the claim
  hands it out first again (lowest ids); a claim of only images the run already handled is not the end - they are the
  worker's own claims now, which come last, so the next claim reaches past them. It stops only when a claim brings back
  nothing but what the claim before it brought (before 2026-10-19 the first such claim ended the stage: the M1's
  `barcode` stopped at 14,800 with ~28k to do).
- **Apple Vision watchdog:** every `ocr` / `barcode` / `vision_labels` call runs on its own thread; past 60 s its
  requests are cancelled and the image fails (`VisionTimeout`), the stage goes on. A call still stuck 10 s after the
  cancel is left behind; after two of them the stage ends ("stage broken" - stored, claims released) and the next
  pass starts it in a fresh process (an OCR call once hung on the M1 for good). Leases are 3× the batch's measured time + the store interval + 2 min
  (the M3 runs up to 2-3× slower while Jan works on it).
- `artwork` runs as two processes: the **gate** walks the stale list and scores every picture with SigLIP 2 (stored
  locally only), then the **VLM pass** claims: gate-flat pictures get the border trim (ms), the rest Qwen3-VL-4B + the
  quad snap. A picture the gate has not seen yet keeps its claim until the pass ends and is gated next round.
- Backfill mode keeps going while the other Mac still produces what a stage requires (the M3's `describe` waits on the
  M1's OCR): after a pass without claimable work it sleeps 10 min (`--idle-minutes`) and claims again. It ends when
  nothing is claimable for it (stale, `requires` met, not given up, not held by another worker) **and** no required
  upstream analyzer is being worked on (no claim on its stale pictures) - so it never waits forever for pictures
  nobody can analyse.
- Exit codes: 0 = done / the daily pass completed; 143 = stopped (SIGTERM / Ctrl-C); 124 = `--hours` ran out. After an
  early end, `--daily` runs no derive and no crop proposals. The run writes its own pid (the python process, not
  `uv`) to `~/Library/Application Support/puzzle-vision/run-<profile>.pid` - `kill $(cat …)` stops it cleanly.
- Progress lines go to the terminal and `~/Library/Logs/puzzle-vision/<profile>.log`.

### Daily mode and launchd

After the backfill each machine keeps the catalogue current (~60 new images a day, p90 100):

```sh
uv run puzzle-vision launchd-plist --profile m1-air --install     # writes ~/Library/LaunchAgents/com.myspeedpuzzling.puzzle-vision.m1-air.plist
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.myspeedpuzzling.puzzle-vision.m1-air.plist
launchctl bootout   gui/$(id -u) ~/Library/LaunchAgents/com.myspeedpuzzling.puzzle-vision.m1-air.plist   # stop / unload
```

- **M1:** `KeepAlive` + `run --follow` - claims whatever appears, nonstop.
- **M3:** a nightly window (01:30, `run --daily --hours 6`): `artwork` + `describe` on new / stale images (~15-20 min),
  then `derive` and up to 50 crop proposals.
- Logs: `~/Library/Logs/puzzle-vision/<profile>.out.log` / `.err.log` (launchd) and `<profile>.log` (the pipeline).
- The plist holds no token - the job reads the Keychain. Template: `tools/puzzle-vision/launchd/`.

### Stop and resume

Ctrl-C, `kill <pid>` or `launchctl bootout` → every stage stores what it finished and releases its claims; nothing
else to do. A crash leaves claims that simply expire (lease = 2 batches + margin). Resuming is running the same
command again: production is the truth, the local work state is only a cache.

## 4. Versions and re-runs

- `analyzerVersion` = `<schema major>.<minor>.<patch>+<model short id>` (image-taxonomy.md §11), e.g.
  `artwork 1.0.0+siglip2-q3vl4b`, `describe 1.0.0+qwen35-9b-4bit`, `embed_siglip2 1.0.0+so400m-14-384`. Derived ones
  carry `@tax<TAXONOMY_VERSION>` (`tags 1.0.0+fusion@tax1.0.0`). The versions live in `profiles/*.toml`; a test pins
  that both machines name the same version for every analyzer.
- **One version per analyzer at a time.** The API compares versions for equality, never order: a machine on an older
  version would overwrite newer results. Bump on both profiles in one commit and `git pull` on both Macs.
- A bump makes every row of that analyzer stale → the next run re-does them (claims hand out "another version" as
  to-do). Major: meaning or schema changed, re-run all. Minor: new optional fields. Patch: a fix that changes values.
  Crop-based analyzers (`colors`, `traits`, `segmentation`, `embed_dinov2`, `embed_siglip2`, `vision_labels`,
  `describe`) do not see an `artwork` version change by themselves - when a new `artwork` version moves crops, bump
  them too (the `/image-pipeline-add-analyzer` checklist says so).
- A **new picture** always gets a new fingerprint (`PuzzleImageNamer`), so it is to-do for every analyzer at once.
- A picture that failed 3 times for a version is skipped until the next version bump (work state `failures`).

## 5. Disk and memory rules

- **Model lock** `/tmp/msp-model-lock` (`mkdir` lease shared with ComfyUI, the labs and every local model user):
  taken only with **≥ 40 % free memory** (`memory_pressure`); never removed when it is someone else's (a lock without
  our owner file is never touched; our own lock of a crashed process is cleared). GPU and Apple Vision stages take it;
  the CPU sidecar does not.
- **Memory watchdog:** below 25 % free a stage stops, stores what it has and releases its claims.
- Never two GPU stages at once on one machine; never Apple Vision next to a GPU stage (§19). Nothing else should load
  a large model while `describe` runs - the lock makes others wait.
- **Image cache** `~/Library/Caches/puzzle-vision/images/` - each original once per fingerprint, EXIF-oriented, alpha
  flattened, long side ≤ 1600 px, JPEG q95 4:4:4 (~1 MB, ~40 GB for the catalogue; deterministic for the pinned
  Pillow, so both Macs feed the models the same pixels), written to a temporary name and moved in place. Downloads ≤ 4 requests/s with retries. After the backfill the
  cache can be deleted (only new pictures are downloaded afterwards).
- Work state `~/Library/Application Support/puzzle-vision/workstate.sqlite`; logs `~/Library/Logs/puzzle-vision/`.
  Nothing of it is ever in the repository (`.gitignore`).

## 6. Coverage and progress

```sh
uv run puzzle-vision status --profile m3-max            # this profile's analyzers
uv run puzzle-vision status --profile m3-max --all      # every analyzer of both machines
uv run puzzle-vision status --profile m1-air --json
```

One walk of the work list (~42 requests): per analyzer and version **done / stale / missing / waiting** (missing but
its `requires` not there yet) **/ claimed** (and by which worker), results received in the last hour / 6 h / 24 h
from every machine, an ETA from the last 6 h, and this machine's stored counts, failures and cache size.
`uv run --extra ml puzzle-vision verify --profile <p> --end-to-end --limit 10` runs every stage of the profile for 10 images
against production (real backfill work) and reports what each stored.

## 7. Derived analyzers

```sh
uv run --extra ml puzzle-vision derive --profile m3-max                      # box_text, zeroshot, neighbours, tags, difficulty_prior
uv run --extra ml puzzle-vision derive --profile m3-max --only tags --dry-run
uv run puzzle-vision derive --profile m3-max --only difficulty_prior          # no model involved - the light set is enough
```

Reads the stored raw results (bulk endpoint, `onlyCurrent=1`), recomputes, and pushes only rows that changed (another
version, picture or content). `zeroshot` loads the SigLIP 2 text encoder once under the model lock (prompt features
cached per prompt bank). A new taxonomy or prompt bank = bump `zeroshot` / `tags` and run `derive` - no image is
touched. `difficulty_prior` runs last (below); the nightly `--daily` run of the M3 runs all of them.

### `difficulty_prior` - picture-based difficulty estimates (plan §5.3)

Not an image analyzer: it trains ridge regressions (scikit-learn, CPU, ~4 minutes for the catalogue - about 17 fits of
~6 s at 7.6k × 2.2k per model) from the picture embeddings + the piece count to the puzzles' measured as-of log
difficulty and pushes one **estimate** per puzzle plus a **model record** per model through the endpoints below. No
fine-tuning, no language model, nothing downloaded. Code and every decision in detail:
`tools/puzzle-vision/puzzle_vision/derive/difficulty_prior.py`. A crash in it is logged (`log.exception`, `error` in
the summary) and never ends the nightly run - the crop proposals still follow.

- **Version** `1.1.0+ridge-siglip2-dinov2` = `model` `ridge-siglip2-dinov2`, `modelVersion` `1.1.0` (constants in the
  module, not in the profiles - it is not an `ImageAnalyzer`). Bump `MODEL_VERSION` whenever the numbers change
  (features, folds, thresholds); fixed seeds make two runs on the same data identical.
- **Inputs**: joined on (puzzleId, imageFingerprint) - the label's and every embedding row's fingerprint must be the
  puzzle's current picture (the work list). Of each analyzer only its **newest `analyzerVersion`** is read (a version
  bump re-runs over days; vectors of two models are never mixed), within it the most common dimension. Everything
  left out is counted under `dropped`; the versions used are in `evaluation.inputVersions`.
- **Two models, each with its own record, guard and estimates.** `ridge-siglip2-dinov2` (the model of record): SigLIP 2
  crop + DINOv2 crop + ln pieces, ln pieces², size group one-hot (≤300 / 500 / 1000 / 1500+ / odd), standardised - it
  estimates every puzzle with both crop embeddings. **Since 1.1.0 also the crop's extras**: the Apple Vision feature
  print (`vision_labels`), the DINOv2 patch mean (`embed_dinov2_patches`) and 45 numbers of `traits` / `colors` /
  `segmentation` / `vision_labels` (`EXTRA_NAMES`), that block repeated 3× (a smaller ridge penalty); a puzzle missing
  an extra gets its corpus mean (`extrasImputed` in the summary). Measured offline on the 2026-10-24 data (10,406
  training puzzles): out of fold Spearman 0.704 → 0.762, MAE 0.092 → 0.082, tier exact 0.535 → 0.575 ("Average"
  0.454); newer from older 0.644 → 0.704, tier exact 0.508 → 0.550; 500 box newer exact 0.39 → 0.57. `ridge-siglip2-full` (fallback): the whole-image SigLIP 2 vector +
  pieces, trained on the whole-image vectors of the labelled puzzles - it estimates the puzzles without crop
  embeddings (no `artwork`, or the M1 has not embedded the crop yet) and, **while the model of record has fewer than
  200 training puzzles (phase A of the backfill), every puzzle it can**. A puzzle without any SigLIP 2 vector gets no
  estimate. `?model=ridge-siglip2-full` reads its record.
- **Training**: labelled puzzles with ≥ 3 indices, weighted n/(n+10); alpha by GCV inside every fit. A model with
  **fewer than 200 training puzzles records and pushes nothing** and says so (`skipped`) - normal early in the backfill.
- **Folds**: 5-fold GroupKFold (shuffled, fixed seed) over **near-duplicate components** (SigLIP 2 cosine ≥ 0.95 between
  training puzzles - the same artwork as another edition, size or brand is never on both sides of a fold).
  `evaluation.folds` says how many pairs and components; when one component holds > 20 % of the rows the grouping is
  `off: …` (plain puzzles as groups).
- **Estimates**: training puzzles get **out-of-fold** estimates (`outOfFold: true`); labelled puzzles with 1-2 indices
  get the full model, which was not trained on them (`outOfFold: true` as well); unlabelled puzzles the full model
  (`outOfFold: false`). Intervals `logP10` / `logP90` = estimate + residual quantiles of out-of-fold residuals of
  puzzles with ≥ 10 indices per (size group × image kind), falling back to the size group and then to all of them
  below 50 residuals; **a training puzzle's interval comes from the other folds' residuals only** (never its own);
  always `logP10 ≤ logDifficulty ≤ logP90`; a non-finite number is never pushed (`estimates.nonFinite`).
- **Validation** (in the record's `evaluation`): `overall` + `groups` (out-of-fold on puzzles with ≥ 20 indices -
  `testMinIndices` drops to 10 / 5 / 3 only while fewer than 30 reach it), `holdouts.brands` (GroupKFold over brand ∪
  near-duplicate components), `holdouts.newerFromOlder` (oldest 70 % by `addedAt` → the rest, newer puzzles with a
  near-duplicate among the older left out - `testDroppedNearDuplicates`; intervals from a cross-fit inside the older
  set; **its Spearman is `holdoutSpearman`**, with `holdoutCount` / `holdoutTestMinIndices`; `holdoutSource` says
  `cross_fit` when fewer than 30 newer test puzzles exist), `intervals`, `folds`, `guard`. Every slice: `count`,
  `spearman`, `mae` (log difficulty), `r2`, `tierExact` / `tierWithinOne` (DifficultyTier::fromScore cut points)
  next to `averageTierExact` / `averageTierWithinOne` (always "Average"), `window50Hit` / `window80Hit` (interval
  coverage, never circular) and `window80Width` (median, log). `groups` lists every `sizeGroup` × `imageKind` (flat /
  box / assembled / other / unknown = no `artwork`) plus `all` marginals.
- **Push guard** (per model): it reads the model's current record and compares **only like with like** - the same
  `holdoutSource` and `holdoutTestMinIndices`, ≥ 100 holdout test puzzles tonight and in the previous record, and a
  training size within 25 % of the previous one. Otherwise the run is recorded with `guard.skipped` naming why (during
  the backfill most nights - a lucky early run on 31 test puzzles has an SD of ~0.08 and must not lock the model).
  When compared and the new holdout Spearman is **more than 0.05 below, nothing is recorded and nothing pushed for that
  model** (the regressed run must not become tomorrow's baseline; yesterday's estimates stay): an error `PUSH
  BLOCKED` in the log, `blocked` in the summary, exit code 3 from `derive`, and **`status` prints
  "difficulty_prior blocked since …"** (from `~/Library/Application Support/puzzle-vision/difficulty_prior-last-run.json`,
  written by every run that fitted a model, with the full evaluation). Look at what changed (a label definition, an
  embedding version, a burst of new puzzles of one kind); when the drop is understood and accepted, a human runs
  `uv run puzzle-vision derive --profile m3-max --only difficulty_prior --force-model` (the record then says
  `guard.forced: true`). Never put `--force-model` into a schedule.
- **Push order** per model: the record, then its estimates in chunks of 500. Outcomes `stale_fingerprint` (the picture
  changed meanwhile), `hidden` (became secret) and `unknown_puzzle` (merged / deleted) are skipped and counted;
  `invalid` is a bug (warning per item). `--dry-run` computes and validates but records and pushes nothing.

**Reading the model record**: `GET /internal-api/puzzle-difficulty-estimates/model?model=ridge-siglip2-dinov2` (or
`ridge-siglip2-full`) → `current` (the latest run): `holdoutSpearman`, `trainingSize`, `evaluation.overall`,
`evaluation.groups` (find the size group × kind the gates care about), `evaluation.holdouts.newerFromOlder`,
`evaluation.intervals`, `evaluation.folds`, `evaluation.guard`, `evaluation.alphaAtGridEnd` (true = widen the alpha
grid). The research expects ~0.70-0.75 Spearman and R² ≈ 0.55 with 2.5-5k labelled puzzles (`prediction-research.md`
Q3). These are the Mac's numbers; the app's **gate record** (`myspeedpuzzling:evaluate-image-estimates`, stream 5d)
decides what is shown.

**Measured only on the real data (first full run on the M3 after the backfill):** runtime and memory of the run
(expect ~4 min, < 1.5 GB), whether alpha lands inside the grid, how many near-duplicate pairs/components there are
(and whether the grouping switched off), Spearman / tiers / coverage per size group × image kind (the pilot had almost
only flat 500s), how many puzzles fall to the fallback model and how it does, how many rows are dropped for another
picture or an older version. Run `--dry-run` first, read `difficulty_prior-last-run.json`, then the real run; report
`evaluation.overall`, `holdouts`, `folds` and the groups with ≥ 50 puzzles of both models.

### The `difficulty_prior` server contract

The server side is built ([`../internal-api.md`](../internal-api.md) § "Puzzle difficulty labels and estimates"); the
client methods are in `puzzle_vision/api.py`, the fake server for tests in `tests/conftest.py`.

| Step | Client call | Endpoint | Notes |
|---|---|---|---|
| 1. Labels | `api.iter_difficulty_labels()` → `DifficultyLabel` | `GET /internal-api/puzzle-difficulty-labels?limit=..5000&after=` | as-of `logDifficulty` (ln median index from 5 indices, else the mean ln index - `labelSource`), `indexCount`, `piecesCount`, `manufacturerId`, `addedAt`, `imageFingerprint`; aggregates only, secret puzzles never listed |
| 2. Features | `api.iter_analyses("embed_siglip2" / "embed_siglip2_full" / "embed_dinov2")` | bulk read, `onlyCurrent=1` | join on (puzzleId, imageFingerprint): a label whose fingerprint differs from the embedding's (or is null) has no usable picture |
| 3. Guard | `api.current_difficulty_model(model)` | `GET /internal-api/puzzle-difficulty-estimates/model` | `None` = first run; block the push when the new holdout Spearman is > 0.05 below `current["holdoutSpearman"]` |
| 4. Record the run | `api.record_difficulty_model(DifficultyModelRecord(…))` | `POST …/puzzle-difficulty-estimates/model` | `holdoutSpearman` + `evaluation` (overall, groups per size group × image kind, holdouts) - append-only, latest = current |
| 5. Estimates | `api.store_difficulty_estimates([DifficultyEstimate(…)])` | `PUT /internal-api/puzzle-difficulty-estimates` (≤ 500 a call, chunked) | log values within ±5, `logP10 ≤ logDifficulty ≤ logP90`, `logP90 - logP10 ≤ 1.5`, `outOfFold` for cross-fitted labelled puzzles; outcomes `stored` / `stale_fingerprint` / `hidden` / `unknown_puzzle` (skip, nothing stored) / `invalid` (our bug); idempotent upsert of the current value |

**On the server**, two daily crons turn the pushed derived results into app data: `myspeedpuzzling:refresh-puzzle-tags`
writes `puzzle_tag` from `tags` (+ the moderators' corrections) - [`tags.md`](./tags.md) - and
`myspeedpuzzling:detect-box-text-mismatches` compares `box_text` with the catalogue for the moderators' list
([`../puzzle-approvals.md`](../puzzle-approvals.md#box-text-mismatches)). Both read only analyses of the puzzle's
current picture and skip secret puzzles.

## 8. Crop proposals and the review loop

```sh
uv run --extra ml puzzle-vision propose-crops --profile m3-max --limit 50 [--dry-run]
uv run puzzle-vision crop-review-plan --profile m3-max            # what would happen (dry run)
uv run puzzle-vision crop-review-plan --profile m3-max --apply    # approve the earned auto band
uv run puzzle-vision crop-review-approve --profile m3-max <id> …  # middle-band items Claude Code checked by eye
```

- **Proposals:** box photos / renders / assembled puzzles (artwork kind ≠ flat), most-solved first (the work list's
  `solvedCount`), then puzzle id. A picture scored below the threshold (or too small) is remembered per (puzzle,
  picture, crop model) in the work state and not downloaded and scored again every day. The perspective-corrected crop comes from the **full-resolution
  original**. Confidence = weighted geometric mean of **agreement signals**, never a raw model score: VLM box vs
  snapped quad IoU, the flat-art gate on the crop, edge straightness (a strong edge exactly where the crop ends, all
  four sides), a plausible puzzle aspect ratio, and how much of the photo the artwork fills (and enough pixels,
  ≥ 600 px). Below 0.6 nothing is proposed. Each proposal is a change request with the image, `source:
  image_pipeline`, `tags: ["artwork_crop"]`, `confidence`, `modelVersion`, `sourceImageFingerprint` (the JPEG as plain base64). Refusals are
  skipped, never retried: `409` with `code` `already_proposed` (any status - a rejected crop is remembered),
  `image_changed` (the picture changed - the next artwork run proposes the new one), `pending_proposal` (the puzzle has
  another pending request); `404` = the picture is hidden now; `422 errors.image` = the server's photo rule refused the
  image (a pipeline bug - counted as failed). Start small (50 a day).
- **Review** (`/image-crop-review`, daily): moderators decide in the before/after grid. Auto-approval is **earned**:
  nothing below ~300 human decisions; then the lowest confidence band whose human precision has a **Wilson lower
  bound ≥ 99 %** (≥ 50 decisions) is approved by Claude Code through the API with a `decisionNote`
  (`auto: confidence 0.987, band >= 0.980 (human precision Wilson LB 0.991, n 412), model …`). A deterministic **5 %
  audit** of would-be-auto items stays for humans; if their precision falls below **98 %** (≥ 20 audited), auto-approval
  **stops** and the plan says so (exit code 2). The middle band may be looked at by Claude (before/after URLs) and
  approved only when certain (`claude-review: …` note); anything unsure stays for moderators. Only people's decisions
  earn and check the threshold: **`decision.source == "admin_ui"`** (the moderators' web review). Everything decided
  through the internal API (`auto:`, `claude-review:` or a script) and `outdated` closures (`automatic`) never count.
  A crop counts as **right only when it was taken** (`decision.image == "proposed"`); approved while keeping the old
  picture or uploading another (`keep` / `upload`) counts like a rejection. Only decisions on the **current crop model
  version** (the newest proposal's `modelVersion`) count - a new model earns its threshold anew. The audit precision is
  measured on the last 200 decided audit items. Before the threshold is earned, Claude approves at most 20 by eye a
  day; `crop-review-approve` refuses audit-sample ids and anything outside the middle band, and skips a `409`.
  The plan reads the list's documented item shape strictly and stops with an error when a field it relies on is missing.
- Approving keeps the original (archived under `originals/`, "Restore original" on the puzzle history page).

## 9. Payload notes (what the stages store)

Every payload carries the envelope `schema`, `model` (repo id + quantisation or tool + macOS version), `sourceSha256`
(of the downloaded original), `crop` (`null` = full image, else the `artwork` bbox/corners used) and `runtimeMs`, and is
checked against the 64 KB limit (measured like the server: compact JSON, unescaped slashes / unicode) before it is
pushed. Specifics beyond image-taxonomy.md §11:

- `artwork`: `bbox` `{x, y, w, h}` (the field the server validates - vision-analysis.md §9.1 called it `box`),
  `corners` only when skewed, `method` `trim | vlm | vlm+quad`, `confidence` (gate margin / quad IoU / 0.5),
  `kindScores`, `source` `{width, height}` (the ≤ 1600 px cached image), `signals` (`gateMargin`, `vlmKind`,
  `vlmBbox`, `quadIou` - reused by the crop proposals).
- `colors` / `traits`: vision-analysis.md §9.2 / §9.3 in camelCase, plus `grid16` (`flat`, `lightness` ×100,
  `chroma` ×500, `gradient` ×500 - 4 × 256 small integers), `borderBand` (`uniformShare` of the outer ring of piece
  cells vs `interiorUniformShare`), `pieceLevel` `{real, p500, p1000}` (string keys - numeric keys would come back as a
  list), `regularity`, `symmetry`, `lineStrength`. `textShare` / `textChars` from Apple Vision fast text, CPU only.
- `embed_siglip2` (crop) and `embed_siglip2_full` (whole image): the vector under `vector` (fixed 384 squash);
  `embed_dinov2`: `cls`.
- `describe`: the §18 schema (`none` in `time` / `weather`, `characters` a list, `extraTags`, no `specialHints`),
  post-processed subjects / place / artistSignature, plus `promptVersion` `"v2.1"` (= prompt v2 + "never the puzzle
  brand or publisher" on the artistSignature line), `titleContext`, `title` (the catalogue title it was given -
  `verify --describe-parity` skips puzzles renamed since), `ocrLines`, `tokens`.
- `ocr`: lines capped at 200 characters; when a dense page would pass ~60 KB the smallest lines are dropped
  (`truncated: true`). `inArtwork` / `textAreaShareInArtwork` are null when OCR ran before `artwork` existed (the M1's
  phase A) - consumers compute "inside the crop" from the line boxes and the `artwork` bbox.
- **No empty maps:** an empty map is sent as null (the app decodes `{}` into a PHP array and returns `[]`), and every
  reader goes through `as_map()` - the derive step treats `{}`, `[]` and null as the same "nothing" when it decides
  whether a row changed.
- `segmentation`: `classes` (shares ≥ 0.002 by COCO panoptic label), `instances` (thing classes), `grid16`
  (dominant class id per cell, 255 = none), `classList`.

## 10. Troubleshooting

| Symptom | Meaning / what to do |
|---|---|
| `stale_fingerprint` outcomes, `409` on a store | The picture changed since it was claimed. Fine - skipped; the next claim brings the new picture. |
| `not_found` | The puzzle was merged, deleted or became secret (hidden images are never listed or accepted). Fine. |
| `429` | Production asks to slow down. The client waits (`Retry-After`, else 30 s doubling) and retries; nothing to do. Many in a row: raise the pause between calls. |
| `409` on approve with `code` `image_changed` / `original_not_archived` | The crop was made from an older picture (reject it, the pipeline proposes the new picture) / the original could not be archived first (nothing changed, try again later). |
| `409` rejecting / approving a decided request | Somebody decided it meanwhile - nothing changes, skip it. |
| `400` with `errors.requires` | A profile names an unknown analyzer or one requiring itself - a bug in `profiles/*.toml`. |
| `401` | Token missing or wrong: re-add it to the Keychain (§2). |
| `artwork` failures "no usable kind / box" | The VLM's answer could not be parsed; the picture is retried (3 attempts for the version) instead of being stored as a guessed flat picture. |
| Exit 143 / 124 | The run was stopped / its `--hours` ran out; a `--daily` run then skips derive and crop proposals. |
| `invalid` outcomes | The server refused a payload (bad `bbox`, too big, NUL). A pipeline bug: read the error in the log / `status` failures. |
| A stage waits forever "for the model lock" | Another process holds `/tmp/msp-model-lock` (ComfyUI, a lab, another stage) or memory is below 40 %. Check `ls -la /tmp/msp-model-lock`, `memory_pressure`. Never delete someone else's lock. |
| `describe` claims nothing | It needs `artwork` and `ocr` of the current picture - the other Mac is still behind (`status` shows `waiting`). Backfill mode sleeps and retries. |
| M1 never runs `describe` | `verify --describe-parity` has not passed on this machine for the current describe version (20 images compared byte for byte with the M3's results). Run it after phase B; if it fails, the Air stays on its own analyzers. |
| Throughput far below §19 | Other GPU work on the machine (20-50 % slower), the Air throttling (expected), or Apple Vision next to a GPU job. `bench --stage <s> --limit 20` compares with the measured numbers (±30 %). |
| `VisionTimeout` failures, "stage broken" | An Apple Vision call took > 60 s (cancelled, that image failed) / stayed stuck after the cancel twice (the stage ended; the next pass starts a fresh process). Many timeouts: something else is on the GPU or the Neural Engine (§5). |
| Images failing to decode | HEIC/AVIF are converted through `sips`; a picture failing 3 times is skipped until the next version (work state `failures`, `status`). |
| `difficulty_prior` `skipped` "the model needs 200" | Fewer than 200 labelled puzzles (≥ 3 indices) have that model's embeddings yet - normal during the backfill; nothing was recorded or pushed for it (the fallback may still have run). |
| `difficulty_prior` `blocked`, exit 3, `status` "blocked since …" | The push guard: a comparable run's holdout Spearman fell > 0.05 below the current record. Nothing recorded or pushed for that model; read `difficulty_prior-last-run.json` (§7) and decide; override only by hand with `--force-model`. |
| `difficulty_prior` `error` | It crashed (traceback in the log); the rest of the run went on. A bug - fix before the next night. |
| `guard.skipped` in the record | Not compared (first run, too few test puzzles, another threshold, training size moved ≥ 25 %) - recorded and pushed normally. |
| `ml extra` FAIL in setup-check | `uv sync --extra ml`; run model commands as `uv run --extra ml …` (a plain `uv run` uses the light set). |

## 11. Not verified before the backfill (first steps of the M3 session)

The pipeline was built without loading any model (Jan's Mac was busy). Unit tests cover the pure parts against a fake
of the API; the following is checked on the M3 at the start of the backfill session:

- `uv sync --extra ml` installs on the machine; `setup-check` passes.
- `bench --stage artwork-gate|artwork|describe --limit 20` within ±30 % of §19 (M1: `ocr`, `embed_siglip2`,
  `segmentation`, `embed_dinov2`, `colors`, `traits`).
- `verify --end-to-end --limit 10` stores 10 results for every analyzer of the profile, and `status` shows them.
- The model glue that only runs with models: mlx-vlm generation with the JSON-schema logits processor, SigLIP 2 /
  DINOv2 / EoMT through transformers 5.20, Apple Vision through pyobjc (incl. `usesCPUOnly` for the traits sidecar,
  NaturalLanguage for OCR languages), the artwork VLM prompt answer parsing on real answers.
- `verify --describe-parity` on the M1 after phase B.
