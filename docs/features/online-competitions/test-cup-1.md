# MSP Online Test Cup #1 - runbook

**Target:** Tue 2026-11-10 (milestone M1, [implementation-plan.md](implementation-plan.md) §1). **Goal:** prove Phase 1 end to end on production with 5-10 volunteers: personal links, phones, the shared countdown, the Finished tap with its frame burst, time's up, the review into official results, the accuracy report. No video, no detection yet (those are Test Cups #2 and #3).

The cup is a **draft** event: it never appears in a public list, the personal links work anyway, and its results stay visible to its editors only.

## What we measure

| Measure | Where | Good enough for M1 |
|---|---|---|
| Every participant ends with a valid result (a time, or pieces placed) | review list | all of them |
| Reviewer adjustment = confirmed moment − tap (median, IQR) | accuracy report | median under 1 s |
| Clock agreement between phones | control room tiles ("clock ± ms") | every phone within ±100 ms |
| Reviewer minutes per participant | a stopwatch on the reviewer's desk | ≤ 1.5 min |
| Incidents: reloads, offline gaps, takeovers, camera lost, page hidden | review flags | each one explained |
| How it felt | volunteers' answers (below) | no blocker |

## Before - production prerequisites

- [ ] The real-device pass ([device-test.md](device-test.md)) on an iPhone, a mid-range Android and a Pixel, within the week before the cup, on the version that will run it.
- [ ] Jan's OK, then `myspeedpuzzling:online-media:cors` (dry run) and `--apply`: the bucket CORS rule for the phones' uploads and the review's downloads ([TODO.md](../../TODO.md) "participant API and evidence storage").
- [ ] Jan's OK: deny `online/` in the images proxy on lily.srv (or move the evidence to its own bucket).
- [ ] lily.srv: redact the personal tokens in Traefik's access log, or accept it for this cup (the app's own logs and Sentry already redact them).
- [x] Daily `myspeedpuzzling:prune-online-media` on lily.srv (a1f9fb6).
- [x] All online pages in 6 locales (2026-10-13).
- [ ] **A deploy freeze** from two hours before the start until the review is done: every push to `main` deploys, and a stopwatch page open across a deploy fails its first action. Every session working on the repo is told.

## T-14 days - volunteers

- Jan picks 5-10 volunteers. Mix the phones: at least two iPhones, two Androids, one laptop + phone set-up.
- The puzzle: ideally one 500-piece puzzle everybody owns; otherwise any 500 pieces each - the cup tests the timing, not a ranking. The round carries one puzzle in MySpeedPuzzling either way ("Add to my profile" offers that one).
- Tell them what is recorded: the camera's periodic photos, the frames around their Finished tap and the end photo are stored for the review - periodic photos 30 days, the rest a year - and never shown publicly.

## T-10 days - set the cup up

1. **The event**: "Add event" → online, saved as a **draft** - or the internal API (`POST /internal-api/competitions` with `"isOnline": true, "draft": true`, [internal-api.md](../internal-api.md)).
2. **The round**: solo, 45 minutes (`minutesLimit`), starting at the planned moment in its time zone, with the cup's puzzle.
3. **Online timing**: the round's "Online timing" page (`online_round_settings`) → turn it on; detection off (Phase 1), video off, unfinished participants report the pieces left (the default - the review turns them into pieces placed with the puzzle's piece count).
4. **Participants**: the participants sheet (paste the volunteers) - each one gets a personal link at once. The links page (`online_links`) has the CSV and the QR sheet.
5. **Sending the links**: one e-mail per volunteer from the CSV, drafted for Jan to approve: the link, when to open it (30 minutes before the start), what to prepare (the phone on a stand, charger, sound on, a plain mat if they have one) and that the page has a practice run.
6. **Rehearsal (T-7)**: Jan + two phones on a second draft copy ("Test Cup #1 - rehearsal"), never on the cup itself - the rehearsal's results stay on the copy.

## The day (S = the start)

| When | Who | What |
|---|---|---|
| S-2 h | orchestrator | deploy freeze starts; production checked (no other round running) |
| S-60 min | Jan | the control room (`online_control_room`) open on a laptop; the review (`online_review`) in a second tab |
| S-30 min | volunteers | open their link: checklist (camera, stand, sound, screen stays on), a practice run, "I'm ready" |
| S-10 min | Jan | the grid shows everybody ready (chase the missing ones); "Start at S" (or "Start in 10 s" at S) |
| S | everybody | 10 → 0 on every phone, the stopwatch runs; puzzling |
| during | Jan | watch the tiles: offline, camera lost, page hidden; finishes arrive as they happen |
| S+45 min | - | time's up by itself on every phone; unfinished participants report their pieces |
| after | reviewer | every participant: scrub the frames around the tap, set the exact moment, `C` confirm / `U` unfinished |
| after | Jan | the results desk publishes the round (visible to the editors - the event is a draft); the accuracy report → CSV |
| after | orchestrator | deploy freeze ends; numbers into [STATUS.md](STATUS.md), issues into [TODO.md](../../TODO.md) |

## If something breaks

| Problem | What happens / what to do |
|---|---|
| A phone dies or crashes | the participant keeps puzzling; the same link on another device takes over; worst case they tell their time and the desk enters it by hand (with a note) |
| Mercure is down | phones fall back to asking the server; a scheduled start still counts down from the stored moment |
| The primary storage is down | photos wait in the phone's outbox and retry; the review waits for them |
| The control room fails | the round's stopwatch page (`manage_round_stopwatch`) runs the clock; the review can come later |
| The site is down | phones keep counting locally and queue their events; everything arrives once it is back |

## After - five questions for the volunteers

1. How long did the set-up take, and what was unclear?
2. Which phone, which browser - did anything on the page surprise you?
3. Did the countdown feel the same as the others' (if you were on a call with them)?
4. Did tapping Finished feel right? Did you tap it late?
5. Anything you would change before we invite more people?
