# Insights - status

Living progress record for [`implementation-plan.md`](./implementation-plan.md). Update it as you go: per phase the
state, PR, merge and deploy, deviations from the plan (and why), follow-ups.

| Phase | State | PR | Merged | Deployed | Notes |
|---|---|---|---|---|---|
| Prerequisites | done | #254, #255 | 2026-10-18 | 2026-10-18 | time zone; image-analysis internal API (14 analyzers, claims, bulk read) |
| Research + plan | done | - | 2026-10-18 | - | README.md, implementation-plan.md, research files, prototype.html |
| 1 Production API for the pipeline | in progress (1a, 1b, then 1c) | | | | streams below |
| 2 Mac pipeline | in progress | | | | stream below |
| Backfill (M3 Max + M1 Air sessions) | not started | - | - | - | starts after 1 + 2 are deployed; ≈ 5.5 days |
| 3 Prediction fix | not started | | | | |
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

Merge order: 1a → 1b → 1c → 2 (2 is built in parallel against the documented contract, merged after 1a/1b are on main).
Phase 3 starts as soon as a subagent slot frees (1a is small).

## Deviations and decisions during the build

- **Phase 2 acceptance on the M3 (§2.8 `bench` ±30 % and `verify --end-to-end --limit 10`)** cannot run in the
  orchestrator session: it must never load ML models while Jan's Mac runs the backfill tooling, and has no production
  token. The code paths are unit-tested with a mocked API; `setup-check`, `bench` and `verify --end-to-end` are the
  first steps of the M3 backfill session (§9.2), which reports the numbers. Recorded here instead of blocking the merge.

(none yet)

## Follow-ups

(none yet)
