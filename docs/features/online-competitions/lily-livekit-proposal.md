# LiveKit on lily.srv - proposal for the lily.srv session (app ADR "D78")

**Status: approved by Jan 2026-11-17 - lily ADR D106, carried out by the lily.srv session.** Written 2026-11-17 from the
app side (online competitions → Test Cup #2); "D78" in lily.srv is another decision (zs-celadna). **Jan's decisions
change two points below:** sandbox **and** production go live together (not sandbox first), and TURN/UDP + ICE/TCP are
opened now on both. Ports: media UDP 7882 production / 7883 sandbox, TURN/UDP 3478 / 3479, ICE/TCP 7881 / 7882 (TCP);
hosts `live.` + `turn.` (production), `sandbox-live.` + `sandbox-turn.` (sandbox), all DNS only. The lily repo
(`apps/myspeedpuzzling`, `apps/msp-sandbox`, journal, D106) is the record of what was built; this file keeps the reasoning.

**What it enables:** the live video that is built and proven against a local LiveKit 1.13.9 (STATUS.md, Phase 1B):
puzzle cameras in the moderators' grid, the host's voice, the studio, spectators. Without it the app stays exactly as
today (`LIVEKIT_*` empty = video off, every video endpoint answers 409 `video_unavailable`).

**Already done on the infrastructure side** (lily journal 2026-11-17): `live.myspeedpuzzling.com` and
`turn.myspeedpuzzling.com` are DNS-only CNAMEs to the apex (lily, 91.98.188.211) - they must stay unproxied. CrowdSec
already ignores the app's online-competition background calls (lily `6d83105`).

## 1. Two stages - sandbox first

| Stage | What | Why first / second |
|---|---|---|
| **A - sandbox** | a LiveKit of the sandbox's own in `apps/msp-sandbox` | the demo + the real-device pass of video (`device-test.md` steps 25-37) with zero risk to production; the same configuration as B, so B is a copy |
| **B - production** | LiveKit in `apps/myspeedpuzzling` | Test Cup #2 |

Two servers, not one shared: a LiveKit API key is not limited to rooms, so a sandbox key (a public test site, D88 "nothing
writable shared with production") could mint tokens for production's rooms, and LiveKit signs every webhook with one key
- one server cannot serve two apps with different keys. Cost of the second one: one more UDP port, two more DNS records.

## 2. What each stage adds (B shown; A = the same with the names in §5)

### 2.1 Compose (`apps/myspeedpuzzling/compose.yaml`)

```yaml
  livekit:
    image: livekit/livekit-server:v1.13.9@sha256:<pin at the time>   # the version the app was proven against
    restart: always
    command: ["--config", "/etc/livekit.yaml"]
    volumes:
      - ./livekit.yaml:/etc/livekit.yaml:ro          # rendered from livekit.yaml.template by dump_secrets (key pair)
    ports:
      - "7882:7882/udp"                               # WebRTC media, one muxed UDP port (the ONLY new public port)
    mem_limit: 2g
    mem_reservation: 256m
    pids_limit: 512
    security_opt: [no-new-privileges:true]
    networks:
      traefik: {}                                     # signalling + TURN/TLS through Traefik
      internal:
        aliases: [myspeedpuzzling-livekit]            # the app's LIVEKIT_API_URL (lily rule: project-unique alias, never a bare name)
      monitoring: {}
    labels:
      app: "myspeedpuzzling-livekit"
      metrics.scrape: "true"
      metrics.port: "6789"
      metrics.path: "/metrics"
      traefik.enable: "true"
      # Signalling (wss) - HTTP router
      traefik.http.routers.myspeedpuzzling-livekit.rule: "Host(`live.myspeedpuzzling.com`)"
      traefik.http.routers.myspeedpuzzling-livekit.entrypoints: "websecure"
      traefik.http.routers.myspeedpuzzling-livekit.tls.certresolver: "le"
      traefik.http.routers.myspeedpuzzling-livekit.middlewares: "public-edge@file"   # no bot-blocker: browsers' wss only
      traefik.http.routers.myspeedpuzzling-livekit.service: "myspeedpuzzling-livekit"
      traefik.http.services.myspeedpuzzling-livekit.loadbalancer.server.port: "7880"
      # TURN/TLS on 443 - TCP router by SNI, TLS terminated by Traefik (LiveKit `turn.external_tls: true`)
      traefik.tcp.routers.myspeedpuzzling-livekit-turn.rule: "HostSNI(`turn.myspeedpuzzling.com`)"
      traefik.tcp.routers.myspeedpuzzling-livekit-turn.entrypoints: "websecure"
      traefik.tcp.routers.myspeedpuzzling-livekit-turn.tls.certresolver: "le"
      traefik.tcp.routers.myspeedpuzzling-livekit-turn.service: "myspeedpuzzling-livekit-turn"
      traefik.tcp.services.myspeedpuzzling-livekit-turn.loadbalancer.server.port: "5349"

  livekit-redis:      # only when a second node or Egress comes (Phase 4) - NOT in stage B; a single node needs none
```

- `web`: add `aliases: [myspeedpuzzling-web]` on `internal` - LiveKit's webhook goes there, never through Traefik
  (`livekit` sits on the shared `traefik` network, where a bare `web` resolves to other tenants - lily D78 finding).
- `deploy.sh`: add `livekit` to the explicit `docker compose up -d …` list (before the web/api rollout).
- Env on `web`, `api`, `messenger-consumer` (not secret except the pair):
  `LIVEKIT_API_URL=http://myspeedpuzzling-livekit:7880`, `LIVEKIT_PUBLIC_URL=wss://live.myspeedpuzzling.com`,
  `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` from Infisical (the secret ≥ 32 characters, HS256).

### 2.2 `livekit.yaml.template` (secrets filled by `dump_secrets`, the listmonk pattern)

```yaml
port: 7880
log_level: info
prometheus_port: 6789
rtc:
  udp_port: 7882            # mux: one port for every participant
  tcp_port: 0               # ICE/TCP left out: TURN/TLS on 443 covers networks without UDP (plan §5)
  use_external_ip: false
  node_ip: 91.98.188.211    # lily's public IPv4 - advertised to browsers (no STUN lookup at start)
turn:
  enabled: true
  domain: turn.myspeedpuzzling.com
  tls_port: 5349            # plain TCP inside: Traefik terminates TLS on 443
  external_tls: true
  udp_port: 0               # TURN/UDP 3478 left out (one more public port; UDP clients reach 7882 directly)
keys:
  __LIVEKIT_API_KEY__: __LIVEKIT_API_SECRET__
webhook:
  api_key: __LIVEKIT_API_KEY__
  urls:
    - http://myspeedpuzzling-web:8080/-/livekit-webhook
room:
  auto_create: true
  empty_timeout: 300
```

### 2.3 Firewall (the one new public port)

- Hetzner **Robot** firewall: allow `udp 7882` in (check the rule cap first - ~7 used); stage A: `udp 7883`.
- **ufw**: `ufw route allow proto udp from any to any port 7882` (ufw-docker: a Docker-published port is a route rule).
- D7 exception like WireGuard: "only Traefik publishes ports" - LiveKit's media port is the second written exception
  (WebRTC media cannot go through an HTTP proxy).

### 2.4 Host tuning

`net.core.rmem_max` / `net.core.wmem_max` = 5 MB (LiveKit's recommendation for an SFU), `sysctl -w` + a file in
`/etc/sysctl.d/` - live, no restart.

### 2.5 CrowdSec

Add `^(live|sandbox-live)\.` to the host list (A, any status) of `lily/not-blockable`: the signalling socket
(`/rtc`, `/rtc/validate`) reconnects on every network change of a phone and must never push a participant toward a
ban. TURN/TLS is a TCP router - no HTTP access-log line, CrowdSec never sees it (LiveKit asks TURN credentials).

### 2.6 Monitoring

- Scrape `:6789` (labels above). Alert rules + promtool tests: `LiveKitDown` (target down 2 m), `LiveKitHighCPU`
  (raise `ContainerHighCPU`'s 1.5 cores / 30 min for this container - an event is meant to use CPU), and a
  packet-loss / NACK-rate panel on a small dashboard (rooms, participants, tracks, bytes in/out, NIC throughput next to it).
- Uptime: `https://live.myspeedpuzzling.com/` answers 200 "OK" - add it to the existing HTTP probes.

## 3. Downtime

**None expected for any site.**

| Step | Effect |
|---|---|
| Compose + deploy.sh + env | takes effect on the next MSP deploy - the usual drain-first blue-green rollout, no gap (the `web` alias and new env ride on it) |
| Traefik HTTP + TCP routers | Docker labels → hot-reloaded, **no Traefik restart**. The TCP router matches ONLY `HostSNI(turn.myspeedpuzzling.com)` - never `HostSNI(*)`, which would take every TLS connection on 443 |
| Robot + ufw rules, sysctl | live, no restart |
| CrowdSec whitelist | the reconciler recreates the engine (~20 s, the bouncer keeps its cached decisions - stream mode) |

Rollback: `docker compose stop livekit` + empty `LIVEKIT_API_URL` on the next deploy (video off again - the app is built
for LiveKit disappearing mid-round: the round goes on, STATUS VP), then remove the rules.

## 4. Checks after each stage (the app session runs them)

1. `curl https://live.<host>/` → 200; `openssl s_client -connect turn.<host>:443 -servername turn.<host>` → a valid
   certificate; every other site on 443 unchanged (a few hosts with and without SNI).
2. The moderator console + a phone on mobile data (UDP) and a laptop on a network with UDP blocked (TURN/TLS) join the
   hall; webhook events arrive (`/-/livekit-webhook` 200 in the app log).
3. `device-test.md` steps 25-37 on real phones.
4. Before Test Cup #2: the production load run (`tools/online-load/README.md`) with the generators whitelisted in
   CrowdSec first.

## 5. Stage A names (sandbox)

`apps/msp-sandbox`: service `livekit` with alias `msp-sandbox-livekit`, routers `msp-sandbox-livekit` /
`msp-sandbox-livekit-turn`, hosts `sandbox-live.myspeedpuzzling.com` + `sandbox-turn.myspeedpuzzling.com` (**two new
DNS-only CNAMEs** - the wildcard is gone), UDP **7883** (`rtc.udp_port: 7883`, published 7883:7883), `node_ip` lily,
webhook `http://msp-sandbox-web:8080/-/livekit-webhook` (+ that alias on the sandbox's `web`), its own key pair in
Infisical `myspeedpuzzling-sandbox`, `cpus: 2` + `mem_limit: 1g` (D88: a busy sandbox never takes CPU from production),
and in the sandbox compose `LIVEKIT_API_URL=http://msp-sandbox-livekit:7880`,
`LIVEKIT_PUBLIC_URL=wss://sandbox-live.myspeedpuzzling.com` instead of the empty values.

## 6. What Jan decides

1. OK for stage A (sandbox) - two DNS records + UDP 7883 + the sandbox compose.
2. OK for stage B (production) - after A worked on real phones, or together with A.
3. Optional: TURN/UDP 3478 and ICE/TCP 7881 stay out (one Robot rule each) unless the real-device pass shows a gap.
