# Image-based difficulty and time prediction - research + pilot

Status: research only, 2026-10-18; nothing implemented. Data: local production copy (solves to 2026-10-11, 523k times, 41.3k puzzles), read only. Images: only the 448 puzzles embedded by earlier pilots. Every number came from scripts in the local, git-excluded lab `.claude/worktrees/insights-prediction-lab/` (`scripts/`, outputs in `out/`). Jan's decisions on the open questions are in `README.md` (this folder). No players named. Error = mean |log(actual/predicted)|, 0.10 ≈ 10 %.

## TL;DR + recommendation
1. **Phase 0, now, no images:** event-driven pace history (§4) → as-of difficulty indices; a drift term for each player's improvement; predictions for puzzles with 1-4 solves by shrinkage towards a prior (τ ≈ 1); a 500-piece-scaled pace for players without a baseline at a size; `solving_time_prediction` table; model version 2; aggregate as-of label endpoint.
2. **Phase 1, after the embedding backfill:** nightly ridge model (SigLIP 2 + DINOv2 + pieces) as a "derive" step on the Mac; out-of-fold difficulty estimate per puzzle, used as the shrinkage prior; both predictions stored side by side for every solve (§5).
3. **Phase 2:** members see "Estimated difficulty / estimated time" for unrated puzzles, with ranges.
4. **Phase 3:** taste-robust personal term (shrunk), explainer, per-size checks; fine-tuning only if ridge plateaus.

## 1. Today's system (from the code)
- Baseline: weighted median of first-attempt solo times per piece count (≥ 5 puzzles), interpolated/extrapolated across sizes.
- Difficulty index = seconds ÷ the solver's **current** baseline; puzzle difficulty = median of ≥ 5 indices.
- 6 tiers, cuts 0.75/0.90/1.10/1.25/1.45 (`DifficultyTier::fromScore`; README still says 7).
- < 5 indices: no prediction (`predictable=false`), no fallback.
- Local copy has stored predictions for only ~108k solves → everything rebuilt as of each solve (`asof.py`).

## 2. Findings

### Q1 Opportunity
Catalogue (40,622 visible puzzles) by distinct solo solvers: 0 = 24.0 %, 1-2 = 50.6 %, 3-4 = 9.5 %, 5-9 = 7.1 %, 10-19 = 3.7 %, 20-49 = 2.6 %, 50+ = 2.4 %. 15.2 % rated, carrying 89.1 % of solves. 17.8 % of puzzles added 12-24 months ago reached 5 solvers (median 270 days). ~59 new puzzles a day.

First attempts, last 12 months (149,279): predicted today 66.3 %; **baseline but puzzle unrated 15.5 % (the image's target)**; no baseline 10.0 %; neither 8.2 %. Repeats (36 % of usable solves) get personal predictions.

Error by prior indices k (first attempts with an as-of baseline):

| Prediction | k=0 | 1-2 | 3-4 | 5-9 | 10-19 | 20-49 | 50+ |
|---|---|---|---|---|---|---|---|
| baseline × brand+pieces | 0.189 | 0.171 | 0.166 | 0.161 | 0.157 | 0.155 | 0.156 |
| as-of rule (k ≥ 5 today) | - | (0.140) | (0.114) | 0.103 | 0.096 | 0.092 | 0.091 |
| shrink τ = 1 | 0.189 | 0.131 | 0.111 | 0.101 | 0.095 | 0.091 | 0.091 |

Floor: 500 pc, k ≥ 20 - baseline explains 61 % of log-time variance, baseline × difficulty 86 %, residual SD 0.120.

**Index bias:** as-of indices unbiased (+0.001, error 0.093); current-baseline indices **−9.9 %, error 0.133**. Inflation by solve age: < 6 mo +1.8 %, 6-12 +5.6 %, 1-2 y +9.5 %, 2-3 y +12.7 %, 3+ y +24 %. On 2,847 puzzles with ≥ 10 indices stored scores are a median 8.4 % above as-of; 46 % would move one tier easier (rank Spearman 0.97).

**Timing:** 76 % of "nobody solved it yet" solves are logged within 10 min of the puzzle's creation → value of k=0 estimates is the catalogue, the next solvers and checks.

Unsolved list items: collections 31,755 (39 % unrated, 8,615 distinct unrated puzzles); wishlists 8,106 (16 %, 1,166).

### Piece-count groups (Jan #2)

| Group | Puzzles (rated) | Share of solves | Own baseline | Predicted today | Baseline but cold |
|---|---|---|---|---|---|
| ≤300 (205 sizes) | 10,468 (13.1 %) | 16.6 % | 49.6 % | 26.7 % | 22.9 % |
| 500 | 14,615 (28.1 %) | 77.7 % | 92.2 % | 80.5 % | 11.7 % |
| 1000 | 11,973 (5.3 %) | 4.2 % | 64.5 % | 15.6 % | 48.9 % |
| 1500+ | 1,378 (1.7 %) | 0.3 % | 11.7 % | 0 % | 11.7 % |
| odd (550, 750, 350, 504…) | 2,188 (6.4 %) | 1.2 % | 9.0 % | 0.2 % | 8.8 % |

Shrinkage error per group: ≤300 0.124, 500 0.101, 1000 0.179, 1500+ 0.243, odd 0.177. Time ∝ pieces^1.35 (≤300 1.33, 1000 1.44, 1500+ 1.49, odd 1.62). For 1000 pc a 500-scaled baseline beats the player's own thin one (0.129 vs 0.138); for ≤300 own is better (0.104 vs 0.120). Same artwork 500 → 1000: Spearman 0.88 (14 pairs), error 0.088 vs 0.171 for the mean; 300 → 500: 0.54 (27 pairs) → difficulty is largely a property of the artwork. Image per size: sample is 195 × 500, 8 × ≤300, 1 × 1000 - inconclusive (0.35 on the 9 others); measure on the backfill per group.

### Q2 Personal taste (Jan #5)
Player × size + puzzle + low-rank taste (ALS). Random holdout: taste −6.8 % of leftover error; later solves (train < 2026-04-11), no drift: 2.5 %. **Drift dominates:** error 0.160 → 0.137. Taste on top of drift, later solves, 3,148 players:

| Player training solves | Raw (λ=1) | Taste-robust (shrink n/(n+100), ×0.5) |
|---|---|---|
| all | −9.5 % (worse) | **+4.0 %** |
| 5-19 | −21.3 % | −0.5 % |
| 20-49 | −10.9 % | +0.8 % |
| 50-99 | −0.8 % | +4.8 % |
| 100-299 | +0.7 % | +11.3 % |
| 300+ | −21.7 % | +5.3 % |

(Settings picked on the test period - slightly optimistic.) Player × brand: −0.6 … +0.2 %. Design: optional, shrunk, damped - robust to taste, never dependent on it.

### Q3 Image → difficulty
Labels = as-of medians (reliability 0.96 at ≥ 10 solves). Pieces + brand, whole catalogue (4,743): Spearman 0.285, R² 0.09; unseen brands 0.01.

| Features (ridge unless noted), 239 labelled | Spearman CV | brands held out | R² |
|---|---|---|---|
| pieces + brand | 0.18 | 0.17 | 0.04 |
| 13 simple traits | 0.45 | 0.44 | 0.19 |
| 44 zero-shot scores | 0.59 | 0.56 | 0.30 |
| DINOv2 | 0.64 | 0.64 | 0.38 |
| SigLIP 2 | 0.69 | 0.70 | 0.41 |
| SigLIP 2 + DINOv2 + brand + pieces | 0.71 | 0.73 | 0.45 |
| **SigLIP 2 + DINOv2 + pieces (pure image)** | **0.72** | - | - |
| kNN-10 | 0.53 | - | - |
| gradient boosting | 0.50 | - | - |

Harder tests: stratified → 79 random rated: 0.65 (R² 0.25; pieces+brand 0.23); older → newer: 0.74 (R² 0.55). Tiers on the random 79: 60 % exact / 90 % ±1 (always "Average": 46 % / 87 %). Learning curve R² 0.02/0.17/0.30/0.35/0.36 at 25/50/100/150/175 → extrapolated 0.50 at 1k, 0.55 at 2.5k, 0.57 at 5k (20× extrapolation, mostly flat art). Labelled puzzles with image: ≥1 22,374, ≥3 7,616, ≥5 4,979, ≥10 2,846.

**Label quality (Jan #4)**, test = 115 puzzles ≥ 20 solves: train ≥1 0.70 (weighted 0.72), ≥3 **0.74**, ≥5 0.72, ≥10 0.69, ≥20 0.62; at equal training size 100 the threshold barely matters (0.62-0.63) → train on ≥ 3 (or ≥ 1 weighted n/(n+10)), test on well-measured.

Value as prior (first k solves): none 0.172/0.090/0.069/0.051 at k = 0/1/2/5; image 0.127/0.086/0.067/0.049.

### Q4 Image vs today on the same solves (Jan #1) - 12,608 first attempts on 239 puzzles

| Prediction | k=0 | 1-2 | 3-4 | 5-9 | 10-19 | 20-49 | 50+ | all | coverage |
|---|---|---|---|---|---|---|---|---|---|
| today, production (bias −0.092) | - | - | - | 0.151 | 0.136 | 0.128 | 0.118 | 0.126 | 91 % |
| today, corrected as-of | - | - | - | 0.104 | 0.093 | 0.090 | 0.090 | 0.092 | 91 % |
| image + pieces only | 0.164 | 0.161 | 0.165 | 0.162 | 0.153 | 0.157 | 0.164 | 0.161 | 100 % |
| shrink to brand+pieces | 0.216 | 0.133 | 0.113 | 0.105 | 0.091 | 0.089 | 0.090 | 0.096 | 100 % |
| **shrink to image prior** | **0.173** | **0.124** | **0.110** | 0.103 | 0.092 | 0.089 | 0.090 | **0.095** | 100 % |

Each puzzle as unseen: per-solve error none 0.219 / image 0.170 / oracle 0.091; within 20 %: 49 % → 62 % (oracle 89 %); honest 80 % range around an image estimate −27 % … +22 %. Images predict taste factors weakly (R² −0.01/0.15/0.14/0.02); per-player slopes on image features +3-4 %, simple traits ≤ 0.5 %.

## 3. Train or not
| Rung | What | Accuracy | Verdict |
|---|---|---|---|
| a. no training | kNN over embeddings | 0.53 | "similar puzzles" only |
| **b. light training** | ridge nightly, seconds on CPU, ~2.2k weights | 0.70-0.75, R² ≈ 0.55 expected | **recommended** |
| c. fine-tuning | LoRA on SigLIP 2 / DINOv2 | +0.02-0.05 expected, re-embed per change | only if b plateaus |
| d. LLM / VLM | traits, "why it's hard", pairwise | not a regressor | explanations only |

Rung b = the Mac pipeline's derive step: reads aggregate as-of labels via the planned `GET /internal-api/puzzle-difficulties`, pushes a `difficulty_prior` analyzer per puzzle (optionally the weights so PHP can score a new embedding).

## 4. Per-player pace history (Jan's request) - `q11_pace_snapshots.py`
Exists today: `player_baseline` (current only, 17.5k rows); `player_skill_history` (monthly since 2026-03, 105k); `player_rating_snapshot` (daily full copy of every player × size, 2.7M rows / 575 MB, mostly unchanged values, nothing reads it). None goes back before 2026-03, none records which solve moved the pace.

**Recommendation: event-driven `player_pace_history`** - one row per player × piece count per change (each qualifying first attempt, optionally collapsed per day): valid-from (solved, tracked), triggering `time_id`, as-of baseline seconds + type, qualifying count, drift term (mean residual of last 10), step vs previous row. Full backfill 224,928 rows (179,045 collapsed per day), ~30 MB; ongoing ~322 changes/day ≈ 117k rows/year (a full daily snapshot: 2.3M/year locally, 6.4M in production). Between events only time decay changes - ignorable or recomputed at lookup.

One table serves: (1) as-of difficulty indices (row valid just before the solve - fixes the 9.9 % bias); (2) the reconstructor (live and reconstructed agree); (3) the improvement chart. Backfill: `asof.py` rebuilds every as-of baseline in ~10 s; a PHP command reusing `PlayerBaselineCalculator::weightedBaselineSeconds()` - minutes; fully reproducible.

Insights uses: improvement curve per size; "your biggest jumps" (steps > 3 % in 8 % of solves, p99 12 %, linked to solve + puzzle); plateau detection; comparison with the newcomer curve - 500 pc median change vs first baseline after 25/50/100/150/300 first attempts: −4.5/−7.6/−12.2/−15.0/−21.0 % (measured from the 6th solve). Consider thinning/retiring `player_rating_snapshot` afterwards.

## 5. Storing both predictions side by side (Jan #3)
- Existing `puzzle_solving_time` prediction columns stay **the shown prediction** (UI + API unchanged).
- New `solving_time_prediction`: `time_id` (cascade), `predictor` (statistical / image / blend), `model_version`, `source` (live / reconstructed), `predictable`, predicted seconds + range, difficulty used, `difficulty_source` (solves / shrunk / image), `k_indices`, `prior_log`, `computed_at`; unique (time_id, predictor, model_version). ~290k first attempts per predictor-version, ~30-50 MB each.
- New `puzzle_difficulty_estimate`: puzzle, model, version, log difficulty, 80 % interval, out-of-fold flag, training size, computed_at (current value only).
- Rules: compare on `live` rows computed at the same moment; reconstructed image rows use out-of-fold priors only; solves logged with a brand-new puzzle get image `predictable=false` live and are reconstructed later; written in the existing backfill pattern, no domain events.

## 6. Real-time
Precomputed: image prior, `sum_log_indices`, pace history, optional taste vectors. Per request: PHP arithmetic (µs), no extra queries when riding on the difficulty join, bulk-safe. CPU timing (M3 Max CPU): SigLIP 2 so400m 477 ms (4 threads) / 405 ms (8); DINOv2-base 43 / 41 ms (0.9 GB). A Mac pipeline polling every few minutes is enough; on-server embedding not needed. Nightly holdout check on newer puzzles; a drop > 0.05 blocks the push. Hidden puzzles (`hide_until` / `hide_image_until` in the future): no prior, no training row, no neighbour; priority in the claim queue on reveal.

## 7. Product uses, ranked
1. As-of indices + pace history + drift (−27 % error, no bias, improvement chart). 2. Shrinkage for 1-4 solves + 500-scaled pace for thin sizes (biggest gain at 1000 and ≤300). 3. "Estimated difficulty" for the 34.5k unrated puzzles. 4. "Your estimated time" on unsolved list items, Picker, marketplace (±25 %). 5. Soft input to pace checks. 6. Competition prep after reveal. 7. Similar puzzles. 8. Explainer from traits. 9. Manufacturer view. 10. Weak-trait training suggestions (not supported yet).
Don't: replace real-solve difficulty with image guesses, show estimates as fact / precise tiers, use unshrunk taste, give hidden puzzles priors.

## 8. Data enrichment
1. Artwork crops (unrated puzzles are mostly phone photos: 15-23 % flat art vs 53-63 % among rated). 2. As-of labels for the 22k puzzles with ≥ 1 solve. 3. Non-500 puzzles in training and evaluation. 4. Cut style: brand cut-family table + the 62,252 finished-puzzle photos (17,762 puzzles, 12,350 unrated), internal only. 5. Real piece count and finish from box text.

## 9. Risks
Small biased sample (honest today: R² 0.25 random set) → validate on the backfill with brands held out, newer vs older, per size group and image kind; label weighting; fix the age bias before training; Picker feedback loops; new players → community pace with wide range; licences (SigLIP 2, DINOv2, scikit-learn fine; avoid DINOv3, MobileCLIP, jina-clip-v2); leakage → out-of-fold priors only.

## 10. Gates for Phase 1/2
On newer puzzles, per image kind and size group: Spearman ≥ 0.6; ±1 tier ≥ 80 %; exact tier clearly above always-"Average" (46 %). Taste term only if ≥ 3 % on later solves and never worse for low-volume players.

## 11. Open questions for Jan (all decided 2026-10-18 - see README.md §3)
1. Ship the as-of fix although ~46 % of well-rated puzzles move one tier easier (incl. public lists)?
2. Estimates as a tier range or a single "Estimated" tier?
3. Low-confidence difficulty for 1-4 solves to members?
4. Thin or retire `player_rating_snapshot` once `player_pace_history` exists?
5. Read cut style from finished-puzzle photos (internal only)?
6. A few minutes' delay for a new puzzle's estimate fine?

## 12. Prediction windows (Jan: "a 30-minute window is too easy to hit") - `q12_windows.py`

### Today
| Window | n | Hit | Median width | Faster than window | Slower |
|---|---|---|---|---|---|
| First try, stored | 51,822 | **29 %** | 16 % | **66 %** | 5 % |
| First try, 500 pc | 46,188 | 29 % | 16 % (11 min on 68 min) | 67 % | 4 % |
| First try, ≤300 / 1000 | - | 36 % / 36 % | 17 % / 18 % | - | - |
| First try, −10 % bias removed | - | **50 %** | 16 % | 29 % | 20 % |
| Repeat, stored | 30,948 | **76 %** | **30 %** (13 min on 52 min) | 12 % | 12 % |

First-try window ≈ a 50 % window in the wrong place (the bias explains the misses). Repeat window ±15 % is hit 3 of 4 times - the "too easy" complaint.

### Calibrated windows
Centre: as-of baseline + shrinkage + drift. Width: player's own recent spread (shrunk to global) + 1/(k+1) puzzle uncertainty + size term (e² = 0.20 × player spread² + 0.049/(k+1) + size term). Asymmetric (standardised residual 10/50/90 % = −1.17 / −0.04 / +1.21). Bucket quantiles per player-consistency quartile × k bucket. Fit on 156k earlier solves, test on 68.9k later.

| Target | Hit (later) | Median width | p10-p90 | 60-min 500 pc |
|---|---|---|---|---|
| 50 % | 51.0 % | 13 % | 12-22 % | 7 min (7-10) |
| 80 % | 80.8 % | 26 % | 23-45 % | 15 min (13-20) |
| today first try | 29 % | 16 % | 10-22 % | 11 min |
| today repeat | 76 % | 30 % | - | 13 min |

| Group | 50 % hit / width | 80 % hit / width |
|---|---|---|
| most consistent 25 % | 52 % / **11 %** | 82 % / 21 % |
| 2nd quartile | 51 % / 12 % | 80 % / 24 % |
| 3rd quartile | 50 % / 14 % | 80 % / 28 % |
| least consistent 25 % | 50 % / 16 % | 81 % / 32 % |
| k = 0 | 54 % / 30 % | 82 % / 60 % |
| k = 1-4 | 56 % / 20 % | 85 % / 40 % |
| k = 5-19 | 53 % / 15 % | 83 % / 29 % |
| k ≥ 20 | 50 % / 12 % | 80 % / 24 % |
| ≤300 / 500 / 1000 | 50/52/53 % / 16/13/27 % | 80/81/83 % / 32/25/54 % |

(k/size rows from the first, unrefined fit.) Best case: consistent quartile on puzzles with ≥ 20 indices = 50 % window 11 % wide (≈ ±6 %). An image prior narrows the k = 0 case by roughly 20 %.
Repeats calibrated around Holt: today 75 % / 30 % → 50 % window 49 % / **11 %**, 80 % window 79 % / 22 %.

Rounding (50 % window): outward to 5/10 min → hit 76 %, width 26 % (wrecks it); nearest 5/10 min with ≥ 1 step → 51 % / 14 %; nearest 1 min → 51 % / 14 %.

### Recommendation
1. Show the 50 % "likely" window as THE window: "Likely 57-64 min - half of solves land inside this window". Hit: "Inside your likely window - only half of solves do". Miss: side + margin ("3 min faster than likely").
2. Detail sheet: 80 % range, what the width rests on (consistency, puzzle certainty, first try vs repeat), centre, the player's own hit rate so far as a calibration badge.
3. Rounding: window ends to the nearest minute when the window is < 20 min wide, else nearest 5 min (< 1 h) / 10 min; minimum one step; never outward. Point estimate keeps 5/10-min rounding.
4. First-try windows only after the as-of fix (no width works without it).
5. Recalibrate nightly from stored predictions; alert when a bucket drifts > 3 points from target.
6. Storage: `predicted_range_*` = the 50 % window + two columns for 80 %; the same bounds in `solving_time_prediction`.
