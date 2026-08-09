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
| `--force` | re-check players that already have a row |
| `--player=UUID` | single player, for debugging |
| `--delay=MS` | between requests, default 1000 |

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
```

One shared token for both directions. Empty = closed-by-default: the client reports
`isEnabled() === false` and the inbound endpoint 401s everything. Production value lives in
Infisical.

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

Player-facing UI. The backfill numbers come first: the real match and conflict rates should
inform what the connect screen has to say. A connect flow also needs an editable e-mail field
(their address may differ from the MySpeedPuzzling one), which brings rate limiting and
input validation with it — see quirk 6.
