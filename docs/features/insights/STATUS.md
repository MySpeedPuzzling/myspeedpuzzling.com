# Insights - status

Living progress record for [`implementation-plan.md`](./implementation-plan.md). Update it as you go: per phase the
state, PR, merge and deploy, deviations from the plan (and why), follow-ups.

| Phase | State | PR | Merged | Deployed | Notes |
|---|---|---|---|---|---|
| Prerequisites | done | #254, #255 | 2026-10-18 | 2026-10-18 | time zone; image-analysis internal API (14 analyzers, claims, bulk read) |
| Research + plan | done | - | 2026-10-18 | - | README.md, implementation-plan.md, research files, prototype.html |
| 1 Production API for the pipeline | **done** | #258 (1a), #259 (1b), #260 (1c) | 2026-10-18 | 2026-10-18 | verified: 401 without token, `requires` filter (400 on bogus), change-request list 200, source backfill 897 internal_api / 2,790 player, picture grid behind login |
| 2 Mac pipeline | **done** | #261 | 2026-10-18 | 2026-10-18 | `solvedCount` live on the work list; `bench` / `verify --end-to-end` run in the M3 session (see deviations) |
| Backfill (M3 Max + M1 Air sessions) | **running, behind plan; M1 Air analyzers never started** | - | - | - | 2026-10-19 12:47 Prague: 43,253 eligible puzzles. `artwork` 13,753 (31.8 %), last stored 10:46 UTC, 79 claims held. Analysed per hour (by `analyzed_at`) 07-10 UTC: 2,015 / 1,548 / 1,620 / ~1,715 = **~1,700/h** → the remaining 29,500 take ≈ 17 h, **ETA artwork ≈ 2026-10-20 06:00 Prague**. The 09:24 "nothing stored for 2 h" was not a stall: the Mac analyses continuously and pushes in batches (count by `analyzed_at`, not `received_at`). **Stall flag: no other analyzer has ever stored a result** (colors, traits, ocr, barcode, vision_labels, embeddings, segmentation, box_text, tags, …) - the M1 Air session has not started or cannot push; asked Jan. No difficulty estimates pushed, gate record from 04:51 UTC (empty run before any estimate). Checked every 6 h by the orchestrator (`scratchpad/backfill-check.sh`) |
| 3 Prediction fix | **done** (backfill one-off running) | #263 (3a), #272 (3b), #273 (3c) | 3a: 2026-10-18, 3b + 3c: 2026-10-19 | 3a: 2026-10-18, 3b + 3c: 2026-10-19 | 3a: backfill on prod 244,985 rows / 4,235 players in 33 s; first as-of batch 22 s (was 16-18 s; re-check steady state); tiers 1-6 = 249 / 1,838 / 2,956 / 891 / 400 / 225, 6,559 rated. 3b: migration Version20261019025300 ran at boot; first prod fit 13.9 s (39,966 puzzle priors, 245,069 drift rows, 146,215 first-try / 90,117 repeat samples, tau 0.5; thin rules: up to 300 below 20 own first tries, 1000 below 50, odd sizes always, 1500+ too few samples); `--recompute-reconstructed` one-off started 05:15 Prague (7,528 players); the 3c deploy at 05:36 replaced the web container and killed it at 1,526 players - restarted 05:38, finished 06:32 (6,001 players, 282,548 times, 0 failed): 448,537 shown v3 reconstructed + 464,578 side rows. Batch on prod after 3b: 25.4-25.8 s (was 23 s after 3a + 4a) - over the plan budget, in docs/TODO.md. Lesson: a deploy kills a one-off run through `docker compose exec web`; crons fit 04:11 + calibration 04:51 in lily.srv (da7d296) |
| 4 Insights computation | done | #269 (4a), #270 (4c), #271 (4b) | 2026-10-19 | 2026-10-19 | 4a: first batch filled 310,209 rating entries + 2,687 time arrays (29 s first run); daily command 4.5 s, 3,808 profile cards, 6 constants; cron `recalculate-insights-daily` 04:41 in lily.srv (4a17f81). 4c: daily rerun 4.7 s, constants on as-of paces (pairs 1.296 → 1.200 first attempt, 1.173 → 1.091 other; teams of 3 1.316 / 1.257, 4+ 1.367 / 1.325). 4b: migration Version20261018230621 ran at boot; first snapshot 2026-10 (taken 2026-10-19 = mid-month state, not a 1st-of-month one): 2,908 players, 1.5 MB, 24.5 s; cron `snapshot-player-insights` 05:15 on the 1st in lily.srv (6934ba3) |
| 5 Image intelligence on the server | **done** | #265 (5a), #267 (5b), #268 (5c), #275 (5d) | 2026-10-18/19 | 2026-10-18/19 | 5a: crons 07:40/07:45 (lily.srv 0953a4b), ran clean. 5b: labels endpoint live, model record empty until the first derive. 5c: Python only, 187 tests. 5d: migration Version20261019043839 at boot; `evaluate-image-estimates` ran on prod 2026-10-19 (empty record - no estimates pushed yet, every gate closed); its daily cron comes with 6c |
| 6 Display behind INSIGHTS_PUBLIC | **done** (admins only until §8) | #274 (6a), #277 (6c), #278 (6b) | 2026-10-19 | 2026-10-19 | `INSIGHTS_PUBLIC=0` on prod: guests 302 to login, signed-in non-admins 404 (tested). Page first response 10 statements; lazy frames 2-14 statements, ~25-80 ms; estimates on the puzzle page + 5 list kinds (flag off = byte-identical HTML and statement counts); cron `evaluate-image-estimates` 08:23 in lily.srv (1a3fb95) |
| Launch (flag removed) | not started | | | | waits for the backfill + gates |

## Orchestration

Orchestrator session started 2026-10-18 (prompt: implementation-plan.md §9.1). Every stream works in its own worktree
`.claude/worktrees/insights-<stream>` branched from `origin/main`; the main checkout is Jan's. Tests run in a one-off
`docker run --rm` of the base image on the compose network with an own test DB `speedpuzzling_insights<stream>_test`
(memory "isolated PR testing"); CI is the full gate. At most ~3 subagents at once.

### Phase 1 + 2 stream plan (2026-10-18)

| Stream | Branch / worktree | Scope (plan §) | Migration | Tests |
|---|---|---|---|---|
| **1a** claims `requires` + `embed_siglip2_full` | `insights/phase-1a` | §1.1, §1.2: `ImageAnalyzer::EmbedSiglip2Full`; `requires` on `POST /internal-api/puzzle-image-claims` (body list) and `GET /internal-api/puzzle-images` (`requires=a,b`) = only puzzles whose *current* fingerprint has a result of every required analyzer (any version); docs `internal-api.md` + OpenAPI + `image-taxonomy.md` §11 | none expected (analyzer is a string column) | claim/list tests extended: requires respected, stale fingerprint does not count, unknown analyzer = 422 |
| **1b** change requests from the pipeline (API + storage) | `insights/phase-1b` | §1.3 API part + §1.4 storage part: nullable columns `source` (backfilled player / internal_api), `tags` jsonb, `confidence`, `model_version`, `source_image_fingerprint`, `original_archive_path`; `POST /internal-api/puzzle-change-requests` with base64 `image` (same validation/storage as the web proposed photo) + metadata; 409 `already_proposed` (puzzle + fingerprint + tag, any status), 409 stale fingerprint, 404 secret; `GET /internal-api/puzzle-change-requests` (filters, keyset); `decisionNote` on approve/reject (stored on the moderation decision); archive copy `originals/<puzzleId>/<fingerprint>.<ext>` before an `image_pipeline` approval (failure = 409 + warning); test that an approved image change keeps the old object | yes - generated against a scratch DB from committed migrations | API tests per rule, archive test, old-object-kept test |
| **1c** moderator web (stacked on 1b) | `insights/phase-1c` | §1.3 web part + §1.4 restore: review queue filter by source/tag, before/after grid for `artwork_crop` (keyboard-friendly, mobile usable, `PUZZLE_MODERATION_ACCESS`), "Restore original picture" on `/admin/puzzles/{id}/history` via `PuzzleRecordUpdater` (moderator direct edit, new file name, decision recorded); curl walkthrough in `internal-api.md` (§1.5); 6 locales | only if restore needs a column | controller tests, restore handler test |
| **2** Mac pipeline | `insights/phase-2` | §2.1-2.7: `tools/puzzle-vision/` (uv, pinned lock), API client (claims with `requires`, batch store, release, bulk read, change requests), workstate, model lock, download, stages, derive (box_text, zeroshot, neighbours, tags; difficulty_prior stub for Phase 5), crop proposals, profiles, launchd templates, runbook `image-pipeline.md`, 6 skills in `.claude/skills/` | - | `uv run pytest` on generated tiny images + a mocked HTTP API (httpx MockTransport); **no model downloads or inference in this session** |

### Phase 3 stream plan (2026-10-18)

| Stream | Branch | Scope (plan §) | Migration | Tests |
|---|---|---|---|---|
| **3a** pace history + as-of difficulty | `insights/phase-3a` | §3.1, §3.2: `player_pace_history` (entity, index `(player_id, pieces_count, valid_from)`), incremental writer in the 15-min batch via `PlayerBaselineCalculator::weightedBaselineSeconds()`, `myspeedpuzzling:backfill-pace-history` → message → idempotent keyset handler; difficulty index = time ÷ pace row valid just before the solve; `sum_log_indices` + `index_count` on `puzzle_difficulty`; reconstructor uses the same lookup; puzzle-intelligence README fixes (snapshots read by the digest, 6 tiers) | yes | pace writer/backfill idempotency, as-of lookup (ties on `tracked_at`), live == reconstructed guards |
| **3b** prediction model v2 (stacked on 3a) | `insights/phase-3b` | §3.3-3.5: drift, shrinkage behind `DifficultyPrior` (`BrandPiecesPrior`), thin sizes (500-scaled pace per size group when it wins on a time split), calibrated 50/80 % windows (bucket table refit nightly, small JSON row), repeats around Holt, `predicted_range80_*` columns, `solving_time_prediction` side by side (live in savepoint + reconstructed), `MODEL_VERSION` 2, `myspeedpuzzling:report-prediction-calibration` (daily, warning on > 3-point drift), a time-split evaluation command for §3.6 | yes | calculator unit tests, calibration, guards |
| **3c** window display (stacked on 3b) | `insights/phase-3c` | §3.4 display rules: rounding (nearest minute < 20 min wide, else 5 / 10 min, ≥ one step, never outward), "Likely 57-64 min · half of solves land inside" + hit / miss wording on every surface (recap, add-time pace check, result detail, Picker, collections display mode), API v1 additive `predicted_range80_*` fields, 6 locales | no | formatter unit tests, surface tests |

3a can merge alone (tier moves approved by Jan). 3b and 3c merge back to back (the new wording promises calibrated windows).
§3.6 acceptance numbers are measured on a scratch copy of the dev DB (production copy) with the evaluation command, never on `speedpuzzling` itself.

### Phase 4 + 5 stream plan (2026-10-18)

Phases 4 and 5 are split so the parts that do not need the Phase 3 model start at once (deviation from the strict
3 → 4 → 5 order, decided for throughput; nothing visible changes).

| Stream | Branch | Scope (plan §) | Depends on | Migration |
|---|---|---|---|---|
| **4a** batch additions | `insights/phase-4a` | §4.1: run marker / generation, `player_rating_entry` (changed rows only), per-puzzle sorted time arrays, pair/team coordination constants, daily profile-card percentiles (7 axes), distinct solvers per puzzle | - | yes |
| **4b** read models + core builders | `insights/phase-4b` | §4.2 (`GetInsightsSoloResults`, `GetInsightsGroupResults`, `GetCloseCalls` + parity, hydrate), §4.3 `InsightsCriteria`, `InsightsBuilder` (form, skill map, histogram, expected vs actual, learning curve, habits, consistency, accuracy, findings, profile card), §4.5 query budget + performance guard | 3a, 3b, 4a | maybe |
| **4c** rating + extras builders, cache | `insights/phase-4c` | §4.3 `RatingOpportunities`, `RatingForecast`, `ResolveRadar`, `PairsTeamsSynergy`, `ImprovementCurve`, `RatingOverTime`, `ShelfPicks`; §4.4 cache `insights_cache` + monthly snapshot | 4a, 4b | yes (snapshot) |
| **5a** tags + box-text list | `insights/phase-5a` | §5.5 taxonomy registry (6 locales), `puzzle_tag` from the `tags` analyzer (cron), `puzzle_tag_correction` (internal API first); §5.6 `/admin/box-text-mismatches` with Dismiss / Ignore, `box_text_mismatch_decision` | Phase 2 payloads | yes |
| **5b** estimates storage + labels | `insights/phase-5b` | §5.1 labels endpoint (as-of, aggregates only), §5.2 `puzzle_difficulty_estimate` + `PUT` batch + model record endpoint | 3a | yes |
| **5c** Mac `difficulty_prior` derive | `insights/phase-5c` | §5.3 ridge, out-of-fold, validation, push guard (Python, fakes only) | 5b | - |
| **5d** image prior in the app + gates | `insights/phase-5d` | §5.4 `ImageEstimatePrior`, image / blend rows in `solving_time_prediction`, `myspeedpuzzling:evaluate-image-estimates`, `ImageEstimateGate`; §5.8 canary | 3b, 5b | maybe |

Merge order: 1a → 1b → 1c → 2 (2 is built in parallel against the documented contract, merged after 1a/1b are on main).
Phase 3 starts as soon as a subagent slot frees (1a is small).

### Phase 6 stream plan (2026-10-19)

| Stream | Branch | Scope (plan §) | Depends on | Migration |
|---|---|---|---|---|
| **6a** flag + page + first response | `insights/phase-6a` | §6.0 flag `INSIGHTS_PUBLIC` (`InsightsFeature::isVisibleTo()`, Twig `insights_visible()`, `feature_flags.md`); §6.1 route `insights`, `PlayerHeaderTab::Insights` (own profile only), menu row, "See why" links (recap + own result rows); Live Component with URL filters (`InsightsCriteria`); sections 1-8, 12 (learning curve), 14 (habits), 16 (nudges); one lock per section with real counts + trial button; free page from 10 results; skill-map drill-down (own hydrate); 6 locales | Phase 4 (on main) | not expected |
| **6b** lazy frames (stacked on 6a) | `insights/phase-6b` | §6.1 sections 9 (rating opportunities + pending + portfolio) and 10 (forecast) in one frame, 11 re-solve radar, 13 pairs & teams, 15 improvement curve + rating over time; "today's ladder" wording; screenshots 320 / 390 / 1200 px; button overflow audit in 6 locales; §6.3 canaries | 6a | no |
| **6c** estimates, option C | `insights/phase-6c` | §6.2 puzzle page card + one-line list estimates + sheet, bulk per list page, `ImageEstimateGate` | 3b, 3c, 5d | no |

Decisions for Phase 6 (orchestrator, 2026-10-19):
- Insights honours `time_predictions_opted_out` like the puzzle page, the Picker and the recap do. For an opted-out
  player there are no expected times in expected vs actual, moves, shelf picks or radar. The section says predictions
  are switched off and links to the setting.
- The page calls `InsightsPageLoader` only for the signed-in player's own id. It passes membership (trials count) and
  `profile.timezone`. Free players never receive members-only content in the HTML: a lock shows only counts.
- Rating opportunities and the forecast share one lazy frame, so the rating model loads once.

## Deviations and decisions during the build

- **1a:** an invalid `requires` answers **400** + `errors` (not the 422 of the brief): every invalid field of the claim
  and list endpoints already answers 400; only the store endpoints answer 422. Both SigLIP 2 analyzers carry the
  vector under `vector` (`crop` is the envelope field saying which part of the image was used).

- **Phase 2 acceptance on the M3 (§2.8 `bench` ±30 % and `verify --end-to-end --limit 10`)** cannot run in the
  orchestrator session: it must never load ML models while Jan's Mac runs the backfill tooling, and has no production
  token. The code paths are unit-tested with a mocked API; `setup-check`, `bench` and `verify --end-to-end` are the
  first steps of the M3 backfill session (§9.2), which reports the numbers. Recorded here instead of blocking the merge.

- **1b:** an `image_pipeline` proposal earns its reporter **no karma** (orchestrator, on the karma principle "a person's
  accepted contribution": it is a machine's proposal filed in the reviewer player's name); a moderator's web decision
  on it earns decision karma as usual. A reject's `decisionNote` lives in the decision's `details.decisionNote` (the
  `note` column is the reason the player reads). Added after the independent review: approval re-checks the pipeline
  fingerprint (409 `image_changed`), reject refuses a decided request (409) and takes the per-puzzle lock.
  `SubmitPuzzleChangeRequest` is `SerializedByLock` per puzzle. Refusals carry a stable `code`.

- **1c:** a restore is logged as a `puzzle_edited` decision with `details.restoredFromDecisionId` (no new decision
  type; shown as its own "Picture restored" history line). The picture grid refuses requests that propose more than
  a picture (they need the full review page). Review pages stay English like every admin review page.
- **2:** `vision_labels` runs in M1 phase B (it needs `artwork`); §2.3/§9.3 updated. Human crop decisions = decisions
  made in the moderators' web review (`decision.source == admin_ui`); an approval with image choice `keep`/`upload`
  counts as a rejected crop (`decision.image` exposed in the list). The Keychain token is only ever sent to production.
- **3a:** solves with no earlier pace use the solver's first pace (dropping them lost 14 % of indices / ~800 rated
  puzzles); no `baseline_type` column (derived at lookup); k = `puzzle_difficulty.sample_size` (no `index_count`).
  `MODEL_VERSION` 1 → 2 in 3a (difficulty inputs changed), so 3b bumps to 3.

- **5a:** tag corrections and box-text dismissals earn no karma (they change no puzzle record; revisit with a web UI
  for corrections). Only `assigned` tags are stored. Admin flashes English only (convention of the admin pages).
- **Order:** 5a and 3a merged before Phase 4/5 streams that need them; Phase 4/5 parts that don't need the Phase 3
  model started early (4a, 5a) - recorded in the Phase 4 + 5 plan above.

- **4a:** `player_rating_entry` stores percentile / decay / points per entry as planned (~250 MB extra WAL a day;
  accepted: lily backs up by pg_dump, no WAL archiving). Distinct solvers per puzzle not stored (viewer-dependent;
  `GetRanking` has it). Coordination constants run daily, not every 15 min. The 15-min batch bumps the generation
  every run (too many other writers to know "nothing changed"); the daily run only when something changed. Public
  ratings pinned to the pre-4a algorithm by a reference-copy test.
- **5c:** push guard compares only like with like (same holdout source + threshold, ≥ 100 test puzzles, training
  size within 25 %), otherwise records with `guard.skipped`; near-duplicate artwork kept in one fold (cosine ≥ 0.95);
  intervals never use a puzzle's own residual; fallback model `ridge-siglip2-full` (whole-image vector) with its own
  record and guard. Open for 5d: `ridge-siglip2-full` estimates mostly have image kind "unknown", so per-kind gates
  won't pass for them.
- **3c (built, waits for 3b):** window ends follow the research rule (step by the point prediction; 10 s steps under
  3 min); the point estimate stays unrounded as today (no 5/10-min rounding existed - kept, small puzzles would
  suffer).
- **Batch runtime after 3a + 4a:** `recalculate-puzzle-intelligence` steady state 23 s (2026-10-18 23:02 UTC), was
  16-18 s before Phase 3/4: +5-7 s against the combined Phase 3 (+5 s) + Phase 4 (+3 s) budget. 3b has ~1-2 s left.
- **4c:** the monthly rating snapshot belongs to 4b (brief), not 4c. `GroupCoordinationFitter` (4a) now fits on
  as-of paces like the synergy (computation.md §5 planned it): pairs 1.198 / 1.090, teams of 3 1.317 / 1.252, 4+
  1.368 / 1.326 (were drifting to 1.29 / 1.17 on today's baselines). "Faster than N % of its solvers" counts public
  first attempts only, like the rating's lists. Review fixes: players opted out of rankings get a rating but never a
  ladder place (`RatingStanding::OptedOut`); a hidden private teammate carries no player id; a first try that makes a
  puzzle reach 20 solvers is a move of its own.
- **4b:** the monthly snapshot (`player_insights_snapshot`) is 4b's, not 4c's. Suspicious results and secret puzzles
  are out of every section; close calls and the learning curve follow only the puzzle filters, not the period; the
  builder computes everything for free players too (locks show real counts) - Phase 6 must not render members-only
  content for them; image traits are a preview group in the skill map until launch
  (`SkillMapBuilder::IMAGE_TRAITS_ARE_PREVIEW`); member default period 24 months, free 6; prediction accuracy counts
  model version >= 3 only (`CALIBRATED_MODEL_VERSION`, needs 3b's bump). Review fixes: the segment range is
  4c's `ShrunkEffect` (symmetric), the form chart never runs into the future, a snapshot re-run replaces its month,
  `--month` only the current (or, in the first 3 days, the previous) month, zone in `InsightsCriteria::cacheKey()`.

- **3b:** model version 3 (not "v2" as the plan says - v2 was 3a's as-of inputs). Review fixes: 0-second first
  attempts never feed drift / fit / evaluation; drift residuals clipped at ±0.5; no side-by-side rows before the first
  fit, `--recompute-reconstructed` re-records everything computed before `fitted_at` (resumable); `pace_source` (own /
  interpolated / scaled_500) on the result and the side row, time verification leaves scaled-500 predictions out like
  shrunk ones (no classifier version bump); a size group keeps the default window term below 200 first tries; the fit
  takes the pace-history writer's lock. Picker: the finished prior is stored per puzzle (`difficulty_prior_puzzle.prior_log`)
  and copied onto `puzzle_difficulty.prior_log` (one lookup per candidate still cost ~45 ms on "any unsolved +
  budget"): heaviest player 100-107 ms (main 55-59 ms; main itself went to ~3 s on a freshly rewritten copy - a
  nested-loop plan 3b no longer has), "any + gap" 107-108 ms (main 87-90). Batch +1.3 s locally. Add-time statement
  budget 44 → 45 (the live side row INSERT). §3.6 numbers (fit before 2026-04-11): MAE 0.087, bias −0.2 %, 50 % hit
  50.8 % / width 13.8 % (quartiles 50.8 / 51.1 / 50.9 / 50.6), 80 % hit 80.2 %, repeats 50.2 % / 11.9 %.
- **3c:** API v1 fields are `range80_low_seconds` / `range80_high_seconds` (next to the existing `range_low_seconds` /
  `range_high_seconds`; `predicted_range_*` are only column names). "Never outward" = nearest step, ties inward
  (`prediction-research.md`: outward rounding gave 76 % hits); a window that rounds to nothing becomes the step holding
  the prediction; minutes-only text ("50-85 min") only while the high end is under 90 min. Stored pre-v3 predictions
  (recap) never claim "half of solves" (`TimePredictionResult::$calibratedWindow`). Picker cards keep the short
  "Likely …" (compact). Point estimate stays unrounded.
- **Phase 6 register (orchestrator, 2026-10-19):** Insights texts follow `puzzle_intelligence.*` on main - Czech
  formal "vy", French "vous", German "du", Spanish "tú" (the site mixes registers per area).
- **6a:** first response 10 statements (5 Insights + 5 page), 27-34 ms full request on a 1,011-result fixture. Not
  built: "Prediction as shown" switch, histogram median / usual markers, sticky filter bar (collides with the
  player header's compact bar). No "Your puzzling" menu heading exists - the row sits after Statistics.
- **5d:** the image prior is NOT in `prior_log` (that would change shown predictions without the flag and before a
  gate passes): `ImageEstimatePrior` answers only for the `image` / `blend` predictors; the blend prior is stored
  apart (`blend_prior_log`). **Launch (§8): letting the image prior into shown predictions = a `MODEL_VERSION` bump,
  then `backfill-solving-time-predictions --recompute-image` + `evaluate-image-estimates` (a bump closes every gate
  until then).** Gate "exact tier clearly above 46 %" = ≥ max(46 %, the group's always-Average share) + 10 points.
- **6c:** which number shows (`estimates.md`): solved before = personal; 5+ indices = measured; 1-4 = statistical
  (shrunk toward brand x pieces), marked estimated - not the blend, so no surface shows two times for one puzzle
  (the blend comes with the launch `MODEL_VERSION` bump); k = 0 = picture-only behind `ImageEstimateGate` (which
  also checks the estimate generation and fails closed for an unevaluated one); no pace = community median per piece
  count (>= 20 puzzlers). Rounding to the minute under 10 min (5-min steps made a 7-min puzzle "5 min"). Free
  players get no new lock on lists (no wall of CTAs). Biggest collection (1,351 puzzles) 16-83 ms on the prod copy.
- **6b:** the MSP rating tile reads the stored rating + the place on today's ladder from the page's context statement
  (header's rank rule; opted out / private = no place); the "Try next" / "MSP rating" cards and every section below
  the fold are lazy frames with an error state ("Couldn't load this part · Try again"). Rating over time shows no
  ladder place (snapshot ranks follow another rule). The skill-map / pairs axis shows from md up only. The free
  rating lock keeps "+X points" (the plan's own example). The top card writes "Likely …" (prose inside a link card),
  the shelf list uses 6c's "≈" line - the same prediction.
## Follow-ups

- Rejected crop images are deleted like any rejected proposal's file (decision + confidence stay, which is what the
  threshold calibration needs). Revisit only if crop-model retraining needs the rejected crops.
- API change-request rejects before 1b read `admin_ui` in the decision log (documented in puzzle-approvals.md).
- 5a: tag-corrections web UI, bulk corrections endpoint, storing tag candidates; watch the first real
  box-text run for brand / brand-code false positives (licensor brands, "No." prefixes) before relying on those filters.
- 3a: `reconstructed` predictions stored before 3a stay v1 (frozen history); suspicious-time detection now sees
  expected times ~7 % shorter (inputs changed, classifier version unchanged).
- 4c: Phase 6 loads rating + forecast in one frame (or shares the cached rating model); the ladder milestone compares
  against today's ladder - the page text must say so; a re-solve what-if can count a just-improved best time twice
  until the next 15-min batch (documented). Phase 6 decides whether Insights honours `time_predictions_opted_out`
  (expected times in moves, shelf picks, radar).
- 4b: after 3b add `predicted_range80_*` to `GetInsightsSoloResults` + an 80 % hit rate to `PredictionAccuracyBuilder`
  (5d/3c follow-up); flip `IMAGE_TRAITS_ARE_PREVIEW` at launch; drill-down result ids need their own hydrate call;
  Phase 6 calls `InsightsPageLoader` only for the signed-in player's own id, passes membership (trials included) and
  `profile.timezone`, and wires `InsightsCriteria::cacheKey($timezone)` into 4c's `InsightsCacheKey`.
- 3b: a live add in the minutes between a deploy and the first fit writes a v3 side row from the defaults
  (docs/TODO.md); stale leave-one-out, fit centre vs live baseline, RepeatHistory same-moment exclusion are in
  docs/TODO.md. Measured on prod 2026-10-19: batch 25.4-25.8 s (TODO.md); Puzzle Picker whole requests (Sentry) last 4 h after 3b p50 93 ms / p95 122 ms (n = 40) vs the 7-day baseline p50 76 ms / p95 127 ms (n = 3,880) - re-check over a full day.
