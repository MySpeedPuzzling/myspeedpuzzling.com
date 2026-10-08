# Live result entry and name tags

The referee's phone during a round, and the name tags that let it find people by camera. Built on the official results
core ([official-results.md](official-results.md): the round entries, `RecordRoundResults`, the JSON API, Mercure).
Decided 2026-10-07 (RESULTS-SPEC §4, §5): "focus on quick action, verify and click save".

## Routes

| Route | Path | Who | What |
|---|---|---|---|
| `live_results` | `GET /en/live-results/{roundId}` (localized, cs `/zadavani-vysledku/{roundId}`) | organisers and referees (`CompetitionResultsEntryVoter`) | the live entry page; `?entrant=<participantId>` opens that person's entry (theirs or their pair's/team's); `?notice=tag_unknown` says the scanned name tag is nobody of this event (any more) |
| `live_results_event` | `GET /en/live-results/event/{competitionId}` (localized, cs `/zadavani-vysledku-udalosti/{competitionId}`) | organisers and referees | the link to hand the referees (the referees page shows it with a QR): 302 to the current round (`?auto=1`), or - when the event has no rounds - to the round list (organisers) / the event page (referees) |
| `live_results_scan` | `GET /{_locale}/live/{competitionId}/p/{participantId}` - **never change this path**: printed name tags carry it | anyone | the URL in a name tag's QR: organisers and referees → the person's current round with them open; a person removed from the event, unknown or of another event → the current round with `?notice=tag_unknown`; an event without rounds → the round list with a warning; everybody else → the event page (`CompetitionDetailUrl`), an unknown event → the events list |
| `competition_name_tags` | `GET /en/name-tags/{competitionId}` (localized, cs `/jmenovky-ucastniku/{competitionId}`) | organisers | standalone A4 print page; `?round=<roundId>`, `?sort=name|table`, `?waitlist=1` (offered only while somebody is on the waitlist) |
| `competition_referees` | `GET/POST /en/manage-event-referees/{competitionId}` (localized) | organisers (`CompetitionEditVoter`) | the referees page: list, add by player search, remove (`competition_referee_remove`, POST + CSRF), the link for referees with copy button and QR (`competition_referees_qr_code`, SVG, `private, max-age=86400`) |

All of them answer `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`. The participants
sheet's Tools menu has "Name tags" (in-person events only) next to its other tools (registration settings, check-in);
the round list links `live_results` (results desk stream). The name tags sheet shows one tag per row on a phone (the A4 sheet
of two 90 mm columns would widen the page).

**Current round** (`LiveResultsCurrentRound`): the round whose stopwatch runs (the latest started if several run -
parallel halls), else the one started last within 12 hours (a stopwatch start, or the schedule once it passed), else
the next one, else the last one. The QR route applies it to the person's own rounds first. On the event link the
device's own pick wins while it is fresh (12 hours) and that round is not stopped (`preferredRound()`, localStorage
`msp.liveResults.round.<competitionId>`, written when the referee switches rounds).

## The page (mobile first, one thumb)

No site header/footer: a sticky bar with back, the round picker (a switch is a Turbo visit to the other round) and
the **sync pill**, and below it the stopwatch ("Running 0:47:12") and "Tables: 18 / 20" (in-person rounds that use
table numbers). The page renders from the state embedded in it (same JSON as `official_results_round_state`, its
live updates token included), then fetches that state once (clock sync).

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
   first and gets the focus (no keyboard over it): elapsed = server-synced now − `startedAt`, frozen at the tap. The
   page trusts the stopwatch it knows only while live updates flow or within 10 s of a state fetch; otherwise the tap
   fetches the state first (a stop or pause meanwhile never over-counts - a stopped stopwatch says "type the time";
   a failed fetch keeps what is known). Then
   the time field (`inputmode="numeric"`, the shared `parseResultTime()`, the parsed value always shown under it,
   "longer than the 90 min limit" as a note), **Didn't finish** → pieces placed (1..pieces−1 for a single-puzzle round),
   **Did not start**, **Clear**.
3. **Confirm** - table, name, the result in large type, what it replaces ("Replaces 1:24:00 (Eva, 10:42)") and Save
   (focused - Enter saves). Same value as saved = "Nothing changed", no write.
4. **Saved** - the change goes into the outbox, a toast "Saved: Puzzle Sharks · 1:23:45" with **Undo** (a waiting
   change is just dropped; a sent one gets the opposite change; then the entry opens again), back to Find with the
   input empty and focused.
5. **Quick add** - "Not on the list? Add an entrant": a name (solo round) or a pair/team name + members, optional table
   (refused on the device when another entry has it). While typing, "Already on the list?" shows the round's matching
   entries, and below them the **event's people who are no entry of this round** (`GetLiveResultsEventPeople`, embedded
   in the page: another group, a forgotten import row, the individual rounds' people in a pairs round - people going
   to the event only: somebody of the waitlist who turns up gets a spot in the participants sheet's People tab first). Picking one of
   them puts that participant into the round (solo: `newEntry.participantId`; a pair/team: the member field gets them,
   `members[].participantId`) - never a second person of the same name, so their link, country and notifications stay.
   The entrant is a `newEntry` with a device-made id: created on the server by its first saved change (the table
   number, else the result), never matched by name.

Views over Find get a history entry of their own (Turbo's history paused meanwhile, like `dynamic_modal_controller`),
so the phone's back button returns to Find instead of leaving. Lists and texts are re-rendered; inputs never are.

## Referees

At an in-person event volunteers enter results on their phones. They do not need - and must not get - the organisers'
rights (edit or delete the event, participants, registration, rounds, seating, qualification, publishing), so a
competition has **referees** (`CompetitionReferee`: competition, player, added by, added at; unique per competition +
player; an edition is a competition - series owners and maintainers stay full organisers of every edition).

- **Who may enter results** - `CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY` (subject = competition id):
  admins, everybody with `COMPETITION_EDIT`, and the competition's referees. `GetCompetitionPermissions` loads the
  referee rows in the same one statement per request as the organiser rights (`canEnterResults()`), so a referee
  check costs no query of its own.
- **What a referee may do**: open `live_results` / `live_results_event` / `live_results_scan` (a name-tag QR opens the
  live entry like for an organiser), read the round state (`official_results_round_state`) and send result changes
  (`official_results_record`). `RecordRoundResultsController` sets `RecordRoundResults::$resultsOnly` for a caller
  without `COMPETITION_EDIT`, and the handler refuses every `table_number` / `qualified` change of that set one by one
  (`rejected`, reason `results_only`, translated) while the results in the same set go through. Quick add works with a
  result (the new entrant is created by the result change; a table number sent with it is refused). Everything else -
  results desk, export, seating, publish/unpublish, table numbers, advancing, the overview, rounds, stopwatch control,
  participants, registration, check-in, name tags, the event edit page, the referees page - stays `COMPETITION_EDIT`.
- **The page for a referee** hides what they cannot use: no back link to the round list, no results desk / seating
  links, no "give every entry a table number" hint, no table field in quick add (and its note promises the first
  result only, not a table number - like an organiser's on a round without tables).
- **Private players** (browser verification of PR #136): a referee never gets the #code, profile name or id of a
  linked player who is private to them - PrivateProfileAccess decides, so one who lets the referee see them (allow
  list) stays visible. `RefereeEntriesView` masks the round's entries (`RoundResultEntry::forReferee()`, flagged
  `playerWithheld`) in the page's initial state, `official_results_round_state` and the answers to the referee's own
  saves; the quick add's event people (`GetLiveResultsEventPeople`, `withPrivateCodes: false`) lose the code the same
  way. The referee's live updates come on a topic of their own, `/round-results/{roundId}/referees`
  (`OfficialResultsSubscription::forRound($roundId, organiser: false)`): an update has no viewer, so it withholds
  every private player, and the live entry keeps what its own state showed of them (`keepWithheldPlayers()` in
  `official_results_live.js`). Organisers see every code as recorded. `RefereePrivatePlayersTest`.
- **Referees page** (`competition_referees`, linked from the event/edition edit page and - through
  `official_results/_referees_link.html.twig` - from the results overview): the referees with who added them and
  when, "Add referees" (the maintainers' player picker, `player_search_autocomplete`, up to 10 at once;
  `AddCompetitionReferee` answers `added` / `already_referee` / `organiser` (an organiser is never stored - they can
  enter results anyway) / `unknown_player` / `limit_reached` (200 per competition), shown as flashes), Remove
  (`RemoveCompetitionReferee`, CSRF; an expired form gets the page again with 422 and removes nothing; results the
  referee entered keep "entered by"), and the **link for referees** - the absolute `live_results_event` URL in the
  page's language with a copy button and its QR (`NameTagQrCode`), to send or to scan from the organiser's screen.
  Referees need a MySpeedPuzzling account and sign in with it. `noindex`, `private, no-store`.
- Referees do not see the event under "My events" - they open the link they were given (the "My events" cards are
  organiser cards with edit/delete; see docs/TODO.md).
- A referee's account deletion removes the row (FK cascade); the organiser who added them going leaves `added_by` empty.

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
  of that field sent again as one, from their value; "Take theirs" = mine dropped); refused → kept with the server's
  translated reason, or the generic translated "could not be read" - never a key or a developer text ("Fix" opens the
  entry with the value, "Discard"); a new save of that field replaces a refused/conflicting one. The server keeps the id
  of every change it took (`RoundResultChangeReceipt`): a replay whose answer got lost is `unchanged` even after
  somebody corrected the value meanwhile.
- **Failures** - only our endpoint's JSON is a verdict (`officialResultsRequest()` kinds):
  - signed out (401, any redirect, a 200 that is not JSON) → stop, keep everything, "Sign in again" banner with a
    sign-in link in a new tab and Retry (also retried when the window gets the focus back);
  - 403 `invalid_csrf_token` → stop, "This page is out of date - reload it" with Reload;
  - 403 `forbidden` (no rights for that round's event, e.g. yesterday's event the referee is no maintainer of any more)
    → **only that round's changes** are set apart (`state: forbidden`), every other round goes on; a banner counts them
    with "Show" (the list: Retry, and Discard with a confirmation, "Discard them" for all); the page's own round
    forbidden (its state fetch too) shows "This account may not enter results of this event";
  - 404 `round_not_found` (the round was deleted) → that round's waiting changes are refused with the reason;
  - offline/timeout (15 s) → retry after 1, 2, 4 … 30 s, and on `online`/visibility;
  - busy: 408 / 425 / 429 and every 4xx that is not our JSON (a rate limiter's or CrowdSec's HTML page, a proxy) → no
    verdict on any change: everything pauses for the `Retry-After` the answer asks (at most 15 min - Retry sends at
    once), else 5 s, 15 s … 2 min; nothing is refused, no batch is split;
  - 5xx → the batch is split up, a change failing alone pauses 5 s, 15 s … 2 min on its own (at least its
    `Retry-After`; after 3 attempts shown as "the server keeps failing"); a whole set the server cannot read is split
    up and the unreadable change refused alone.
  - A waiting change of a round of **another event** (not in this page's round list) can be discarded with a
    confirmation.
- **Two tabs**: one sends at a time (Web Locks `ifAvailable`, a localStorage lease where they are missing); every tab
  reads the shared store and a BroadcastChannel tells the others to re-read. Two senders at once would do no harm -
  replays answer `unchanged`.
- **Leaving**: `beforeunload` warns while anything is unsent; a Turbo visit out of the tool asks first (another live
  entry page is fine - it sends them).
- **Sync pill**, always visible: "All saved" / "Saving…" / "3 waiting" / "3 waiting - offline" / "2 need you"
  (conflicts, refusals, changes without rights) / "Sign in again" / "No permission" / "Reload the page"; tap → the list
  of what is not saved yet with the actions above.
- No service worker changes: the page itself needs the network to load; once loaded it works offline.

## Live updates

The page follows the round's private `/round-results/{roundId}` (a referee: `/round-results/{roundId}/referees`, see
"Private players" above) and the public `/round-stopwatch/{roundId}` on one stream of its own, authorised by the
subscriber token its state carries (`mercure`, minted after the voter - a referee gets their round's too) and kept by `OfficialResultsEvents` (`assets/official_results_events.js`, shared with the desk,
seating and the overview - official-results.md "Subscribing: a token per page, never the cookie"): reopened with
backoff after a drop, an error or the hub's write timeout (then the state is fetched again - catch-up), renewed with
the state before the token ends, stopped while the state answers signed out / no rights (until Retry). The Mercure
cookie is no longer involved - other responses can not take the round away from the page any more.
Entries updates are merged by ref (own saves arrive twice, harmlessly); `refresh` refetches; the stopwatch payload
updates the clock; the state is also fetched every 60 s while visible and when the tab comes back. "Finished now"
trusts the stopwatch only while the stream is live (open and not silent) or the state was fetched in the last 10 s.
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
(`build/images/<country>.svg`), #CODE of a linked player **with a public profile** (a badge is worn in public - a
private player's code is never printed, whoever prints), the table of the person's **first** round by schedule (a
pair's/team's number in a pair/team round; none when that round has none or does not use table numbers - the page says
to print again after re-seating), and a QR (`NameTagQrCode`: bacon/bacon-qr-code SVG inline, error correction M,
~9 KB each - no request per tag) of the absolute `live_results_scan` URL in the page's language. Everybody going
(`CompetitionParticipantGoing`: not removed, not waitlisted - the waitlist too when the organiser ticks "Also the N
people on the waitlist") by name, or one round's people with that round's numbers; sorted by name or table (no table
last). `GetCompetitionNameTags`: one statement.

Drawing a QR costs ~15 ms (the ~110-character URL is a version 7 code), so each SVG is cached per URL in the
`name_tag_qr_cache` pool for 90 days: 200 tags shown again render in ~0.1 s instead of ~4 s (pinned by a test). The URL
keeps the route's shape - printed tags keep working and the in-page scanner checks the event's id in it.

## Tests

`tests/Controller/LiveResults/` (page access, state, entrant, the event's people for quick add, the live updates token,
event link, QR route for organisers / others / foreign / unknown / removed / an event without rounds, name tags content,
private codes, the waitlist, 200 tags with cached codes, round filter, sort; the participants sheet's Tools entry is
pinned by `tests/Controller/ParticipantsSheet/ParticipantsSheetPageTest.php`),
`tests/Controller/Referees/` (who may enter results, a referee's result / refused table and qualified changes / quick
add, every organiser-only page and endpoint refused to a referee, event link and QR route for a referee, the referees
page: access, add, organiser/duplicate notes, 422, remove, CSRF, series maintainers), `tests/MessageHandler/
CompetitionRefereeHandlersTest.php`, `tests/Query/GetCompetitionPermissionsTest.php` (`canEnterResults()`),
`tests/Services/LiveResults/LiveResultsCurrentRoundTest.php`, `tests/LiveResultsScriptsTest.php` running
`tests/live-results-harness.mjs` under node (the outbox state machine with a fake server and clock: stored before sent,
order, batches, conflicts, refusals, auth, a 403 for one event while another goes on, a stale page, a busy server with
and without Retry-After, a deleted round, offline backoff, server-error isolation, two tabs, lease fallback, foreign
account, broken store; the JSON client's classification of answers; search; shown values; recent list; result texts;
time parser; QR URL parsing; round preference); the live updates stream: `tests/OfficialResultsEventsScriptsTest.php`
and `tests/Controller/OfficialResults/LiveUpdatesSubscriptionTest.php` (official-results.md).

## Follow-ups

- The live updates stream is not covered by an automated browser test (the dev hub runs on another host); the module
  is pinned under node, and was run against the dev hub (Mercure 0.24.2) with tokens from `OfficialResultsSubscription`:
  private + public updates on one token, nothing without it, 401 → fresh token → reopened, the hub's close before `exp`.
- Table numbers are not edited on the live entry (only given to a quick-added entrant) - that is the seating page's job.
- The native apps could get a QR mode in their scanner bridge.
- Quick add has no country field (the name tag and "best of each country" need one - set it in the participants sheet).
