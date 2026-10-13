# Phase 1B contract - live room and studio

Binding names, shapes and file ownership for the parallel streams of Phase 1B (implementation-plan.md §4 "Phase 1B", design README §5.12). Same working rules as [phase-1.md](phase-1.md) §9. A stream that needs to change anything here stops and tells the orchestrator.

**Scope:**
- moderators see every puzzle camera live;
- the host's voice reaches the hall;
- participants see each other when the round turns it on;
- a studio where the host brings finishers on air;
- spectators watch the studio.

Open source and self-hosted only (LiveKit server + `livekit-client`, both Apache-2.0). **Development runs against a local LiveKit in the dev compose; production infrastructure (lily.srv) waits for ADR D78 and Jan's OK** - nothing here may depend on it.

---

## 1. Streams and file ownership

| Stream | Scope | Owns |
|---|---|---|
| **VF - video foundation** (first, alone) | dev LiveKit, configuration, tokens, server API client, webhooks, the studio model, settings switches, the participant + organiser token endpoints | `compose.yml` (a `livekit` service), `.env` (LIVEKIT_* block), `config/livekit/dev.yaml`, `src/Services/LiveKit/*`, `src/Controller/LiveKitWebhookController.php`, `src/Controller/Compete/ApiVideoTokenController.php`, `src/Controller/OnlineRounds/OnlineVideoTokenController.php`, `src/Entity/StudioInvite.php` + repository + migration, the studio messages (§4) + `InviteToStudio` / `AnswerStudioInvite` / `EndStudioGuest` handlers, the video switches in the online-timing settings form, `package.json` (`livekit-client` pinned), `assets/online/livekit_session.js` (pure wrapper) + its harness |
| **VP - participant video** | the phone publishes its puzzle camera to the hall, plays the host's voice, shows others when allowed, joins the studio when invited | `assets/controllers/compete_controller.js` + `assets/online/compete_*.js` video parts, `templates/compete/**` video parts, `assets/styles/compete.scss` additions |
| **VM - moderator console + studio host** | the control room's video grid, spotlight, push-to-talk, "Invite to the studio", the host's studio view | `assets/controllers/online_control_controller.js` video parts, `assets/online/console_*.js`, `templates/online_rounds/control*.html.twig` video parts, `templates/online_rounds/studio*.html.twig`, `src/Controller/OnlineRounds/OnlineStudioController.php` (host page), `assets/styles/online_rounds.scss` additions |
| **VS - spectators** | the public watch page of the studio | `src/Controller/OnlineRounds/OnlineWatchController.php` + `OnlineWatchTokenController.php`, `templates/online_rounds/watch*.html.twig`, `assets/controllers/online_watch_controller.js`, `assets/styles/online_watch.scss` (its own Encore style entry, added by VF) |

Translations: new sub-roots `compete.video`, `online_rounds.console`, `online_rounds.studio`, `online_rounds.watch` (English during the build). VF creates them with one placeholder key each.

---

## 2. Configuration (VF)

| Env | Meaning | Dev default (`.env` / compose) | Production |
|---|---|---|---|
| `LIVEKIT_API_URL` | server-side API base (Twirp) | `http://livekit:7880` | later (D78) |
| `LIVEKIT_PUBLIC_URL` | what browsers connect to | `ws://localhost:7880` (compose: `${LIVEKIT_PORT:-7880}`) | `wss://live.myspeedpuzzling.com` (D78) |
| `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` | the key pair | `devkey` / a dev secret in `.env` | Infisical |

An empty `LIVEKIT_API_URL` means **video is off**: every video endpoint answers 409 `video_unavailable`, and the pages hide video. That way production stays exactly as it is until D78.

The dev `livekit` service:
- image `livekit/livekit-server:v1.13.9`, pinned;
- config `config/livekit/dev.yaml`, with `rtc.udp_port` (mux) and `rtc.tcp_port` published on overridable host ports (`LIVEKIT_PORT`, `LIVEKIT_UDP_PORT`, `LIVEKIT_TCP_PORT`, like the other services in CLAUDE.md);
- `node_ip: 127.0.0.1` for the local browser;
- webhook URL `http://web:8080/-/livekit-webhook`.

No Redis in dev (a single node; egress comes in Phase 4).

---

## 3. Rooms, identities, grants (VF)

| Room | Name |
|---|---|
| Hall | `round-{roundId}` |
| Studio | `studio-{roundId}` |

| Identity | Who | Grants |
|---|---|---|
| `p:{participantId}` | the participant's camera device | hall: `canPublish` (sources: camera only), `canSubscribe`, `canPublishData: false`; studio (after a joined invite): camera + microphone |
| `p:{participantId}:screen` | a second screen (no camera) | hall: subscribe only |
| `m:{playerId}` | moderators / host (`ONLINE_ROUNDS_MANAGE`) | hall + studio: publish camera / microphone / screen share, subscribe, `roomAdmin` for the studio |
| `s:{random}` | a spectator | studio: subscribe only |

- Participants restrict who subscribes to their camera with `setTrackSubscriptionPermissions`: moderators only, or everybody when `participantsSeeEachOther` is on.
- Tokens are JWTs (HS256, `lcobucci/jwt`) valid 6 h, minted only after the personal token / voter check.
- `LiveKitTokens::participant(OnlineAccessContext, deviceId, room)`, `::moderator(playerId, roundId, room)`, `::spectator(roundId)`.

---

## 4. Studio (VF model + handlers, VM + VP UI)

`StudioInvite` (`studio_invite`):
- `id`, `round` (`CASCADE`), `participant` (`CASCADE`);
- `invitedBy` (Player, `SET NULL`), `invitedAt`;
- `status` (`invited` / `joined` / `declined` / `ended` / `expired`), `answeredAt`, `onAirFrom`, `onAirTo`.

| Message | Fields | Effect |
|---|---|---|
| `InviteToStudio` | `competitionId`, `roundId`, `participantId`, `actingPlayerId` | a new invite (one open invite per participant); LiveKit `SendData` to `p:{participantId}` in the hall, topic `studio`, payload `{inviteId}` |
| `AnswerStudioInvite` | `inviteId`, `participantId`, `answer` (`join` / `decline`) | `join` → `joined` + `onAirFrom`; the participant then fetches a studio token |
| `EndStudioGuest` | `inviteId`, `actingPlayerId` | `ended` + `onAirTo`; LiveKit `UpdateParticipant` revokes publishing in the studio |

An invite unanswered for 2 minutes is `expired` (checked on read). The participant state JSON gains `studio: {inviteId, status} | null`. The heartbeat answer also carries the open invite id, so a missed data message costs nothing.

---

## 5. Endpoints (names binding)

| Name | Method + path | Stream |
|---|---|---|
| `compete_api_video_token` | POST `/api/compete/{token}/video-token` `{deviceId, room: "hall" \| "studio", role: "camera" \| "screen"}` → `{url, token, identity, room}` | VF |
| `compete_api_studio_answer` | POST `/api/compete/{token}/studio-invites/{inviteId}` `{answer}` | VF |
| `online_video_token` | POST `/en/online-rounds/{roundId}/video-token` `{room}` (moderator, CSRF) | VF |
| `online_studio_invite` | POST `/en/online-rounds/{roundId}/studio-invites` `{participantId}` | VF |
| `online_studio_end` | POST `/en/online-rounds/{roundId}/studio-invites/{inviteId}/end` | VF |
| `livekit_webhook` | POST `/-/livekit-webhook` (signature-checked; stateless firewall) | VF |
| `online_studio` | GET `/en/online-rounds/{roundId}/studio` (host page) | VM |
| `online_watch` | GET `/en/online-rounds/{roundId}/watch` (public unless the event is a draft) | VS |
| `online_watch_token` | POST `/en/online-rounds/{roundId}/watch/token` (rate-limited per IP) | VS |

All errors are JSON `{"error": code}`. `video_unavailable` 409 applies when the env is empty or the round's `videoEnabled` is off.

---

## 6. Client (VF wrapper, VP / VM / VS use it)

`assets/online/livekit_session.js` wraps `livekit-client` (`import { Room, RoomEvent, Track, VideoPresets } from 'livekit-client'`):
- `joinRoom({url, token, publishTrack?, simulcastLayers?, allowSubscribers?})`;
- `setSubscribers(identities | 'all')`;
- `onData(topic, fn)`, `onParticipants(fn)`;
- `attach(trackPublication, element)`;
- `leave()`.

Simulcast for the puzzle camera: 180p / 360p / 720p; `adaptiveStream` + `dynacast` on. The camera track is **the same** `MediaStreamTrack` the frame grabber reads - publishing must never stop or reopen the camera.

---

## 7. Done when (Phase 1B)

- Locally, two browsers (fake cameras) in the hall are seen by a moderator.
- The host's voice reaches them.
- A finisher accepts an invite and appears in the studio.
- A spectator page plays the studio.
- The heartbeat / state carry the invite.
- An empty `LIVEKIT_API_URL` leaves every page and endpoint as it is today.
- Gates green.
- `spikes.md` S6 filled for the local run.
