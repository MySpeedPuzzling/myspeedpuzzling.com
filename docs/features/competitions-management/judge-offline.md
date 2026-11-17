# Judge page offline and fast (competition toolkit C1-C10)

How the referee's live entry page ([live-results.md](live-results.md)) survives a refresh, a dead venue network, a
sign-out and a shared phone, and how it got fewer taps. Packages C1-C10 of the toolkit (`toolkit/STATUS.md`); design
`toolkit/C-judge-page.md` §1, §2, §4, binding `toolkit/R-review-and-plan.md` §1.3, §1.7, §2.7, §2.8, §3 (where they
differ, the code follows R - see "Design vs build" below).

**Shape:** a data-free page that fills itself from one state fetch (C1), its own lean bundle (C2), a user-independent
offline copy of that markup per locale (the *shell*, C3) answered by the service worker when the network fails (C6),
rendered from per-player round *snapshots* in IndexedDB (C4/C5). Never a cached copy of the real page (a referee's
names, codes and token would sit in CacheStorage - `ServiceWorkerBehaviourTest`).

## What survives what

| Situation | What the judge sees |
|---|---|
| Refresh online | The page again + the tab's draft (open entry, typed value, a frozen "Finished now"); the outbox as always. |
| Network drops, page open | Nothing changes - saves wait in the outbox ("N waiting - offline"). |
| First state fetch fails | The round's snapshot with the offline-copy strip; state asked every 5 s, goes live by itself. |
| Reload / open a live URL offline | The worker answers the stored shell (after one 500 ms retry or 4 s silence) → snapshot or neutral screen. Only after one online visit (`live-shell:prepare`). |
| Sign-out / another player signs in | Cookie cleared → the next site page deletes snapshots + drafts; the shell says "You are offline - open this page again with a connection". Unsent results and the shell stay. |
| Native apps | No worker, no offline reload (the sheet says so); snapshots, drafts and the outbox work while open. |

## Lean layout + bundle (C2)

- `templates/live_results/_layout.html.twig` (not `base.html.twig`): no site header, footer, flashes, e-mail banners,
  modals, global search, analytics or Turbo; the site stylesheet (`encore_entry_link_tags('app')`) + its own Encore
  entry `live_entry` (`assets/live_entry.js`: Stimulus + `live-results` controller only, eager, registers the same
  `/service-worker.js` outside the native apps with an hourly `update()`). The inline self-heal scripts are the
  partials `base.html.twig` includes too (`LiveEntryLayoutTest` pins they are the same). Every link is a plain page
  load; views over Find get their own `pushState`.
- `webpack.config.js`: `live_entry` and `live_entry_native` take no split chunk (`splitChunks.chunks` excludes them and
  `display`), so the site's pages keep their script tags and the entry lists a complete file set.
- **No `import()` anywhere in the entry's graph** - the worker stores exactly what `entrypoints.json` lists with the
  shell. `LiveEntryBundleTest` (`tests/live-entry-bundle.mjs`, terser-minified modules, independent of the build in
  `public/build`): no lazy chunk, no Turbo / Live Components / Bootstrap JS / stimulus bridge, one file of its own,
  budget **190 KB minified / 52 KB gzip** (2026-11-17 after C9: 185 KB / 50.8 KB; history in the test's docblock -
  raise only on purpose, saying why in the commit).
- The service worker's install precache skips `live_entry` and `live_entry_native` (`PRECACHE_SKIP_ENTRIES`, with
  `display`): only referees' devices need them, and they store them explicitly (C6). C2 also fixed the precache itself
  (it silently did nothing: `runtime.js` listed twice made `cache.addAll()` refuse) - `ServiceWorkerInstallTest`.

## Data-free page + state parameters (C1)

- The page carries **no data of the round, its event or its reader**: it says "Loading the round…" and fills itself
  from one fetch of `official_results_round_state?viewer=1` (round, rounds, entries, clock, Mercure token,
  `viewer: {playerId, organiser}`). Every variant (solo / pair / team quick add, tables or not, organiser or referee)
  is in the markup hidden and picked by `assets/live_entry_page.js` (`pageSettings()`, `roundOptions()`,
  `resolveLanding()`, `fillLink()` over the link templates' placeholders `00000000-…` round / `11111111-…` event).
  A failed first fetch says so and retries by itself (2, 4 … 30 s, at once on `online`, Retry).
- State parameters (`RoundResultsStateController`, R §1.8; without them the JSON is the one every other consumer reads,
  `RoundResultsStateGoldenTest`): `?viewer=1` adds `viewer` (only the live entry asks); `?entries=0` leaves `entries`
  and its query out (clock + token only) - supported server side and by `stateUrl(…, {entries: false})`, `applyState()`
  keeps its entries on such an answer, but **no caller asks for it today**.
- Page landings (`LiveResultsController`, validated there, `landingOf()` in JS, one at a time: entrant, entry, table):
  `?entrant=<participantId>`, `?entry=<participant_round:id|team:id>`, `?table=<1-9999>` (the targets of the tag
  routes' judge branch, B2) - a malformed value is ignored, nobody there = "Nobody sits at table N in Group A" /
  "This entry is not in Group A"; each is taken out of the URL once used (`urlWithoutLanding()`).
- Quick add's event people: own endpoint `official_results_event_people`, asked for on first open; kept in snapshots.

## Shell route, device cookie, native apps (C3)

- `live_results_shell` (`/{en|es|ja|fr|de}/live-results-shell`, cs `/zadavani-vysledku-shell`,
  `LiveResultsShellController`): the very `live_results.html.twig` with nothing of any round or person -
  `data-live-results-shell-value="true"`, no round id, a sign-in link without a return (C5 adds it in JS), no
  `msp-rendered-at` meta (the stale-document check must not judge a copy kept for hours), never the native apps'
  Turbo. `Cache-Control: public, no-cache`, `X-Robots-Tag: noindex, nofollow`, on the `stateless` firewall (no
  session, no remember-me renewal, no cookie). It lists the files it needs in `<script type="application/json"
  id="msp-live-shell-assets">` (`shellAssets()`: the `live_entry` scripts, the `app` CSS, the Rubik + bootstrap-icons
  fonts). `LiveResultsShellControllerTest`.
- **Device cookie** `msp_live_player=<playerId>` (`LiveEntryDeviceCookie`): set only by `live_results`' answer (so
  only organisers and the event's referees - not by the state endpoint), `Path=/`, `SameSite=Lax`, `Secure` on https,
  **not HttpOnly** (the shell's JS reads it), lifetime `loginLifetimeSeconds` (30 days, like the sign-in). Not a
  credential - the server never reads it for access; it only decides whose snapshots the device shows.
  `LiveResultsDeviceCookieSubscriber` clears it on `LogoutEvent` (after the logout redirect is set) and on a
  `LoginSuccessEvent` of another player (any way in, remember-me included; one profile query only on a device carrying
  the cookie; the same player keeps it; a response that sets it for the new player is left alone).
  `LiveEntryDeviceCookieTest`.
- **Native apps** (`is_native_app()`): the layout adds the entry `live_entry_native` (`assets/live_entry_native.js`,
  Turbo with `session.drive = false`) - the apps' WebView finishes a visit only on a page that runs Turbo (without it,
  iOS waits 30 s, Android 4 s, then shows the error screen). Links stay plain loads, routed natively by the apps. The
  shell never carries it.

## Snapshots, drafts, device gate (C4)

- **Snapshots** (`assets/live_entry_snapshot.js`, `SnapshotStore`): own IndexedDB database `msp-live-entry` (never a
  store in the outbox's `msp-official-results` - a version upgrade there would block while another tab holds it),
  store `snapshots`, key `<playerId>:<roundId>`; record `{key, playerId, roundId, competitionId, savedAt,
  clockOffsetMs, clockMeasuredAt, state, eventPeople}` - `state` = competition, round (with the stopwatch), rounds,
  entries, viewer, proposals (when on); the Mercure token is never stored.
  - Written on every state the page gets (fetches, merged live updates): the page load's first state of a round at
    once (F1, D3 - an offline reload a second after opening finds it), later ones at most 2 s after the first change
    not yet written, the newest state then (F1, D4 - a steady stream of other judges' saves never postpones it);
    flushed best effort on `visibilitychange` hidden and `pagehide`. Never while the page runs on a snapshot (its time would claim knowledge
    the page does not have).
  - Only for the player the cookie names (`allowed()`): no cookie or another player → nothing read or written, also a
    write already queued when the device changed hands.
  - `start()` on page open: deletes other players' snapshots, those older than **36 h** and more than **30** per
    player; sets the device marker (localStorage `msp.liveEntry.player`).
  - A refused write stays in memory for this page; a store that cannot open is replaced by memory.
  - **Prefetch** (`prefetchTargets()`, `prefetchRounds()`): once per page, 10 s after it went live, the event's other
    rounds starting within **±12 h** (nearest first, **at most 4**), one request at a time 10 s apart; stops at a
    failed request, offline, an offline copy, or a state of another player; skipped on `saveData`. So a round switch or
    another group's tag works offline too.
- **Drafts** (`assets/live_entry_draft.js`): sessionStorage (the tab's), key `msp.liveResults.draft.<playerId>`, one
  per player: `{roundId, ref, view: entry|confirm, mode, typed, pieces, choice (C10), proposalId (C9), pending,
  finishedNow: {seconds, tappedAt}, savedAt}`, checked field by field (`normaliseDraft()`). Written on every input /
  view change, cleared on Save, Back and Undo, never saved by itself. Restored only for the same player and round,
  younger than 10 min, when the page was not opened for a landing; a "Finished now" comes back with "tapped at …",
  never re-measured. Another round's draft stays for that round's page.
- **Preferences**: one localStorage key `msp.liveResults.prefs` `{v, keyboard, autoOpenTable, scanAfterSave,
  myTables}` (the older `msp.liveResults.keyboard` taken over once). Only `keyboard` and `autoOpenTable` are used;
  `scanAfterSave` / `myTables` are stored defaults of deferred features (R §3).
- **Device gate** (`assets/live_entry_device.js`, small - the site's `app` bundle imports it): `app.js` runs
  `leftLiveEntryCleanup()` on every `turbo:load` - one localStorage read; when the marker names a player the cookie no
  longer names (signed out, another player), it deletes the whole `msp-live-entry` database (an open live tab closes
  its connection on `versionchange`), every draft in this tab and the marker. The live page's `setupSnapshots()` does
  its part on open (`forgetDraftsExcept()`, `start()`). The outbox is never touched here.
  `SnapshotStore.wipe()` exists (harness-tested) but no page calls it.

## Shell mode, route parsing, neutral screens, going live (C5)

- The controller sees `shell` and reads the URL it was answered at (`assets/live_entry_route.js`
  `parseLiveEntryRoute()`, pure): `live_results` (round), `live_results_event` (event), the printed name tag
  `/{locale}/live/{cid}/p/{pid}` (participant) and the frozen tag paths `/live/{cid}/g|t|e/…` with or without a locale
  (lineup / table 1-9999 / entry); ids lower-cased, trailing slash tolerated, any host.
- `assets/live_entry_shell.js` `shellPlan()` picks among the snapshots of the cookie's player: the round's own; for an
  event the JS port of `LiveResultsCurrentRound::pick()` (`currentRound()`, shared cases
  `tests/fixtures/live-current-round-cases.json`, run by PHPUnit and the node harness) then the round picked by hand
  within 12 h (`preferredRound()`); for a name tag the entrant's round (the entry opens), else the current one (which
  says they are not in it); line-up / table / entry tags their round across the snapshots (`snapshotOfTag()`, C8), else
  the current one.
- The round renders at once from the snapshot with the strip "No connection - this is the round as this device knew it
  at 10:42 …" (clock offset from the snapshot); Find, entry, Finished now, quick add, scan work; saves go into the
  outbox. The state is asked for every 5 s and on `online`; its first answer makes the page live (`goLive()`: strip
  gone, prefetch and offline prepare start; a state of another player reloads the page) and rewrites the address to the
  round's own live URL (`liveUrlOnceLive()` - an event or tag URL would pick again on a reload). Sign-in comes back to
  the URL asked for (`loginUrlWithReturn()`), after going live to the round's URL.
- **Neutral screens**, never a blank page (R §1.3 - a non-judge scanning a tag offline gets the shell too): no cookie →
  "You are offline - open this page again with a connection" + Retry + Sign in + the device's unsent count (any
  player's, from `msp-official-results`); no snapshot → "No offline copy of this round on this phone - connect once to
  load it" + "Saved on this phone:" links to its snapshots (the URL's event first, max 10); a round URL keeps asking for
  that round, a page without a round reloads on `online`.
- The outbox pause after offline failures is cut short by any state answered (`connectionBack()`, C7), so results
  saved on the shell go out as soon as it goes live - the "~20 s" of C5's first build is gone.

## Service worker (C6)

`public/service-worker.js`, rules in `.claude/rules/service-worker.md`.

- Once the page is live (its first state, or a shell going live) it posts `live-shell:prepare` with its locale and
  `scanner: true` when the browser reads no QR itself (`assets/live_entry_offline_ready.js`, once per page load, never
  in the native apps, 60 s timeout). The worker fetches the locale's `live_results_shell` (`credentials: 'omit'`,
  `cache: 'no-cache'`, refuses a redirect, non-200, non-HTML or `Set-Cookie`), checks every listed file is a same-origin
  `/build/` or `/fonts/` path, stores each into `static-v7` (buffered, `cache: 'reload'`), and **only then** the copy
  in `live-shell-v7` + a record `/__live-shell/{locale}.json` `{locale, assets, storedAt}` - a copy never points at a
  missing file. With `scanner` it also stores the zbar decoder (`/barcode-scanner/zbar-wasm-0.9.15/*`, best effort).
  It answers `live-shell:ready` / `live-shell:failed` → the unsent-results sheet says "Ready offline … (saved 10:42)".
- Navigations to exactly the paths `parseLiveEntryRoute()` reads (`LIVE_ENTRY_PATHS`; shared cases
  `tests/fixtures/live-entry-routes.json` + every router path, `ServiceWorkerLiveRoutesTest`) go to the network first -
  no cache read while it answers, a redirect or error page passed through. The stored copy of the URL's locale
  (header `X-MSP-Live-Shell: 1`) answers when the fetch fails (after the one 500 ms retry; none when the device knows it
  is offline) or stays silent for 4 s (`LIVE_ENTRY_TIMEOUT_MS`, the only navigation timeout). A locale-less tag path or
  a locale never prepared gets the newest stored copy; none stored → wait for the network, then the offline page.
- The prune after a deploy keeps every file a stored record lists. `/barcode-scanner/` is cache-first like `/build/`.
  `CACHE_VERSION` stays `v7` (a new cache name evicts nothing). The copy holds no user data, so sign-out leaves it.
- The tag scanner (`assets/official_results_scan.js`) loads zbar from our server first (`.wasm` fetched whole first),
  jsdelivr only when ours fails within 8 s - the EAN scanner's order.

## Speed (C7)

Described in [live-results.md](live-results.md) "The page" steps 1-4 and "Reliability": taps with the stopwatch running
4 (was 5: table → Finished now → Save) and 10 (was 11: typed time); unique table opens without Enter (`uniqueTable()`,
pref `autoOpenTable`); confirm in place with Save focused, `enterkeyhint="done"`, 350 ms guard (`confirmTapAllowed()`);
outbox groups never split (`enqueueGroup()`) and instant send when back (`connectionBack()`); O(1) entries view, perf
budget "300 entries and 300 unsent changes - search and rows under 5 ms" in `tests/live-results-harness.mjs`.

## QR kinds in the judge page (C8)

- One parser `parseCompetitionTagUrl(text)` in `assets/official_results_scan.js` → `{competitionId, kind:
  participant|lineup|table|entry, id}` (a table's id is its number): the printed name tag read exactly as before (own
  regex, any two-letter locale), the frozen `g|t|e` paths through `parseLiveEntryRoute()` (with or without a locale),
  any host, http(s) only. `parseNameTagUrl()` stays as the wrapper.
- `assets/live_entry_tag.js` (pure): `entryOfTag(entries, tag)` - participant → their own entry or their pair/team
  (`entryOfParticipant()`); table → the entry at that table in the round on screen; entry → by
  `CompetitionParticipantRound` / `CompetitionTeam` id; lineup → the entry whose exact member set hashes to the id:
  `lineupIdOf()` = `CompetitionLineupId::of()` (uuid5 in the event's namespace over the sorted, lower-cased member
  participant ids; synchronous SHA-1 of its own - `crypto.subtle` is async and missing outside https). Parity with PHP
  pinned by `tests/fixtures/competition-lineup-id-cases.json` (`CompetitionLineupIdTest` + the shell harness). The
  phone recomputes from `members`; the entries' server-side `lineupId` is not used.
- `scanned()`: another event's code → "This QR code is not a tag of this event" (a non-tag / name tag keeps "… not a
  name tag of this event"); a found entry opens (vibration); a person not in the round → "… not in Group A" + "Open
  their round" (the scan URL); a line-up / entry not in the round → "This pair/team does not play in …" / "This entry
  is not in …" + "Open their round" = the round from this phone's other snapshots of the event (`snapshotOfTag()`,
  `?entry=<ref>`), else the tag's own path (the server - or offline the shell - picks the round); an empty table →
  "Nobody sits at table N in …" (no link: a table is per round).
- Offline the shell resolves a scanned or opened tag URL across the event's snapshots (C5 above).

## Puzzle chips (C10) and proposals panel (C9)

Documented in [live-results.md](live-results.md): "Pick rounds" under step 4 of "The page" (chips, K-of-N order taps,
grouped `puzzles` + `result` saves, "Puzzle missing"), and "Time proposals" (panel, Confirm / Change / Not right,
"Proposals waiting", outbox `proposalId` + `proposal` reject). Both ride the drafts (`choice`, `proposalId`) and the
snapshots (`proposals`).

## Design vs build

- R §3 deferred the JS port of `LiveResultsCurrentRound::pick()`; C5 built it anyway (shared cases).
- R §2.8 expected `live_entry` precached for every visitor (~35 KB gzip); C3 put it on `PRECACHE_SKIP_ENTRIES`
  (stored per device on `live-shell:prepare`); the bundle is ~51 KB gzip now.
- R §2.7 / C §1.3: the cookie is set by the live page only, not by the state endpoint. Snapshots and drafts go on the
  next page after a sign-out (whole database), not in a logout-page script.
- C §1.4-1.8 items not built: `buildId` in the shell record and a re-prepare on a new build (once per page load
  instead), `?entries=1` / `?entries=0` fetches, prefetch `[−12 h, +24 h]` max 6 (R: ±12 h, max 4),
  `navigator.storage.persist()`, `liveUrlTemplate` in the snapshot, the "Stopwatch as known at … (offline)" confirm note
  and outbox `meta {finishedNowAt, stopwatchKnownAt}`, the ⚙ preferences panel, continuous scanning and "My tables"
  (deferred, R §3), C's `parseLiveQr` and `/r/{rid}/e/…` path (replaced by R §1.3).
- C §7.4 planned a Panther `tests/Panther/LiveEntryOfflineTest.php`; it does not exist - C1, C4, C6 were walked by hand
  in a browser (STATUS).

## Known gaps / real-device walks pending (H)

- Real devices not walked: the native apps' live page (C3, Turbo bootstrap), iPhone Safari offline reload, the camera
  scan (zbar from the worker's cache).
- C8: a pair/team with a **waitlisted member** hashes differently on the phone and on the server (open in STATUS).
- C8: "Open their round" to a tag path (and the name tag's scan URL) is a "leave" for `linkNavigation()` (not under the
  live entry prefix), so with unsent results the judge is asked whether to leave - the results are kept either way.
- C10 not tried: a reveal while a card is open, an offline group save.

## Tests

- PHP: `tests/Controller/LiveResults/` `LiveResultsShellControllerTest`, `LiveEntryDeviceCookieTest`,
  `LiveEntryLayoutTest`, `LiveResultsPageTest` (landings); `CompetitionLineupIdTest`, `LiveResultsCurrentRoundTest`
  (shared cases), `RoundResultsStateGoldenTest`; `LiveEntryBundleTest` (`tests/live-entry-bundle.mjs`).
- Node: `tests/live-entry-offline-harness.mjs` (`LiveEntryOfflineScriptsTest`: snapshots, retention, prefetch, cookie,
  gate, drafts, prefs); `tests/live-entry-shell-harness.mjs` (`LiveEntryShellScriptsTest`: route parsing, SW routes,
  offline-ready, zbar order, current-round port, `shellPlan()`, tag parser, line-up parity, offline tags, going live);
  `tests/live-results-harness.mjs` (`LiveResultsScriptsTest`: `uniqueTable`, confirm guard, groups, perf, chips,
  proposals).
- Service worker: `ServiceWorkerLiveShellTest` (`tests/service-worker-live-harness.js`, real in-memory caches),
  `ServiceWorkerLiveRoutesTest` (every router path), `ServiceWorkerInstallTest` (precache skip),
  `ServiceWorkerBehaviourTest` (no document from a cache but this one).
- Fixtures: `tests/fixtures/live-entry-routes.json`, `live-current-round-cases.json`,
  `competition-lineup-id-cases.json`.
