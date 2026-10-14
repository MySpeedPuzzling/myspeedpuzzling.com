# Online competitions - spikes (Phase 0)

Small experiments that turn the open questions into numbers before the code depends on them (implementation-plan.md §4, Phase 0). Results are written here as they come.

---

## S1 - footage protocol (for Jan and volunteers)

**Goal:** real solves to tune and score the hands-up detection (S2) and the "no missing pieces" check (Phase 3). Without footage, detection is guesswork.

**Privacy:** the videos show people and homes. They are **never committed to this public repository**. Upload them to the private folder Jan names, and name files by number, not by person.

### What to record

15-20 solves in total, spread over:

| Variable | Variants |
|---|---|
| Phone | an older iPhone, a mid-range Android, any third phone |
| Placement | **angled** (phone on a stand at the far edge of the table, 3-4 ft away, ≥ 12" above it, the puzzle and the upper body in view) and **top-down** (phone above the table) |
| Camera | front camera (screen towards you) and back camera |
| Light | daylight, evening lamp light |
| Puzzle | 300 / 500 / 1,000 pieces; at least three with **low-texture** motifs (sky, sea, one colour) |
| Mat | a plain mat in a contrasting colour, and once your normal table |

### Each recording

1. Start recording with the phone's own camera app, landscape, 1080p, 30 fps.
2. **Film the empty mat for 5 seconds.**
3. Unwrap the puzzle on camera.
4. Solve as you normally would. The whole solve doesn't have to be one take: a 10-minute excerpt plus the full finish is enough.
5. **At the finish raise both hands** for about a second, as in a competition.
6. Keep filming 10 seconds after the finish.

Also record a few special cases:

- **2-3 premature raises:** raise your hands while a piece is still missing, then finish and raise again.
- **2-3 finishes with one piece deliberately left out:** hide one piece, raise your hands - the "missing piece" case.
- **1-2 recordings with someone walking through the background.**

### Labels - one small text file per video

```
video: 07.mp4
phone: iPhone 12, front camera
placement: angled
light: evening lamp
puzzle: 1000 pcs, low texture (sea)
empty_mat: 00:00:01-00:00:05
last_piece_placed: 00:12:41.5
hands_up: 00:12:42.2
complete: yes            # or: no - 1 piece missing
notes: someone walked behind at 00:08:10
```

Times are as shown by the video player (minutes:seconds, tenths are welcome); a rough value is fine - the bench refines them.

**Several raises (premature ones, a missing piece):** `hands_up` may appear once per raise, in any order. Each value is a time or a range `start-end` (when the hands went down - optional), optionally followed by `complete` or `incomplete`. A raise without that word is incomplete unless it is the last one, which takes the file's `complete:` - so the one-raise file above reads exactly as before.

```
hands_up: 00:10:05.0-00:10:06.1 incomplete   # a piece was still missing
hands_up: 00:12:42.2 complete
```

The bench's labelling mode writes these files (moments to the millisecond of the frame).

---

## S2 - detection bench

Tooling: [`tools/online-bench/`](../../../tools/online-bench/README.md) (code only, no footage - `footage/`, `out/`, `models/` gitignored); the README there has every option.

| Tool | Run | Gives |
|---|---|---|
| Bench page (`index.html`) | serve the repository root (`python3 -m http.server 8765`), open `/tools/online-bench/`, drop a video + its label file | the video stepped frame by frame through the phones' own detector worker (`assets/online/detector.worker.js`, MediaPipe tasks-vision 1.1.0, pose + gesture, CPU), landmarks + signal timeline, the finish machine's candidates live; labelling mode (keys) writes the S1 label file; exports `<video>.signals.json` |
| `score.mjs` | `node tools/online-bench/score.mjs <signals…> --labels <dir> --sweep` | per video: detection error (candidate − labelled raise), detected within ±5 s / within 1 s, misses (and those held back by the cooldown), false raises, the finish; a sweep over hold, minScore, dropped frames, gaps, frame rate (all / 5 / 4 / 2 fps), view and the signal's thresholds → a ranked table; `per-video.csv`, `candidates.csv`, `sweep.csv`, `summary.md` |
| `completeness/` | `docker run --rm -v "$PWD/tools/online-bench/completeness":/app:ro -w /app python:3.12-slim sh -c 'pip install -q -r requirements.txt && python selftest.py'` | background holes and reference-grid matching → `complete` / `gaps` (boxes) / `unsure`; `selftest.py` = confusion matrices on synthetic finish photos; `check.py` = the verdicts for real photos |

What to measure on the footage day: the detection error and the misses per view (angled = pose, top-down = gestures) and per frame rate; which hold and thresholds keep false raises at zero; inference time per frame on the phones (the pacing targets 4-5 fps at half a core); the completeness checks' false "complete" on the finish photos with a piece left out.

*Results: pending (footage).*

## S3 + S4 - endurance and clock

The participant page in practice mode (Phase 1, stream B):
- **S3 - endurance:** 90 minutes of camera + wake lock (+ the detector from Phase 2) on 3-4 phones: frame rate, battery, heat, tab survival, a call, a lock.
- **S4 - clock:** five phones counting down side by side, filmed at 240 fps.

*Results: pending.*

## S5 - load + storage

Tooling: [`tools/online-load/`](../../../tools/online-load/README.md) - a k6 script (k6 v2.3.0 + xk6-sse, built with
`grafana/xk6`) where every simulated phone goes through a round end to end on the participant API with the page's own
cadences (page, clock sync, heartbeats, events through an outbox, presign → PUT straight to the store →
`media.uploaded`, start burst, Finished + photo + ±5 s burst, time's up) and holds its live stream through Mercure -
the streams replace the Gatling scenario. Profiles `steady`, `start_burst`, `finish_wave`; a markdown report per run.
The production run waits for Jan's OK (the plan: the tool's README).

Also checked here:
- presigned PUT + CORS on Hetzner Object Storage and on a Garage test instance - Garage done, Hetzner pending;
- a failover drill - done locally.

### Local run (LT), 2026-10-13

Throwaway stack on the dev Docker network (`local-stack.sh`): the working tree at `main` e3ee4f14 (with P1F's Lua
limiter) in FrankenPHP **worker mode as production runs it** (APP_ENV=prod, OPcache without timestamp checks, 32
workers, `max_requests 500`), the messenger consumer, its own Redis, production's Mercure image (same digest), MinIO as
the primary store, Garage v2.4.1 as the second; the database a copy of the test database with 1,200 imported "Load
Tester" participants of the Online Test Cup round (links from `GenerateOnlineTokens`, the links page's CSV format).
Every run got its own participants; the organiser's start was scheduled 2.5 min ahead (45 s for the burst / wave
runs), "End now" a minute before the end. Each phone sent its own `X-Forwarded-For` (the per-IP limit). Photos ~150 KB
(the pessimistic size), bursts 1.8 MB. The machine: Docker Desktop's VM (8 CPUs, 11.7 GB) on a 14-core Mac shared with
other agents and projects - the numbers are **an upper bound on the cost, not lily's capacity**, and a local MinIO's PUT
times say nothing about Hetzner's.

| Run (6 min unless noted) | Requests | p95, slowest endpoint | ±3 s around GO | Web CPU mean / max, memory | Postgres (load DB) commits/s mean / max | Redis cmd/s mean / max | Mercure | Load average (mean) |
|---|---|---|---|---|---|---|---|---|
| 25 phones | 3,187 = 8.9 req/s | events 40 ms | 372 req, p95 42 ms | 0.04 / 0.42 cores, 457 MiB | 30 / 73 | 76 / 266 | 25 streams | 7.8-18.2 (10.9) |
| 50 phones | 6,368 = 17.7 req/s | events 66 ms | 758 req, p95 68 ms | 0.09 / 0.93, 460 MiB | 56 / 182 | 150 / 530 | 50 | 2.4-8.8 (3.8) |
| 100 phones | 12,748 = 35.4 req/s | events 129 ms (state 107) | 1,514 req, p95 140 ms | 0.12 / 0.54, 466 MiB | 109 / 340 | 301 / 822 | 100 | 3.2-13.9 (8.4) |
| `start_burst`, 100, 2.5 min | 6,781 = 45.2 req/s | events 234 ms | 1,579 req, p95 194 ms | 0.15 / 1.06, 467 MiB | 120 / 463 | 314 / 1,115 | 100 | 7.1-15.1 (11.0) |
| `finish_wave`, 100, 4 min | 8,729 = 36.4 req/s | events 137 ms | 1,569 req, p95 114 ms | 0.11 / 0.29, 468 MiB | 99 / 416 | 277 / 980 | 100 | 3.3-6.7 (5.0) |

- **No error anywhere:** every answer 2xx, no API error code, no refused event, nothing left on a phone at the end.
- **The start:** every phone showed GO, p95 4 ms after the start on its synced clock (in two runs one phone of 100 at
  1.6 s - its loop was in a request); the state fetches, clock samples, `start.seen` and start photos of 100 phones
  (~1,500 requests, most of them within 3 s) are served at p95 140-194 ms.
- **The finish wave** (30 % of 100 within a minute, each photo + 1.8 MB burst): tap → both uploaded and acknowledged
  p95 95 ms locally; the wave's other requests p95 15 ms.
- **Live stream:** up to 100 streams, connect p95 ≤ 5 ms, an update from the moment the server read the state to its
  arrival p95 20-41 ms (the publisher was a separate PHP process; production's worker is warm).
- **A phone's rate** (from the cadences, the runs agree): 8 requests a minute while running (heartbeat 4, a photo's
  presign + `media.uploaded` 2 + 2), 16 in the 3 minutes before GO (photos every 10 s), ~14 within 3 s at GO; the PUTs
  go to the store, not to lily. A real 60-minute round of 100 phones is ~13 req/s, ~27 before GO. The web container's
  CPU says ~0.1 core on average for 100 phones and a ~1 core peak for the start burst - nothing for lily (2 × 32
  workers, 16 threads); the same runs on the code before P1F's limiter gave the same picture.
- **Cost per upload in Postgres:** `VerifyOnlineMedia` goes through the Doctrine transport - 3 more writes per upload
  (the message row in, delivered, out) next to the media row; fine at 100 phones (~1 upload/s), worth a Redis
  transport at 1,000.
- The k6 generator itself: ~1 GB of memory and < 0.5 core per 100 phones (every phone VU holds a photo and a burst).

### Storage failover drill (LT), 2026-10-13

Same stack, 50 phones running a round (start at ~1.5 min), the primary (MinIO) stopped at 2.5 min for 2 minutes
(`local-stack.sh block-primary` / `unblock-primary`), then the run's end; the breaker's counters sampled every 2 s.

**Before the fix the second store never took a single upload.** 844 PUTs failed, the breaker never opened (its
counter stayed at 1 source - the server's own `VerifyOnlineMedia` HEAD failing; 3 are needed), Garage stayed empty;
the phones kept everything and uploaded it to the primary once it was back (nothing lost - layer 1 of README §5.11
worked, layer 2 never engaged). Cause: a phone reported only a store's readable 408 / 429 / 5xx (`previousFailure`);
a PUT that gets no answer counted as "the phone is offline". In a browser a store that is down is exactly that - a
stopped or unreachable store refuses the connection, and its error pages (an LB's 503) carry no CORS headers, so the
preflight fails and `fetch()` rejects without a status. **Fix** (`media_outbox.js` `unreachable()`,
`OnlineStoreBreaker::countsAsStoreFailure()`): while `navigator.onLine` is not false, no answer at all is reported as
`previousFailure.status = 0` and counted by the breaker; the item gets a fresh URL from its second failure on (same as
a 5xx); the pause stays the offline one (1 s, 2 s …). A phone its browser knows to be offline blames no store and keeps
its URL. Pinned by two new scenarios in `tests/online-outbox-harness.mjs` and `OnlineStoreBreakerTest`.

After the fix:

| | Fast failure (the store refuses / its name is gone) | Black hole (connections hang) |
|---|---|---|
| How | `docker stop`, k6 resolving every request (`K6_DNS=ttl=0`) | `docker stop`, k6 dialing the dead address it had cached: 30 s `i/o timeout`, then 14 s / 3 s `no route to host` |
| Primary down → first failed PUT | 0.8 s | 0.8 s |
| → breaker open (3 sources) → first upload on Garage | **3.3 s** | 41 s |
| PUTs failed during the 2 minutes (each tried again by the phone, none lost) | 52 | 156 |
| Uploads on Garage / still on the primary before and after | 279 (71 MB) / 645 | 331 (80 MB) / 591 |
| Flap while still down: closed 60 s after the last report, a wave of uploads failed, reopened after | 12 s | 6 s |
| Primary back → new uploads on the primary again | ~25 s (the last reports aged out) | ~50 s |

- **The phones' uploads landed on the second store** through the same presigned URLs: Garage enforces the signed
  `Content-Length` and `Content-Type`.
- **Nothing on the web container's disk:** `docker diff` of the web container identical before and after (Caddy's own
  start-up files only), the upload spool directory never created; the web container received 36 MB (JSON and the
  database's answers) while 172 MB went to the two stores.
- **`VerifyOnlineMedia` verified every upload on its own store** (591 / 591 primary, 331 / 331 secondary; 645 / 645,
  279 / 279), verified bytes = declared; HEADs failing during the outage were tried again by the transport (30 s,
  2 min) and passed once the primary was back.
- **The review read every object from the store its row names:** a participant with photos on both stores
  (`OnlineReview::detail()`): 18 presigned GETs, MinIO for the primary rows, Garage for the secondary ones, bytes as
  declared. 2 rows "pending" - URLs dropped after a failure, never uploaded; the item went up under a new id.
- **The prune handles both stores:** `myspeedpuzzling:prune-online-media 30` run with the clock 31 days ahead
  (Symfony's global clock in a throwaway process, the command unchanged): 12,932 periodic photos deleted - 12,400 rows
  and objects on MinIO, 532 on Garage - in 12 s, 0 failed; the orphan sweep listed both stores (2,688 / 79 objects) and
  deleted the one orphan planted in each.

**Presigned PUT + CORS on Garage v2.4.1** (path-style, bucket + key made with the `garage` CLI): a PUT of 1,001 bytes on
a URL signed for 1,000 → 403, `image/png` on one signed for `image/jpeg` → 403, exactly as signed → 200, a presigned
GET reads it; the app's `myspeedpuzzling:online-media:cors --store secondary --apply` writes the bucket rule, the
preflight from the allowed origin → 200 with `Access-Control-Allow-Origin`, another origin → 403. MinIO: the same for
the URLs, but no bucket CORS (`PutBucketCors` → 501, its CORS is server-wide). **Hetzner's bucket: still to check** -
the CORS rule needs Jan's OK.

**Recommended breaker settings:** keep 3 distinct sources within 60 s. A fast failure moves uploads in ~3 s; the
breaker closes 60 s after the last failure report, so during a long outage it flaps once a minute at the cost of one
wave of failed uploads, retried within seconds - and returns to the primary within a minute of its recovery. Since a
phone with a network blip in the middle of a PUT now reports a failure too, at 1,000+ phones consider
max(3, 1 % of the round's phones) sources. Follow-ups (`docs/TODO.md` material):
- a timeout on the phone's PUT (`putToStore` has none - a black-holed store holds each phone's upload for the
  browser's connect timeout, up to minutes, and the failover waits for it): e.g. 30 s + 1 s per 50 KB; **done (P1G)**:
  `uploadTimeoutMs()` = 15 s + the body at 32 KB/s (a 35 KB photo 16.1 s, 150 KB 19.7 s, a 1.8 MB burst 69.9 s), an
  aborted upload is reported as status 0 with a fresh URL at once - three phones on a hanging store reach the second
  store 17.1 s after it began to hang (harness), was 41 s;
- a presign carrying `previousFailure.mediaId` could mark that never-uploaded row superseded, so the review shows no
  "pending" twin;
- before closing, a server-side probe of the primary (a HEAD of a canary object) would spare the flap's failed wave.

**For lily** (the production run's plan is in the tool's README - Jan's OK, a quiet hour, the edge prepared):
- 2 × 32 FrankenPHP workers are plenty for 100 phones (~0.1 core, a ~1 core start burst); the participant API limits
  (300 per link, 60 event batches, 60 presigns a minute) are 10-20× a phone's needs; 1,200 a minute per IP is ~75
  phones before GO on one IP (a venue or a carrier NAT).
- **The edge is the real limit:** `/api/compete/*` and `/-/time` run on the web router - CrowdSec with captcha
  decisions (unsolvable for a JSON API) and the **bot-blocker's 90 requests a minute per IP + User-Agent**: six phones
  of the same model and iOS on one Wi-Fi (a club meeting) are blocked in the 3 minutes before GO. Route both like
  `/api/v` (`myspeedpuzzling-api-edge`: no bot-blocker, no captcha) before the first event - a lily.srv change, Jan's
  "yes".
- One load generator counts as one client everywhere (app 1,200/min, Traefik 100/s burst 200, bot-blocker, CrowdSec):
  ~30 phones per generator address keeps the start burst inside Traefik's bucket - 100 phones = 3-4 small VMs.

## S6 - video

LiveKit locally (docker), then on a test box with TURN:
- `lk load-test` with 100 → 300 publishers;
- three real phones publishing + detecting for 90 minutes;
- a studio round trip;
- a strict-network test.

### Local run (VF), 2026-10-13

Throwaway containers on the dev Docker network (Docker Desktop on a Mac laptop): `livekit/livekit-server:v1.13.9` (one node, UDP mux 7882, ICE/TCP 7881, `--node-ip` = its address on the network), the stream's worktree on `php -S` (dev env, a scratch copy of the test database: the Online Test Cup), Selenium Chromium with a fake camera and microphone (`--use-fake-device-for-media-stream --use-fake-ui-for-media-stream`). The pages ran the real `assets/online/livekit_session.js` over livekit-client 2.22.4; every token came from the real endpoints / `LiveKitTokens`, every server call from `LiveKitApi`, every webhook went through `POST /-/livekit-webhook`.

| Check | Result |
|---|---|
| A participant token (`compete_api_video_token`, camera role) joins the hall and publishes the fake camera (1280×720) | connected + published in 274 ms and 312 ms (two participants, warm browsers; 660 ms in a fresh one); LiveKit lists each camera with 3 simulcast layers and grants exactly `CAMERA` |
| A moderator token subscribes | both cameras arrive and play (320×180 tiles, adaptive stream) |
| Subscriber permissions (`moderators`, "see each other" off) | the other participant sees the camera announced but is not subscribed to it |
| `SendData` (InviteToStudio → StudioLiveUpdates::invited()) | reaches the participant's page: topic `studio`, `{"inviteId": …}`, sender = the server (null); the next heartbeat carries `studioInviteId` too |
| Studio: answer "join" → studio token → camera + microphone | leaving the hall leaves the camera `live` (the wrapper never stops it); studio join 102 ms; the host has the guest's camera + microphone ~2 s after its own join |
| `UpdateParticipant` revoking publishing (EndStudioGuest → StudioLiveUpdates::ended()) | the call takes 12 ms; at once the host sees both tracks unpublished and `canPublish: false`; the guest stays in the studio watching, their camera still `live` |
| Webhooks | 32 delivered, all verified - LiveKit's JWT carries iss, iat, nbf, exp (+300 s) and sha256, no `Bearer`, `Content-Type: application/webhook+json`, `createdAt` as a string of seconds; `participant_joined` in the studio set `onAirFrom`, the guest's leave set `ended` + `onAirTo`; a reconnect with the same identity (`participant_left` with `disconnectReason: DUPLICATE_IDENTITY`, then `participant_joined`) kept the guest on air; an unsigned request → 401 |
| LiveKit container | idle: 0.1 % CPU, 73 MiB; 2 publishers (720p capture, simulcast) + 1 moderator subscribed to both: 1.3-1.8 % CPU (of one core), 125 MiB |

Not covered here: TURN and strict networks, real phones, the load test, and the dev compose service's own host-port path (`node_ip: 127.0.0.1`) - only checked that `config/livekit/dev.yaml` starts the server and that the host-port overrides reach it (`rtc.portTCP` from `LIVEKIT_RTC_TCP_PORT`, `rtc.portUDP` from `--udp-port`).

### Moderator console (VM), 2026-10-13

The same kind of throwaway set-up: `livekit/livekit-server:v1.13.9` (one node; a fixed address on the dev network as its node IP, UDP mux 7882, ICE/TCP 7881, webhooks to the web container), the stream's worktree on `php -S` in prod mode with a warmed cache, its own Redis and a scratch copy of the test database: the Online Test Cup's round running with video on, seven invented participants added to Olivia (personal links, entries, ready phones, three Finished taps). Selenium Chromium with fake devices: one browser with eight windows as the eight phones (a scratch page publishing a 1280×720 fake camera through `livekit_session.js` with tokens from `compete_api_video_token` - the participant page has no video before VP), the moderator's browser on the control room, the host's on the studio page, hidden spectators with tokens minted for the probe.

| Check | Result |
|---|---|
| 8 phones in the hall | each joined in 266-612 ms; the console's grid: 8 live tiles received at 320×180 (the 180p layer), the finished first (newest tap on top), then by name; Arthur's tile "Not in the video room" |
| Spotlight (click / Enter, ← → / Esc) | the spotlit camera arrives at 1280×720 within ~3 s while every other tile stays at 320×180; closed, it is back at 320×180. The spotlit phone sends its 180p, 360p and top layers, a phone only in the grid its 180p layer only |
| Paging (4 a page for the proof - the page has 25) | page 2's cameras arrive at 180p; a camera on another page is not received at all and its phone sends no layer (dynacast) |
| Why a tile has no video | "In the studio" (the phone moved there), "Not in the video room", "No video from this phone" (in the hall, nothing published), "Camera paused" (muted), "Loading the video…" |
| Push-to-talk | the microphone is published muted (the phone: subscribed, muted, silence); held (Space or the button) the phone sees it unmuted 0.19 s after the press and hears the fake tone (RMS 0.38); released, muted again; "Keep talking" stays on air even when the window loses focus. A held key or button is let go when the window loses focus - by design |
| Invite → join → studio | the phone answered "join" 66 ms after the data message, left the hall and joined the studio in 119 ms; the console said "On air in the studio" 1.5 s after the click (the studio's JSON, read at once when the camera left the hall); the host's stage shows the guest (640×360 tile, sound playing) and the time on air |
| "Thank you" | UpdateParticipant + RemoveParticipant: the guest's connection closed 96 ms after the click (`PARTICIPANT_REMOVED`); "Was on air" on both pages |
| "Withdraw" | an invitation waiting for its answer (the countdown on the tile and in the host's list) withdrawn from the studio page: "Invitation withdrawn" on both |
| A studio token used again | 12 s and 59 s after it was minted LiveKit admits it - the webhook removes the guest 61-177 ms after their session started, before their camera's publication completes. 130 s after (past the 2-minute `exp`) LiveKit still admits it - **its token check allows 60 s of clock leeway** (go-jose), so a 2-minute token works for about 3 - and the webhook removes it; 190 s after LiveKit refuses it (`token is expired`) |
| Audience | two hidden spectators: "Live" and "2 people are watching" on the host's page - LiveKit's room count (`Room.numParticipants`, updated every few seconds) includes hidden participants, which nobody sees in the room itself |
| The same moderator in a second tab | the new tab takes the video over (`DUPLICATE_IDENTITY`); the first says so, its tiles go quiet, "Use the video here" takes it back |
| LiveKit container | 7 phones publishing 720p simulcast, the moderator receiving 6 tiles at 180p and 1 spotlight at 720p, the host on air with a guest: 4.5 % of one core (median of 12 samples, 4.3-7.0 %), 135 MiB. With 8 phones and the moderator only: 4-6 %, 100-110 MiB |

The encoding is the browsers' work: the Chromium container with the 8 phones and 3 more browsers took 0.8-1.3 cores and 1.9 GiB.

Not covered here: real phones, TURN, a full page of 25 tiles, the participant page's own video (VP), the watch page (VS).

*Results on a test box: pending.*
