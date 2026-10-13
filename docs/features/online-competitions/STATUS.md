# Online competitions - status

The living record of where the prototype stands and what comes next. Updated by the orchestrator at every merge.

- **Design and decisions:** [README.md](README.md) (§13)
- **The plan:** [implementation-plan.md](implementation-plan.md)
- **Current contract:** [contracts/phase-1.md](contracts/phase-1.md)
- **Spikes:** [spikes.md](spikes.md)

**Target:** the first public online competition in Q1 2027, after four test cups (implementation-plan.md §1).

**Cost rule (Jan, 2026-10-13):** we pay only for servers; every tool is open source and self-hosted.

---

## Now

| | |
|---|---|
| **Phase** | 1 - remote stopwatch (with Phase 0 spikes in parallel) |
| **Step** | Phase 1 is built and on `main` (English only); an end-to-end pass runs; next: translations into 6 locales, the real-device pass on phones, Test Cup #1. Phase 1B (live room + studio) has its contract ([contracts/phase-1b.md](contracts/phase-1b.md)) and starts with its foundation on a local LiveKit |
| **Started** | 2026-10-13 |
| **Next milestone** | M1 → Test Cup #1 (target Tue 2026-11-10) |

## Phases

| Phase | State | Notes |
|---|---|---|
| 0 - spikes | in progress | S1 footage protocol ready for Jan (spikes.md); S2-S6 tooling follows Phase 1 |
| 1 - remote stopwatch | in progress | contract fixed; F → A, B, C1, D in parallel → C2 |
| 1B - live room + studio | contract written; foundation next (local LiveKit) | production infrastructure needs ADR D78 on lily.srv (Jan's OK) |
| 2 - hands-up detection | not started | after the bench (S2) |
| 3 - completeness check | not started | |
| 4 - scale + public event | not started | |

## Phase 1 streams

| Stream | State | Branch / commit |
|---|---|---|
| F - foundation (model, migration `Version20261013111419`, shared services, flag `ONLINE_ROUNDS_FOR_ORGANISERS`, fixture) | **merged** | see the log |
| B1 - participant page: pure JS modules (clock sync, round state, event + media outbox, frame buffer; 80 node scenarios) | **merged** | see the log |
| D - round clock (countdown, `scheduled`, publish after commit, every stopwatch reader, schedule / cancel) | **merged** - code-reviewed (no blockers), projector countdown checked in a real browser; scheduling actions admin-only until the test cups | see the log |
| A - participant backend (`/-/time`, state, heartbeat, events, presigned media, connect, pruning) | **merged** - reviewed; hardened (quotas, per-IP + per-link limiters, unknown-link cache, strict ids, never-wedging prune + orphan sweep, tokens redacted from logs and Sentry) | see the log |
| B2 - participant page (checklist, countdown, stopwatch, Finished + frame burst, time's up, practice, offline queues) | **merged** - real-browser pass with a fake camera; real-device pass pending (`device-test.md`) | see the log |
| C1 - organiser set-up (online timing settings, personal links + CSV + QR sheet, sheet column, event-page button, menus) | **merged** - reviewed; the event page hands a link only to a player who showed they hold it | see the log |
| C2 - control room (live grid, Start in 10 s / at / cancel / end) + review (frame-burst scrubber, confirm into the official results) + accuracy report | **merged** - a result entered meanwhile at the desk is a conflict, never overwritten | see the log |

## Next steps

1. The end-to-end pass on the dev stack (real API, MinIO, Redis, a fake camera) - its fixes merge.
2. Translations of every new key into cs, de, es, fr, ja.
3. Jan: the real-device pass on phones (`device-test.md`), volunteers for Test Cup #1, the OK to apply the bucket CORS rule and to deny `online/` in the images proxy, the OK for ADR D78.
4. Phase 1B foundation (local LiveKit, tokens, webhooks, studio model).
3. C2 (control room + review), then the real-device pass, translations into 6 locales, Test Cup #1.
4. Jan: S1 footage (spikes.md), volunteers for Test Cup #1, an OK to prepare lily.srv ADR D78 for Phase 1B.

## Log

| Date | What |
|---|---|
| 2026-10-13 | Analysis, decisions, plan and Phase 1 contract written and committed; delivery started |
| 2026-10-13 | Control room + review + accuracy report merged; lily.srv cron `prune-online-media` (03:47) added and the command verified on production (no-op) |
| 2026-10-13 | Participant API + participant page + organiser set-up merged (migration `Version20261013131348`: `competition_participant.online_link_confirmed_at`, additive). Two code reviews: a link is never handed out on the event page to someone who merely claimed a name; the API treats every link as a possibly hostile client; evidence storage never wedges; personal links never reach logs or Sentry |
| 2026-10-13 | Round clock merged: "Start in 10 s / Start at…" + cancel (admin-only for now), a `scheduled` status every stopwatch reader understands, Mercure published after the commit, millisecond fields + one state hash, CSRF on the stopwatch forms. In-person changes: a second Start / Stop does nothing, Stop during a countdown cancels it, a Mercure outage no longer fails Start / Stop / Reset. Stopwatch pages open across this deploy fail their first action once (new CSRF token) - deployed with no live round on production |
| 2026-10-13 | Foundation merged (4 tables + 2 columns on `competition_participant`, additive; full suite 9,780 tests green) + the participant page's pure JS modules; online page styles get their own Encore entries (`compete`, `online_rounds`) instead of growing `app.scss` |
