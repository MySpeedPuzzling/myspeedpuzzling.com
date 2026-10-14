# Load test of online competitions (`tools/online-load`)

Spike S5 of the online competitions ([`docs/features/online-competitions/spikes.md`](../../docs/features/online-competitions/spikes.md)):
N participants of one online round, each a simulated phone going through the round end to end on the public
participant API - the way the participant page does it, cadences included - plus its live stream through Mercure.
Results of the local runs and of the storage failover drill: spikes.md S5.

**Code only.** Personal links are access rights, photos are photos: `input/`, `out/` and the built k6 binary (`.bin/`)
are gitignored.

| Piece | What it does |
|---|---|
| `participants.js` | the k6 script: one VU per phone (`lib/phone.js`) + one per phone's stream (`lib/subscriber.js`), the profiles below, the markdown report (`lib/report.js`) |
| `lib/phone.js` | a phone as `assets/controllers/compete_controller.js` and `assets/online/*` behave: the page, clock sync (8 × `/-/time`), consent + `device.ready`, the empty-mat photo, a heartbeat every 15 s (state fetched when its hash changes), periodic photos every 30 s / every 10 s in the last 3 min before GO, state + 4 clock samples 2 s before and 1 s after GO, `countdown.seen`, `start.seen` + the start photo, Finished (`finish.button`, photo + ±5 s burst), "I tapped by mistake" (`finish.button.undone`), time's up (end photo, `timeup.pieces`); events through an outbox (batches of 50), photos through a media outbox mirroring `media_outbox.js` (presign → PUT straight to the store → `media.uploaded`; critical first; the same backoffs, `previousFailure` on the next presign) |
| `lib/subscriber.js` | the phone's live stream: state on every (re)connect (that is where the subscriber token comes from), the round's stopwatch topic with `Authorization: Bearer`, reconnect with backoff |
| `k6.sh` | runs the script in the `grafana/k6:2.3.0` image with the binary `build-k6.sh` made |
| `build-k6.sh` | builds k6 v2.3.0 + [xk6-sse](https://github.com/phymbert/xk6-sse) v0.2.0 (Apache-2.0) with Grafana's `grafana/xk6:1.4.14` image - k6 itself has no Server-Sent Events, and its automatic extension resolution does not know `k6/x/sse` for k6 v2 (tried 2026-10-13) |
| `make-media.php` | the evidence the phones upload: `photo.jpg` (1280×720, ~150 KB - every photo kind; a phone's periodic photo is smaller, so this is the pessimistic size) and `burst.bin` + `burst.json` (100 frames 640×360, ~1.8 MB, the way `frame_buffer.js` builds a burst) - synthetic, no people |
| `local-stack.sh` | the throwaway local stack: FrankenPHP in worker mode like production, the messenger consumer, Redis, production's Mercure image, MinIO (primary) and Garage (second store) |

Open source only (Jan's rule): k6 AGPL-3.0, xk6 and xk6-sse Apache-2.0, Garage AGPL-3.0, MinIO AGPL-3.0, Mercure AGPL-3.0 -
all run as self-hosted tools, none is a service we pay for or depend on.

## Before the first run

```sh
tools/online-load/build-k6.sh                       # once per machine (~1 min, needs Docker)
mkdir -p tools/online-load/input
# any PHP with GD - the site's image has it
docker run --rm -v "$PWD":/app -w /app --entrypoint php ghcr.io/myspeedpuzzling/web-base-php85:main \
  tools/online-load/make-media.php tools/online-load/input
```

**The links:** the round's personal links as the organiser's links page exports them ("Personal links" → CSV,
`online_links_csv`) saved as `input/links.csv` - the script takes the token from each link. A plain list of links or
tokens (one per line) works too. N participants = the first N links after `OFFSET`; give every run its own slice, so
no run inherits another one's entries.

**The participants** are the round's own: imported like any event's participants (the participants sheet's import, or
`myspeedpuzzling:import-competition-participants <competitionId> <file>` with columns `name,country,round_names`), with
made-up names ("Load Tester 0001" - the repository and the site are public, never real people). The links page gives
every going participant a link when it is opened.

## Profiles and options

`tools/online-load/k6.sh <run name> -e BASE_URL=… [-e …]` - the report goes to stdout and `out/<run name>/report.md`,
k6's raw summary to `out/<run name>/summary.json`.

| Profile (`PROFILE`) | What it stresses |
|---|---|
| `steady` (default) | a whole round compressed into the run: phones join, wait, the organiser schedules the start (≤ 3 min ahead: photos every 10 s), the start burst, half of the phones tap Finished spread over the rest of the run (10 % take it back and tap again), "End now" → time's up for the others |
| `start_burst` | nobody finishes: the start alone - every phone fetches the state 2 s before GO, sends `start.seen` + the start photo at GO, fetches again 1 s after it, with clock samples around both |
| `finish_wave` | 30 % of the phones tap Finished within one minute (`FINISH_AFTER` after GO, `FINISH_WINDOW` long): finish events, photos and ±5 s bursts (~1.8 MB each) at once |

| Option (`-e NAME=value`) | Default | |
|---|---|---|
| `BASE_URL` | - | the site (required) |
| `N`, `OFFSET` | all links, 0 | which links |
| `DURATION` | `5m` | the whole run; the phones stop at its end, the streams are cut there |
| `JOIN_SPREAD` | `30s` | the phones open their links within this |
| `START_AT` | - | the expected start (ISO 8601 or epoch ms) - for the report only |
| `FINISH_SHARE`, `FINISH_AFTER`, `FINISH_WINDOW`, `UNDO_SHARE` | by profile, `0.1` | who taps Finished and when |
| `SSE` | `1` | `0`: no streams |
| `PAGE` | `1` | `0`: skip the page itself (the HTML) - the phone starts from the state; for a run through the bot-blocker |
| `SPOOF_IPS` | `0` | `1`: every phone its own `X-Forwarded-For` (198.18.0.0/15) - **the local stack only**, see below |
| `PHOTO`, `BURST`, `BURST_MANIFEST`, `LINKS` | `/input/…` | the files |

**The organiser starts the round** - the script never does (it has no organiser account and never should): "Start at …"
in the control room (or "Start in 10 s"). Schedule it **at least 30 s ahead**: k6 holds a stream in its own VU, so a
simulated phone learns the start from its next heartbeat (≤ 15 s) - a real phone hears the push at once. "End now"
ends the round; the phones that did not finish report their pieces. (On a local copy without a browser: the same
messages - `ScheduleRoundStart`, `StopRoundStopwatch`, `ResetRoundStopwatch` - and then
`RoundStopwatchLiveUpdates::stopwatchChanged()`, which publishes the state as the controllers do after the commit.)

## Local run (throwaway)

Never against production. The stack runs the working tree in production mode against a **copy** of a test database
(the test suite builds `speedpuzzling_<x>_test` with the "Online Test Cup" fixture; copy its `_template`):

```sh
docker exec speedpuzzlingcz-postgres-1 createdb -U postgres -T speedpuzzling_<x>_test_template speedpuzzling_<x>_test_load
DATABASE_URL='postgresql://postgres:postgres@postgres:5432/speedpuzzling_<x>_test_load?serverVersion=16&charset=utf8' \
  NAME_PREFIX=<x> VENDOR_DIR=<main checkout>/vendor BUILD_DIR=<main checkout>/public/build \
  tools/online-load/local-stack.sh up
```

Then the participants: `local-stack.sh console myspeedpuzzling:import-competition-participants <competitionId> <file>`
(the file inside the working tree - it is mounted at `/app`). Their links come from the links page, which needs a
signed-in organiser - on a throwaway copy without a browser, a few lines of PHP through the app's own pieces do the
same (`GenerateOnlineTokens` on the message bus, then `GetOnlineLinks::ofCompetition()` + `OnlineLinkUrl::of()`, the
CSV the page writes); that script stays out of the repository. Then run:

```sh
NAME_PREFIX=<x> tools/online-load/k6.sh ramp100 -e BASE_URL=http://<x>-web:8080 -e SPOOF_IPS=1 -e N=100 -e DURATION=6m
```

`local-stack.sh stats` shows CPU / memory / network per container; Mercure's `mercure_subscribers_connected`:
`docker exec <x>-mercure wget -qO- http://127.0.0.1:2019/metrics`; Redis: `docker exec <x>-redis redis-cli info stats`.
`local-stack.sh reload-app` after a code change (OPcache in production mode never looks at the files again). At the end:
`local-stack.sh down` (every container with its volumes) and drop the database copy.

The machine running the stack also runs k6, Postgres and everything else - the local numbers are an **upper bound on
the cost**, not lily's capacity, and the PUT times are a local MinIO's, not Hetzner's.

**`SPOOF_IPS=1` locally:** the participant API limits requests per client IP (1,200 a minute, `config/services.php`) -
one machine simulating 100 phones would be one client. The app trusts private proxies (`TRUSTED_PROXIES`), so locally
each simulated phone can name its own address. Through Traefik the header is replaced by the real one.

## Reading the report

- **Requests** - per endpoint: requests, rate, p50 / p95 / p99 / max of k6's `http_req_duration` (the whole request).
  `put` is the upload to object storage, not PHP. The streams are not in this table (k6's own `http_req_duration` does
  hold them as one long request each - ignore it).
- **Answers by status** - 404 / 409 / 413 / 422 / 429 / 503 are the API's named refusals (contract §6), `no answer` a
  failed connection. **API error codes** names them (`rate_limited`, `retry`, `too_many_pending_media` …); event
  refusals come from `refused` in the events answer. A healthy run has none of either.
- **By phase** - `start` = every request within ±3 s of GO: the start burst; `finish` = the finish wave.
- **The start** - how many phones showed GO and how late (their own synced clock - it measures the phone's timing, not
  the server), how long before GO they knew the start, the clock sync's best round trip.
- **Finishes** - a tap until its photo and burst were uploaded and their `media.uploaded` acknowledged.
- **Evidence uploads** - presigned / uploaded / MB / failed PUTs per store; PUTs tried again after a failure (the
  outbox's retries); what was left on the phones at the end (should be 0). With failures: the failover timeline.
- **Live stream** - connect = until the stream's headers; update latency = from the moment the server read the state it
  publishes (`asOfMs`) to the update's arrival, on the server's clock.

The server side is not in the report - watch it while the run goes (`local-stack.sh stats`, the counters above; on lily
Grafana).

## The storage failover drill

`local-stack.sh block-primary` stops the primary store (MinIO) during a run, `unblock-primary` starts it again (its data
kept). How the outage looks to the phones depends on k6's name cache: by default k6 keeps the stopped container's
address and its uploads hang until the connection times out (a black-holed store: 3-30 s each); with `K6_DNS=ttl=0`
(passed through by `k6.sh`) every upload resolves the name and fails at once (a refused, unreachable store). What to
look at: the breaker's counters in redis-state (`redis-cli -n 2 zcard online:store_failures:primary` - it opens at 3
sources within 60 s), the report's failover timeline and uploads per store, `online_entry_media.store` of the rows made
meanwhile, `garage bucket info <bucket>` (objects on the second store), `docker diff <x>-web` (nothing written into the
web container), the consumer's VerifyOnlineMedia. The results and the breaker settings they led to: spikes.md S5.

## A production run (only with Jan's OK)

At a quiet hour (Tuesday or Wednesday 05:00-07:00 Prague - the quietest time of lily's week), announced, Jan watching.

**Before the day**
1. Jan's explicit OK and the time window.
2. A separate **draft** event "MSP Online Test Cup (load)" with one online round (60 min limit) - never Test Cup #1 -
   with N "Load Tester" participants imported; the links CSV downloaded from its links page onto the machine that runs
   k6 only (the tokens are access rights - deleted after the run).
3. **The edge lets the generator through** - lily.srv, Jan's "yes" for each change:
   - `/api/compete/*` and `/-/time` run on the `myspeedpuzzling-web` router: CrowdSec (`crowdsec-msp`, captcha
     decisions) + Traefik's `rate-limit@file` (100 req/s, burst 200 per IP) + the **bot-blocker** forwardAuth
     (90 requests a minute per IP + User-Agent, a risk score on HTML GETs). The generator's addresses go on CrowdSec's
     allowlist for the window, or the run measures the defences. k6 sends a User-Agent per phone (the bot-blocker
     counts per IP + User-Agent); `-e PAGE=0` skips the HTML page (scored like a browser, k6 is none).
   - Every generator is one client to the app (1,200 requests a minute per IP) and to Traefik (100 req/s, burst 200):
     a phone sends 16 requests a minute in the 3 minutes before GO and ~14 within 3 s at GO, so ~30 phones' start
     burst fills Traefik's bucket. **100 phones = 3-4 generator addresses** (small Hetzner Cloud VMs, each with
     `build-k6.sh` and its own `OFFSET`/`N` slice of the links), 1,000 phones = 30+ - or a router that exempts them.
     Real phones each have their own address; this limits only the test.
4. The bucket CORS rule is not needed for k6 (no browser) - uploads go to the production bucket under
   `online/<the load event>/…` and are removed afterwards (below).

**The run** (`NETWORK=bridge`, `-e BASE_URL=https://myspeedpuzzling.com -e PAGE=0`, no `SPOOF_IPS`)
1. 25 phones, 6 min; the organiser schedules the start ~2.5 min ahead and presses "End now" a minute before the end.
2. 50, then 100 phones the same way (the slices split over the generators).
3. 100 phones `start_burst`, then `finish_wave`.
4. 1,000 phones only with a separate OK (30+ generator addresses).

Watch in Grafana: MSP web CPU / memory (2 replicas × 32 workers), Postgres, Traefik's request rate and latency of the
web router, redis-state memory (capped at 64 MB, no eviction - the presence and the breaker's counters live there; the
rate limits on the cache Redis), Mercure (not scraped yet: `docker exec <mercure> wget -qO- http://127.0.0.1:2019/metrics
| grep mercure_` on the box - production's image exposes `mercure_subscribers_connected` there as it is).
**Stop (Ctrl-C) at once** when the site's own pages slow down (Traefik p95 of the web router doubles), answers fail
(5xx above 1 %), web CPU stays above 6 cores, or redis-state passes 48 MB.

**After**: reset the round, delete the load event (draft) with its participants - the links die with them - remove its
objects (`online/<competitionId>/` in the bucket; the daily prune would take the periodic photos after 30 days, the
rest after a year), delete the links CSV and the reports from the generators.
