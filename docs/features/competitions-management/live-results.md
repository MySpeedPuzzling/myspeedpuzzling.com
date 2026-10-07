# Live result entry and name tags

The referee's phone during a round, and the name tags that let it find people by camera. Built on the official results
core ([official-results.md](official-results.md): the round entries, `RecordRoundResults`, the JSON API, Mercure).
Decided 2026-10-07 (RESULTS-SPEC §4, §5): "focus on quick action, verify and click save".

## Routes

| Route | Path | Who | What |
|---|---|---|---|
| `live_results` | `GET /{_locale}/live-results/{roundId}` | organisers (`CompetitionEditVoter`) | the live entry page; `?entrant=<participantId>` opens that person's entry (theirs or their pair's/team's) |
| `live_results_event` | `GET /{_locale}/live-results/event/{competitionId}` | organisers | the link to hand the referees: 302 to the current round (`?auto=1`), or to the round list when the event has no rounds |
| `live_results_scan` | `GET /{_locale}/live/{competitionId}/p/{participantId}` | anyone | the URL in a name tag's QR: organisers → the person's current round with them open; everybody else, unknown/removed/other event's people → the event page (`CompetitionDetailUrl`), an unknown event → the events list |
| `competition_name_tags` | `GET /{_locale}/name-tags/{competitionId}` | organisers | standalone A4 print page; `?round=<roundId>`, `?sort=name|table` |

All of them answer `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`. The participants page
has a "Name tags" button (in-person events only); the round list links `live_results` (results desk stream).

**Current round** (`LiveResultsCurrentRound`): the round whose stopwatch runs (the latest started if several run -
parallel halls), else the one started last within 12 hours (a stopwatch start, or the schedule once it passed), else
the next one, else the last one. The QR route applies it to the person's own rounds first. On the event link the
device's own pick wins while it is fresh (12 hours) and that round is not stopped (`preferredRound()`, localStorage
`msp.liveResults.round.<competitionId>`, written when the referee switches rounds).

## The page (mobile first, one thumb)

No site header/footer: a sticky bar with back, the round picker (a switch is a Turbo visit to the other round) and
the **sync pill**, and below it the stopwatch ("Running 0:47:12") and "Tables: 18 / 20" (in-person rounds that use
table numbers). The page renders from the state embedded in it (same JSON as `official_results_round_state`), then
fetches that state once (clock sync + Mercure cookie, see below).

1. **Find** - one input "Table, name or #code" with a 123/ABC switch (numeric pad by default when the round uses
   table numbers, remembered per device - iOS's number pad has no Enter, the big first row is the tap target) and
   Scan. Client-side (`searchEntries()`): an exact table number first (`12` + Enter opens table 12), then tables
   starting with it, then names; names, pair/team names, members' names and player names accent-insensitive, every
   word as a word start; `#code` = the linked player's code (members' too). Enter opens the exact table, else the only
   match. With table numbers off (or online) only names. Empty input = **Recent entries** (`recentEntries()`):
   results by `enteredAt` from any device plus this device's unsent ones, newest first, one row per entry, each with its
   sync state (✓ saved / clock = not sent / ⚠ needs you). The "recommended: give every entry a table number" hint
   shows while not everybody is seated and the round's seating step holds (`tablesReadiness`, the one rule of
   [seating.md](seating.md#the-seating-step-one-rule) - a round under way or over never nags), with the seating link.
2. **Entry card** - table, name, members/#code, "Saved: 1:23:45 · Eva · 10:42" (or "Not sent yet: …"), problems of
   this entry (conflict, refused, failing) with their actions. While the round's stopwatch runs **Finished now** comes
   first and gets the focus (no keyboard over it): elapsed = server-synced now − `startedAt`, frozen at the tap. Then
   the time field (`inputmode="numeric"`, the shared `parseResultTime()`, the parsed value always shown under it,
   "longer than the 90 min limit" as a note), **Didn't finish** → pieces placed (1..pieces−1 for a single-puzzle round),
   **Did not start**, **Clear**.
3. **Confirm** - table, name, the result in large type, what it replaces ("Replaces 1:24:00 (Eva, 10:42)") and Save
   (focused - Enter saves). Same value as saved = "Nothing changed", no write.
4. **Saved** - the change goes into the outbox, a toast "Saved: Puzzle Sharks · 1:23:45" with **Undo** (a waiting
   change is just dropped; a sent one gets the opposite change; then the entry opens again), back to Find with the
   input empty and focused.
5. **Quick add** - "Not on the list? Add an entrant": a name (solo round) or a pair/team name + members, optional table
   (refused on the device when another entry has it). "Already on the list?" shows matches while typing. The entrant is
   a `newEntry` with a device-made id: created on the server by its first saved change (the table number, else the
   result), never matched by name.

Views over Find get a history entry of their own (Turbo's history paused meanwhile, like `dynamic_modal_controller`),
so the phone's back button returns to Find instead of leaving. Lists and texts are re-rendered; inputs never are.

## Reliability (`assets/official_results_outbox.js`)

- **Outbox in IndexedDB** (`msp-official-results` / `outbox`), one record per change: `userId`, `roundId`, `seq`,
  `entryRef`, `newEntry`, `field`, `from`, `to`, `state`, `attempts`, `nextAttemptAt`. Stored **before** the network is
  asked (every store operation runs through one queue); a store that refuses (private mode, full) keeps the change in
  memory, still sends it and shows "this browser cannot keep unsent results". Another account's changes on the device
  are never sent ("N results entered under another account wait on this device").
- **From** = what the device believes the server holds: its own earlier waiting change's value, the other device's
  value of a conflict being overwritten, else the server value.
- **Sending**: per round, in saving order, batches of ≤ 100 (the server chains changes of one field in a set). A change
  waits only behind earlier changes of the same entry's field; other entries go on. Every change of every round of the
  signed-in organiser is sent from any live entry page (a round switch loses nothing).
- **Answers**: applied/unchanged → gone; conflict → kept with the other value and who/when ("Keep mine" = all my changes
  of that field sent again as one, from their value; "Take theirs" = mine dropped); refused → kept with the translated
  reason ("Fix" opens the entry with the value, "Discard"); a new save of that field replaces a refused/conflicting one.
- **Failures**: signed out (401, any redirect, a 200 that is not JSON) → stop, keep everything, "Sign in again" banner
  with a sign-in link in a new tab and Retry (also retried when the window gets the focus back); 403 → "No permission",
  same; offline/timeout (15 s) → retry after 1, 2, 4 … 30 s, and on `online`/visibility; 5xx → the batch is split up,
  a change failing alone pauses 5 s, 15 s … 2 min on its own (after 3 attempts shown as "the server keeps failing"); a
  whole set the server cannot read is split up and the unreadable change refused alone.
- **Two tabs**: one sends at a time (Web Locks `ifAvailable`, a localStorage lease where they are missing); every tab
  reads the shared store and a BroadcastChannel tells the others to re-read. Two senders at once would do no harm -
  replays answer `unchanged`.
- **Leaving**: `beforeunload` warns while anything is unsent; a Turbo visit out of the tool asks first (another live
  entry page is fine - it sends them).
- **Sync pill**, always visible: "All saved" / "Saving…" / "3 waiting" / "3 waiting - offline" / "2 need you" /
  "Sign in again" / "No permission"; tap → the list of what is not saved yet with the actions above.
- No service worker changes: the page itself needs the network to load; once loaded it works offline.

## Live updates

The page subscribes with its own `EventSource` to the round's private `/round-results/{roundId}` and the public
`/round-stopwatch/{roundId}` - **after** its state fetch: `RoundResultsStateController` and
`RecordRoundResultsController` add the round's topic to `MercureTopicCollector`, because the Mercure subscriber
cookie is rewritten by every response of a signed-in user and a reconnect after a Wi-Fi drop would otherwise lose the
private topic. Entries updates are merged by ref (own saves arrive twice, harmlessly); `refresh` refetches; the
stopwatch payload updates the clock. After a drop the state is fetched again (catch-up), and every 60 s while visible.
The clock offset is NTP-style (midpoint of the state request).

## Scanning (`assets/official_results_scan.js`)

Lazy, separate from the EAN scanner (`barcode_scanner_controller.js` unchanged): `BarcodeDetector` with `qr_code` where
the browser reads QR, else the same zbar-wasm + polyfill scripts from the same URLs (loaded once per page whoever asks
first). Full-screen camera, ~10 reads a second, vibration on a read. `parseNameTagUrl()` reads the ids from any
`http(s)://…/{locale}/live/{competitionId}/p/{participantId}`; another event's tag or any other code → "This QR code
is not a name tag of this event"; a person not in the selected round → "… not in Group A" + "Open their round" (the
scan URL). The native apps' scanner bridge reads EAN only, so the apps use the web camera here.

## Name tags (`competition_name_tags`)

A4, 2 × 5 tags of 90 × 55 mm with dashed cut lines; each: event (and round), name (smaller from 25 characters), flag
(`build/images/<country>.svg`), #CODE of a linked player, the table of the person's **first** round by schedule (a
pair's/team's number in a pair/team round; none when that round has none or does not use table numbers), and a QR
(`NameTagQrCode`: bacon/bacon-qr-code SVG inline, error correction M, ~9 KB each - no request per tag) of the absolute
`live_results_scan` URL in the page's language. Everybody active (not removed, not waitlisted) by name, or one round's
people with that round's numbers; sorted by name or table (no table last). `GetCompetitionNameTags`: one statement.

## Tests

`tests/Controller/LiveResults/` (page access, state, entrant, the Mercure cookie, event link, QR route for organisers /
others / foreign / unknown, name tags content, round filter, sort, the participants page button),
`tests/Services/LiveResults/LiveResultsCurrentRoundTest.php`, `tests/LiveResultsScriptsTest.php` running
`tests/live-results-harness.mjs` under node (the outbox state machine with a fake server and clock: stored before sent,
order, batches, conflicts, refusals, auth, offline backoff, server-error isolation, two tabs, lease fallback, foreign
account, broken store; search; shown values; recent list; result texts; time parser; QR URL parsing; round preference).

## Follow-ups

- Mercure for the live entry is not covered by an automated browser test (the dev hub runs on another host).
- Table numbers are not edited on the live entry (only given to a quick-added entrant) - that is the seating page's job.
- The native apps could get a QR mode in their scanner bridge.
- Quick add has no country field (the name tag and "best of each country" need one - set it on the participants page).
