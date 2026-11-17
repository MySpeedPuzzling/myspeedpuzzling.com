# Judge page chaos suite (`tools/judge-chaos`)

A repeatable real-browser campaign against the referee's live entry page (`live_results`) and the competitor's time
proposals: what a venue does to a phone - a flapping or dead Wi-Fi, a reload in the middle of a save, a deploy, an
expired session, a busy or failing server, a slow CPU, a wrong clock, a frozen tab - and the four printed QR tag kinds
read by a (fake) camera offline. Each scenario prints one `PASS`/`FAIL` line and checks the **database**, not just the
screen: values, `result_entered_by`, and the change receipts (`round_result_change_receipt` - one row per change id,
so a change sent twice under a new id or applied twice shows as an extra row).

**Not in CI**: it needs ~2.5 GB of Docker memory (Chrome, FrankenPHP in worker mode, Mercure, Redis), a production
asset build and 15-25 minutes (s12c alone freezes a tab for 10 minutes). Run it on a branch that touches the live entry,
the outbox, the service worker or the proposals.

## Run

From the worktree root, after the test bootstrap made `speedpuzzling_<prefix>_test_template` (any test through
`run.sh <prefix>`) and with your own asset build (`OWN_BUILD=1 run.sh <prefix> 'npx encore production'`):

```sh
OUT=<artefacts dir> tools/judge-chaos/chaos.sh all                 # up, every scenario, down (≈ 20 min)
QUICK=1 tools/judge-chaos/chaos.sh all s01_flapping s06_session_expires   # some of them
tools/judge-chaos/chaos.sh up        # then, repeatedly:
tools/judge-chaos/chaos.sh run s04_two_judges
tools/judge-chaos/chaos.sh list      # the scenarios
tools/judge-chaos/chaos.sh seed      # the round anew (results, receipts, proposals cleared, stopwatch restarted)
tools/judge-chaos/chaos.sh down      # every container removed with its volumes, the database copy dropped
```

**Against any branch:** the stack serves the worktree the script lives in (`APP_DIR`, default `../..` of this folder) -
check the branch out in a worktree, build its assets there, give it its own prefix and run its copy of the script:
`NAME_PREFIX=<x> OUT=<dir> <worktree>/tools/judge-chaos/chaos.sh all`. `vendor/` comes from the worktree when it has
one, else from the main checkout (`MAIN_CHECKOUT`, `VENDOR_DIR`); `BUILD_DIR` defaults to the worktree's `public/build`.
Defects found are re-checked by naming their scenarios, e.g. `chaos.sh all s03b_snapshot_timing s06_session_expires
s12b_clock s13_tap_moment_kept`.

`NAME_PREFIX` (default `rlt3`) prefixes every container and the database `speedpuzzling_<prefix>_test_chaos` (a copy
of the template; `chaos.sh` refuses any other name). After a PHP/Twig change: `chaos.sh down && chaos.sh up` (OPcache
never re-reads files in production mode). Results: `$OUT/results-<time>.json` (every scenario's evidence: page state,
proxy timelines, receipts, numbers) + a screenshot of every browser of a failed scenario.

## The stack

| Container | What |
|---|---|
| `<prefix>-web` | the worktree in **production mode**: FrankenPHP worker mode (8 workers), OPcache without timestamps, `APP_ENV=prod`, the worktree's `public/build` - so the real service worker, precache and offline shell |
| `<prefix>-redis`, `<prefix>-mercure` | cache/state; production's Mercure image with anonymous subscribers |
| `<prefix>-proxy` | `chaos/proxy.py` - **the browser's only way to the site and to Mercure** (`APP_URL` and `MERCURE_PUBLIC_URL` are the proxy) |
| `<prefix>-selenium` | `selenium/standalone-chromium`, 3 sessions, `--shm-size 2g`, `media/` mounted for the camera videos |
| runner (one-off) | `chaos/run.py` + `chaos/scenarios.py` with the Python WebDriver client (image `docker/Dockerfile`) |

Data (`seed.sql`): the fixtures' Results Cup gets "Chaos Round" - solo, 300 made-up entrants "Chaos Tester 001-300" at
tables 1-300, the stopwatch running, a 600-minute limit; referee PLAYER_WITH_FAVORITES (Michael), organiser
PLAYER_WITH_STRIPE (Sarah); competitor pages + time proposals on, entrants 300/299/298 linked to John / Jane / Admin;
every fixture account gets the password `judge-chaos-1`. A second round "Chaos Solve All" (pick 2 of 2, "Challenge 1/2",
the same running stopwatch, Chaos Testers 241-245 at tables 241-245) serves `s14_puzzle_splits`. Each scenario uses its own tables and clears them first, so
any scenario runs alone and again. The login limiter (5 a minute per address) is reset in the stack's Redis before
every sign-in.

## How the network is broken (and why there)

- **Proxy, TCP level** (`down`, `blackhole`, `flap`): `down` refuses every connection and resets the open ones
  (Mercure's stream too); `blackhole` accepts and never answers. This is what the **service worker's own fetches** see
  - CDP `Network.emulateNetworkConditions` does not reliably reach them, so every "really offline" step uses the proxy.
- **CDP offline** in addition, so `navigator.onLine` and the `online`/`offline` events are right (a phone knows its
  Wi-Fi is gone), and alone where only **one** browser must be offline (s04's second judge, s07's competitor - page
  fetches only, which CDP does cover).
- **Proxy, HTTP level** (rules per path): N answers of 500 / 429 + `Retry-After` / HTML 503 / 502, a delay, a hang
  (never answered - the page's 15 s timeout), `after` (the app saved, the answer lost), Mercure refused, and a changed
  `/service-worker.js` (a deploy: the browser's update check gets new bytes).
- CDP for the rest: `Emulation.setCPUThrottlingRate` (6x), `Page.setWebLifecycleState` (frozen tab),
  `Page.addScriptToEvaluateOnNewDocument` (a shifted `Date`, a `BarcodeDetector` shim), `Network.setBlockedURLs`.

## Scenarios

| Name | Proves |
|---|---|
| `s01_flapping` | 30 results while the network flaps 4 s up / 3 s down: 30 applied receipts, values, entered by, "All saved", outbox empty |
| `s02_refresh_mid_save` | reload while the save's answer is 3 s late, reload in the same tick as Save, draft with a typed value, draft in the confirm step: 5 applied, nothing lost |
| `s03_offline_reload` | offline reload → shell + snapshot, 5 results, cold open of a table tag and a name tag offline, back online → sent; a silent network → shell after 4 s |
| `s03b_snapshot_timing` | offline reload 1 s after opening; snapshot writes while another judge saves every second |
| `s04_two_judges` | same entry, different values: offline (conflict + Take theirs) and both online with live updates - never a silent overwrite |
| `s05_worker_update` | a new `service-worker.js` installed while 3 results wait (server busy): no reload, outbox drains, the offline copy still answers |
| `s06_session_expires` | session + remember-me cookies gone: "Sign in again", kept; **another account signs in on the phone** → nothing sent as them; the referee signs in → sent as the referee |
| `s07_proposals_offline` | competitor proposes offline → online → judge confirms → "Confirmed"; a proposal + reload while offline keeps the tap's time |
| `s08_undo` | Undo of a sent save, of an unsent one (never reaches the server), of a confirmed proposal (`superseded`/`result_cleared`, Stop again) |
| `s09_server_bursts` | 500×3, 429×2, a hang, 503×2, 502×2 on the saves, then 3 answers lost after the save: no refusal shown, 10 + 5 applied, nothing doubled |
| `s10_cpu_throttled` | 6x CPU, 375×812, 300 entries: in-page timings of table typed → card, Save → sent (30 saves) |
| `s11_offline_scanning` | the 4 tag kinds (person, line-up, table, entry) screenshotted from the real print pages, filmed into `media/*.y4m` (Chrome's fake camera), scanned **offline** after the page prepared: zbar from the worker's cache for real; line-up on its own round; online scan; `BarcodeDetector` via a shim |
| `s12a_mercure_down` | the hub refused: saves still go, another judge's result arrives by the 60 s poll |
| `s12b_clock` | phone clock 2 h wrong from the start, then jumping +1 h with the page open: "Finished now" must save the stopwatch's elapsed time |
| `s12c_background_tab` | the tab frozen 10 min (QUICK: 2) while another judge saves: catches up, "Finished now" right |
| `s13_tap_moment_kept` | Jan's rule - a stopped time is the tap's moment: judge's "Finished now" offline (saved / left in confirm), 65 s, reload, online; competitor's Stop offline, 65 s, reload, online, judge's one-tap Confirm - every stored value = the tap |
| `s14_puzzle_splits` | real events W5 - "Finished now" per puzzle on the seed's "Chaos Solve All" (pick 2 of 2): puzzle 1 tapped offline, reload offline (card + waiting split back), online → sent; puzzle 2 tapped online → confirm → Save: both splits = the taps, last split = the result, 3 applied receipts (exactly once) |

## What it cannot prove (needs a real phone)

- iOS Safari / WebKit (service worker eviction, the "Load failed" first fetch, no Web Locks in old versions), real
  Android Chrome battery savers and tab discarding (a frozen tab is emulated, a discarded one is not).
- A real camera, autofocus, glare on a laminated tag; Chrome's native `BarcodeDetector` decoding (Linux Chrome has
  none - the shim proves only that the page uses one when it exists).
- A real captive portal / Wi-Fi that answers HTML, cellular latency patterns, two physical devices' clock drift.
- A real deploy with a new asset build (s05 changes only the worker's bytes; new hashed `/build` files and the prune
  of the old ones are covered by `ServiceWorkerLiveShellTest`, not here).
