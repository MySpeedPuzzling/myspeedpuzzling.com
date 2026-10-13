# Online competitions - status

The living record of where the prototype stands and what comes next. Updated by the orchestrator at every merge.

- **Design and decisions:** [README.md](README.md) (§13)
- **The plan:** [implementation-plan.md](implementation-plan.md)
- **Contracts:** [contracts/phase-1.md](contracts/phase-1.md), [contracts/phase-1b.md](contracts/phase-1b.md) (+ the orchestrator's deviations, recorded there when VF merges)
- **Spikes:** [spikes.md](spikes.md)

**Target:** the first public online competition in Q1 2027, after four test cups (implementation-plan.md §1).

**Cost rule (Jan, 2026-10-13):** we pay only for servers; every tool is open source and self-hosted.

---

## Now

| | |
|---|---|
| **Phase** | 1 built (all 6 locales); 1B (live room + studio) and the Phase 2 groundwork (detection bench) in progress |
| **Step** | Phase 1 is on `main` in 6 locales; an end-to-end pass on the dev stack runs; the online texts get a plural/wording pass (TX). Phase 1B: the video foundation (VF) runs against a local LiveKit. Phase 2 groundwork (DB): the finish rule, the hands-up signal, the detector worker and the bench tools - ready to score footage the day S1 arrives |
| **Started** | 2026-10-13 |
| **Next milestone** | M1 → Test Cup #1 (target Tue 2026-11-10) |

## Phases

| Phase | State | Notes |
|---|---|---|
| 0 - spikes | in progress | S1 footage protocol ready for Jan (spikes.md); S2 bench tools being built (stream DB); S3-S6 follow |
| 1 - remote stopwatch | **built**, translated | end-to-end pass on the dev stack running; real-device pass on phones pending (`device-test.md`) |
| 1B - live room + studio | in progress: foundation (VF) on a local LiveKit | then VP (participant video), VM (moderator console + studio), VS (spectators) in parallel; production needs ADR D78 on lily.srv (Jan's OK) |
| 2 - hands-up detection | groundwork in progress (stream DB) | the finish rule + detector worker + bench; integration into the participant page after 1B |
| 3 - completeness check | not started | the bench's OpenCV self-test comes with DB |
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

## Phase 1B and Phase 2 groundwork streams

| Stream | State |
|---|---|
| VF - video foundation (dev LiveKit in the compose profile `video`, tokens, server API client, webhook, studio invites, video switches) | **merged** - proven against a real LiveKit 1.13.9 (join ≈ 0.3 s, 3 simulcast layers, data + permission changes live, 32 webhooks verified); as built: [contracts/phase-1b.md](contracts/phase-1b.md) §8 |
| VP / VM / VS - participant video, moderator console + studio, spectators | next, in parallel ([contracts/phase-1b.md](contracts/phase-1b.md)) |
| DB - detection bench (finish rule, hands-up signal, detector worker, bench page + scorer, OpenCV completeness self-test) | in progress |
| TX - online texts: every plural renders for 0, "1 pieces", clearer wording, unused keys, a guard test over all 6 locales | **merged** (`OnlinePluralMessagesTest`) |
| P1F - fixes from the end-to-end pass: a Reset starts a fresh run, the limiters under concurrency, End never before the click, take-back in seconds, smaller ones | in progress |

## Next steps

1. Merge the end-to-end pass's fixes, TX, VF and DB as they finish; then start VP, VM and VS.
2. Jan: the real-device pass on phones (`device-test.md`), S1 footage (spikes.md), volunteers for Test Cup #1, the OK to apply the bucket CORS rule and to deny `online/` in the images proxy, the OK for ADR D78 (LiveKit on lily.srv).
3. Test Cup #1 (target Tue 2026-11-10): the runbook is [test-cup-1.md](test-cup-1.md) - prerequisites, set-up, the day, fallbacks.

## Log

| Date | What |
|---|---|
| 2026-10-13 | Video foundation merged: a local LiveKit (compose profile `video`, off by default), tokens, the server API client, the signed webhook, studio invites (migration `Version20261013154345`, additive), the online timing page's video switches, the client wrapper (`livekit-client` 2.22.4 in its own lazy chunk; the initial bundles are byte-identical). Production unchanged: no `LIVEKIT_*` = video off. Also merged: the end-to-end pass (GO drawn on time, the "Tap here once" banner after a reload) and the texts pass (TX) |
| 2026-10-13 | Translations of all 450 online keys into cs, de, es, fr, ja merged (one translator agent per locale, parity + plural checks). Their findings started the TX pass: 8 plural texts threw at a count of 0, "1 pieces", unclear chips. Older texts fixed on the way: 9 Japanese plurals always showed their first form (the live feed's "auto-update in 1 second"), a Czech one threw for 5+. Phase 1B foundation (VF) and the detection bench (DB) started |
| 2026-10-13 | Analysis, decisions, plan and Phase 1 contract written and committed; delivery started |
| 2026-10-13 | Control room + review + accuracy report merged; lily.srv cron `prune-online-media` (03:47) added and the command verified on production (no-op) |
| 2026-10-13 | Participant API + participant page + organiser set-up merged (migration `Version20261013131348`: `competition_participant.online_link_confirmed_at`, additive). Two code reviews: a link is never handed out on the event page to someone who merely claimed a name; the API treats every link as a possibly hostile client; evidence storage never wedges; personal links never reach logs or Sentry |
| 2026-10-13 | Round clock merged: "Start in 10 s / Start at…" + cancel (admin-only for now), a `scheduled` status every stopwatch reader understands, Mercure published after the commit, millisecond fields + one state hash, CSRF on the stopwatch forms. In-person changes: a second Start / Stop does nothing, Stop during a countdown cancels it, a Mercure outage no longer fails Start / Stop / Reset. Stopwatch pages open across this deploy fail their first action once (new CSRF token) - deployed with no live round on production |
| 2026-10-13 | Foundation merged (4 tables + 2 columns on `competition_participant`, additive; full suite 9,780 tests green) + the participant page's pure JS modules; online page styles get their own Encore entries (`compete`, `online_rounds`) instead of growing `app.scss` |
