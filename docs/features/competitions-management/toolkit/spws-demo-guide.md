# Speed Puzzle World Series - demo guide (sandbox)

For Jan and the SPWS organisers: what to open, how to sign in and what to click to see every role of the
competition toolkit on a made-up copy of the SPWS format. Everything here is on the **sandbox**
(https://sandbox.myspeedpuzzling.com) - a test copy of MySpeedPuzzling: made-up people, nothing reaches the real site,
nothing can break. How the event is built: [spws-demo-event.md](../spws-demo-event.md).

## 0. Before the call (Jan, 5 minutes)

- The sandbox shows a welcome page first - click **Enter** (once per browser).
- The event: **Speed Puzzle World Series Toronto 2027 - demo**, `/en/events/spws-toronto-2027-demo`.
- It is laid out as if the call's day (`--date`) were the **Teams day** (Thursday at the real event): Pairs (yesterday) is finished and published, Teams
  Challenges 1 + 2 has some results in, Teams Challenges 3 + 4 (15:00 Toronto) and the two Individual days are still
  to come. Times are Toronto times - on a call in the European evening it is morning in Toronto, so the organisers
  start the stopwatches themselves (step 3) rather than wait for the schedule.
- The URLs of this installation are printed by the build command (README "Demo events"); the list in §6 below is the
  one built on 2026-11-24.

## 1. Who signs in as whom

| Role | Account | Password |
|---|---|---|
| Organisers | the two SPWS organisers' own sandbox accounts (copied from MySpeedPuzzling with their own passwords) | their usual one |
| Made-up organiser | `test-user+1@myspeedpuzzling.com` | `sandbox` |
| Judge (referee) | `test-user+2@myspeedpuzzling.com` | `sandbox` |
| Competitor | a made-up player in the event, e.g. `test-user+3@myspeedpuzzling.com` (see §6 for who is in which pair/team) | `sandbox` |
| Commentators, hall screen, stream | no sign-in - links only | |

The organisers are on the event's team and on the team of the organization "Speed Puzzle World Series (demo)", so they
see every organiser page. A second judge on the call: an organiser's phone (organisers can enter results too).

## 2. Per role - what to open and what to click

### Organiser (laptop)
1. Event page → **Manage** → **Toolkit** (`/en/manage-event-toolkit/<event id>`): one card per area.
2. **Seating** of "Pairs - Challenges 1 + 2" (Toolkit → card "Round formats & reveals" → the link "Tables A1 L … H21 R"
   under the round, or the **Seating** button on the results desk): tables named A1 L, A1 R, A2 L … (two places per
   table; Teams: A1, A2 … one table per team). "Table names" panel: change the scheme (e.g. A1-1 / A1-2) for the whole championship.
3. **Name plates**: Toolkit → card "Competitor pages & QR codes" → **Print tags** → "Pairs & teams" (or "People"),
   Layout "Table tents", Round = the championship's first round, "Time boxes" = the championship, "Show country flags":
   name, flag, table, pair/team name, QR and a box per challenge for writing times by pen. Print preview.
4. **Results desk** of "Teams - Challenges 1 + 2": who has finished, who has only Challenge 1, the standing preview.
5. **Standings** (`/en/manage-event-standings/<event id>`): one standing per championship, rules 5 s per missing piece,
   total time only, a no-show stays ranked.
6. **Displays** (`/en/manage-displays/<event id>`): five screens in the SPWS navy + gold - open, edit, copy the link.

### Judge (phone, 375 px)
1. Sign in as the judge → the round's judge page (`/en/live-results/<round id>`), add it to the home screen.
2. Type `a1` → A1 is at the top of the list (A10 … also match) - tap it or press Enter; a unique table opens at once
   (`a1l` = A1 L). **Finished now** per challenge appears only while the round's stopwatch runs - Teams Challenges 1 + 2
   is *not* started in the build, so start its stopwatch first (no secret puzzles in it) or type the time on the clock
   (a block: Challenge 1's split first, then Challenge 2 = the time - always the clock's time, never a challenge's own
   time). **Didn't finish**: tap both challenges first, then type the pieces still loose; **Pieces missing** is on the
   Save step (one count for both challenges).
3. Airplane mode: keep entering - the phone keeps the results and sends them when back online.

### Competitor (phone)
1. Sign in as a made-up player → the event page → **Competitors** → their own name (marked "You") - or scan the QR on
   the plate. The event page itself has no "Your competitor page" link.
2. Their table, the day and clock of each round, their results and rank; with the stopwatch running,
   **Done - propose my time** → **Propose** sends their time to the judge, who confirms it on the table's card.

### Commentators
The round page → "Analytics studio" (`/en/events/spws-toronto-2027-demo/analytics-studio/<round slug>`): who is at
which table, their history and predictions, live results as the judges enter them. No sign-in.

### Hall screen and stream
- **SPWS hall screen**: open its link full screen on the hall's computer/TV - logo, round, big timer, finished count,
  live top 10.
- **SPWS stream overlay**: add its link as a Browser Source in OBS (1920 × 1080) - the background is transparent;
  timer top right, live top 8, the latest finishers.
- **SPWS championship standings**, **SPWS challenge board** (Challenge 1 / Challenge 2 side by side),
  **SPWS lower third** (the latest finisher + a message the organiser types on the live control page).

### The public / their website
- Round results with per-challenge rankings (`?challenge=1`), the championship standing, and its **embed** for their
  own website (standings page → "Embed on your website").

## 3. Live simulation for the call (30-45 minutes)

| Min | Who | What |
|---|---|---|
| 0-5 | Jan | Event page: three championships in the timeline, the Pairs standing (provisional), a no-show ranked last, a tie (shared place), "+ 1 piece missing". Round page `?challenge=1` / `?challenge=2`. |
| 5-10 | Organiser | Toolkit → Seating (table names) → Name plates print preview with the time boxes. |
| 10-12 | Jan | Open the hall screen in one window and the stream overlay in another (shows how it looks over a camera). |
| 12-15 | Organiser | **Stopwatch** of "Teams - Challenges 3 + 4" (`/en/manage-round-stopwatch/<round id>`) → tick **"Reveal the 2 puzzles when the stopwatch starts"** → Start. The two secret puzzles appear on the event page and screens at that moment; Challenge 4 stays without a box picture (unboxed) until the results are published. |
| 15-25 | Judges | Judge 1 (`test-user+2`) on a phone: table `a1` → Finished now (Challenge 3), later Finished now (Challenge 4). Judge 2 (an organiser's phone): one team **Didn't finish** with 40 pieces loose, one **finished with 1 piece missing**, one **Did not start** (no-show). |
| 18 | Competitor | Signed in as a made-up player of a team: their page → Stop → the judge sees the proposal on the table card and confirms. |
| 15-25 | Everyone | Watch the hall screen and the overlay update (finished count, top 10, latest finishers); the challenge board. |
| 25-30 | Commentators | Analytics studio of the running round: entrants, history, predictions, live order. Live control page: type a message → it shows on the lower third. |
| 30-35 | Organiser | Results desk → review → **Publish** → the Teams standing moves (Challenges 3 + 4 counted, the no-show ranked with its penalty, the missing piece +5 s) → the embed on "their website" shows the same. |
| 35-45 | All | Questions; the organisers can set the same format up themselves in the **event creation wizard** ("A competition with several rounds" → "No - challenges added up per championship", the Challenges template with labels and Unboxed per puzzle). |

## 4. What is made up

Every person, pair, team and result. The puzzles are catalogue stand-ins of the right brands (Speedmaster,
Boardwalk, Wentworth wooden 250, Buffalo Games, Ravensburger); Teams Challenges 3 + 4 are two invented secret
puzzles. The two places per table are named "L"/"R" until SPWS decides.

## 5. After a rehearsal

A started stopwatch, revealed puzzles and typed results stay. To start over, Jan deletes the event (admin) and runs
the build command again (README "Demo events").

## 6. URLs (built on the sandbox 2026-11-24, `--date=2026-12-02`)

| What | Link |
|---|---|
| Event page | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo |
| Organization | https://sandbox.myspeedpuzzling.com/en/organizations/speed-puzzle-world-series-demo-organization |
| Toolkit hub (organisers) | https://sandbox.myspeedpuzzling.com/en/manage-event-toolkit/01a20b2b-0cd2-7b3b-8e49-74e27784a483 |
| Participants sheet | https://sandbox.myspeedpuzzling.com/en/participants-sheet/01a20b2b-0cd2-7b3b-8e49-74e27784a483 |
| Competitors (public list) | https://sandbox.myspeedpuzzling.com/en/competitor/01a20b2b-0cd2-7b3b-8e49-74e27784a483 |
| Standings (organisers) | https://sandbox.myspeedpuzzling.com/en/manage-event-standings/01a20b2b-0cd2-7b3b-8e49-74e27784a483 |
| Displays (organisers) | https://sandbox.myspeedpuzzling.com/en/manage-displays/01a20b2b-0cd2-7b3b-8e49-74e27784a483 |
| Live control (message, spotlight) | https://sandbox.myspeedpuzzling.com/en/display-control/01a20b2b-0cd2-7b3b-8e49-74e27784a483 |
| Pairs standing | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/standings/pairs-championship |
| Teams standing | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/standings/teams-championship |
| Individual standing | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/standings/individual-championship |
| Pairs standing embed | https://sandbox.myspeedpuzzling.com/embed/standing/01a20b2b-1761-70b8-92ed-92c2cf0ee184 (add `?theme=dark`) |
| Teams standing embed | https://sandbox.myspeedpuzzling.com/embed/standing/01a20b2b-1771-7168-9fde-59a64ba17de1 |
| Name plates - pairs | https://sandbox.myspeedpuzzling.com/en/name-tags/01a20b2b-0cd2-7b3b-8e49-74e27784a483?kind=lineups&layout=tents&boxes=standing:01a20b2b-1761-70b8-92ed-92c2cf0ee184&flags=1 |
| Name plates - teams | https://sandbox.myspeedpuzzling.com/en/name-tags/01a20b2b-0cd2-7b3b-8e49-74e27784a483?kind=lineups&layout=tents&boxes=standing:01a20b2b-1771-7168-9fde-59a64ba17de1&flags=1 |
| Name plates - individuals | https://sandbox.myspeedpuzzling.com/en/name-tags/01a20b2b-0cd2-7b3b-8e49-74e27784a483?kind=people&layout=tents&boxes=standing:01a20b2b-174b-7585-9110-174d0376882d&flags=1 |
| SPWS hall screen | https://sandbox.myspeedpuzzling.com/display/LeXaK4EjDQPFgh57cfRQFB |
| SPWS stream overlay (OBS) | https://sandbox.myspeedpuzzling.com/display/YCUazosLiaFFKEagMowinD |
| SPWS championship standings | https://sandbox.myspeedpuzzling.com/display/EHh5Pv6BxCG6WBKLtLsKFn |
| SPWS challenge board | https://sandbox.myspeedpuzzling.com/display/H3maevaPrtSqrgyUs784j4 |
| SPWS lower third | https://sandbox.myspeedpuzzling.com/display/XckkEw6YFjd6xv9iM7s6Ta |

Per round (desk = organisers' results desk, judge = the phone page, stopwatch = organisers' start page):

| Round | State | Desk | Judge | Stopwatch | Results | Analytics studio |
|---|---|---|---|---|---|---|
| Pairs - Challenges 1 + 2 | published | https://sandbox.myspeedpuzzling.com/en/manage-round-results/01a20b2b-11f8-72d4-8a5d-82d799388646 | https://sandbox.myspeedpuzzling.com/en/live-results/01a20b2b-11f8-72d4-8a5d-82d799388646 | https://sandbox.myspeedpuzzling.com/en/manage-round-stopwatch/01a20b2b-11f8-72d4-8a5d-82d799388646 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/results/pairs-challenges-1-2 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/analytics-studio/pairs-challenges-1-2 |
| Pairs - Challenges 3 + 4 | published | https://sandbox.myspeedpuzzling.com/en/manage-round-results/01a20b2b-1210-7d64-81ac-399437a1f9ff | https://sandbox.myspeedpuzzling.com/en/live-results/01a20b2b-1210-7d64-81ac-399437a1f9ff | https://sandbox.myspeedpuzzling.com/en/manage-round-stopwatch/01a20b2b-1210-7d64-81ac-399437a1f9ff | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/results/pairs-challenges-3-4 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/analytics-studio/pairs-challenges-3-4 |
| Pairs - Challenge 5 | published, unboxed | https://sandbox.myspeedpuzzling.com/en/manage-round-results/01a20b2b-121f-76a7-a383-8232999c8a52 | https://sandbox.myspeedpuzzling.com/en/live-results/01a20b2b-121f-76a7-a383-8232999c8a52 | https://sandbox.myspeedpuzzling.com/en/manage-round-stopwatch/01a20b2b-121f-76a7-a383-8232999c8a52 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/results/pairs-challenge-5 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/analytics-studio/pairs-challenge-5 |
| Teams - Challenges 1 + 2 | results in (5 finished, 5 with Challenge 1 only), stopwatch **not started** | https://sandbox.myspeedpuzzling.com/en/manage-round-results/01a20b2b-122b-77ba-a0b6-53f8ddf29ad0 | https://sandbox.myspeedpuzzling.com/en/live-results/01a20b2b-122b-77ba-a0b6-53f8ddf29ad0 | https://sandbox.myspeedpuzzling.com/en/manage-round-stopwatch/01a20b2b-122b-77ba-a0b6-53f8ddf29ad0 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/results/teams-challenges-1-2 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/analytics-studio/teams-challenges-1-2 |
| Teams - Challenges 3 + 4 | planned: 2 secret puzzles, reveal at the stopwatch start, Challenge 4 unboxed | https://sandbox.myspeedpuzzling.com/en/manage-round-results/01a20b2b-1237-79be-b897-ede5d631a1f6 | https://sandbox.myspeedpuzzling.com/en/live-results/01a20b2b-1237-79be-b897-ede5d631a1f6 | https://sandbox.myspeedpuzzling.com/en/manage-round-stopwatch/01a20b2b-1237-79be-b897-ede5d631a1f6 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/results/teams-challenges-3-4 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/analytics-studio/teams-challenges-3-4 |
| Individual - Challenge 1 | planned | https://sandbox.myspeedpuzzling.com/en/manage-round-results/01a20b2b-125d-75ce-b181-be1228eac59f | https://sandbox.myspeedpuzzling.com/en/live-results/01a20b2b-125d-75ce-b181-be1228eac59f | https://sandbox.myspeedpuzzling.com/en/manage-round-stopwatch/01a20b2b-125d-75ce-b181-be1228eac59f | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/results/individual-challenge-1 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/analytics-studio/individual-challenge-1 |
| Individual - Challenge 5 | planned, unboxed | https://sandbox.myspeedpuzzling.com/en/manage-round-results/01a20b2b-1293-7208-8b4e-860ddeb3fc54 | https://sandbox.myspeedpuzzling.com/en/live-results/01a20b2b-1293-7208-8b4e-860ddeb3fc54 | https://sandbox.myspeedpuzzling.com/en/manage-round-stopwatch/01a20b2b-1293-7208-8b4e-860ddeb3fc54 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/results/individual-challenge-5 | https://sandbox.myspeedpuzzling.com/en/events/spws-toronto-2027-demo/analytics-studio/individual-challenge-5 |

Individual Challenges 2-4 follow the same pattern (the build command prints every link).

Who is where (made-up players, password `sandbox`): table A1 of the teams = "Corner Sorters" (`test-user+91` …
`+94`); `test-user+92` (Alfie Linton) is also individual A1 R; pairs A1 L = "Antoni & Sem" (`test-user+37`, `+38`).

## 7. Notes from the walk (2026-11-24)

- **Finished now** on the judge's phone appears once the round's stopwatch runs (before that: type the time).
- The stream overlay's background is transparent (checked); a normal browser tab shows it on white.
- Before the call the standings of Teams are empty ("No round of this standing has published results yet") - they
  fill when the organiser publishes Teams Challenges 1 + 2 or 3 + 4 in the simulation.
- The Pairs no-show did not start in any of the three Pairs rounds of this build, so it is not in the Pairs standing
  (somebody who never came is left out); the Teams simulation's "Did not start" shows a no-show that stays ranked.
  A rebuilt event puts each Pairs no-show on another pair, so each stays ranked.
