# WJPF player pairing

Maps MySpeedPuzzling players to their accounts in the World Jigsaw Puzzle Federation player
database (`worldjigsawpuzzle.org`), matching on e-mail address. Both sides then hold each
other's id: we store their `IdJugador` + `NombreURL`, they store our player UUID.

Not to be confused with `WjpcParticipant.remoteId`, which is a competition-scoped
`IdJugador` for WJPC entrants. This mapping is account-level.

## The two directions

### A. We call them (primary, working today)

`POST https://worldjigsawpuzzle.org/users/users_pr.php?accion=wjpf_user`

| Field | |
|---|---|
| `token` | shared static token |
| `email` | the address to match |
| `myspeedpuzzlingid` | our player UUID — **only sent when claiming** |

One call does both halves: they answer with their record, and if their `MySpeedPuzzlingId`
column is empty they store the id we sent.

```json
{"IdJugador":"189","NombreURL":"cristina-roura-suarez","MySpeedPuzzlingId":"018dc357-…","status":"ok"}
{"status":"error","mensaje":"player not found"}
{"status":"error","coderror":151,"mensaje":"token invalid"}
```

Implemented by `Services\Wjpf\WjpfClient`.

### B. They call us

`POST https://myspeedpuzzling.com/api/v0/wjpf-pairing`, `application/x-www-form-urlencoded`,
fields `token`, `idusuario`, `email`, `nombreurl`.

```json
{"status":"ok","MySpeedPuzzlingId":"018dc357-dcfd-70a4-97bf-b6a2c8f0a48e"}
{"status":"error","mensaje":"player not found"}
{"status":"error","coderror":151,"mensaje":"token invalid"}
```

Implemented by `Controller\Api\V0\WjpfPairingController`.

### C. Manual pairing (browser flow)

For members whose two accounts hold different e-mail addresses, matching can never work. This
flow takes the identity from the player's own MySpeedPuzzling session instead, so no address
has to agree.

```
WJPF (member signed in there)
  └─> GET https://myspeedpuzzling.com/connect/wjpf?state=<opaque>
        ├─ not signed in with us -> standard ?return= login, comes back here
        └─ consent page -> POST /connect/wjpf
              └─> 302 https://worldjigsawpuzzle.org/users/users_pr.php?accion=msp_pair_redirect&code=<code>&state=<opaque>
                    └─ WJPF back-channel: POST /api/v0/wjpf-pairing {token, code, idusuario, nombreurl}
                          └─> {"status":"ok","MySpeedPuzzlingId":"…"}
```

**The redirect carries a code, never the player id.** A player id would be forgeable — ours
appear in public API paths, so anyone could hand WJPF somebody else's id and link their own
WJPF account to that person's profile. A code is only ever issued to a browser that has just
authenticated, so the worst it can do is link the account that asked for it. Codes are
single-use, expire in 10 minutes, and are stored hashed
(`Services\Wjpf\WjpfPairingCodeStore`, `wjpf_pairing_code_cache` pool).

**`state` is theirs and must be checked by them.** We echo it back untouched, but only after
it passes a strict unreserved-character allowlist (`Value\WjpfPairingState`) — it goes back
into a URL we build, so anything that could add a parameter or break a header is dropped
rather than escaped. Without WJPF verifying `state` against their own session, an attacker can
feed a victim a code of their own and link the victim's WJPF account to the attacker's profile.

The return URL is configuration (`WJPF_PAIR_REDIRECT_URL`), never taken from the request:
accepting a redirect target from the caller, on a page that sits right after a login prompt,
is the textbook phishing primitive. Empty disables the flow (404).

Cancelling returns `?error=access_denied&state=…`, mirroring the OAuth convention so their
side can tell a refusal from a failure.

## Their quirks, and what each one forces on us

These are load-bearing — the design is shaped by them.

1. **Their response is echoed *before* their UPDATE runs.** `MySpeedPuzzlingId` in any
   response is the pre-call value, so a call can never confirm its own write. The only
   evidence a claim landed is that the pre-call value was empty — that is what
   `claimedAt` records.
2. **Their write is conditional (`if (!$fila['MySpeedPuzzlingId'])`) and therefore
   permanent.** Once their column holds any value, nothing we send can change it. There is
   no remedy from our side for a stale or wrong mapping — account deletion + re-registration,
   an e-mail change, or a mis-pair all become unfixable. **Open request to Alfonso: an
   overwrite or clear-the-field path.**
3. **A response holding a *different* UUID means our write was silently dropped.** That is
   the conflict signal, and the only one we get.
4. **`echo $BDwjpf->error;` runs outside the JSON**, so a database hiccup on their side
   arrives as JSON with text glued to it. `WjpfClient::decode()` retries at the outermost
   braces and logs a warning before giving up.
5. **Everything is HTTP 200, including `token invalid`.** Branch on the `status` field.
   Their failures carry a `coderror`; a missing player does not — that distinction is how
   the client separates "we are misconfigured" (throw) from "not a member" (null).
6. **`$email` is interpolated raw into their SQL.** Only server-derived addresses are ever
   sent. If a player-supplied address is ever accepted (a connect UI), it must be validated
   before it leaves the process.
7. **A read-only survey is genuinely side-effect-free.** Omitting `myspeedpuzzlingid` makes
   their `request_var` default it to `''`, and their conditional write then puts `''` over an
   already-empty column.

## Storage

`wjpf_identity`, one row per player (`WjpfIdentity`).

| Column | |
|---|---|
| `wjpf_id` | their `IdJugador` |
| `wjpf_name_url` | their `NombreURL` profile slug |
| `status` | `paired` / `not_found` / `conflict` |
| `conflicting_my_speed_puzzling_id` | the foreign UUID, on conflict only |
| `checked_email` | the address we matched on |
| `checked_at` / `paired_at` / `claimed_at` | see quirk 1 for `claimed_at` |
| `last_response` | their decoded payload, verbatim |

Two deliberate choices:

- **`wjpf_id` is not unique.** Two players claiming one `IdJugador` is real data about a real
  problem; a unique constraint would abort a multi-hour backfill over it. Duplicates are
  logged at warning level instead.
- **`not_found` is a stored row, not an absence.** It is what lets a re-run skip everyone
  already checked. A later `not_found` never erases an earlier `wjpf_id`.

**Conflicts still pair on our side** (decision, 2026-08-09) — we keep our half of the mapping
even when theirs disagrees — and every conflict emits a `warning` log with the player, both
ids, and the response.

## The sync command

```bash
docker compose exec web php bin/console myspeedpuzzling:sync-wjpf-identities --limit=50
docker compose exec web php bin/console myspeedpuzzling:sync-wjpf-identities --limit=50 --claim
```

| Option | |
|---|---|
| `--limit=N` | cap the batch |
| `--claim` | send our id so their side stores it — **permanent, survey first** |
| `--force` | re-check players already checked **but not yet paired** |
| `--include-paired` | with `--force`, also re-check players we already hold an id for |
| `--player=UUID` | single player, for debugging |
| `--delay=MS` | between requests, default 1000 |

### Selection on a repeat run

Default skips every player who already has a row. `--force` is the repeat-run mode — it
re-checks previous misses (people who have joined WJPF since) while leaving settled mappings
alone, because re-asking about them cannot improve on what we hold and is pure load on their
host. `--include-paired` overrides that. "Already paired" means we hold a `wjpf_id`, whatever
the latest status says: a match that later stopped resolving still counts.

### Running it without a deploy killing it

**`docker compose exec … web` is the wrong way to start a long backfill.** A deploy blue-greens
the `web` container and the run dies with it. Use a one-off container instead, which no deploy
touches:

```bash
docker compose run --rm --no-deps --label traefik.enable=false \
  -e WJPF_API_TOKEN=… -e WJPF_API_URL=… \
  web php bin/console myspeedpuzzling:sync-wjpf-identities --claim
```

`--label traefik.enable=false` matters: a one-off container otherwise inherits the service's
Traefik labels and can start attracting live traffic.

Interrupting a run is cheap either way — progress is one row per player, so a plain re-run
(no `--force`) resumes exactly where it stopped.

Read-only by default. Sequential at ~1 req/s with a 5s timeout, aborting after 10 consecutive
failures — their host is a small shared PHP box and a burst reads as an attack. Roughly 3
hours for ~10k players.

Scope: every player with an e-mail, **excluding private profiles** (pairing discloses the
address to a third party and a hidden profile has not asked for that), oldest registration
first, skipping anyone already checked unless `--force`.

The command dispatches one `SyncWjpfIdentity` message **per player** on purpose: the
`doctrine_transaction` middleware wraps every handler in a transaction, and a batch-shaped
message would hold one open for the entire backfill.

Manual only — no cron. Revisit once the backfill is done and the conflict rate is known.

## Configuration

```
WJPF_API_URL=https://worldjigsawpuzzle.org/users/users_pr.php
WJPF_API_TOKEN=
WJPF_PAIR_REDIRECT_URL=https://worldjigsawpuzzle.org/users/users_pr.php?accion=msp_pair_redirect
```

One shared token for both directions. Empty = closed-by-default: the client reports
`isEnabled() === false`, the inbound endpoint 401s everything, and `/connect/wjpf` 404s
without a redirect URL.

**Production values live in Infisical**, which the box re-renders into `.env` on every
deploy — anything written to that file by hand is wiped at the next release. That is exactly
how the endpoint shipped rejecting every request on 2026-08-09: the token was deployed in code
but never added to Infisical, so the containers ran with an empty one and the closed-by-default
guard did its job. Adding a value means `infisical secrets set` (the box's machine identity
has write access) followed by `dump_secrets myspeedpuzzling` and a `rollout`.

Note `/api/v` is a Traefik `PathPrefix` for the **api** service, so the pairing endpoint is
served by `api`, not `web` — both need the env, and both need rolling out after a change.

The endpoint sits on the `stateless` firewall (`security.php`) so a server-to-server call
never mints a session.

## Still open with Alfonso

- **Overwrite/clear path for `MySpeedPuzzlingId`** — see quirk 2. Highest priority; without
  it every pairing is write-once-forever.
- A dedicated pairing token, separate from the legacy `/api/v0/players/{id}/results` one.
- Escaping `$email` in their `wjpf_user` SQL.
- The profile URL pattern for `NombreURL`, needed before any UI can link to their site.
- Their `BUSCAR_MYSPEEDPUZZLING_ID` case needs `json_decode` on our response — it reads keys
  off the raw string today, which never yields the value.

## Not built yet

An MSP-initiated entry point. Today the manual flow only starts from WJPF; a "Connect WJPF
account" button on edit-profile would need their equivalent of `/connect/wjpf` — a URL that
authenticates the member on their side and hands us back a code we redeem with the same token.
Worth asking Alfonso for once the WJPF-initiated direction is live.

Showing the current link on the consent page (and on profiles) is also open — it needs the
`NombreURL` profile URL pattern from Alfonso.
