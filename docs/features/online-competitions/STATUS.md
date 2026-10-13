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
| **Step** | foundation (stream F): the shared model, services and fixtures |
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
| F - foundation | in progress | - |
| A - participant backend | waiting for F | - |
| B - participant page | waiting for F | - |
| C1 - organiser set-up | waiting for F | - |
| D - round clock | waiting for F | - |
| C2 - control room + review | waiting for A + D | - |

## Next steps

1. Foundation lands on `main` (additive tables, nothing visible - the feature is admin-only).
2. Streams A, B, C1, D in parallel worktrees; each merges after its gates and a review.
3. C2 (control room + review), then the real-device pass, translations into 6 locales, Test Cup #1.
4. Jan: S1 footage (spikes.md), volunteers for Test Cup #1, an OK to prepare lily.srv ADR D78 for Phase 1B.

## Log

| Date | What |
|---|---|
| 2026-10-13 | Analysis, decisions, plan and Phase 1 contract written and committed; delivery started |
