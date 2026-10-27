# Insights - design of record

Status: **planned 2026-10-18**, nothing of the page built yet. Delivery: [`implementation-plan.md`](./implementation-plan.md)
(phases, tasks, acceptance criteria, the session prompts). This file is the *what and why*; every decision below is
Jan's, made during the planning session of 2026-10-18 - do not re-ask them.

## 1. What it is

1. **A personal "Insights" page.** It covers only the player's own data, compared with the puzzles' other solvers and with themselves.
   - It answers: what kind of puzzler am I, am I getting better, where are my strengths and weak spots, which puzzles
     went faster or slower than expected, where am I way off the median, where am I close to a better place, where can
     I gain MSP rating, what should I re-solve, who do I solve best with, when do I puzzle best.
   - Compare answers "how do I stand against others?". Insights answers "what is true about my puzzling, and what
     should I do next?".
2. **Better predictions.**
   - Difficulty is computed from each solver's pace *at the time of the solve*: a pace history fixes today's age bias.
   - Calibrated "likely" windows that a hit means something for.
   - Predictions for puzzles with only 1-4 solves and for thin piece counts.
3. **Image intelligence.** A one-time extraction run over every box image, on Jan's two Macs with self-hosted open models.
   - Artwork crop, colours, traits, OCR, embeddings, segmentation, structured description; results go through the internal API.
   - It powers **the killer feature**: a personal time estimate for puzzles nobody has solved yet.
   - It also powers image traits in the skill map, look-alikes for duplicate checks, and later public surfaces.
4. **Display behind one feature flag** (`INSIGHTS_PUBLIC`, admins always see it), removed once the estimate gates pass
   and the image analysis apart from `describe` is complete (Jan 2026-10-23: launch before `describe`, which fills
   its tags in the ~6 days after).

The page as built (routes, flag, sections, free/member split, budget): [`page.md`](./page.md). The personal time
estimates as built: [`estimates.md`](./estimates.md).

Visual reference: [`prototype.html`](./prototype.html), the interactive prototype with sample data plus the plan. Open it in a browser.
The "Prototype" tab is the page design; the "Plan & research" tab holds the same decisions as this file, with
mock-ups, among them the estimate display options (Plan tab → "Image prediction").

## 2. Research behind it (all in this folder)

| File | What |
|---|---|
| [`prediction-research.md`](./prediction-research.md) | The age bias, the pace history, image → difficulty / time pilot, piece counts, personal taste, train-or-not, side-by-side storage, **calibrated windows** (§12), realtime |
| [`image-taxonomy.md`](./image-taxonomy.md) | What to extract from box images, the taxonomy (~310 keys), analyzer payload schemas (§11), run order, the description-model bake-off (§18), **measured resources per stage + the M3 Max / M1 Air split** (§19) |
| [`estimates.md`](./estimates.md) | The personal time estimates as built (Phase 6c, option C): who sees what, which number shows where (the one rule), rounding, the one-line list display, statement costs |
| [`image-estimate-gates.md`](./image-estimate-gates.md) | How the app measures the picture-based estimates on its stored predictions (`myspeedpuzzling:evaluate-image-estimates`) and the gates that decide where they may be shown (`ImageEstimateGate`) |
| [`finished-photos.md`](./finished-photos.md) | Players' photos of the finished puzzle as a second view for the picture estimate: the `finished_photo` analyzer (registration, straightening, measures, embeddings), privacy rules, storage, measurements |
| [`vision-analysis.md`](./vision-analysis.md) | The vision proof of concept: finding/cropping the artwork (mIoU 0.915), the first difficulty signals |
| [`data-opportunities.md`](./data-opportunities.md) | The data-warehouse brainstorm (puzzle science, handicap, year in review, brand reports, retention) - follow-up features, not this plan |
| [`prototype.html`](./prototype.html) | Prototype + plan (artifact export) |

Prerequisites already live: player time zone (#254, `docs/features/player-timezone.md`), image-analysis internal API with 14 analyzers, claims and bulk read (#255, `docs/features/internal-api.md` § "Puzzle image analysis").

## 3. Decisions (Jan, 2026-10-18)

### Product
| Topic | Decision |
|---|---|
| Membership | One membership, always. Insights is its headline perk. |
| Free tier | Real "wow" value: the puzzler profile card (7 axes), findings with real names, close calls, form for **6 months**, the median histogram, results per weekday. Period 3/6 months + pieces filters. Shelf picks are members only. |
| Members | Everything that uses insights data: full skill map with ranges and drill-down, expected vs actual, rating opportunities, re-solve radar, learning curve, outliers explained, habits, consistency, pairs & teams, forecast, shelf picks, **exact date ranges and every filter**, image traits. |
| Visibility | Insights and the puzzler card are **self-only**: nobody else sees them, nothing to share publicly. Private players see their own. |
| First-try predictions | Improve the model, but never discouraging: beating a prediction can mean a fast puzzler, not a wrong model. Wording frames it as "your margin". |
| Path onto the ladder | Only where useful (e.g. when the player is within reach). |
| Pairs & teams | Yes: who you perform best with, and how (synergy). Like with like: pair/team results never mix into solo sections. |
| Close calls | Free. |
| Performance | **≤ 1 s page load, hard maximum**; faster is better; anything heavier loads asynchronously (lazy frames). Measured plan: ~60-80 ms server for the first response. |
| Profile card | The 7 axes are fine for v1. |
| Rating snapshots | **Keep `player_rating_snapshot`** (one row per player × piece count × day, the day's last values; prepared for a rating-progress display, read today only by the digest). It becomes the "your rating over time" chart. |
| Difficulty fix | Wanted: difficulty from the solver's pace at the time of the solve. Puzzles moving between tiers is fine (≈ 46 % of well-rated puzzles move one tier easier). |
| Prediction windows | Show the calibrated **50 % "likely" window** as *the* window (≈ 7 min on a 60-min 500, half of solves land inside); the 80 % range in the detail sheet. Window ends rounded to the nearest minute when the window is < 20 min wide (5 / 10 min above), **never outward**. Wording: "Likely 57-64 min · half of solves land inside"; a hit: "Inside your likely window - only half of solves do"; a miss: side + margin ("3 min faster than likely"), never failure. |
| Estimates | **Option C - your time first** ("≈ 3 h for you", certainty bar, tier chip secondary), **mobile first and compact**: one line on list rows (time + tiny certainty bar + dashed "Hard?" chip, a sheet on tap), the full card on the puzzle page. Personal through the time; the tier stays a property of the puzzle (difficulty is skill-invariant: a hard puzzle is 1.49-1.54× an easy one in every speed band). Estimated = dashed outline + "?" + "estimated" in the accessible name; measured = solid. Members only, like today's difficulty. |
| Picture-only estimates | **The killer feature**: a personal time estimate for puzzles nobody has solved yet, from the image analysis, with honest reliability and as precise a window as the data allows. Members, clearly marked as estimated; switched on once the out-of-sample gates pass on the backfill. |
| Predicted difficulty | For unrated puzzles members only, through the existing difficulty mechanisms. |
| Display rollout | All new display (Insights page, estimates, improvement and rating charts) behind one flag `INSIGHTS_PUBLIC`; admins always see it; the flag is removed once the gates pass and every analyzer but `describe` is complete (Jan 2026-10-23: `describe` runs last and fills its tags after the launch). The prediction fix (Phase 3) improves numbers already shown and ships unflagged. |

### Image intelligence
| Topic | Decision |
|---|---|
| Models | **Self-hosted open models with licences allowing commercial use only** (Apache-2.0 / MIT). Excluded for licences: SegFormer, MobileCLIP, jina-clip, DINOv3, Llama 3.2 Vision, Surya/LFM, Moondream 3, ADE20K-trained models. |
| Machines | Jan's **M3 Max 36 GB** + a **MacBook Air M1 16 GB** running nonstop. Claims (leases) guarantee two machines never take the same image for the same analyzer. |
| Where results live | Originals stay in S3. Every result goes through the internal API into the production database = the master copy. The Macs keep only a temporary work state and read results back through the bulk endpoint (not a direct DB connection). |
| Backfill time | About a week is fine (measured plan: ≈ 5.5 days with both Macs). The backfill runs in **separate Claude Code sessions on the Macs** and never blocks the implementation; only the flag removal waits for it. |
| Stored, not shown | Nothing from the image analysis appears on puzzle pages, search, filters or SEO on day one. It feeds Insights and the estimates. Public surfaces (tags, theme pages, "hard because…", franchise pages) come later, one at a time. |
| Description model | **Qwen3.5-9B 4-bit MLX** (`mlx-community/Qwen3.5-9B-MLX-4bit`, Apache-2.0), JSON-schema constrained decoding, prompt v2 + catalogue title + OCR lines as context, crop at 512 px, unbatched (`image-taxonomy.md` §18). |
| Machine split | **M3 Max:** `artwork` (~1 day) then `describe` (~5 days). **M1 Air:** everything else (`ocr`, `barcode`, `vision_labels`, `embed_siglip2_full` + `embed_siglip2`, `embed_dinov2`, `segmentation`, `colors`, `traits`; ~2.4 days), then helps with `describe`. Apple Vision never shares a machine with a GPU-saturating model (0.13 → 14.8 s/img). (`image-taxonomy.md` §19) |
| Artwork crops | The crop is **internal analysis data** first. The pipeline never changes a puzzle picture directly. A cleaner catalogue picture is always a **change request** carrying the proposed image plus `source` (image pipeline), `tags` (`artwork_crop`), `confidence` and the model version. **Very high confidence** (a threshold *earned* on human-reviewed crops: ≥ 99 % precision, lower bound) is auto-approved by **Claude Code through the internal API**. The middle band goes to moderators (before/after grid), optionally after Claude Code looked at it first. Low confidence proposes nothing. The **original picture is always kept** (an approved image change never deletes the old file; plus an `originals/` archive copy), and "Restore original" is one click. Automate as much as possible, but reliability, precision and backups come first. |
| Cut style | May be read from players' finished-puzzle photos where one exists (often not): internal data and a training feature, never shown as fact. |
| Corrections | Tags are corrected by admins and moderators only (puzzle-moderation access). |
| Box-text mismatches | Never automatic change proposals (boxes say 500 when the puzzle really has ~550): a moderator list with **Dismiss / Ignore**, remembered so a dismissed mismatch never returns. |
| Franchise pages | Yes, later, if they help SEO, with a friendly design (not in this plan). |

### Follow-up features (decided, not in this plan)
Handicap (computed and stored apart, never shown; feeds a later update of the skill / rating formulas) · year in
review (Wrapped / Replay style, self-only, opt-in share card; ship by mid-December) · brand reports (aggregates only) ·
add-form extras behind a collapsed "More optional details" toggle · aggregate logging (page views, searches with no
result, unknown scans; no player id) · yearly "State of Speed Puzzling" report (later). Details: `data-opportunities.md`.

## 4. Key facts the design rests on (production copy, 2026-10)

- It is a 500-piece world: 77.7 % of solo solves. ≤300 is 16.6 %, 1000 is 4.2 %. ~1,000 active members (82 % have 25+ solo results). Free players have a median of 9 results; members 163.
- **Age bias:** difficulty indices divide old times by the solver's *current* baseline. Inflation is +1.8 % (< 6 months) … +24 % (3+ years). So statistical first-try predictions run **9.9 % slow**.
  - As-of baselines: error 0.126 → 0.092 (MAE of log ratio), bias gone.
- First tries predicted today: 66.3 %. 15.5 % have a baseline but an unrated puzzle (the image's target).
  - 500 pc: 80.5 % predicted. ≤300: 27 %. 1000: 16 %. 1500+ and odd sizes: ~0 %.
- Image → difficulty (pilot, 239 labelled puzzles): ridge on SigLIP 2 + DINOv2 + pieces = Spearman 0.72 CV. 0.65 on a random test, 0.74 newer-from-older. Expected R² ≈ 0.55 at 2.5-5k labelled puzzles (4,979 have ≥ 5 solves).
  - The image prior helps at 0-2 solves (0.172 → 0.127 at k = 0); one real solve beats it.
  - Shrinkage to the image prior: 0.095 at 100 % coverage vs today's 0.126 at 91 %.
- Personal taste: small (1-2.5 % of the residual on later solves). Shrunk n/(n+100) × 0.5 it gains 4 % overall; unshrunk it hurts. Improvement drift matters more (0.160 → 0.137).
- Windows today:
  - First try: 29 % hit, 16 % wide; the bias explains the misses.
  - Repeat: 76 % hit, 30 % wide; too easy.
  - Calibrated 50 %: 51 % hit, 13 % median width (11 % for the most consistent quarter). 80 %: 81 % hit, 26 %.
- Performance (measured): solo results statement 7-9 ms for the heaviest player; all aggregates in PHP 8-14 ms; close calls 16-51 ms (worst 98); rating what-ifs ~15-25 ms with batch-written sorted arrays (270-490 ms if computed on read).

## 5. Architecture in one picture

```
Mac M3 Max ─┐  tools/puzzle-vision (Python, uv)       production (Symfony)
Mac M1 Air ─┤  claims → download original → model ──→  /internal-api/puzzle-image-*  → puzzle_image_analysis
            │  derive (zeroshot, neighbours, tags,      /internal-api/puzzle-difficulty-labels (aggregates, as-of)
            │  difficulty prior = nightly ridge) ───→  /internal-api/puzzle-difficulty-estimates → puzzle_difficulty_estimate
            └  crop proposals (+ Claude Code review) →  /internal-api/puzzle-change-requests (image, tags, confidence)

15-min batch (recalculate-puzzle-intelligence): pace history → as-of difficulty → shrinkage (prior: brand/pieces, later image)
  → window calibration tables → player_rating_entry + per-puzzle time arrays + run marker
On write: live prediction (statistical + image, side by side in solving_time_prediction), calibrated window
On read (per player, Redis cache keyed by batch generation + result fingerprint): Insights sections, what-ifs, forecasts, estimates
Display: everything new behind INSIGHTS_PUBLIC (admins always)
```

## 6. Principles (carry into every phase)

- **You against yourself first.** Every split is your time ÷ puzzle median, divided by your form at that moment. That removes puzzle difficulty and improvement.
- **Every section answers one question** in the player's words, with a one-sentence takeaway above the chart; question chips jump to sections.
- **Show how sure we are.** Ranges, sample sizes, "not sure yet". Nothing is a weak spot on fewer than ~10 results; small samples are shrunk toward your usual (n/(n+8)); strength / weak spot only when the whole range is beyond ±1.5 %.
- **Every chart drills down** to the results behind it.
- **Turn insight into a next step**: re-solve this, try this from your shelf, mark these first tries; links into the Puzzle Picker and puzzle pages.
- **Neutral wording** ("weak spot", "slower than your usual"; never blame), the same rules as "needs verification".
- **URL holds the filters** (like Compare); members-only values stripped server-side.
- **Never leak secret puzzles**: no estimate, no training row, no look-alike, no aggregate for anything with `hide_until` / `hide_image_until` in the future.
- **FrankenPHP worker mode**: caches in Redis pools, never service properties (`ResetInterface` otherwise).
