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
| **Step** | wave 1 - the foundation and the participant JS modules are on `main`; the participant backend (A), the participant page (B2), the organiser set-up (C1) and the round clock (D, part 2) are being built in parallel worktrees |
| **Started** | 2026-10-13 |
| **Next milestone** | M1 → Test Cup #1 (target Tue 2026-11-10) |

## Phases

| Phase | State | Notes |
|---|---|---|
| 0 - spikes | in progress | S1 footage protocol ready for Jan (spikes.md); S2-S6 tooling follows Phase 1 |
| 1 - remote stopwatch | in progress | contract fixed; F → A, B, C1, D in parallel → C2 |
| 1B - live room + studio | not started | needs ADR D78 on lily.srv (Jan's OK) - local LiveKit development can start before |
| 2 - hands-up detection | not started | after the bench (S2) |
| 3 - completeness check | not started | |
| 4 - scale + public event | not started | |

## Phase 1 streams

| Stream | State | Branch / commit |
|---|---|---|
| F - foundation (model, migration `Version20261013111419`, shared services, flag `ONLINE_ROUNDS_FOR_ORGANISERS`, fixture) | **merged** | see the log |
| B1 - participant page: pure JS modules (clock sync, round state, event + media outbox, frame buffer; 80 node scenarios) | **merged** | see the log |
| D - round clock (countdown, `scheduled`, publish after commit, every stopwatch reader, schedule / cancel) | **merged** - code-reviewed (no blockers), projector countdown checked in a real browser; scheduling actions admin-only until the test cups | see the log |
| A - participant backend | in progress | - |
| B2 - participant page: the page itself | in progress | - |
| C1 - organiser set-up | in progress | - |
| C2 - control room + review | waiting for A + D | - |

## Next steps

1. Streams A, B2, C1 and D (part 2) in parallel worktrees; each merges after its gates and a review.
2. C2 (control room + review) starts once A is merged (D is in).
3. C2 (control room + review), then the real-device pass, translations into 6 locales, Test Cup #1.
4. Jan: S1 footage (spikes.md), volunteers for Test Cup #1, an OK to prepare lily.srv ADR D78 for Phase 1B.

## Log

| Date | What |
|---|---|
| 2026-10-13 | Analysis, decisions, plan and Phase 1 contract written and committed; delivery started |
| 2026-10-13 | Round clock merged: "Start in 10 s / Start at…" + cancel (admin-only for now), a `scheduled` status every stopwatch reader understands, Mercure published after the commit, millisecond fields + one state hash, CSRF on the stopwatch forms. In-person changes: a second Start / Stop does nothing, Stop during a countdown cancels it, a Mercure outage no longer fails Start / Stop / Reset. Stopwatch pages open across this deploy fail their first action once (new CSRF token) - deployed with no live round on production |
| 2026-10-13 | Foundation merged (4 tables + 2 columns on `competition_participant`, additive; full suite 9,780 tests green) + the participant page's pure JS modules; online page styles get their own Encore entries (`compete`, `online_rounds`) instead of growing `app.scss` |
