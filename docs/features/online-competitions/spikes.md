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

Tooling: `tools/online-load/` - a k6 script (heartbeats, events, presigning) + Mercure's Gatling scenario through Traefik at a quiet hour.

Also checked here:
- presigned PUT + CORS on Hetzner Object Storage and on a Garage test instance;
- a failover drill.

*Results: pending.*

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

*Results on a test box: pending.*
