# MSP Online Test Cup #1 - runbook

**Target:** Tue 2026-11-10 (milestone M1, [implementation-plan.md](implementation-plan.md) §1). **Goal:** prove Phase 1 end to end on production with 5-10 volunteers: personal links, phones, the shared countdown, the Finished tap with its frame burst, time's up, the review into official results, the accuracy report. No video, no detection yet (those are Test Cups #2 and #3).

The cup is a **draft** event: it never appears in a public list, the personal links work anyway, and its results stay visible to its editors only.

## What we measure

| Measure | Where | Good enough for M1 |
|---|---|---|
| Every participant ends with a valid result (a time, or pieces placed) | review list | all of them |
| Reviewer adjustment = confirmed moment − tap (median, IQR) | accuracy report | median under 1 s |
| Clock agreement between phones | control room tiles ("clock ± ms" = half the best round trip - the error bound, not the offset) | every phone within ±100 ms |
| Reviewer minutes per participant | a stopwatch on the reviewer's desk (the page itself waits < 0.4 s per participant - rehearsal) | ≤ 1.5 min |
| Incidents: reloads, offline gaps, takeovers, camera lost, page hidden | review flags - except reloads: a reload leaves no event, ask the volunteers | each one explained |
| How it felt | volunteers' answers (below) | no blocker |

## Before - production prerequisites

- [ ] The real-device pass ([device-test.md](device-test.md)) on an iPhone, a mid-range Android and a Pixel, within the week before the cup, on the version that will run it.
- [ ] Jan's OK, then `myspeedpuzzling:online-media:cors` (dry run) and `--apply`: the bucket CORS rule for the phones' uploads and the review's downloads ([TODO.md](../../TODO.md) "participant API and evidence storage").
- [ ] Jan's OK: deny `online/` in the images proxy on lily.srv (or move the evidence to its own bucket).
- [ ] lily.srv: redact the personal tokens in Traefik's access log, or accept it for this cup (the app's own logs and Sentry already redact them).
- [x] Daily `myspeedpuzzling:prune-online-media` on lily.srv (a1f9fb6).
- [x] All online pages in 6 locales (2026-10-13).
- [ ] **A deploy freeze** from two hours before the start until the review is done: every push to `main` deploys, and a new build is a risk nobody needs during a round. Every session working on the repo is told. (A page open across a deploy no longer fails its first action - that was the one deploy that added the stopwatch forms' CSRF tokens, 2026-10-13. The rehearsal of 2026-10-14 restarted the app mid-round: every phone lost one heartbeat and caught up, and "End now" in a control room / "Stop" on a stopwatch page opened before the restart worked as the first action after it.)
- [ ] **The results for the volunteers - Jan decides:** the cup is a draft, so its event page and round results answer 404 to everybody but its editors, publishing the round notifies nobody, and the volunteers get no "Add to my profile" - also a signed-in, connected volunteer (rehearsal 2026-10-14). If they should see the results and add the time to their profile: after the review, "Publish" on the event page + approve it in `/admin/competition-approvals` (it then shows in the public lists like any event). Otherwise tell them where the results go.

## T-14 days - volunteers

- Jan picks 5-10 volunteers. Mix the phones: at least two iPhones, two Androids, one laptop + phone set-up.
- The puzzle: ideally one 500-piece puzzle everybody owns; otherwise any 500 pieces each - the cup tests the timing, not a ranking. The round carries one puzzle in MySpeedPuzzling either way ("Add to my profile" offers that one).
- Tell them what is recorded: the camera's periodic photos, the frames around their Finished tap and the end photo are stored for the review - periodic photos 30 days, the rest a year - and never shown publicly.

## T-10 days - set the cup up

1. **The event**: "Add event" → online, saved with the **draft** button - or the internal API (`POST /internal-api/competitions` with `"isOnline": true, "draft": true`, [internal-api.md](../internal-api.md)).
2. **The round**: solo, 45 minutes (`minutesLimit`), starting at the planned moment in its time zone ("Add Round" on Manage Rounds); the cup's puzzle through the round's "Puzzles" → "Add Puzzle" (not secret). The round's start in the form is the announced one - the clock starts only from the control room.
3. **Online timing**: the round's "Online timing" page (`online_round_settings`) → turn it on; detection off (Phase 1), video off, unfinished participants report the pieces left (the default - the review turns them into pieces placed with the puzzle's piece count). The way there before it is on: the event's ⋯ menu "Online timing" (= the links page) → "Set up" at the round; the round tools on Manage Rounds offer "Online timing", "Personal links", "Control room" and "Review" only once it is on.
4. **Participants**: the participants sheet - paste the volunteers (name and country columns from a spreadsheet) onto the "Type a name to add a person" row, choose "And put them into: <the round>", Apply. **Then open the links page** (`online_links`): it creates the personal links (the sheet shows "No link yet" until then) and has the CSV and the QR sheet.
5. **Sending the links**: one e-mail per volunteer from the CSV, drafted for Jan to approve: the link, when to open it (30 minutes before the start), what to prepare (the phone on a stand, charger, sound on, a plain mat if they have one) and that the page has a practice run. The CSV's links are in the language of the links page Jan opened (`/en/compete/…`); the welcome screen has the language switch at its bottom - say so in the e-mail, or send a Czech volunteer the `/soutezit/…` form of the link.
6. **Rehearsal (T-7)**: Jan + two phones on a second draft copy ("Test Cup #1 - rehearsal"), never on the cup itself - the rehearsal's results stay on the copy. The copy's round can be run again and again with "Reset" on its stopwatch page as long as no result is confirmed there (the phones stay ready). Include the smallest phone at hand and one tap on it (device-test.md step 15).

## The day (S = the start)

| When | Who | What |
|---|---|---|
| S-2 h | orchestrator | deploy freeze starts; production checked (no other round running) |
| S-60 min | Jan | the control room (`online_control_room`) open on a laptop; the review (`online_review`) in a second tab |
| S-30 min | volunteers | open their link: checklist (camera, stand, sound, screen stays on), a practice run, "I'm ready" |
| S-10 min | Jan | the grid shows everybody ready (chase the missing ones); **"Start at S"**, set at least 2 minutes ahead - every phone has the moment long before GO and counts down to it even if the push (Mercure) fails (rehearsal: GO on all phones 2-6 ms after S, Mercure stopped 30 s before it changed nothing). Not "Start in 10 s": it reaches the phones by the push alone in time - without it a phone learns the start from its next heartbeat (rehearsal: one of five showed GO 1.3 s late; up to 15 s possible) |
| S | everybody | 10 → 0 on every phone, the stopwatch runs; puzzling |
| during | Jan | watch the tiles: offline, camera lost, page hidden; finishes arrive as they happen |
| S+45 min | - | time's up by itself on every phone; unfinished participants report their pieces |
| after | orchestrator | `myspeedpuzzling:prune-online-media` once (safe any time) - uploads that never arrived then show "Never arrived" in the review instead of waiting for the nightly run |
| after | reviewer | every participant: scrub the frames around the tap, set the exact moment, `C` confirm / `U` unfinished. With the completeness check off every finish shows "Needs a look". A tap marked "(taken back)" can still be confirmed - look at its frames when a participant says the tap vanished |
| after | Jan | the results desk publishes the round (visible to the editors - the event is a draft); the accuracy report → CSV; the volunteers' results as decided before (Before, "The results for the volunteers") |
| after | orchestrator | deploy freeze ends; numbers into [STATUS.md](STATUS.md), issues into [TODO.md](../../TODO.md) |

## If something breaks

| Problem | What happens / what to do |
|---|---|
| A phone dies or crashes | the participant keeps puzzling; the same link on another device takes over ("Use this device instead" on a device that showed "Opened on another device" - rehearsal: the laptop ran the round 0.1 s after the tap, its Finished counted); worst case they tell their time and the desk enters it by hand (with a note) |
| Mercure is down | phones fall back to asking the server (heartbeat every 15 s); a scheduled start still counts down from the stored moment - one more reason for "Start at" |
| The primary storage is down | photos wait in the phone's outbox and retry; once 3 sources (phones, the server's check) have reported failures within a minute the uploads go to the second store - in the rehearsal (5 phones, a store that stopped answering: each upload waits for its 15 s timeout) after 25-40 s; nothing lost, the review reads each photo from the store it landed on |
| The control room fails | the round's stopwatch page (`manage_round_stopwatch`) runs the clock: "Start at a set time", "Cancel the start", **"Stop Stopwatch" = the end** of an online round (it asks to confirm; every phone shows time's up within seconds); "Reset" only while no result is confirmed (phones stay ready); the review can come later |
| The site is down | phones keep counting locally and queue their events; everything arrives once it is back (rehearsal: a phone offline from 1:00 to after the end sent its events with their original moments and all 16 photos within seconds of coming back) |
| A deploy happens anyway | a restart of the app costs each phone a failed heartbeat (rehearsal: one, retried); pages opened before it - phones, control room, stopwatch page - keep working |

## After - five questions for the volunteers

1. How long did the set-up take, and what was unclear?
2. Which phone, which browser - did anything on the page surprise you?
3. Did the countdown feel the same as the others' (if you were on a call with them)?
4. Did tapping Finished feel right? Did you tap it late?
5. Anything you would change before we invite more people?
