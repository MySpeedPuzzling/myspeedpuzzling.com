# Speed Puzzle World Series - demo guide (sandbox)

For Jan and the SPWS organisers: what to open, how to sign in and what to click to see every role of the
competition toolkit on a made-up copy of the SPWS format. Everything here is on the **sandbox**
(https://sandbox.myspeedpuzzling.com) - a test copy of MySpeedPuzzling: made-up people, nothing reaches the real site,
nothing can break. How the event is built: [spws-demo-event.md](../spws-demo-event.md).

## 0. Before the call (Jan, 5 minutes)

- The sandbox shows a welcome page first - click **Enter** (once per browser).
- The event: **Speed Puzzle World Series Toronto 2027 - demo**, `/en/events/spws-toronto-2027-demo`.
- It is laid out as if today were the **Teams day** (Thursday): Pairs (yesterday) is finished and published, Teams
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
2. **Seating** of "Pairs - Challenges 1 + 2": tables named A1 L, A1 R, A2 L … (two places per table; Teams: A1, A2 …
   one table per team). "Table names" panel: change the scheme (e.g. A1-1 / A1-2) for the whole championship.
3. **Name plates**: Toolkit → Name tags → Table tents, "Time boxes for" = the championship, "Show country flags":
   name, flag, table, pair/team name, QR and a box per challenge for writing times by pen. Print preview.
4. **Results desk** of "Teams - Challenges 1 + 2": who has finished, who has only Challenge 1, the standing preview.
5. **Standings** (`/en/manage-event-standings/<event id>`): one standing per championship, rules 5 s per missing piece,
   total time only, a no-show stays ranked.
6. **Displays** (`/en/manage-displays/<event id>`): five screens in the SPWS navy + gold - open, edit, copy the link.

### Judge (phone, 375 px)
1. Sign in as the judge → the round's judge page (`/en/live-results/<round id>`), add it to the home screen.
2. Type `a1` → the table A1 opens without Enter → **Finished now** per challenge (a block: Challenge 3's split
   first, then Challenge 4 = the time), or **Didn't finish** with the pieces still loose, or **Missing pieces**.
3. Airplane mode: keep entering - the phone keeps the results and sends them when back online.

### Competitor (phone)
1. Sign in as a made-up player → the event page → "Your page" (or scan the QR on the plate).
2. Their table, the clock of their round, their results and rank; with the stopwatch running, **Stop** proposes
   their time to the judge, who confirms it on the table's card.

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
| 12-15 | Organiser | **Stopwatch** of "Teams - Challenges 3 + 4" (`/en/manage-round-stopwatch/<round id>`) → tick **"Reveal the puzzle(s) when the stopwatch starts"** → Start. The two secret puzzles appear on the event page and screens at that moment; Challenge 4 stays without a box picture (unboxed) until the results are published. |
| 15-25 | Judges | Judge 1 (`test-user+2`) on a phone: table `a1` → Finished now (Challenge 3), later Finished now (Challenge 4). Judge 2 (an organiser's phone): one team **Didn't finish** with 40 pieces loose, one **finished with 1 piece missing**, one **Did not start** (no-show). |
| 18 | Competitor | Signed in as a made-up player of a team: their page → Stop → the judge sees the proposal on the table card and confirms. |
| 15-25 | Everyone | Watch the hall screen and the overlay update (finished count, top 10, latest finishers); the challenge board. |
| 25-30 | Commentators | Analytics studio of the running round: entrants, history, predictions, live order. Live control page: type a message → it shows on the lower third. |
| 30-35 | Organiser | Results desk → review → **Publish** → the Teams standing moves (Challenges 3 + 4 counted, the no-show ranked with its penalty, the missing piece +5 s) → the embed on "their website" shows the same. |
| 35-45 | All | Questions; the organisers can set the same format up themselves in the **event creation wizard** ("Several rounds" → "No - challenges added up per championship", the Challenges template with labels and Unboxed per puzzle). |

## 4. What is made up

Every person, pair, team and result. The puzzles are catalogue stand-ins of the right brands (Speedmaster,
Boardwalk, Wentworth wooden 250, Buffalo Games, Ravensburger); Teams Challenges 3 + 4 are two invented secret
puzzles. The two places per table are named "L"/"R" until SPWS decides.

## 5. After a rehearsal

A started stopwatch, revealed puzzles and typed results stay. To start over, Jan deletes the event (admin) and runs
the build command again (README "Demo events").

## 6. URLs (built 2026-11-24)

Filled in after the sandbox build - see the next section of this file once the event exists.
