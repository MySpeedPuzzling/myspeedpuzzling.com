# Insights - implementation plan

Self-contained delivery plan for the Insights feature: prediction fix, image pipeline, Insights page, estimates.
Read [`README.md`](./README.md) first (decisions - all made by Jan on 2026-10-18, do not re-ask them). The research
files in this folder hold the numbers and the reasoning. [`prototype.html`](./prototype.html) is the visual reference.

- **Status:** planned 2026-10-18. Nothing built except the prerequisites: time zone #254 and the image-analysis internal API #255.
- **Progress record:** keep `STATUS.md` in this folder up to date as you go. Per phase: started / PR / merged / deployed, deviations from this plan and why, open follow-ups.

## 0. How to work (rules for every phase)

1. **Phases and PRs.**
   - One PR per phase (a big phase may be split into stacked PRs), branched from `main` in a git worktree.
   - Parallel sessions share the main checkout: never `git add -A`; stage your own paths, `git commit -- <paths>`.
   - CI (`.github/workflows/test.yml`, 3 test shards + PHPStan + CS + migrations check) is the gate.
   - **Jan delegated review, translation check and merge to the implementing session** (2026-10-18):
     - Merge when CI is green, the phase's acceptance criteria are met and every new translation key exists in all 6 locales (cs, de, en, es, fr, ja).
     - Deploy is automatic after merge; boot migrations run under an advisory lock.
     - After each deploy, check production: liveness `/-/health-check/liveness`, the new endpoints, the cron output.
2. **Local machine.**
   - During the image backfill Jan's M3 Max runs large models. **Do not run the full test suite locally**; run single test files (`docker compose exec web vendor/bin/phpunit <file>`) and let CI run the rest.
   - Check `memory_pressure` before starting heavy Docker work.
   - Never load ML models from this session.
3. **Project rules** (CLAUDE.md applies in full). The ones that bite here:
   - CQRS: state changes only through Messenger handlers, and repositories never flush.
   - Single-action controllers; `Uuid::uuid7()`; `ClockInterface`.
   - Migrations are **generated** against a scratch DB built from the committed migrations (never hand-written, except `custom_` indexes). **Never run `doctrine:migrations:migrate` on the dev DB** without asking Jan. Never drop the dev DB `speedpuzzling`.
   - FrankenPHP worker mode: no request state in services (`ResetInterface`); caches in Redis pools.
   - Raw SQL queries in `src/Query/` honour `suspicious = false`, `HiddenPlayers`, `PrivateProfileAccess` and secret puzzles (`hide_until` / `hide_image_until`), as the coverage tests demand.
   - Log levels: warning = Sentry.
   - Docker containers always `--rm`.
   - Public repo: no player names, e-mails or real per-brand quality signals in code, fixtures or docs.
4. **Docs as you go:**
   - Update `docs/features/internal-api.md` + `internal-api.openapi.yaml` for every endpoint.
   - Update `docs/features/puzzle-intelligence/` for model changes, `docs/features/feature_flags.md` for the flag, `docs/database-indexes.md` for custom indexes, and `docs/TODO.md` for loose ends.
   - Add a CLAUDE.md "Feature Planning" entry when the page launches.
5. **The backfill is not your job.** It runs in separate Claude Code sessions on the two Macs (prompts in §9). Phases 1 + 2 unblock it.
   - After Phase 2 is merged and deployed, tell Jan the backfill sessions can start, and give him the two prompts from §9.
   - Then continue with Phases 3-6 without waiting. Only §8 (launch) waits for the backfill.

## 1. Phase overview and order

| Phase | What | Visible to players? | Depends on |
|---|---|---|---|
| **1** | Production API for the pipeline: claim `requires=`, `embed_siglip2_full`, change requests with image / source / tags / confidence, list + auto-resolve support, original archive + restore, crop review grid | moderators only (review grid) | - |
| **2** | `tools/puzzle-vision/` Mac pipeline + runbook + skills + launchd | no | 1 (API contract; can be built in parallel, merged after 1) |
| → | **Jan starts the M3 Max + M1 Air backfill sessions (§9)**; ≈ 5.5 days in the background | | 1 + 2 deployed |
| **3** | Prediction fix: pace history, as-of difficulty, drift, shrinkage, thin sizes, calibrated windows, side-by-side predictions, model v2 | yes, **unflagged** (better numbers on existing surfaces, new window wording) | - |
| **4** | Insights computation: batch additions, read models, builders, cache, profile percentiles (no UI) | no | 3 |
| **5** | Image intelligence on the server: labels + estimates endpoints, image prior in shrinkage, image predictions side by side, evaluation + gates, tags registry, box-text list | moderators only | 1, 3 (derive runs on partial backfill data) |
| **6** | Display behind `INSIGHTS_PUBLIC`: Insights page, estimates (option C), improvement + rating charts | admins only until §8 | 4, 5 |
| **§8** | Launch: backfill complete, final derive, gates pass, Jan's yes → remove the flag | everyone | backfill |

Phase 3 can start right after Phase 1 (or in parallel with Phase 2): it touches no image code.

---

## 2. Phase 1 - production API for the pipeline

Goal: everything the Macs need from production exists before the backfill starts, including the crop change-request
flow. One PR, maybe two (claims + analyzer; change requests).

### 1.1 `embed_siglip2_full` analyzer
- Add `ImageAnalyzer::EmbedSiglip2Full = 'embed_siglip2_full'` (raw, full image). `embed_siglip2` now holds the **crop** embedding only.
- Why split: the M1 computes the full-image embedding before crops exist (phase A), and one analyzer = one payload = one push.
- Update `image-taxonomy.md` §11 payload schemas and `internal-api.md`.

### 1.2 Claim / list filter `requires=`
- `POST /internal-api/puzzle-image-claims` and `GET /internal-api/puzzle-images` accept `requires` (a list of analyzers; claim body `"requires": ["artwork","ocr"]`, list query `requires=artwork,ocr`).
- It hands out only puzzles whose **current** picture (fingerprint) already has a result for every required analyzer, any version.
- Dependency table (enforced by the pipeline profiles, documented in the runbook):

| Analyzer | requires |
|---|---|
| `ocr`, `barcode`, `embed_siglip2_full`, `artwork` | - |
| `colors`, `traits`, `segmentation`, `embed_dinov2`, `embed_siglip2`, `vision_labels` | `artwork` |
| `describe` | `artwork`, `ocr` |

- Tests: claims respect `requires`, stale fingerprints don't count, concurrency unchanged (`SerializedByLock`). The existing claim tests are the template.

### 1.3 Change requests carry pipeline metadata
- New nullable columns on `puzzle_change_request`:

| Column | Type | Meaning |
|---|---|---|
| `source` | enum `player` / `internal_api` / `image_pipeline` | existing rows backfilled: `player` (web) / `internal_api` |
| `tags` | jsonb list of strings | e.g. `["artwork_crop"]` |
| `confidence` | float 0..1 | the pipeline's confidence |
| `model_version` | string | the producing model/version |
| `source_image_fingerprint` | string(16) | fingerprint of the puzzle image the proposal was made from |

- Generate the migration against a scratch DB.
- `POST /internal-api/puzzle-change-requests` accepts:
  - `image`: base64 JPEG/PNG/WebP, ≤ 15 MB decoded, through the same validation + storage path as the web form's proposed photo (`proposedImage`, `proposedImageRatio`). Today the controller passes `proposedPhoto: null`.
  - `source: "image_pipeline"`, `tags`, `confidence`, `modelVersion`, `sourceImageFingerprint`.
- **Never twice:** a pipeline request with the same (puzzle, `sourceImageFingerprint`, tag) in any status → `409 already_proposed`. A rejected proposal is therefore remembered, and a new picture gets a new fingerprint.
- `409` when `sourceImageFingerprint` ≠ the puzzle's current fingerprint (the picture changed).
- Secret puzzles (hidden image) answer `404`, like the image API.
- `GET /internal-api/puzzle-change-requests?source=&tag=&status=pending|approved|rejected|all&limit&after` lists requests with `confidence`, `tags`, `modelVersion`, decision (who / when / outcome), `originalImageUrl` and `proposedImageUrl` (fetchable), `puzzleId`. It feeds the auto-resolve skill and the precision calibration.
- Approve / reject (existing endpoints) accept an optional `decisionNote` (e.g. `"auto: confidence 0.987, band ≥ 0.98, model artwork-crop 1.0.0"`), stored on the moderation decision. Reuse the merge audit's `decisionNote` / `decisionConfidence` shape if it fits.
- Web review queue (`/admin/puzzle-change-requests`): filter by source / tag, and a **before/after grid view** for `artwork_crop` requests.
  - Original and proposal side by side, the confidence, approve / reject per item (one decision per puzzle, each recorded as today).
  - Keyboard-friendly, mobile usable. Moderators only (`PUZZLE_MODERATION_ACCESS`), not behind the Insights flag.

### 1.4 Originals are always kept + one-click restore
- Today an approved image change never deletes the old file (only `SecretPuzzleHides` renames delete pictures), and the change request keeps `originalImage`. Keep it that way: add a test that approving an image change leaves the old object in storage.
- On approving an `image_pipeline` request: before switching, copy the current original to `originals/<puzzleId>/<fingerprint>.<ext>` (Flysystem copy; a failure blocks the approval with a 409 and logs a warning). Record the archive path on the change request (`original_archive_path`).
- "Restore original picture" for moderators: on the puzzle history page (`/admin/puzzles/{id}/history`) next to every image change. It re-applies the previous picture through `PuzzleRecordUpdater` as a moderator direct edit, recorded as a moderation decision. The image gets a new file name (never re-use names - image caches key on them), so the analyses re-run.

### 1.5 Acceptance
- PHPStan, CS, CI green.
- Internal API docs + OpenAPI updated.
- A curl walkthrough in `internal-api.md`: claim with requires → store → propose a crop (base64) → list pending → approve with note → restore.
- Deployed. Production answers 401 without the token, and the claim endpoint accepts `requires`.

---

## 3. Phase 2 - the Mac pipeline (`tools/puzzle-vision/`)

Goal: a rerunnable, resumable pipeline any Mac can run from the repo. Two machine profiles, Claude Code skills, a
runbook. Port the proven lab code (local, git-excluded, on the M3 Max only):

| Lab (`.claude/worktrees/…`) | What to port |
|---|---|
| `insights-vision-lab/scripts/` | `crop.py`, `hybrid.py`, `detect_classic.py`, `detect_vlm.py`, `kind_siglip.py` (artwork: SigLIP 2 flat-art gate → Qwen3-VL-4B box + OpenCV quad snap, mIoU 0.915), `features.py`, `with_model_lock.sh`, `memgate.sh` |
| `insights-taxonomy-lab/scripts/` | `apple_vision.py` (OCR / barcode / labels via pyobjc), `traits.py`, `embed.py` (SigLIP 2 / DINOv2), `zeroshot.py`, `vlm_prompt.py` |
| `insights-describe-bakeoff/scripts/` | `run.py`, `schema.py` (**the describe schema + system prompt v2**, also in `image-taxonomy.md` §18), `stages.py` (per-stage benchmark) |

If the labs are not on the machine, `image-taxonomy.md` §11, §18 and §19 plus `vision-analysis.md` hold everything needed to rebuild them.

### 2.1 Package
- Python 3.12, managed with `uv` (`pyproject.toml` + `uv.lock`, pinned).
- Dependencies: `mlx`, `mlx-vlm` (the version the bake-off used - read it from the lab venv), `torch` (MPS), `transformers`, `pyobjc-framework-Vision`, `opencv-python-headless`, `scikit-image`, `scikit-learn`, `numpy`, `httpx`, `pillow`.
- Layout:
```
tools/puzzle-vision/
  pyproject.toml, uv.lock, README.md (short; the runbook is docs/features/insights/image-pipeline.md)
  puzzle_vision/
    cli.py            # `uv run puzzle-vision <command>`: setup-check, run, status, derive, propose-crops, bench, verify
    api.py            # internal API client: claims (requires), batch store ≤ 200, release, bulk read, change requests
    config.py         # profile + Keychain token + base URL (default https://myspeedpuzzling.com)
    workstate.py      # SQLite: downloads cache index, failures, per-stage progress (temporary state only)
    lock.py           # /tmp/msp-model-lock (mkdir lock, ≥ 40 % free memory via `memory_pressure`), released on exit
    download.py       # originals → local cache, long side ≤ 1600 px, ≤ 4 req/s, retries; never re-download a fingerprint
    stages/           # artwork.py, apple_vision.py (ocr, barcode, vision_labels), embeddings.py (siglip2 crop + full, dinov2),
                      # segmentation.py (EoMT-L COCO panoptic, MIT), colors_traits.py (N single-thread CPU workers), describe.py
    derive/           # box_text.py, zeroshot.py, neighbours.py, tags.py, difficulty_prior.py (Phase 5 contract)
    proposals/crops.py# crop proposals (§2.5)
  profiles/m3-max.toml, profiles/m1-air.toml
  launchd/com.myspeedpuzzling.puzzle-vision.plist.tmpl
  tests/ (pure-python unit tests on tiny generated images; no model downloads in tests)
```

### 2.2 Worker contract
- One stage = one process = one model. Each stage first takes the model lock (shared with ComfyUI and other local model users).
- Loop:
  1. Claim `{analyzer, version, worker: "<hostname>-<profile>", limit, leaseSeconds, requires}`.
  2. Pre-fetch the next claim while working.
  3. Download, run, and batch-store results (≤ 200 per batch).
  4. On a per-image failure, record it in workstate and **release** the claim.
  5. On SIGTERM / Ctrl-C, release all own claims and exit cleanly.
- `caffeinate -i` while running. Resumable at any point: production is the truth, workstate is only a cache.
- `analyzerVersion` strings name the model, e.g. `artwork 1.0.0+siglip2-q3vl4b`, `describe 1.0.0+qwen35-9b-4bit`, `embed_siglip2 1.0.0+so400m-14-384`. **One version per analyzer at a time** (versions are compared for equality).
- Payloads exactly as `image-taxonomy.md` §11, with the §18 changes for `describe`:
  - `none` added to `time` / `weather`;
  - `characters` is a list;
  - `specialHints` removed and `extraTags` added;
  - post-processing of `subjects` / `place` / `artistSignature`;
  - "never the puzzle brand or publisher" added to the `artistSignature` line.

  Every payload is within the 64 KB per-analyzer limit.
- `describe`: Qwen3.5-9B 4-bit MLX, thinking off, JSON-schema constrained decoding (`mlx_vlm.structured.build_json_schema_logits_processor`, `clone()` per image), temperature 0, `max_tokens` 700, crop long side 512 px.
  - User message: catalogue title + ≤ 12 OCR lines inside the crop.
  - Unbatched: batching ran out of memory in the bake-off.
- SigLIP 2: feed a fixed 384×384 input (avoids MPS recompiles).
- Apple Vision: never in the same phase as a GPU-saturating stage on the same machine.
- Colors/traits: `OMP_NUM_THREADS=1`, 4-6 worker processes.

### 2.3 Profiles (from the measured split, `image-taxonomy.md` §19)
- **`m3-max`:**
  - phase 1: `artwork`, gate first then the VLM on the non-flat images (~24 h);
  - phase 2: `describe` (requires artwork, ocr; ~123 h).
  - Daily mode afterwards: the same order on new / stale images, then derive + crop proposals.
  - `--hours N` caps a run (Jan also works on this machine; throughput drops 20-50 % with other GPU work).
- **`m1-air`:**
  - phase A (no dependencies): `ocr` + `barcode`, then `embed_siglip2_full`;
  - phase B (requires artwork): `embed_siglip2` (crop), `embed_dinov2`, `segmentation` on the GPU, then `vision_labels` (Apple Vision on the crop - it needs `artwork`, and never runs next to a GPU stage), with `colors` + `traits` in CPU workers alongside;
  - then helps with `describe` with the same model and prompt, once `verify --describe-parity` passed (20 images on both machines must produce byte-identical JSON; otherwise the M1 never claims `describe`).
  - Runs nonstop (launchd `KeepAlive`), fanless: expect throttling, which the measurements include.
- Expected totals: M1 phases A + B ≈ 2.4 days; M3 ≈ 6.1 days alone; **≈ 5.5 days total** with the M1 helping on describe.
- Daily afterwards: ~60 new images / day ≈ 15-20 min on the M3.

### 2.4 Token, config, launchd
- `INTERNAL_API_TOKEN` lives in the macOS Keychain (`security add-generic-password -s msp-internal-api -a "$USER" -w`), read at call time, never printed or written to files. The env var `MSP_INTERNAL_API_TOKEN` overrides it for one-off runs.
- `--base-url` for a local dev API (testing only; production is the default and the only real target).
- launchd template per profile: logs to `~/Library/Logs/puzzle-vision/`, `KeepAlive` on the M1, a nightly window option on the M3.
- `setup-check` verifies:
  - Python / uv, free disk (≥ 60 GB for the originals cache + models);
  - models downloaded (`huggingface-cli download …`, sizes in the runbook);
  - the token present, the API reachable (`GET /internal-api/puzzle-images?limit=1` → 200).

### 2.5 Crop proposals + Claude Code auto-resolve
- `propose-crops --limit N`:
  1. Pick images whose `artwork` result is a box photo (kind ≠ flat), most-solved first (the work list's `solvedCount`: every counted result - solo, pairs, teams; suspicious left out).
  2. Render the perspective-corrected crop from the **full-resolution original**.
  3. Compute the **confidence from agreement signals**, never the raw model score:
     - IoU of the VLM box vs the OpenCV-snapped quad;
     - the flat-art gate's probability on the crop;
     - edge straightness;
     - an aspect ratio plausible for the piece count's typical grid;
     - the artwork filling the box front.
  4. Submit `POST /internal-api/puzzle-change-requests` with the image, `source: image_pipeline`, `tags: ["artwork_crop"]`, `confidence`, `modelVersion`, `sourceImageFingerprint`.
  5. Low confidence (< propose threshold, start 0.6) → no proposal.
- Skill **`/image-crop-review`** (Claude Code runs it, daily or on demand):
  1. List pending `artwork_crop` requests and every decided one.
  2. **Earn the threshold:** while fewer than ~300 human decisions exist, auto-approve nothing.
  3. Afterwards, find the lowest confidence band where human precision's **Wilson lower bound ≥ 99 %**, and auto-approve pending requests above it through the internal API with a `decisionNote` (confidence, band, model).
  4. **Audit:** leave a random 5 % of would-be-auto items pending for humans. If their measured precision drops below 98 %, stop auto-approving and report.
  5. **Middle band:** optionally look at the before/after images itself (Read the two URLs) and approve only when certain; anything unsure stays for moderators.
  6. Print a summary: counts per band, precision so far, what it approved.

### 2.6 Skills (in `.claude/skills/`, each a SKILL.md like `puzzle-change-proposal`)
| Skill | Does |
|---|---|
| `/image-pipeline-setup <m3-max\|m1-air>` | Prepare this Mac: uv env, model downloads, Keychain token (asks Jan to paste it into the `security` prompt himself), `setup-check`, the launchd plist for the profile (load only when asked) |
| `/image-pipeline-run [--hours N] [--phase X]` | Start or resume this machine's profile in the foreground (or via launchd), with progress lines |
| `/image-pipeline-status` | Coverage per analyzer and version from the API (done / missing / stale / claimed), throughput per machine, failures, ETA |
| `/image-pipeline-derive` | Recompute derived results (box_text, zeroshot, neighbours, tags; from Phase 5 also difficulty_prior) from stored raw outputs - no image touched |
| `/image-pipeline-add-analyzer` | Checklist for a new analyzer or version bump (enum case, payload schema docs, profile, what re-runs, what derives) |
| `/image-crop-review` | §2.5 |

### 2.7 Runbook `docs/features/insights/image-pipeline.md`
Covers:
- setup on a new Mac and the split (and why);
- the dependency table and the daily mode;
- versions and re-runs;
- disk and memory rules (model lock, ≥ 40 % free, Apple Vision vs GPU);
- stop / resume and coverage checks;
- crop proposals and the review loop;
- troubleshooting (409 stale fingerprint = the picture changed, just continue; 429 = slow down).

### 2.8 Acceptance
- `uv run puzzle-vision bench --stage <s> --limit 20` reproduces the §19 timings within ±30 % on the M3.
- `verify --end-to-end --limit 10` against production stores 10 puzzles' results for every analyzer of the profile (that is real backfill work, harmless).
- `/image-pipeline-status` shows them.
- Unit tests pass (`uv run pytest`); CI may skip the Python tests.
- Runbook and skills committed; Phase 1 deployed.
- **Then the orchestrator tells Jan to start the backfill sessions and hands him the prompts of §9.2 and §9.3.**

---

## 4. Phase 3 - prediction fix (unflagged)

Goal: the research's Phase 0 (`prediction-research.md` §1-2, §4-7, §12). It improves numbers players already see and changes the window wording.

### 3.1 `player_pace_history` (event-driven)
- One row per player × piece count **per change**, on each qualifying solo first attempt (collapsed per day is fine).
- Columns: `player_id`, `pieces_count`, `valid_from` (solve moment `COALESCE(finished_at, tracked_at)` + `tracked_at` tie-break), `time_id` (the solve that moved it), `baseline_seconds`, `baseline_type` (direct / interpolated / extrapolated), `qualifying_count`, `drift` (log), `step` (vs the previous row).
- Index `(player_id, pieces_count, valid_from)`.
- Written by the 15-min batch: only new events since the last run, via `PlayerBaselineCalculator::weightedBaselineSeconds()`, the one baseline rule.
- Backfill: `myspeedpuzzling:backfill-pace-history` dispatches a message whose handler walks history in keyset batches and is idempotent. Research: ~225k rows, ~30 MB, minutes. Add the cron row on lily myself (memory rule).
- Keep `player_rating_snapshot` as it is (Jan). Fix the puzzle-intelligence README's "nothing reads the snapshots" (the digest does) and its 7-tier mention (there are 6).

### 3.2 As-of difficulty
- `PuzzleDifficultyCalculator`: each difficulty index = time ÷ the solver's pace-history row **valid just before the solve**, not the current baseline.
- Store `sum_log_indices` and `index_count` (k) on `puzzle_difficulty` for shrinkage on read.
- The reconstructor uses the same lookup, so live == reconstructed (the existing guard tests).
- Expected: rated scores drop ~8 % at the median and ~46 % of well-rated puzzles move one tier easier (Jan: fine). Public hardest / easiest lists change accordingly.

### 3.3 Drift, shrinkage, thin sizes
- **Drift:** per player × piece count, the mean log residual of the last 10 first attempts, shrunk toward 0 with few solves; part of the centre.
- **Shrinkage:** puzzle log difficulty for k = 1..4 (and k = 0 when a prior exists) = (Σ log indices + τ · prior) ÷ (k + τ), τ ≈ 1 (tune on a time split).
  - Prior behind an interface `DifficultyPrior`: now `BrandPiecesPrior` (fit in the batch); Phase 5 adds `ImageEstimatePrior` in front of it.
  - Puzzles with 1-4 solves become predictable (first-try coverage 66 % → ~82 %). The tier shown for k < 5 stays "not rated yet" until Phase 6 displays estimates.
- **Thin sizes:** when the player's own baseline at a piece count rests on few puzzles, use their 500-piece pace scaled by the size exponent (fit in the batch; research: ≤300 1.33, 1000 1.44, 1500+ 1.49, odd 1.62, overall 1.35) when it predicts better. Research: better for 1000, worse for ≤300 - keep per group whichever wins on a time split.

### 3.4 Calibrated windows
- **Centre:** the corrected prediction.
- **Width:** σ² = a · playerSpread² + b / (k + 1) + sizeTerm.
  - `playerSpread` = robust spread of the player's recent log residuals, shrunk toward the global spread with few solves.
  - a, b and the size term are fitted in the batch.
- **Asymmetric bounds** = centre × exp(q · σ), with q = standardised-residual quantiles from a **bucket table**: consistency quartile × k bucket (0, 1-4, 5-19, 20+) × first try / repeat × size group, for 25/75 % (the 50 % window) and 10/90 % (80 %).
  - The batch refits the table nightly; keep it small (a table or a JSON row).
- **Repeats:** centre from today's Holt; width from the player's own repeat spread. Research: 30 % wide / 76 % hit becomes 11 % / 49 %.
- **Storage:** `predicted_range_low/high_seconds` = the 50 % window. New `predicted_range80_low/high_seconds`.
- **Display rules:**
  - Window ends round to the nearest minute when the window is < 20 min wide, else the nearest 5 min (< 1 h) / 10 min; at least one step wide; never outward.
  - Wording: "Likely 57-64 min · half of solves land inside". A hit: "Inside your likely window - only half of solves do". A miss: side + margin. All 6 locales. Find every surface showing the range: recap, add-time pace check, result detail, Picker, collections display mode, API v1 (additive fields only, never rename).
- **Monitor:** `myspeedpuzzling:report-prediction-calibration` (daily cron) reports hit rate per bucket from stored live predictions; logs a warning when a bucket drifts > 3 points from its target.

### 3.5 Side-by-side predictions
- `solving_time_prediction`:
  - Columns: `time_id` (FK cascade), `predictor` (`statistical` / `image` / `blend`), `model_version`, `source` (`live` / `reconstructed`), `predictable`, `predicted_seconds`, `range50_low/high`, `range80_low/high`, `difficulty_used`, `difficulty_source` (`solves` / `shrunk` / `image`), `k_indices`, `prior_log`, `computed_at`.
  - Unique `(time_id, predictor, model_version)`.
- The existing columns on `puzzle_solving_time` stay **the shown prediction**.
- Live writes in `SolvingTimePredictor` (savepoint - a failure never loses the time) and reconstructed writes in `PredictionReconstructor`. No domain events (the existing rule).
- Bump `TimePredictionCalculator::MODEL_VERSION` to 2. Re-run `myspeedpuzzling:backfill-solving-time-predictions` for v2 (in production after deploy - check how prediction-history.md runs it).

### 3.6 Acceptance (measured on the production copy, time split: fit before a date, evaluate after)
- First-try MAE of log ratio ≤ 0.10 with median bias within ±1 % (research: 0.092).
- 50 % window hit 47-55 % overall and per consistency quartile; median width ≤ 15 %; 80 % window 77-84 %.
- Repeat windows: hit 45-55 %, median width ≤ 13 %.
- Batch runtime stays within +5 s of today's ~11 s. Query budgets of existing pages unchanged.
- The prediction-history guard tests (live == reconstructed) pass.
- Docs: `puzzle-intelligence/README.md` + `prediction-history.md` updated (pace history, as-of indices, windows, v2).

---

## 5. Phase 4 - Insights computation (no UI)

Goal: every number the page shows, computed and tested, within the performance budget (README §4 measured plan).

### 4.1 Batch additions (`recalculate-puzzle-intelligence`)
- **Run marker / generation:** the run writes a generation id at its end (a row or a Redis key); the page cache keys on it.
- **`player_rating_entry`:** per player × puzzle × portfolio (first try / best time): time, percentile, difficulty weight, decay, points, in top 100. Only changed rows are written. The MSP rating calculator computes these in memory today.
- **Per-puzzle sorted time arrays** (first-try and best-time) for qualifying and near-qualifying puzzles (~2.4k rows, ~2 MB, only changed rows). Rating what-ifs rebuild points by binary search (~15-25 ms vs 270-490 ms on read).
- **Pair/team coordination constants** c(size, first/repeat): measured pairs 1.21 / 1.08, teams of 3 1.35 / 1.24, 4+ 1.41 / 1.32; refit in the batch.
- **Profile-card percentiles** (daily, not every 15 min): the population distribution of the 7 axes.
  - Axes: Speed = time vs puzzle median; Improvement = vs a year ago; Consistency = spread around your usual; Learning = second-attempt gain; Hard puzzles = pace on Hard / Very hard; Big puzzles = pace on 1000+; Events = event vs home.
  - Each player's score = percentile against all puzzlers.
  - The prototype's `renderProfile()` in `prototype.html` shows the formulas.
- Optional: distinct-solver count per puzzle for "#12 of 340".

### 4.2 Read models (`src/Query/`)
- **`GetInsightsSoloResults`:** one statement - results + puzzle + `puzzle_statistics` + `puzzle_difficulty` (tier, derived metrics, shrinkage fields) + the stored prediction + the on-shelf flag. Uses the existing index `custom_pst_player_puzzle_type`. Measured 7-9 ms for the heaviest player.
- **`GetInsightsGroupResults`:** from `puzzling_team_member`, with every member's baseline (LATERAL).
- **`GetCloseCalls`:** reads only times faster than the player's. The hidden-players set is a **MATERIALIZED CTE** (otherwise JIT adds 290 ms). Ranks must equal `GetRanking::allForPlayer()` (parity test).
- **Hydrate statement** for the ≤ 50 puzzles shown.
- Self-only: the owner's own data, private players included. Other players appear only in existing public rankings (close calls name places, never people).

### 4.3 Builders (pure PHP, `src/Services/Insights/`)
- **`InsightsCriteria::fromUserInput(query, isMember)`:** period (3 / 6 / 12 / 24 months, this year, a past year, all time, exact from–to), pieces, results (all / first tries / repeats), brand, difficulty (multi), events, unboxed, shelf, weekday, puzzle personality, image traits (once tags exist), time of day (once a time zone exists). Members-only values are stripped for free players; free = 3 / 6 months + pieces.
- **`InsightsBuilder`:**
  - **Form:** rolling median of first-try ratios (±75 days) with a fixed sorted window - the naive version is O(n²).
  - **Skill map segments:** shrinkage n/(n+8), order-statistic ranges (never a bootstrap), strength / weak spot only beyond ±1.5 % with the whole range.
  - **Histogram + outliers:** > 30 % off your usual.
  - **Expected vs actual:** stored prediction × your usual ratio until v2 predictions cover the period.
  - **Learning curve:** `player_improvement_ratio` vs `global_improvement_ratio`.
  - **Habits:** weekday; time of day only with a time zone, labelled "when you logged".
  - Consistency, prediction accuracy (hit rate of your likely windows), the findings and the profile card.
- **`RatingOpportunities`:** re-solve moves (decay resets), first try from your shelf, defend (entries leaving the 24-month window within 60 days), points per hour, pending qualification (puzzles at 9-19 solvers), the sum of the best 5 / 10.
- **`RatingForecast`:** stop / keep pace / best moves; milestones.
- **`ResolveRadar`:** bulk predictions over loaded rows.
- **`PairsTeamsSynergy`:** expected = c × difficulty ÷ Σ(1 / baselineᵢ); no guests, every member with a baseline, rated puzzle, not unboxed. Guests listed without a score.
- **`ImprovementCurve`:** pace history - curve per size, biggest jumps (steps > 3 %, each with its solve and puzzle), plateau detection, comparison with the typical curve.
- **`RatingOverTime`:** `player_rating_snapshot` daily rows, day-to-day deltas.
- **`ShelfPicks`:** unsolved collection puzzles that suit you, using v2 predictions; from Phase 5 also image estimates.

### 4.4 Cache + snapshot
- Redis pool `insights_cache`, key = player + batch generation + a ~1 ms fingerprint of the player's solo and group results. Close calls cached per generation. No service properties.
- Monthly `player_insights_snapshot` (small JSON: strengths, weak spots, form, rating) for "what changed since last month" later.

### 4.5 Acceptance
- Unit tests per builder on fixture data (`.claude/fixtures.md`).
- A query-budget test: first response ≤ 5 statements whatever the player's size.
- A performance guard on the heaviest fixture.
- Measured on the production copy (heaviest players):
  - all first-response parts together < 150 ms server time;
  - lazy parts (rating / forecast / re-solve / pairs) < 100 ms each.

---

## 6. Phase 5 - image intelligence on the server (stored, not shown)

### 5.1 Labels endpoint
- `GET /internal-api/puzzle-difficulty-labels?limit&after`: per puzzle `puzzleId`, `logDifficulty` (as-of, Phase 3), `indexCount`, `piecesCount`, `manufacturerId`, `addedAt`.
- Aggregates only - no per-player data leaves production. Secret puzzles excluded.

### 5.2 Estimates
- Table `puzzle_difficulty_estimate`: `puzzle_id` (unique), `model`, `model_version`, `log_difficulty`, `log_p10`, `log_p90`, `out_of_fold` (bool), `training_size`, `image_fingerprint`, `computed_at`.
- `PUT /internal-api/puzzle-difficulty-estimates`, a batch of ≤ 500: refuses hidden puzzles and stale fingerprints (like the image API).
- Plus `POST /internal-api/puzzle-difficulty-estimates/model` with the model's evaluation (gates below) - stored as the current model record.

### 5.3 Mac derive step `difficulty_prior` (in `tools/puzzle-vision/derive/`)
- Ridge regression on SigLIP 2 crop + DINOv2 + log pieces; label = as-of log difficulty.
- Train on puzzles with ≥ 3 solves, weighted n/(n+10); test on puzzles with ≥ 20 solves.
- 5-fold **cross-fitted out-of-fold** estimates for labelled puzzles, the full model for unlabelled ones. Intervals from residual quantiles per (piece-count group × image kind).
- Validation per run:
  - brands held out;
  - newer puzzles predicted from older ones;
  - per size group and per `artwork` kind (flat / box / assembled).
- A push is blocked when the holdout Spearman drops > 0.05 against the previous model.
- Runs nightly on the M3 after the daily pipeline. During the backfill it runs on whatever is embedded so far.
- No fine-tuning, no language models for numbers (`prediction-research.md` §3).

### 5.4 Image prior in the app
- `ImageEstimatePrior` in front of `BrandPiecesPrior` in shrinkage. The prior fades as solves arrive (k ≥ 3: no measurable effect).
- Write `solving_time_prediction` rows for `image` (image + pieces only) and `blend` (shrunk) next to `statistical`, live and reconstructed. Reconstructed rows use out-of-fold estimates only.
- A puzzle added together with its first time gets image `predictable = false` live, and is reconstructed later.
- Command `myspeedpuzzling:evaluate-image-estimates`: from stored live + out-of-fold reconstructed rows on puzzles at k = 0..4, per size group and image kind:
  - Spearman of difficulty;
  - MAE of log time vs statistical;
  - tier exact / ±1;
  - 50 / 80 % window hit and width.

  Stores the result as the **gate record**.
- **Gates** (all on newer puzzles, per size group with ≥ 50 puzzles and per image kind):
  - Spearman ≥ 0.6;
  - within ±1 tier ≥ 80 % **and** exact tier clearly above always-"Average" (46 %);
  - calibrated window hit within ±3 points of target.

  `ImageEstimateGate::passesFor(sizeGroup, imageKind)` reads the stored record. Picture-only estimates show only where the gate passes, even after the flag is gone.

### 5.5 Tags for Insights
- Taxonomy registry in the app: keys + 6-locale labels for what Insights shows first - the image-traits group: large single-colour areas, gradients, busy / collage, text, dark, photo vs illustration.
- `puzzle_tag` (derived): written by app code from the `tags` analyzer (cron after the daily derive).
- `puzzle_tag_correction` for admin / moderator fixes that survive re-runs (UI can follow later; corrections by internal API first).

### 5.6 Box-text mismatch list (moderators)
- From `box_text` (pieces / EAN / brand read on the box vs the catalogue): `/admin/box-text-mismatches`, `PUZZLE_MODERATION_ACCESS`, with **Dismiss / Ignore**.
- Remembered per (puzzle, field, value) in `box_text_mismatch_decision`, so a dismissed mismatch never returns.
- Never automatic change proposals.

### 5.7 Optional
- "Possible duplicates" for moderators from `neighbours` + EAN groups → "Propose merge" (reuses the merge review).
- Cut style from finished-puzzle photos (a new analyzer over finish photos; internal only).

### 5.8 Acceptance
- Endpoints documented and tested. The derive step pushes estimates for every embedded puzzle.
- The evaluation command runs and stores a gate record.
- No hidden puzzle has an estimate (canary test).

---

## 7. Phase 6 - display behind `INSIGHTS_PUBLIC`

### 6.0 The flag
- Env `INSIGHTS_PUBLIC` (default `0` in `.env`) → parameter `insightsPublic` → `InsightsFeature::isVisibleTo(?PlayerProfile)` = flag **or** `ADMIN_ACCESS` (the single decision point). Twig function `insights_visible()`.
- Document it in `docs/features/feature_flags.md` (gated files, remove-when).
- Tests use `OverridesFeatureFlagEnv`.
- While off, nothing new is reachable for non-admins: routes 404, no menu links, no estimate rows.

### 6.1 Insights page
- Route `insights` (`/{_locale}/insights`), **owner-only**, as a tab of the player's own profile (`PlayerHeaderTab::Insights`, only when viewing yourself).
- Also: a menu row under "Your puzzling", and "See why" links from the recap and own result rows.
- Live Component with URL filters (`InsightsCriteria`), Compare-style. Sections exactly as the prototype:
  1. question chips;
  2. findings + tiles;
  3. profile card (7 axes, free);
  4. form (6 months free);
  5. skill map with drill-down;
  6. expected vs actual;
  7. median histogram + outliers;
  8. close calls (free);
  9. rating opportunities + pending qualification + portfolio strips;
  10. forecast;
  11. re-solve radar;
  12. learning curve;
  13. pairs & teams;
  14. habits (results per weekday free);
  15. improvement curve (pace history) + rating over time (snapshots);
  16. "make your insights sharper" nudges.
- Free vs members:
  - one lock per section with real counts ("4 moves worth +9 points"), the trial button in each lock, never a wall of CTAs;
  - the free page works at 10 results and says what unlocks at 25 / 50.
- First response = 4-5 statements; rating / forecast / re-solve / pairs in lazy Turbo Frames below the fold.
- Chart.js through `ChartBuilderInterface` where it fits; the skill map and strips as HTML/SVG.
- Mobile first. Measure button / label overflow in 6 locales at 320-414 px (the button-overflow audit recipe).
- Neutral wording; first-try margins framed positively.

### 6.2 Estimates - option C, mobile first
- **Puzzle page:** the full card - "≈ 3 h 05 for you", certainty bar, range, tier chip (dashed "Hard?" when estimated), context ("2nd-hardest 1000 in your collection").
- **Lists** (collections, wishlist, unsolved, Puzzle Picker cards, marketplace cards, difficulty corners): one line under the name - **time + tiny certainty bar + chip**; tapping the estimate opens a small sheet (range 50 % / 80 %, what it rests on: picture / first solves / measured, how sure).
  - One line, never wrapping at 320 px in all 6 locales. The chip shrinks to its icon before the time ever does.
- Times round to 5 min under an hour and 10 min above; "≈" in lists. Window ends follow the Phase 3 rule.
- Estimated vs measured is never colour alone: dashed outline + "?" + "estimated" in the accessible name.
- Members only; free players see today's lock ("≈ ? for you").
- No estimate for secret puzzles, and none for players without any baseline (a clearly labelled community time by piece count instead).
- Picture-only estimates appear only where `ImageEstimateGate` passes.
- One query per list page, like `ResolvePuzzleListInsights` (bulk predictions, never N+1).

### 6.3 Acceptance
- Flag off: non-admins see nothing new (test).
- Flag on: every section renders for a free and a member fixture.
- Query budget + performance guards hold. 6 locales complete.
- Canaries: no estimate for a hidden puzzle, no other player's private data.
- Screenshots at 320 / 390 / 1200 px reviewed.

---

## 8. Launch (waits for the backfill)

1. `/image-pipeline-status`: every analyzer at 100 % current coverage, or only images that failed for a recorded reason (listed).
2. Final `/image-pipeline-derive`, including `difficulty_prior` on the complete data. `myspeedpuzzling:evaluate-image-estimates` stores a passing gate record for 500 pc at least.
3. Jan looks at the admin-visible page and says yes.
4. Remove the flag:
   - delete `InsightsFeature`'s flag check (keep it as the members / free decision point if useful) and the `.env` entry;
   - update `feature_flags.md` (move to "Retired") and add the CLAUDE.md "Feature Planning" entry;
   - ask Jan whether an announcement modal is wanted (`docs/features/announcement-modals.md`).
5. Daily mode continues on the Macs (launchd), plus the nightly derive and `/image-crop-review`.

---

## 9. Prompts

### 9.1 Orchestrator session (give this to a fresh Claude Code session in the repo on the M3 Max)

```text
You are the ORCHESTRATOR delivering the MySpeedPuzzling "Insights" feature end to end: every phase of
docs/features/insights/implementation-plan.md, including Phase 6 behind the INSIGHTS_PUBLIC feature flag (documented in
docs/features/feature_flags.md), waiting for the image backfill, and - after Jan confirms - removing the flag (§8).

Read first, completely: docs/features/insights/README.md (decisions - all Jan's, never re-ask them),
docs/features/insights/implementation-plan.md (the plan: phases, tasks, acceptance criteria, prompts),
docs/features/insights/STATUS.md, and CLAUDE.md. The research files (prediction-research.md, image-taxonomy.md
§11/§18/§19, vision-analysis.md) and prototype.html are for the subagents of the phases that need them.

How you work:
- Jan works on this repository in parallel in the main checkout. Never touch it: you and every subagent work only in
  git worktrees created from origin/main (one worktree per stream/branch, e.g. .claude/worktrees/insights-phase-3).
  Never `git add -A`; stage explicit paths. Remove a worktree when its branch is merged.
- You do not write feature code yourself. You plan each phase, split it into streams, spawn subagents (one per stream,
  each in its own worktree with a precise brief: the plan section, files, acceptance criteria, the project rules below),
  review what they deliver (read the diff, run the relevant single tests, check translations in all 6 locales, check
  migrations were generated against a scratch DB), send them back with concrete fixes when needed, then open/finish the
  PR, wait for CI and merge. Use a fresh subagent for an independent review of each phase before merging.
- Order: Phase 1 and Phase 2 first (they unblock the image backfill; Phase 3 may run in parallel with Phase 2), then
  Phase 3 → 4 → 5 → 6. As soon as Phases 1 + 2 are merged and deployed, tell Jan the backfill can start and give him the
  two prompts in implementation-plan.md §9.2 and §9.3 verbatim. Never wait for the backfill with Phases 3-6.
- One PR per phase (stacked PRs for big ones). Jan delegated review, translation check and merge to you: merge when CI
  is green, the phase's acceptance criteria are met and every new translation key exists in all 6 locales (cs, de, en,
  es, fr, ja). Deploy is automatic; after each deploy verify production (liveness, new endpoints, cron output) and
  record it.
- Phase 6 ships behind INSIGHTS_PUBLIC (default 0, admins always see it): document it in docs/features/feature_flags.md
  (flag, decision point, gated files, tests, remove-when) in the same PR. After deploy, tell Jan the admin-visible page
  is ready to look at.
- Launch (§8): when only the launch is left, check the backfill regularly (`/image-pipeline-status` or the internal API
  coverage) - e.g. every few hours with a scheduled wake-up or a background monitor - and keep STATUS.md current so the
  work can be resumed with "continue the Insights delivery" if this session ends. When coverage is complete, ask Jan
  (or check STATUS.md for his answer) to confirm the M3 session ran the final derive; then run
  `myspeedpuzzling:evaluate-image-estimates` on production, report the gate results, and ASK JAN FOR CONFIRMATION before
  removing the flag. Only after his explicit yes: a PR that removes the flag (code, .env, feature_flags.md → "Retired"),
  adds the CLAUDE.md "Feature Planning" entry, and asks Jan whether an announcement modal is wanted.
- Machine limits: Jan's M3 Max runs large models during the backfill. Never run the full test suite locally (single
  test files only; CI is the gate), check `memory_pressure` before heavy Docker work, never load ML models, keep at most
  ~3 subagents busy at once.
- Project rules every brief must repeat: CLAUDE.md in full; migrations always generated against a scratch DB built from
  committed migrations, never `doctrine:migrations:migrate` on the dev DB without asking Jan, never drop the dev DB
  `speedpuzzling`; FrankenPHP worker mode (no state in services); repositories never flush; secret puzzles
  (hide_until / hide_image_until) never leak; no player names in the public repo; Docker always `--rm`.
- STATUS.md is the source of truth: before each phase write its stream plan (streams, files, migration, tests), after
  each merge the PR, merge and deploy dates, deviations from the plan and why, follow-ups. If the plan is wrong
  somewhere, decide with its principles and record why; ask Jan only for real product decisions.

Start: read the files above, write the Phase 1 + Phase 2 stream plan into STATUS.md, then spawn the subagents.
```

### 9.2 M3 Max backfill session (Jan starts it after Phases 1 + 2 are deployed)

```text
You run the MySpeedPuzzling image pipeline on this MacBook Pro M3 Max (36 GB), profile `m3-max`. You do not change
application code and you do not commit, except fixing a genuine bug in tools/puzzle-vision/ - then on a branch with a
PR, and tell Jan.

Read docs/features/insights/image-pipeline.md (the runbook) and docs/features/insights/README.md §3 "Image
intelligence". Precondition: `git pull` on main shows tools/puzzle-vision/ and the runbook, and
`/image-pipeline-status` answers (the production API with claims `requires` is deployed). If not, stop and tell Jan.

1. /image-pipeline-setup m3-max (ask Jan to paste the internal API token into the Keychain prompt himself; never print it).
2. /image-pipeline-run with the m3-max profile: phase 1 `artwork` (gate first, then the VLM on box photos), then phase 2
   `describe` (requires artwork + ocr - it starts on what the M1 has already OCR'd and keeps claiming as more arrives).
   Default: run continuously; if Jan says he needs the machine, use `--hours N` or stop cleanly (claims are released).
   Expected: artwork ~24 h, describe ~5 days (10.75 s/img when the GPU is otherwise idle; 20-50 % slower with other GPU work).
3. Check progress every few hours with /image-pipeline-status; report throughput, ETA and failures briefly. A 409
   stale fingerprint means the picture changed - fine. Repeated failures of one stage: read the logs, fix only if it is
   clearly a pipeline bug, otherwise tell Jan.
4. Once `artwork` is complete: start crop proposals (`propose-crops`, small daily limit at first, e.g. 50) and run
   /image-crop-review daily. Auto-approval stays off until ~300 human decisions exist (the skill enforces it).
5. When every analyzer of both profiles is at full coverage: run /image-pipeline-derive (incl. difficulty_prior once
   Phase 5 is deployed) and tell Jan the backfill is complete with the final /image-pipeline-status output.
6. Then load the launchd job for the daily mode (/image-pipeline-setup offers it) so new images are analysed every day.
Never run anything that loads a second large model while describe runs; respect the shared model lock.
```

### 9.3 M1 Air backfill session (Jan starts it on the MacBook Air M1 after Phases 1 + 2 are deployed)

```text
You run the MySpeedPuzzling image pipeline on this MacBook Air M1 (16 GB, fanless), profile `m1-air`. You do not
change application code and you do not commit, except fixing a genuine bug in tools/puzzle-vision/ - then on a branch
with a PR, and tell Jan.

1. Get the repo: `git clone https://github.com/MySpeedPuzzling/myspeedpuzzling.com.git ~/msp` (or `git pull` if it exists),
   then read docs/features/insights/image-pipeline.md (the runbook). If tools/puzzle-vision/ or the runbook is missing,
   stop and tell Jan (Phases 1 + 2 are not deployed yet).
2. /image-pipeline-setup m1-air (installs uv/Python deps, downloads only the models this profile needs, asks Jan to paste
   the internal API token into the Keychain prompt himself; never print it).
3. /image-pipeline-run with the m1-air profile, nonstop:
   - phase A (no dependencies): ocr + barcode first (~3.5 h - the M3's describe waits on OCR), then
     embed_siglip2_full;
   - phase B (requires artwork, which the M3 produces): embed_siglip2 (crop), embed_dinov2, segmentation on the GPU,
     then vision_labels (Apple Vision on the crop - it needs artwork), with colors + traits in CPU workers alongside.
     Expected phases A + B ≈ 2.4 days; the Air throttles under sustained load, that is expected.
4. When phase B is done: run `uv run --extra ml puzzle-vision verify --profile m1-air --describe-parity` (20 images, compares this machine's describe
   output with the M3's). Only if it passes, join `describe` claims with the same model and prompt; otherwise keep the
   Air on its own analyzers and daily mode.
5. Report progress every few hours with /image-pipeline-status (throughput, ETA, failures). Keep at least 40 % memory
   free (the model lock enforces it); never run two GPU stages at once.
6. When done, load the launchd job (KeepAlive) for the daily mode so this machine keeps analysing new images nonstop.
```

---

## 10. Risks and how the plan handles them
| Risk | Handling |
|---|---|
| Image model weaker on non-500 sizes / box photos (pilot was mostly flat 500s) | Gates per size group and image kind; picture-only estimates only where the gate passes |
| Leakage into reconstructed predictions | Out-of-fold estimates only; evaluation on newer puzzles |
| Secret puzzles revealed by an estimate or a look-alike | API refuses hidden images; canary tests; derived neighbours re-checked on read |
| Wrong crops changing catalogue pictures | Change requests only, earned auto-approval threshold, 5 % audit, auto-stop, originals archived, one-click restore |
| Window looks falsely precise | Calibrated per bucket, nightly monitor with a 3-point alarm, never rounded outward |
| Batch slows down | Pace history incremental; arrays and entries written only when changed; runtime acceptance limit |
| RAM on the M3 during the backfill | No local full suites; single tests; CI is the gate |
| Tier changes surprise players | Expected and approved (Jan); mention in the release notes / feature request reply if asked |
