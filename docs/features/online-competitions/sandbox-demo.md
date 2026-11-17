# Online competitions - live demo on the sandbox

A real-time test of online timing on **sandbox.myspeedpuzzling.com** (made-up data, nobody real is affected): your
laptop is the organiser, one or more phones are participants. Takes about 30 minutes. The set-up is scripted through the
sandbox's internal API (`docs/features/internal-api.md` §"Online timing") - the demo event is a **draft**, so only its
editors (sandbox admins) see it.

## What you need

- A laptop signed in on the sandbox as an admin (Enter on the welcome page, then sign in).
- 1-3 phones (iPhone in Safari, Android in Chrome), sound on, charger, a stand or something to lean the phone on.
- A puzzle (any size - a small one finishes quicker) and a plain table or mat.
- Live video only when the sandbox's LiveKit is live (lily D106) - otherwise every video switch stays hidden and the
  rest works exactly the same.

## The demo event

The orchestrator creates (or re-creates) it and gives you the links:

- event **"Online timing demo"** (online, draft), one solo round **"Demo round"** of 15 minutes;
- online timing on, finish detection `on` (raise both hands to finish; the Finished button always works);
- participants **Demo Phone 1, 2, 3** with personal links (`/compete/<token>`, one per phone - never share them).

## Run it

| # | Where | Do | What must happen |
|---|---|---|---|
| 1 | laptop | Open the control room link | The live grid: every participant "Offline" |
| 2 | phone | Open "Demo Phone 1"'s link (scan its QR from the laptop or send it to yourself) → Enter on the sandbox welcome page if asked | The welcome screen of the round, the participant's name on top |
| 3 | phone | Consent → allow the camera → frame the puzzle (the phone 1-1.5 m away, sees the table and you) | Checklist green: camera, clock "Synced (± N ms)", connection, sound |
| 4 | phone | Try the set-up test: raise both hands for a second | "Detection works" (the view it picked: angled / top-down) |
| 5 | phone | "I'm ready" | Waiting screen with the start time; the laptop's grid shows the phone "Ready", camera thumbnail, clock quality |
| 6 | laptop | Control room → **"Start at"** a whole minute **at least 2 minutes ahead** | Every phone counts down to that moment (10 → 0 with beeps), GO on all of them at the same instant |
| 7 | phone | Puzzle. Somewhere in the middle raise your hands once on purpose, then keep going | A green flash "Finish recorded at …" - keep going; it is a candidate, not the result |
| 8 | phone | Finish the puzzle, raise both hands | "Finish recorded at mm:ss"; the laptop shows the finish in the grid at once |
| 9 | phone | (optional) also tap **Finished** | Recorded too (the review compares tap and detection) |
| 10 | laptop | When everyone is done: **"End now"** (or let the 15 minutes run out) | Unfinished phones ask "How many pieces are left?" |
| 11 | laptop | **Review** | Each participant's moments: the early raise (photo shows gaps → press **G**, "shows a gap"), the real finish (photo complete → **C** confirm) |
| 12 | laptop | **Accuracy** | Detection vs tap vs confirmed time, the early raise counted as a premature raise |
| 13 | laptop | (optional) Stopwatch page → **Reset** | The round is ready to run again with the same links (before any result is confirmed) |

**With live video** (once the sandbox's LiveKit is live and the round's "Live video" switch is on - the orchestrator
turns it on through the API): after step 5 the phone's camera appears in the control room's video grid; click a tile for
the big view; hold "Talk" to speak to the phones; "Invite to the studio" on a finisher → the phone asks "Join / Not
now" → on air in the studio; the public watch page (`…/online-rounds/<roundId>/watch`) shows the studio to anybody
signed in to the sandbox. Test one phone on mobile data (UDP) and one laptop on a network that blocks UDP (TURN over
443) - both must show video.

## What to note

Per phone: model, iOS / Android + browser version, the clock's "± N ms", whether GO matched the other phones, whether
the raise was recognised (and how fast), anything that looked wrong (a screenshot). The detailed per-device protocol is
`device-test.md` (65 steps) - this demo is its short version.

## Re-running and cleaning up

- Same event again: Reset on the round's stopwatch page (step 13). New participants: the orchestrator adds them through
  the API (`POST /internal-api/competitions/{id}/participants`, idempotent by name).
- Remove it: the event's ⋯ menu → Delete (only without results) - or keep it as the sandbox's demo event.
