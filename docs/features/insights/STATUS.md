# Insights - status

Living progress record for [`implementation-plan.md`](./implementation-plan.md). Update it as you go: per phase the
state, PR, merge and deploy, deviations from the plan (and why), follow-ups.

| Phase | State | PR | Merged | Deployed | Notes |
|---|---|---|---|---|---|
| Prerequisites | done | #254, #255 | 2026-10-18 | 2026-10-18 | time zone; image-analysis internal API (14 analyzers, claims, bulk read) |
| Research + plan | done | - | 2026-10-18 | - | README.md, implementation-plan.md, research files, prototype.html |
| 1 Production API for the pipeline | in progress (1a + 1b done; 1c) | #258 (1a), #259 (1b) | 1a, 1b: 2026-10-18 | 1a, 1b: 2026-10-18 | 1a verified: 401 without token, `requires=bogus` 400, `requires=artwork` 200. 1b verified: migration applied, list endpoint 200, source backfill 897 internal_api / 2,790 player |
| 2 Mac pipeline | in progress | | | | stream below |
| Backfill (M3 Max + M1 Air sessions) | not started | - | - | - | starts after 1 + 2 are deployed; ≈ 5.5 days |
| 3 Prediction fix | in progress (3a) | | | | streams below |
| 4 Insights computation | not started | | | | |
| 5 Image intelligence on the server | not started | | | | |
| 6 Display behind INSIGHTS_PUBLIC | not started | | | | |
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

Merge order: 1a → 1b → 1c → 2 (2 is built in parallel against the documented contract, merged after 1a/1b are on main).
Phase 3 starts as soon as a subagent slot frees (1a is small).

## Deviations and decisions during the build

- **1a:** an invalid `requires` answers **400** + `errors` (not the 422 of the brief): every invalid field of the claim
  and list endpoints already answers 400; only the store endpoints answer 422. Both SigLIP 2 analyzers carry the
  vector under `vector` (`crop` is the envelope field saying which part of the image was used).

- **Phase 2 acceptance on the M3 (§2.8 `bench` ±30 % and `verify --end-to-end --limit 10`)** cannot run in the
  orchestrator session: it must never load ML models while Jan's Mac runs the backfill tooling, and has no production
  token. The code paths are unit-tested with a mocked API; `setup-check`, `bench` and `verify --end-to-end` are the
  first steps of the M3 backfill session (§9.2), which reports the numbers. Recorded here instead of blocking the merge.

- Rejected crop images are deleted like any rejected proposal's file (decision + confidence stay, which is what the
  threshold calibration needs). Revisit only if crop-model retraining needs the rejected crops.
- API change-request rejects before 1b read `admin_ui` in the decision log (documented in puzzle-approvals.md).

- **1b:** an `image_pipeline` proposal earns its reporter **no karma** (orchestrator, on the karma principle "a person's
  accepted contribution": it is a machine's proposal filed in the reviewer player's name); a moderator's web decision
  on it earns decision karma as usual. A reject's `decisionNote` lives in the decision's `details.decisionNote` (the
  `note` column is the reason the player reads). Added after the independent review: approval re-checks the pipeline
  fingerprint (409 `image_changed`), reject refuses a decided request (409) and takes the per-puzzle lock.
  `SubmitPuzzleChangeRequest` is `SerializedByLock` per puzzle. Refusals carry a stable `code`.

## Follow-ups

(none yet)
