# C - Judge (referee) live entry page: offline shell, WHO, TIME, speed

Architect C, 2026-11-17. Scope: the `live_results` page (Jan's "judge page") - survive refresh and load offline, the new
QR kinds, puzzle choice, confirming a competitor's proposed time, a speed audit, and judge management. Grounded in the
code on `main` at `0ad091b37`. "Judge" = the code's "referee" (`CompetitionReferee`); the UI keeps the word the
translations use today.

Everything here is opt-in in the sense of the build's rule 1: a competition that uses none of the new settings (no
puzzle choice, no proposals, no table/entry QR) sends the same round state and the same change sets as today. The
offline shell is a property of the page, not of a competition - it changes no server answer of an existing route
(see "Rule 1 check" at the end).

---

## 0. Findings (what the code does today)

### Page and assets
- **The page extends the full site layout.** `templates/live_results/live_results.html.twig:1` extends `base.html.twig`
  and empties `header` + `footer` (`:116-117`) - verified: no site header, no footer. But everything else of
  `base.html.twig` still renders: flash messages and the e-mail undeliverable / unverified banners sit **above** the
  page (`base.html.twig:960-977`, outside the emptied blocks - a judge with an unverified e-mail loses ~80 px of a
  375 px screen), plus the members modal, the global search overlay, the `modal-frame`, the insights sheet, the
  analytics controller (`base.html.twig:491`, `1012-1080`).
- **JS:** the page's controller is a *lazy* Stimulus chunk (`assets/controllers/live_results_controller.js:1`
  `stimulusFetch: 'lazy'`), loaded after the whole `app` entry (Turbo, Stimulus bridge, Live Components, Bootstrap JS,
  every eager controller) has booted. Own code minified (terser, measured today): controller 43.5 KB, outbox 10.8 KB,
  events 7.7 KB, live 4.6 KB, scan 2.5 KB, stopwatch clock 2.3 KB, rest ~5 KB = **~76 KB min / ~25 KB gzip**. The
  page is visible at first paint (server-rendered) but not interactive until `app.js` + the lazy chunk arrive - one
  extra round trip on venue Wi-Fi.
- **Offline:** `docs/features/competitions-management/live-results.md:161` "No service worker changes: the page itself
  needs the network to load". The worker (`public/service-worker.js:150`) answers every navigation network-only with
  `offline.html` as the fallback; `tests/ServiceWorkerBehaviourTest.php:54-60` pins that no document is ever read from
  or written to a cache. A refresh / tab discard / round switch while offline = "You are offline" page, the unsent
  outbox invisible (still safe in IndexedDB).
- **Lazy chunks are not precached:** the worker precaches `entrypoints.json` files only (`service-worker.js:55-66`);
  the live chunk lands in `static-v7` only on first use (cache-first, `:125`). After a deploy the new chunk is cached
  only when an online page asks for it.
- **The QR scanner loads zbar from jsdelivr** (`assets/official_results_scan.js:8-11`), while the EAN scanner already
  self-hosts the same bytes at `/barcode-scanner/zbar-wasm-0.9.15/` (`assets/controllers/barcode_scanner_controller.js:21-37`).
  Cross-origin requests are not intercepted by the worker (`service-worker.js:110`), and `/barcode-scanner/` is not a
  cached path either. iOS Safari has no `BarcodeDetector` → **on iPhones scanning after an offline refresh fails**
  ("decoder error") unless the HTTP cache happens to hold the scripts.
- **Service worker registration** lives in `assets/app.js:82-84`, skipped in the native apps (`window.isNativeApp`).
- **Stale-document self-heal** (`base.html.twig:293-350`) would treat any cached document older than 5 min as stale,
  report it to Sentry and reload once per session - any offline document we add must opt out of it.

### Flow (controller)
- `connect()` (`live_results_controller.js:103-245`) renders from the embedded state, then **always fetches the full
  state again** (`:244`) just for the clock sync - with 300 entries the same ~150-200 KB JSON (≈30 KB gzip) twice on
  load, then again every 60 s while visible (`STATE_REFRESH_MS`, `:36`).
- Find: `find()` (`:1120`) opens on Enter only; iOS's numeric pad has no Enter, so the judge taps the first row.
  `searchEntries()` (`assets/official_results_live.js:148-211`) already knows the exact table and the prefix matches.
- Entry: `openEntry()` (`:1218-1254`) focuses **Finished now** while the stopwatch runs and the field is empty, else the
  time field. `finishedNow()` (`:1389-1421`) freezes the tap on the server clock, refetches the state if the stream is
  not trusted (`STOPWATCH_TRUSTED_MS` 10 s, `:40`), then `review()` (`:1480`) shows Confirm; `save()` (`:1524`) enqueues
  and goes back to Find with a toast + Undo.
- Round switch (`:1167`) is a Turbo visit to another page; keyboard mode (`msp.liveResults.keyboard`, `:42`) and the
  picked round (`msp.liveResults.round.<competitionId>`, `:43`, 12 h, `preferredRound()`) are already remembered in
  localStorage. Nothing of an *in-progress* entry (opened entry, typed time, a tapped "Finished now") survives a reload.
- Scan: `scanned()` (`:1692-1723`) understands only the name tag `…/live/{cid}/p/{pid}` (`NAME_TAG_PATH`,
  `official_results_scan.js:13`); the camera restarts from zero for every scan (`openScanner()`, `:1670`).
- Round state (`RoundResultsStateController`, `LiveResultsController.php:96-117`) carries no puzzle data (only
  `round.puzzlesCount`/`piecesCount`, `RoundResultsOverview::jsonSerialize()`) and no proposals.
- Outbox (`assets/official_results_outbox.js`): item = `{id, userId, roundId, seq, entryRef, newEntry, field, from, to,
  state, …}` (`enqueue()` `:322-372`), wire = `{clientChangeId, field, from, to, entry|newEntry}` (`wire()` `:640-654`);
  fields `result | table_number | qualified` (`RoundResultChangesParser.php:18-30`). The design below extends this, it
  does not replace it.
- CSRF is stateless for `official_results` (`config/packages/csrf.php`, `OfficialResultsApi::CSRF_TOKEN_ID`): the
  token in the page is not per user or per session - a cached, user-independent page may carry it.
- Secret puzzles: referees are **not** organisers for `SecretPuzzleAccess` (`src/Services/SecretPuzzleAccess.php:22-28`,
  `:317-337` - only admins, the puzzle's adder and `canEditCompetition`). A referee must never get a hidden puzzle's
  name or image.

### Tap counts today (phone, stopwatch running, table numbers on, numeric pad default)
| Flow | Taps / keys | Detail |
|---|---|---|
| table → Finished now → Save | **5** | `1`,`2` + tap row (no Enter on iOS numpad) + Finished now + Save |
| table → type time → Save | **11** | `1`,`2` + row + tap time field (focus is on Finished now) + `1`,`2`,`3`,`4`,`5` + Review + Save |
| scan → (confirm a proposal) | n/a | proposals do not exist; scan = scan button + aim + (entry opens) + Finished now + Save = 3 taps + camera start ~0.5-1.5 s |

---

## 1. Survive refresh, load offline

### Decision: a user-independent **offline shell** + per-user **snapshots** in IndexedDB - never a cached copy of the real page

Caching the real `live_results` HTML would put a referee's view (names, unpublished results, private codes for
organisers, a Mercure token) into CacheStorage keyed by URL - exactly what `ServiceWorkerBehaviourTest` forbids for good
reasons (v6 incident). Instead:

1. **Shell** = the same template rendered **without any data**: no state, no entries, no user, no round name. One per
   locale, `GET /{_locale}/live-results-shell` (route `live_results_shell`, cs `/zadavani-vysledku-shell`), anonymous
   (works signed out, starts no session, `Cache-Control: no-cache`, `X-Robots-Tag: noindex`). It is the live page's
   markup + texts + the stateless CSRF token + the URLs with placeholders. The worker stores it and answers it **only
   when the network fails** for a live entry URL.
2. **Snapshot** = the last known round state per *player + round* in IndexedDB, written by the online page every time
   it gets a state. The shell renders from it.
3. **Gate** = a small JS-readable cookie `msp_live_player=<playerId>` set by the live page's response and cleared on
   logout. The shell shows only the snapshots of that player; without the cookie it shows "Signed out on this device -
   connect to sign in" (and still sends nothing - the outbox needs a user).

Online, nothing changes for the first paint: the server-rendered page with its embedded state, exactly as today. The
shell is only a fallback, and once the network is back the shell **becomes** the live page by itself (it fetches the
state, gets the Mercure token, starts the stream) - the judge never has to reload.

### 1.1 Lean layout + dedicated entry (prerequisite)
- New layout `templates/live_results/_layout.html.twig` (not extending `base.html.twig`): `<head>` with the title,
  robots, viewport, theme colour, manifest, fonts preload, `encore_entry_link_tags('app')` (the same CSS - cached
  already) and `encore_entry_script_tags('live_entry')`, the two inline self-heal scripts moved from
  `base.html.twig` into partials `_asset_self_heal.html.twig` + `_stale_document_check.html.twig` that both layouts
  include (byte-identical output for every other page). No banners, no flashes, no modals, no global search, no
  analytics. Fixes the banner finding above.
- New Encore entry `live_entry` (`assets/live_entry.js`): Stimulus application with **only** `live-results`
  registered (eager), the SW registration copied from `app.js:82-84`, no Turbo. Its files are listed in
  `entrypoints.json`, so the worker's install precaches them (`service-worker.js:55-66`) and the prune keeps them -
  no lazy chunk to miss. **No dynamic `import()` inside the entry** (the scanner module is 2.5 KB - import it
  statically) so the entry's file list is complete. A CI check (`LiveEntryBundleTest`) fails if
  `entrypoints.json` lists any lazy chunk reachable from it or if the entry's JS exceeds a budget (proposal: 120 KB
  minified total incl. Stimulus).
- Without Turbo: `switchRound()` / other links become plain navigations (`window.location.assign`), the
  `claimHistory()`/`resumeTurboHistory()` pair (`:1302-1333`) keeps only its own `pushState` part. The `turbo:before-visit`
  guard becomes the `beforeunload` guard only (already there).
- `data-turbo-track="reload"` on the layout's assets: a Turbo visit from the rounds list into the live page does a
  full load (Turbo sees different tracked assets) - correct, the live page is its own app.

### 1.2 Template made data-free
Everything the template derives from the round today moves into JS, so the real page and the shell are the **same
markup**:
- `uses_tables`, `solo_round` (`live_results.html.twig:7-9`) → from `state.round` (`tableNumbersOff`, `category`) and
  `state.competition.isOnline` - JS toggles the table badge, the keyboard toggle, the placeholder text, the quick-add
  member inputs (2 for duo, 3 for team, `:381`).
- Round `<select>` options (`:214-221`) → rendered from `state.rounds`.
- Texts with `%round%` (`scanNotInRound`, `entrantNotInRound`, `:48-49`) → keep the placeholder, fill in JS.
- Organiser-only links (back to rounds, results desk, seating, table field in quick add) → rendered always with
  `hidden`, shown when `state.viewer.organiser` (new, see contract). The links themselves carry the round id
  placeholder.
- `logged_user.profile.playerId` (`:186`) → `state.viewer.playerId`; the shell reads the cookie.
- `event_people` (`:443`) → part of the snapshot; new JSON endpoint (below) for the shell when it comes online.

### 1.3 Server changes
| What | Where | Notes |
|---|---|---|
| `live_results_shell` | new `LiveResultsShellController` | renders `live_results.html.twig` with `state = null`, `shell = true`; no `IsGranted`; `Cache-Control: public, no-cache` + `Vary: Accept-Language` not needed (locale is in the path); must not touch the session (`LiveResultsShellControllerTest` asserts no `Set-Cookie`, no player data in the body when signed in as an organiser) |
| `state.viewer` | `LiveResultsController`, `RoundResultsStateController` | `{playerId, organiser: bool}` - added to both (state JSON is private to organisers/referees anyway) |
| event people JSON | new `GET /{_locale}/official-results/rounds/{roundId}/event-people` (`official_results_event_people`) | `CompetitionResultsEntryVoter`, same `withPrivateCodes: $organiser` rule as the page (`LiveResultsController.php:116`) |
| `?entries=0` on the state | `RoundResultsStateController` | the clock sync and the token renewal without the entries; `connect()`'s first fetch (`:244`) uses it when the embedded state is < 10 s old |
| cookie `msp_live_player` | set by `LiveResultsController` + the state endpoint; cleared by a `LogoutEvent` subscriber (`LiveResultsDeviceCookieSubscriber`) | value = playerId, `Path=/`, `SameSite=Lax`, `Secure`, **not** HttpOnly, `Max-Age` = remember-me lifetime. Not a credential - only decides what the shell shows offline |
| `?entry=<ref>` and `?table=<n>` on `live_results` | `LiveResultsController` | the judge branch of B's table / entry QR routes redirects here (like `?entrant=` today) |

### 1.4 Service worker changes (`public/service-worker.js`)
- New cache `live-shell-v7` (same suffix rule - an activate with a new `CACHE_VERSION` drops it like the others).
- **Message `live-shell:prepare`** from the online live page (`navigator.serviceWorker.ready` → `controller.postMessage({type: 'live-shell:prepare', locale})`):
  1. fetch `/{locale}/live-results-shell` with `cache: 'no-cache'`, refuse anything that is not a 200 `text/html` without `Set-Cookie`;
  2. read its asset list from `<script type="application/json" id="msp-live-shell-assets">` (the layout renders
     `encore_entry_js_files('live_entry')` + `encore_entry_css_files('app')` + the fonts) and make sure each is in
     `static-v7` (same buffered `cacheFirst` logic, `cache: 'reload'`);
  3. only when **all** assets are stored, `put` the shell (atomic - a shell never points at missing assets) together
     with a small JSON record `{locale, assets, storedAt, buildId}` under `/__live-shell/{locale}.json`;
  4. answer `{type: 'live-shell:ready', locale, storedAt}` → the page shows "Ready offline" in the sync sheet.
  Repeated at most once per page load and whenever the page sees a new build (the `msp-rendered-at` meta / asset URLs
  differ from the stored record).
- **Prune keeps shell assets**: `pruneStaticCache()` (`:348-384`) adds every URL of every stored shell record to
  `valid` - without this the first cache miss after a deploy would delete the assets of the shell still stored.
- **Navigation routing**: in the `request.mode === 'navigate'` branch (`:150`), if `isLiveEntryPath(url.pathname)`
  → `liveEntryNavigation()` instead of `networkFirstNavigation()`:
  - network first; **no cache read while the network answers** (keeps `cacheReads: []` for the online case);
  - on rejection (after the existing 500 ms retry, not when `navigator.onLine === false`) **or after 4 s without an
    answer** (lie-fi at a venue - the shell is a working page, so a timeout costs nothing here, unlike the generic
    branch) → the stored shell of the path's locale, with header `X-MSP-Live-Shell: 1`; none stored → the offline page
    as today. A redirect or an HTML answer from the network is passed through untouched (a sign-in redirect stays a
    sign-in redirect).
  - `isLiveEntryPath()` = fixed regexes, pinned against the router by a test (`ServiceWorkerLiveRoutesTest` generates
    every localized path of `live_results`, `live_results_event`, `live_results_scan` and B's two new judge-capable
    routes with sample ids and asserts each matches, and that `/en/live-results-shell` itself and unrelated pages do not):
    ```
    ^/(?:(en|es|ja|fr|de)/live-results|zadavani-vysledku)/{uuid}$
    ^/(?:(en|es|ja|fr|de)/live-results/event|zadavani-vysledku-udalosti)/{uuid}$
    ^/(en|cs|es|ja|fr|de)/live/{uuid}/(p/{uuid}|t/[^/]+|r/{uuid}/e/[^/]+)$
    ```
- **Scanner bytes**: add `/barcode-scanner/` to the cache-first branch (`:125`; the path carries the version
  `zbar-wasm-0.9.15`, immutable) and switch `official_results_scan.js` to the self-hosted files first, jsdelivr
  second (the EAN scanner's order). The online live page warms them (`fetch` the three files) right after `prepare`
  when `BarcodeDetector` lacks `qr_code`.
- `CACHE_VERSION` stays `v7`: nothing already in visitors' caches must be evicted (the new cache is new; the worker's
  code change installs itself). Bump only if a shell bug ever stores something it must not.
- Native apps: no worker (`app.js:82`) → no shell; the sync sheet says "This app cannot reopen the page offline - keep
  it open, or use the browser". Unsent changes are still in IndexedDB as today.

### 1.5 Snapshots (`assets/live_entry_snapshot.js`, new, injected storage like the outbox)
- Own database `msp-live-entry` (not a new store in `msp-official-results`: a version upgrade there would block
  (`onblocked` → `createIndexedDbStorage` returns null → the outbox falls back to memory) while another tab holds v1
  open). Store `snapshots`, key `${playerId}:${roundId}`:
  ```json
  {"playerId": "…", "roundId": "…", "competitionId": "…", "savedAt": 1763380000000,
   "clockOffsetMs": -412, "clockMeasuredAt": 1763379990000,
   "state": {"competition": {}, "round": {}, "rounds": [], "entries": [], "viewer": {}},
   "eventPeople": [], "liveUrlTemplate": "/en/live-results/0000…"}
  ```
  `state.mercure` is never stored (useless offline, a credential). Written on every state that changes something
  (debounced 2 s, entries merged from Mercure included), so a refresh shows the results other judges entered up to
  the moment the network went.
- **Prefetch**: once the page is idle and online, it fetches (`?entries=1`) the state of the competition's other
  rounds that start within `[now − 12 h, now + 24 h]` (at most 6) and stores their snapshots - so a round switch or a
  name tag of another group works offline too. One request per round, 10 s apart, skipped on `saveData`.
- **Retention**: snapshots older than 36 h are deleted on every start; at most 30 per player.
- `navigator.storage.persist()` is asked once on the live page (Chrome grants it to engaged sites; Safari ignores it -
  its 7-day eviction does not hit an event day).

### 1.6 The shell at work (`live_results_controller.js`, `shell` mode)
1. `connect()` sees `shell: true` → reads the cookie → no player: the signed-out screen ("Open this page once with a
   connection and sign in"), plus the count of unsent changes of any player on the device (from the outbox store).
2. Parses `location` (`assets/live_entry_route.js`, new, pure): `{kind: 'round', roundId}` | `{kind: 'event',
   competitionId}` | `{kind: 'participant'|'table'|'entry', competitionId, …}`.
3. Picks the snapshot: the round's own; for `event` and the QR kinds the JS port of `LiveResultsCurrentRound::pick()`
   over the snapshots of that competition (the port is pinned to the PHP by a shared fixture set run under both
   PHPUnit and the node harness), then `preferredRound()` as today.
4. No snapshot: "Group B was not opened on this device while online" + the rounds that have one (links), + the unsent
   changes count. Never a blank page.
5. Renders as today from the snapshot (`applyState()`), sets the clock offset from the snapshot, shows a strip under
   the status line: **"Offline copy from 10:42 - results entered elsewhere since then are not shown"**. Outbox, Find,
   entry, Finished now, quick add, scan all work; sending waits (`offline`).
6. Every 5 s and on `online`: tries `?entries=1` state + event people → success = applies them, starts the stream with
   the fresh `mercure`, removes the strip, rewrites the URL's query (`?entry=`, `?table=`) handling as the online page
   does. The shell is now a normal live page.

**Finished now offline**: elapsed = (device now + stored offset) − `startedAt`. The existing rule "a failed fetch keeps
what is known" applies; the confirm view adds "Stopwatch as known at 10:42 (offline)" when the stopwatch was not
trusted, so a judge notices a pause they could not know about. The outbox item gets `meta: {finishedNowAt: <server ms>,
stopwatchKnownAt: <ms>}` (stored, sent, ignored by today's server; available for a later sanity check).

### 1.7 Survive refresh of an *in-progress* entry (`assets/live_entry_draft.js`, new)
sessionStorage key `msp.liveResults.draft.<playerId>` = `{roundId, ref, view: 'entry'|'confirm', typed, pieces,
pendingValue, finishedNow: {seconds, tappedAt}|null, puzzles, savedAt}`, written on every input / view change, cleared
on Save / Back / Undo. On load (real page or shell), a draft younger than 10 min for this round reopens that entry in
that view - the frozen "Finished now" value is restored **exactly** (with "tapped at 10:42:03"), never re-measured.
Never saved automatically. sessionStorage (not localStorage): a draft belongs to this tab.

### 1.8 Judge preferences
One localStorage key `msp.liveResults.prefs` (`{v: 1, keyboard, autoOpenTable, scanAfterSave, myTables}`), read with
try/catch, the existing `msp.liveResults.keyboard` migrated on first read. The round pick stays where it is
(`ROUND_KEY`, per competition, 12 h). A small "⚙" in the sync sheet edits them (no new page).

---

## 2. WHO

### 2.1 Unique table opens at once (no Enter needed)
`uniqueTable(index, text)` (pure, `official_results_live.js`): when the typed digits are an exact table **and no other
table number of the round starts with them**, open it immediately (20 tables: `3` opens 3, `1` waits for `1x`, `12`
opens 12, `2` waits, `20` opens). Deterministic, no timer, removes the row tap that iOS's Enter-less pad forces.
Preference `autoOpenTable` (default **on**; off restores today). Enter keeps today's `entryForEnter()`.

### 2.2 QR kinds (`parseLiveQr()` replaces `parseNameTagUrl()` in `official_results_scan.js`)
```js
parseLiveQr(text) → null
  | {kind: 'participant', competitionId, participantId}          // /{l}/live/{cid}/p/{pid}  (printed, unchanged)
  | {kind: 'table', competitionId, tableKey}                     // /{l}/live/{cid}/t/{tableKey}
  | {kind: 'entry', competitionId, roundId, entryRef}            // /{l}/live/{cid}/r/{rid}/e/{entryRef}
```
Behaviour after a read (same overlay, vibration, history):
| Kind | This round | Another round |
|---|---|---|
| participant | entry of the participant (today) | today's "… not in Group A" + "Open their round" |
| table | the entry seated at that table **in the round on screen** (a table QR is round-independent by definition); empty table → "Table 12 is empty in Group A" | n/a |
| entry | that entry | the QR's round is unambiguous: if it is running or within the 12 h window → **switch to it** (navigation; offline the shell serves it from its snapshot) with the entry open and a notice "Switched to Final"; else ask "This is Final's QR - open Final?" |
| other event / other text | "This QR code is not of this event" (today's text, generalised) | |

**Continuous scanning** (pref `scanAfterSave`, default off): an entry opened by a scan goes back to the scanner after
Save instead of Find; the camera stream is kept alive (paused detection, not stopped) while the entry/confirm views are
open, so the next scan starts in ~0 s instead of 0.5-1.5 s. The stream stops on Back to Find, on `visibilitychange`
hidden and after 2 min idle.

### 2.3 Puzzle choice (pick 1 of N, pick K of N)
Shown only when `state.round.puzzleChoice.mode !== 'all'` (contract A). On the **entry card**, between the name and the
time block:
- **Chips row** - one per round puzzle: thumbnail 48 px + name (one line, ellipsis) once revealed; until the public
  reveal only the label ("A", "B", "C" - by the round puzzle's position) and a lock icon. **The live page uses the
  public reveal state for everybody, organisers included** - a phone in a judge's hand is seen by competitors; the
  server sends `name/image = null` until revealed (rule 4), so neither the page nor a snapshot ever holds a hidden
  puzzle. A reveal arriving (state refresh / Mercure `official_results.round`) fills the chips in place.
- **Pick 1 of N**: one tap selects (radio). Already known (server value, this device's waiting change, or the
  competitor's proposal) → preselected, shown as "Puzzle B ✓" - zero taps.
- **Pick K of N**: tap toggles; the tap order is the solving order (badge 1, 2); the K-th tap completes the choice;
  a (K+1)-th tap replaces the last. "Splits" (only when `puzzleChoice.splits`): a collapsed "Add split times" row; open,
  each chosen puzzle but the last gets a small time field + a "now" button (server-synced elapsed, like Finished now).
  The last puzzle's split is the result itself - never asked twice.
- Not chosen yet when Finished now / Review is tapped → the confirm view shows the chips again with "Which puzzle?" in
  warning colour; **Save stays enabled** (the result is time-critical, the choice can be added later); the entry then
  shows in Find under a filter chip **"Puzzle missing (N)"**. Organiser setting from A could make it required - then
  Save is disabled until chosen.
- Save = one or two outbox items in one go: `field: 'puzzles'` (only when it differs from what the device knows) and
  `field: 'result'`, same entry, consecutive `seq` - the existing per-field chaining handles order and conflicts
  independently (a conflict on the choice never blocks the time).
- The confirm view shows "1:23:45 · Puzzle B" (or "B → A, split 0:41:10").

**Taps**: +1 (chip) only when the choice is not known yet; 0 when the competitor proposed it or a judge recorded it
when the round started.

---

## 3. TIME: confirming a participant's proposal

### 3.1 What the judge sees
- **Entry card** with a pending proposal (contract B): a panel styled like the confirm view - **"Proposed 1:23:45"**
  big, "from their phone · 10:42:03" small (+ "Puzzle B" when the proposal carries the choice), and one big green
  **"Confirm 1:23:45"** as the first, focused button (above Finished now). Under it "Change" (opens the time field
  prefilled with the proposed time) and "Not right" (dismiss).
- **The card is the confirm view for a proposal** (Jan: "confirm via single button after choosing the right table"):
  the value, the replaced value and its author are already on screen, so Confirm saves directly - no second view.
  Every other path (Finished now, typed time) keeps the separate one-tap Confirm view.
- Already saved a **different** time: the panel says "Saved 1:24:00 (Eva, 10:42) · Proposed 1:23:45 (their phone,
  10:42:03)" in warning colour; the button reads "Replace with 1:23:45". Same time saved already: the server resolves
  the proposal itself (contract B); a stale copy on the device shows "Matches the saved time" and no button.
- **Find** gets a chip **"Proposals waiting (N)"** next to "Recent" (hidden at N = 0, updated live). Tap → the list,
  oldest proposal first: table, name, proposed time, age ("2 min"), and a ✓ button per row. ✓ = save that proposal
  (row tap = open the card). After a confirm the list stays open with the next row on top - a sweep is **one tap per
  proposal**, Undo in the toast as always. Rows whose entry already has a different saved time are marked "differs"
  and sorted last; ✓ on them opens the card instead (never a silent overwrite).

### 3.2 Offline-safe confirmation (outbox)
A confirmation **is a result change** carrying the proposal it confirms:
```json
{"clientChangeId": "…", "entry": "team:…", "field": "result",
 "from": null, "to": {"seconds": 5025}, "proposalId": "<uuid>"}
```
- Outbox item gains optional `proposalId` (stored, sent by `wire()` when set). `from` is computed as today (three-way),
  so a judge's earlier save or another device's value still conflicts normally ("Keep mine" resends with the
  `proposalId`).
- Server (contract B, in `RecordRoundResults` under `CompetitionParticipantsLock`): applies the result as today, then
  resolves the proposal - `to.seconds == proposal.seconds` → `confirmed`; differs (the judge edited) → `edited`;
  proposal no longer pending (withdrawn, re-proposed, already resolved) → nothing, outcome note `proposal: "stale"`.
  The judge's value is applied in every case - it is the judge's verdict.
- **Dismiss** ("Not right") = `{"field": "proposal", "entry": …, "from": {"id": "<proposalId>"}, "to": null}` →
  `applied` (dismissed) or `unchanged` (no longer pending). Offline-safe through the same outbox.
- A result change **without** `proposalId` on an entry with a pending proposal resolves it too (equal → confirmed,
  else superseded) - so a judge who did not see the proposal (offline, or typed from the paper) leaves no dangling
  "waiting" row.
- Puzzle choice inside a proposal: confirming enqueues `field: 'puzzles'` from the proposal when the entry has none.

### 3.3 Proposals and the network
- **A proposal arriving while the judge is offline**: it is server state; the judge sees it when the state is fetched
  again. Meanwhile the judge can still Finished-now / type that entry - the server then resolves the proposal (above).
  The "Proposals waiting" chip in an offline copy shows the count as of the snapshot, with the offline strip.
- **Confirmed offline, the competitor re-proposes before the sync**: the judge's confirmed value is applied; the new
  proposal stays pending and reappears in the list ("their phone now says 1:23:50") - the judge decides again. No data
  is lost either way.
- Mercure: B publishes `official_results.entries` for the entry after a proposal is created/withdrawn (after the
  commit, like `RecordRoundResultsController`), on both the organiser and the referee topic - the live page needs no
  new message type; `mergeEntries()` already replaces the entry.

---

## 4. Speed and compactness

### 4.1 Target tap counts (after this design)
| Flow | Today | New | How |
|---|---|---|---|
| table → Finished now → Save | 5 | **4** | `1`,`2` (unique → opens) + Finished now + Save |
| table → type time → Save | 11 | **10** (9 when the stopwatch is not running) | unique open saves the row tap; time field already focused when Finished now is absent |
| scan → confirm proposal | - | **2** + aim | scan + Confirm (card = confirm) |
| sweep N proposals | - | **N** | ✓ per row in "Proposals waiting" |
| next scan after save (scanAfterSave) | 1 tap + camera start | **0 taps**, warm camera | |
| pick-1-of-N round, choice unknown | - | **+1** chip | |

### 4.2 Concrete changes
1. **Confirm in place** (`review()`/`save()`): the confirm view's Save sits where Finished now was (same height, same
   bottom offset), so the thumb does not travel. **Double-tap guard**: Save ignores taps for 350 ms after the confirm
   view appears (`pointerdown` timestamp) - otherwise a nervous double tap on Finished now would save unseen.
2. **Unique table auto-open** (2.1).
3. **Focus**: when the stopwatch is not running (late results, typed times) `openEntry()` already focuses the time
   field; when it runs, a "Type the time" link above Finished now focuses the field (keyboard only on demand) - no
   change in count, but no hunting for the field.
4. **Enter on the time field** goes to Confirm (`enterkeyhint="done"` instead of `next`, `live_results.html.twig:316`) - Android numeric pads
   do show it.
5. **Back to Find keeps the keyboard up** (focus inside the Save click handler - a user gesture, so iOS shows the pad).
   Already done for the input; verify on iOS in the browser walk.
6. **Banner/flash space**: gone with the lean layout (1.1).
7. **Haptics**: vibrate 30 ms on save (exists), 15 ms on chip select, 40 ms on a scan (exists).
8. **"Recent" shows proposals first** when any are waiting (the chip) - the likely next action.

### 4.3 Performance
- **Bundle**: `live_entry` without Turbo/Live Components/Bootstrap JS/other controllers: ~76 KB own + Stimulus ~30 KB
  min ≈ **110 KB min / ~35 KB gzip**, eager, precached. The `app` CSS is reused (cached from every other page). A slim
  CSS entry is a later option (measure first; the CSS is cached and not on the critical path after the first visit).
- **First paint**: unchanged server render; **interactive** one round trip earlier (no lazy chunk after `app.js`).
  With the worker controlling, repeat loads hit only the HTML on the network.
- **Load traffic with 300 entries**: today ≈ 2 × the entries JSON on load (embedded + immediate refetch) + every 60 s.
  New: the load's refetch is `?entries=0` (clock + token only, < 2 KB); the 60 s safety poll sends `If-None-Match`
  with an `ETag` computed over round + entries (not the token) → `304` when nothing changed; the token renewal asks
  `?entries=0` explicitly. Mercure stays the main channel.
- **CPU with 300 entries**: `searchEntries()` is a linear scan with a cached index (`indexDirty`), ≤ 30 rows of HTML
  per keystroke, Recent ≤ 15 rows - fine. Two O(n) hot spots to fix on the way: `entry(ref)` builds `allEntries()` for
  one lookup (`:430`) - keep a `Map` rebuilt with the index; `allEntries()` runs `shownValue()` per entry over the
  outbox items (O(n·m)) - pre-group the outbox by `entryRef` once per render. Budget pinned in the harness: 300 entries
  + 300 outbox items → `renderFind()` data part < 5 ms under node.
- **Snapshot writes** are debounced (2 s) and off the input path (`requestIdleCallback` where available).

---

## 5. Judges management

Exists: any number up to 200 (`AddCompetitionReferee`, `limit_reached`), MSP account required, added by player search,
link + QR for referees, removal, results-only rights, private players masked (live-results.md "Referees").

Proposed, cheap and opt-in:
1. **My tables (device filter)** - pref `myTables` ("1-20, 41-45") in the ⚙ of the sync sheet: Recent, "Proposals
   waiting" and "Puzzle missing" show only those tables (with "+ N other tables" to expand); opening any table still
   works (a judge helps a neighbour). Zero server work. **Recommended for this build.**
2. **Organiser-assigned table ranges** (only if Jan wants it after 1 is used): `competition_referee.tables` (nullable
   text "1-20,41-45", per competition, not per round), edited on the referees page next to each referee, delivered in
   `state.viewer.tables` → pre-fills (does not lock) the device filter. One column, one message
   (`ChangeRefereeTables`), no permission meaning. Not per round - seating changes per round, ranges rarely do.
3. **Referees page: "Ready offline" and activity** - per referee "last result entered 10:42 (Group A) · 23 results"
   from `result_entered_by` (one aggregate query over the competition's entries), so the organiser sees who is
   actually working before the round starts (a judge who never opened the page shows "–"). Cheap, useful on the day.
4. A short "Before the event" checklist on the referees page (open the link once on Wi-Fi in the browser you will use,
   allow the camera, check "Ready offline" in the sync sheet; the home-screen app has its own storage - stick to one).

Not proposed: per-round referee assignment (rights stay per competition), referee roles, notifications to new referees
(docs/TODO.md:903 already tracks it).

---

## 6. Contracts needed

### From A (data model: puzzle choice)
- `state.round.puzzleChoice`: `{mode: 'all' | 'pick_one' | 'pick_k', k: int|null, splits: bool, required: bool}`
  (`all` = today, no UI).
- `state.round.puzzles`: `[{id: <competition_round_puzzle id>, label: 'A', position: 1, revealed: bool, name: string|null,
  brand: string|null, pieces: int|null, image: string|null}]` - `name/brand/image` **null until the public reveal for
  every viewer of this page** (computed with `SecretPuzzleAccess`/`RoundPuzzleReveal` as for the public, not as for
  organisers).
- Entry: `puzzles: null | {ids: [roundPuzzleId, …] (solving order), splits: [int|null, …] (cumulative seconds, aligned,
  last = null)}`.
- `RecordRoundResults` field `puzzles` with three-way `from`/`to` in that shape; refusals `invalid_puzzle_choice`
  (unknown id, wrong count, split ≥ result) translated like the others; allowed for referees (`resultsOnly`).
- A reveal changes the round state → `official_results.round` (or `refresh`) on the round topics.

### From B (QR kinds, competitor proposals)
- Paths (the worker's regexes and `parseLiveQr()` depend on them, **never change once printed**):
  `/{_locale}/live/{competitionId}/t/{tableKey}` and `/{_locale}/live/{competitionId}/r/{roundId}/e/{entryRef}`.
  `tableKey` = the table number as printed (digits) unless B needs layout ids - then `state.round.tables:
  [{key, number}]` so the page maps a key to a number offline. `entryRef` = the entry's id (uuid) - the page matches
  `entry.id` within that round.
- Both routes keep a **judge branch** like `LiveResultsScanController`: a signed-in organiser/referee is redirected to
  `live_results` with `?table=<n>` / `?entry=<ref>`; everybody else gets B's competitor page.
- Entry: `proposal: null | {id, seconds, proposedAt (ATOM), proposedAtMs, by: {playerId, name}|null, puzzles:
  {ids, splits}|null}` - only a **pending** proposal; masked for referees like `playerWithheld` (the `by.name` of a
  private player → null).
- `state.round.proposalsPending: int`.
- `RecordRoundResults`: optional `proposalId` on `result` changes and the `proposal` field (dismiss) as in 3.2; outcome
  gains `proposal: 'confirmed' | 'edited' | 'superseded' | 'dismissed' | 'stale' | null`. A result change without
  `proposalId` resolves a pending proposal (equal → confirmed, else superseded).
- Proposal created/withdrawn → `entriesChanged()` after commit on both round topics.

---

## 7. Test plan

### 7.1 Node harness (`tests/live-results-harness.mjs`, run by `tests/LiveResultsScriptsTest.php`)
New pure modules, each with injected storage/clock like the outbox:
- `live_entry_route.js`: every localized path of the five routes → kind + ids; foreign paths → null; trailing slash,
  uppercase uuid.
- `parseLiveQr()`: the three kinds, http/https, other hosts accepted (as today), another event, garbage, the printed
  name-tag URL unchanged.
- `uniqueTable()`: 1-20 / 1-9 / 1-120 / gaps (3, 30 only) / no tables / table numbers off.
- Snapshot store: write/read per player + round, never another player's, `mercure` stripped, 36 h retention, 30 per
  player cap, debounced writes, broken store → page still works (memory).
- Current-round port: the shared fixture set (also run by `LiveResultsCurrentRoundTest` in PHP) gives the same round.
- Draft: restore within 10 min, never after Save/Undo, frozen Finished-now value restored exactly, other player's draft
  ignored.
- Outbox: `proposalId` stored, sent, kept across "Keep mine"; `proposal` dismiss item; `puzzles` + `result` in one save
  chain independently (conflict on one does not hold the other); `meta.finishedNowAt` persisted.
- Proposals list: ordering, "differs" rows last, count after confirm/undo, offline snapshot count.
- Puzzle chips: pick-1 preselect from server / outbox / proposal; pick-K order and replacement; hidden puzzles render
  label only (a state with `name` on a `revealed: false` puzzle is treated as hidden - defence in depth).
- Perf budget: 300 entries + 300 outbox items, `renderFind` data part < 5 ms, `entry(ref)` O(1).

### 7.2 Service worker harness (`tests/service-worker-harness.js`, `ServiceWorkerBehaviourTest`)
- Live path online → network answer, `cacheReads: []`, `cacheWrites: []` (the existing guarantees hold).
- Live path, network rejects twice → the stored shell of the path's locale (`cs` path → cs shell), header
  `X-MSP-Live-Shell: 1`; no shell stored → `offline.html`.
- Live path, network silent > 4 s → shell; a 302 to sign-in → passed through.
- Non-live path offline → still `offline.html`, never the shell (`/en/live-results-shell` itself, `/en/hub`).
- `live-shell:prepare`: assets missing → shell not stored; all stored → shell + record stored; a shell answer with
  `Set-Cookie` or non-HTML refused.
- Prune after a deploy keeps every asset of a stored shell, deletes others.
- `/barcode-scanner/zbar-wasm-0.9.15/*` cache-first; cross-origin still untouched.

### 7.3 PHP
- `LiveResultsShellControllerTest`: anonymous 200; signed in as organiser → no player id, name, round, competition,
  token or entry in the body; no `Set-Cookie`; robots header.
- `LiveResultsPageTest` additions: `viewer` in the embedded state and the state endpoint; `msp_live_player` cookie set;
  `?entries=0` has no entries; ETag/304; event-people endpoint rights (referee masked codes, others 403).
- `LiveResultsDeviceCookieSubscriberTest`: logout clears the cookie.
- `ServiceWorkerLiveRoutesTest`: router paths ↔ worker regexes.
- `LiveEntryBundleTest`: no lazy chunk, size budget.
- Rule 1: existing state / record tests unchanged except the additive `viewer` key (see below).

### 7.4 Real-browser walk (`tests/Panther/LiveEntryOfflineTest.php` + a manual script)
Panther (Chrome) with `ChromeDevToolsDriver` - `Network.enable` + `Network.emulateNetworkConditions {offline: true}` on
the page target **and** the service worker target (`Target.setAutoAttach` with `flatten`, apply the same command to the
`service_worker` target - Chrome does not apply page-level offline emulation to the worker). Steps:
1. Sign in as a referee fixture, open `live_results` for a running round online; wait for "Ready offline" in the sync
   sheet (= `live-shell:ready`).
2. Go offline. Reload → the shell renders the round from the snapshot, offline strip visible, the pill "All saved".
3. Type `12` → table 12 opens (unique) → Finished now → Save; type `7`→… → typed time → Save. Pill "2 waiting -
   offline".
4. Reload again (offline) → both still waiting, Recent shows them with the clock icon; open table 12 and start a typed
   time, reload → the draft reopens with the typed value.
5. Navigate to another round via the select (offline) → prefetched snapshot renders.
6. Go online → within 5 s the strip disappears, pill "Saving…" → "All saved"; assert in the DB both results with
   `result_entered_by` = the referee and no duplicates; Mercure not required for the assertion.
7. Logout, go offline, open the live URL → signed-out screen, no names in the DOM.
Manual extras (iPhone Safari + Android Chrome, the walk Jan's memory asks for client-side changes): airplane mode
refresh, scanning a name tag after an offline refresh (zbar from cache), keyboard staying up after Save, double-tap
guard, tab discarded in the background for 30 min.

---

## 8. Risks

| Risk | Mitigation |
|---|---|
| A shell that points at assets no longer cached → white page offline | atomic `prepare` (assets first), prune keeps shell assets, the layout's asset self-heal script stays inline |
| Stale-document heal reloads/reports the shell | the shell renders no `msp-rendered-at` meta (the script returns at `!renderedAt`, `base.html.twig:304`), the partial is shared, a test asserts the meta is absent in the shell |
| Personal data in a cache | the shell is rendered data-free and tested so; snapshots live in IndexedDB per player, gated by the cookie, 36 h retention; `mercure` never stored |
| Lie-fi: 4 s timeout shows the "Offline copy" strip on a slow but working network | harmless - the shell turns live by itself within 5 s; the strip says what it is |
| Judges use the home-screen app vs Safari (separate storage on iOS) | referees-page checklist; the sync sheet shows "Ready offline" per browser |
| Native apps have no worker | sync sheet message; outbox unchanged |
| Two judges on one shared tablet | the outbox already keeps per-player items and never sends another player's; the cookie switches with sign-in |
| Finished now offline after a pause/stop the device could not learn | confirm-view note "stopwatch as known at …"; `meta.finishedNowAt` kept for a later check |
| Hidden puzzle leaks via chips/snapshot | server sends public-reveal-only data to this page for everyone; harness treats an unrevealed name as hidden |
| Dropping Turbo on the page breaks the history handling | history code reduced to `pushState`/`popstate`; browser walk covers Back on Android |
| IndexedDB eviction | `storage.persist()`; snapshots are a convenience, the outbox semantics are unchanged |

---

## 9. Open questions (my defaults in bold)
1. Unique-table auto-open on by default? **Yes, per-device preference, on** - it changes no competition behaviour.
2. Proposal confirm without a second view? **Yes - the card is the confirm view for proposals**; Finished now and typed
   times keep the separate one-tap Confirm.
3. Puzzle choice required before Save? **No** unless A's `required` is set - "Puzzle missing (N)" filter instead.
4. Organiser-assigned table ranges? **Not now** - ship the device filter, revisit after an event.
5. Hidden puzzles on the judge page for organisers too? **Yes, hidden until the public reveal for everyone on this page.**

---

## 10. Work packages (≤ 1-2 days each)

| # | Package | Depends on | Size |
|---|---|---|---|
| C1 | Template data-free (round bits to JS, `state.viewer`, organiser links hidden by default) - no behaviour change, existing tests green | - | 1.5 d |
| C2 | Lean layout + `live_entry` Encore entry (no Turbo), shared self-heal partials, `LiveEntryBundleTest` | C1 | 1.5 d |
| C3 | Server: `live_results_shell`, event-people endpoint, `?entries=0`, ETag/304, `msp_live_player` cookie + logout subscriber, `?entry=`/`?table=` | C1 | 1.5 d |
| C4 | Snapshot store + prefetch + draft + prefs modules (pure, node harness) | - | 1.5 d |
| C5 | Shell mode in the controller (route parsing, current-round port, signed-out/no-snapshot screens, offline strip, going live) | C2, C3, C4 | 2 d |
| C6 | Service worker: `prepare` message, live navigation routing + timeout, prune keeps shell assets, `/barcode-scanner/` cache-first, harness scenarios, `ServiceWorkerLiveRoutesTest`; scan module self-hosted zbar first; docs `.claude/rules/service-worker.md` | C3 | 1.5 d |
| C7 | Speed: unique-table open, confirm-in-place + 350 ms guard, `enterkeyhint`, O(1) entry lookup, outbox grouping, perf budget | - (parallel) | 1 d |
| C8 | QR kinds: `parseLiveQr()`, table/entry handling, round auto-switch, continuous scanning | B's paths fixed; C3 | 1 d |
| C9 | Proposals UI: card panel, Confirm/Change/Not right, "Proposals waiting" list + ✓ sweep, outbox `proposalId` + `proposal` field | B's contract (server side by B) | 1.5 d |
| C10 | Puzzle choice UI: chips, pick-1/pick-K, splits, "Puzzle missing" filter, `puzzles` field in outbox | A's contract (server side by A) | 1.5 d |
| C11 | Judges: device "My tables" filter, referees-page activity column + checklist | - | 1 d |
| C12 | Panther offline walk + manual phone walk + live-results.md / official-results.md / TODO updates, translations (en during work, 6 locales at the end) | all | 1.5 d |

Order: C1 → (C2, C3, C4 in parallel) → C5, C6 → C12; C7 and C11 any time; C8-C10 when A/B's server sides land.
C1-C7 alone already deliver Jan's "survives refresh / works offline / faster".

### Rule 1 check
- An untouched competition: same round state **plus** an additive `viewer` key and the new cookie; same change sets;
  same page markup apart from the layout (lean) and the data-free template. These are page-level changes, not
  competition behaviour - the live page has no byte-identity test today (`LiveResultsPageTest` asserts content, not
  bytes); any test pinning the old layout (banners on the live page) is updated intentionally and named in the PR.
- No new default in competition behaviour: puzzle chips, proposals and new QR kinds appear only when A's/B's settings
  are on.
