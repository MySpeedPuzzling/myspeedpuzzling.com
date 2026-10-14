# Online competitions - status

The living record of where the prototype stands and what comes next. Updated by the orchestrator at every merge.

- **Design and decisions:** [README.md](README.md) (§13)
- **The plan:** [implementation-plan.md](implementation-plan.md)
- **Contracts:** [contracts/phase-1.md](contracts/phase-1.md), [contracts/phase-1b.md](contracts/phase-1b.md) (§8 = the video foundation as built - binding for the rest of 1B)
- **Runbooks:** [test-cup-1.md](test-cup-1.md), [device-test.md](device-test.md), [obs-youtube.md](obs-youtube.md)
- **Spikes:** [spikes.md](spikes.md)

**Target:** the first public online competition in Q1 2027, after four test cups (implementation-plan.md §1).

**Cost rule (Jan, 2026-10-13):** we pay only for servers; every tool is open source and self-hosted.

---

## Now

| | |
|---|---|
| **Phase** | 1 built (6 locales, end-to-end tested + fixed, follow-ups done); **1B complete** (foundation, moderator console + studio, spectator page, participant video - proven against a local LiveKit; production waits for ADR D78); **Phase 2 integrated + reviewed** (the detector on the participant page, control room, review, accuracy report; the review's fixes merged; 6 locales) |
| **Resumed** | 2026-10-14 after the pause of 2026-10-13 |
| **Next milestone** | M1 → Test Cup #1 (target Tue 2026-11-10) - runbook [test-cup-1.md](test-cup-1.md) |

## Next

1. **P3 - the completeness check, advisory** (running): an internal Python + OpenCV worker (compose profile `cv`, no public port, `ONLINE_CV_URL` empty = off - production waits for Jan's yes) checks every finish photo against the empty mat and the puzzle's image (the bench's `completeness.py`); the review shows "Looks complete / Gap? / Not sure" with boxes and puts doubtful finishes first - it never decides.
2. Self-host the MediaPipe files before the detection cup (`docs/TODO.md`), the footage day (S1) - tuning the detector waits for real footage.
3. Merging recipe: every stream through `.claude/worktrees/oc-integrate` (`git checkout -B oc-integrate origin/main` → cherry-pick, or the net diff when a branch merged main → PHPStan + full paratest + schema validate → `git push origin HEAD:main` → `git merge --ff-only origin/main` in the shared checkout) → remove the stream's worktree (only after `git cherry` shows no `+`, or the content is verified) + its `speedpuzzling_<name>_test*` databases. A read-only review agent runs on every big stream while the gates run. Briefs and `run.sh` / `heavy.sh` in `.claude/worktrees/online-delivery/` (local).

## Waiting for Jan

- Before Test Cup #1: the real-device pass on phones (`device-test.md`, now 52 steps - 1-24 for this cup), volunteers, the OK to apply the bucket CORS rule (`myspeedpuzzling:online-media:cors --apply`) and to deny `online/` in the images proxy, Traefik access-log redaction of the personal links, a deploy freeze on the day.
- **Before any online event (found by LT):** `/api/compete/*` and `/-/time` pass lily.srv's bot-blocker today (90 requests a minute per IP + browser) - six phones on one club Wi-Fi would be blocked in the minutes before GO, and a CrowdSec captcha in front of the JSON API cannot be solved by the page. Route both like `/api/v` (a lily.srv change - needs Jan's yes).
- For Test Cup #2: the OK for ADR D78 (LiveKit on lily.srv: one UDP port + TURN/TLS on 443 through Traefik), the production load run at a quiet hour (`tools/online-load/README.md`: a draft event with made-up participants, generators allowlisted in CrowdSec, stop rules).
- S1 footage (spikes.md) - the bench scores it the same day.
- Small decisions: the control room plays no other moderator's voice (only "X is talking", against echo) - OK? A round's YouTube link has no form field yet (SQL only) - add it to the online timing page? Referees reviewing online rounds (TODO.md). The second screen stays view-only - Finished stays on the camera phone (its burst comes from that camera) - OK?

## Phases

| Phase | State | Notes |
|---|---|---|
| 0 - spikes | in progress | S1 footage protocol ready for Jan (spikes.md, with the label list form for several raises); S2 bench tools **built** (`tools/online-bench/`); S6 local run done (VF); S3-S5 follow |
| 1 - remote stopwatch | **built**, translated, end-to-end tested + fixed | real-device pass on phones pending (`device-test.md`) |
| 1B - live room + studio | **complete** (VF, VM, VS, VP merged, translated) | everything proven against a local LiveKit 1.13.9 (spikes.md S6); production needs ADR D78 on lily.srv (Jan's OK); real phones: `device-test.md` steps 25-37 |
| 2 - hands-up detection | **integrated + reviewed** (DB, P2I, P2F merged; 6 locales) | the detector runs on the phone (one worker, ~4 fps, button-only when it can't), the set-up test picks the view, candidates with photo + burst, `off` / `shadow` / `on`, control room chip, review candidates confirmed as `detection`, accuracy report (detection error, tap delay, misses); real phones: `device-test.md` 38-52; the MediaPipe files still come from jsDelivr + Google (self-host before the detection cup - TODO); tuning waits for S1 footage |
| 3 - completeness check | **in progress** (stream P3) | the algorithms + their synthetic self-test came with DB (no missing piece ever passed as complete); P3 = the worker, the async check, the review badges + ordering, the accuracy line |
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

## Phase 1B and Phase 2 streams

| Stream | State |
|---|---|
| VF - video foundation (dev LiveKit in the compose profile `video`, tokens, server API client, webhook, studio invites, video switches) | **merged** - proven against a real LiveKit 1.13.9 (join ≈ 0.3 s, 3 simulcast layers, data + permission changes live, 32 webhooks verified); as built: [contracts/phase-1b.md](contracts/phase-1b.md) §8 |
| VM - moderator console (video grid, spotlight, push-to-talk, invites) + the host's studio page + the studio's safety rules | **merged** - 8 cameras joined in 0.3-0.6 s, spotlight 720p in ~3 s, push-to-talk heard in 0.19 s, a reused studio token removed in < 0.2 s |
| VS - public watch page + spectator tokens (per-IP limit, cap 200), "Watch live" on event pages, OBS + YouTube how-to | **merged** - 3 spectators live in 1.2-1.8 s, the cap turns away and lets in, drafts 404 |
| VP - participant video (camera to the hall, host's voice, studio on air, the others' strip, view-only second screen) | **merged** - "I'm ready" → video on in ~0.2 s, the frame grabber kept 9.8 fps while publishing, invite → on air 1 s, Thank you → back in the hall 0.8 s, LiveKit killed mid-round = the round goes on; +6 KB initial JS, the rest lazy |
| P1G - follow-ups: Start refused after End now (online), another device on the tile, upload timeout (failover 41 s → 17 s), uploads that never arrived, breaker probe, cached link during a claim | **merged** (migration `Version20261014011432`, additive) |
| LT - load tooling (k6 + SSE, `tools/online-load/`) + local storage failover drill (Garage as the second store) | **merged** - 100 phones locally: 35 req/s, p95 ≤ 140 ms (234 ms in the start burst), ~0.1 core, 0 errors, GO p95 4 ms late, Mercure updates 20-41 ms; the drill found and fixed a breaker that never opened for a store that gives no answer (now: uploads on Garage 3.3 s after the primary died, all retried); numbers in `spikes.md` S5 |
| DB - detection bench (finish rule, hands-up signal, detector worker, bench page + scorer, OpenCV completeness self-test) | **merged** - end-to-end on a public-domain clip (candidate +33 ms, no false raise); completeness self-test: no missing piece ever passed as complete (90 synthetic photos); open questions for the footage day in `spikes.md` S2 |
| TX - online texts: every plural renders for 0, "1 pieces", clearer wording, unused keys, a guard test over all 6 locales | **merged** (`OnlinePluralMessagesTest`) |
| P2I - detection on the participant page (prefetch during the checklist, one worker fed by the frame grabber, the set-up test, candidates + photo + burst, modes, telemetry + control room chip, review candidates, accuracy report + CSV) | **merged** - local run with a fake camera: detector ready 1.2-1.7 s, test passed 0.6-0.8 s after the raise, inference median 20-35 ms (41 ms with live video), 4.3 fps, candidate 0-100 ms after the clip's raise; page CPU off 60 % / shadow 70 % / on 93 % of a core; with detection off the page is byte-identical. A code review found no blocker and 6 should-fix items → P2F |
| SP - settings reach open phones: `settingsHash` in the state + heartbeat (cached, Postgres-free), an `online.settings` push after every save, video / see each other / studio applied live, detection mode locked from GO until a Reset, a reload banner for a page opened while detection was off | **merged** - a change shows on an open phone 0.3-1.1 s after Save (Mercure), within a heartbeat without Mercure; every other page on the round's topic ignores the push (checked: stopwatch, projector, live results, watch page) |
| P2F - the review's fixes: arming (hands seen down ≥ 600 ms / 3 frames before a raise counts - GO, a hidden page, a camera restart), nothing detected after Finished, the `on` suggestion = the earlier of the first detection and the first tap, stills let go, `shadow` invisible to the reviewer (a `detection` confirmation refused) and never holding "I'm ready", "detector not running" apart from missed raises (`detector.changed` event), slow / stalled / broken detectors and frozen cameras become a visible "Button only" | **merged** - local run: hands up at GO gave no candidate (first one on the real raise at 0:19.27), nothing on the Finished screen, `shadow` review showed taps only; `FINISH_MACHINE_VERSION` 2; +94 B gzipped on the compete chunk |
| P1F - fixes from the end-to-end pass: a Reset starts a fresh run, the limiters under concurrency, End never before the click, take-back in seconds, smaller ones | **merged** - the online limiters are an atomic Redis counter now (exactly 300 of 320 parallel requests, +0.05 ms); take-back 2-15 s (was ~45 s); "End now" rounds up for online rounds only |

## Log

| Date | What |
|---|---|
| 2026-10-14 | Settings reach open phones (SP, 931026fd) + its texts in 6 locales (741 online keys each); full suite 10,486 green |
| 2026-10-14 | Review fixes merged (P2F, 08d8d4f4) + all detection texts in 6 locales (a6e6c703, 736 online keys each); full suite 10,472 green. SP (settings reach open phones) started |
| 2026-10-14 | Phase 2 integrated (P2I, 843a27d6): hands-up detection on the participant page through to the review and the accuracy report; full suite 10,466 green. "Delta" became "Tap delay". Code review → P2F fixes + translations started |
| 2026-10-14 | Resumed. Participant video (VP) merged - Phase 1B complete; Phase 1 follow-ups (P1G) merged; the console / studio / watch texts translated into 5 locales (146 keys each) |
| 2026-10-13 | Load tooling + storage failover drill merged (LT): k6 with SSE, a production-like local stack (FrankenPHP worker mode, Mercure, MinIO + Garage). 100 local phones cost ~0.1 core with 0 errors; the drill found that a store giving no readable answer never opened the circuit breaker - fixed (phone reports "no answer" as a store failure, fresh upload URL after the second failure; server counts it) |
| 2026-10-13 | Moderator console + studio (VM) and the spectator watch page (VS) merged (migration `Version20261013170851`: `online_round.youtube_url`, nullable). Paused at Jan's request after every running stream finished - resume from "Resume here" |
| 2026-10-13 | Fixes from the end-to-end pass merged (P1F): a Reset of an online round starts a fresh run (refused once results are confirmed), the participant API's limiters count parallel requests (Lua counter on the cache Redis), "End now" never before the click, the other device stops within a heartbeat after a take-back, "Offline" only without network, refused photos leave the phone, empty payloads stored as `{}`, presence written only by the device holding the entry, the pending-upload cap counts only unfinished uploads |
| 2026-10-13 | Detection bench merged: `finish_machine.js` (hold 0.5 s, 10 s cooldown, every tunable with its reason), `hands_up_signal.js`, `detector.worker.js` + pacing, `tools/online-bench/` (label + run videos frame by frame, `score.mjs` with a parameter sweep, OpenCV completeness checks + synthetic self-test). Findings: Encore runs the worker classic, MediaPipe needs WebGL2 even on the CPU delegate |
| 2026-10-13 | Video foundation merged: a local LiveKit (compose profile `video`, off by default), tokens, the server API client, the signed webhook, studio invites (migration `Version20261013154345`, additive), the online timing page's video switches, the client wrapper (`livekit-client` 2.22.4 in its own lazy chunk; the initial bundles are byte-identical). Production unchanged: no `LIVEKIT_*` = video off. Also merged: the end-to-end pass (GO drawn on time, the "Tap here once" banner after a reload) and the texts pass (TX) |
| 2026-10-13 | Translations of all 450 online keys into cs, de, es, fr, ja merged (one translator agent per locale, parity + plural checks). Their findings started the TX pass: 8 plural texts threw at a count of 0, "1 pieces", unclear chips. Older texts fixed on the way: 9 Japanese plurals always showed their first form (the live feed's "auto-update in 1 second"), a Czech one threw for 5+. Phase 1B foundation (VF) and the detection bench (DB) started |
| 2026-10-13 | Analysis, decisions, plan and Phase 1 contract written and committed; delivery started |
| 2026-10-13 | Control room + review + accuracy report merged; lily.srv cron `prune-online-media` (03:47) added and the command verified on production (no-op) |
| 2026-10-13 | Participant API + participant page + organiser set-up merged (migration `Version20261013131348`: `competition_participant.online_link_confirmed_at`, additive). Two code reviews: a link is never handed out on the event page to someone who merely claimed a name; the API treats every link as a possibly hostile client; evidence storage never wedges; personal links never reach logs or Sentry |
| 2026-10-13 | Round clock merged: "Start in 10 s / Start at…" + cancel (admin-only for now), a `scheduled` status every stopwatch reader understands, Mercure published after the commit, millisecond fields + one state hash, CSRF on the stopwatch forms. In-person changes: a second Start / Stop does nothing, Stop during a countdown cancels it, a Mercure outage no longer fails Start / Stop / Reset. Stopwatch pages open across this deploy fail their first action once (new CSRF token) - deployed with no live round on production |
| 2026-10-13 | Foundation merged (4 tables + 2 columns on `competition_participant`, additive; full suite 9,780 tests green) + the participant page's pure JS modules; online page styles get their own Encore entries (`compete`, `online_rounds`) instead of growing `app.scss` |
