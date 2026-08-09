# WJPF pairing — anomalies found during the backfill

Running record of what the first full backfill (started 2026-08-09) surfaced, kept so it can
be turned into a report for Alfonso. Every class below has a query that regenerates the
current list, so this file does not need updating by hand as the run progresses.

Everything here is data on **their** side. Our rows are correct in each case — the point is
what their `Jugadores.MySpeedPuzzlingId` column contains and what we cannot fix.

## The constraint that makes all of this matter

Their write is guarded by `if (!$fila['MySpeedPuzzlingId'])`. Once their column holds *any*
value — correct, malformed, or belonging to somebody else — no call of ours can change it.
So every anomaly below is permanent until they add an overwrite or clear path.

---

## Class A — their column holds something that is not a MySpeedPuzzling id

**Found: 1 (as of 1,259 players checked)**

| Their IdJugador | NombreURL | Matched e-mail | Their stored value | What it actually is |
|---|---|---|---|---|
| 2753 | `janette-perme` | `janetteandherpuzzles@gmail.com` | `#LZJY0O` | that same player's MSP **player code** (`lzjy0o`), not their UUID |

The pairing is *semantically* right — same human — but the column holds a display code
instead of the id. Their guard means we cannot replace it with the real UUID
(`018deff9-616b-7022-a6eb-92505c37e523`).

Worth showing Alfonso as the concrete argument for an overwrite path: this is not a
hypothetical, it is a row in his database today that only he can fix.

```sql
-- Class A: conflicting value is this player's own code, not a foreign id
SELECT w.wjpf_id, w.wjpf_name_url, w.checked_email,
       w.conflicting_my_speed_puzzling_id AS their_value,
       p.id AS correct_uuid, p.code AS our_code
FROM wjpf_identity w
JOIN player p ON p.id = w.player_id
WHERE w.status = 'conflict'
  AND lower(w.conflicting_my_speed_puzzling_id) = lower('#' || p.code);
```

---

## Class B — their column holds a different player's real id

**Found: 0 so far**

A genuine cross-wiring: their record for one person points at another MySpeedPuzzling
account. Distinguished from class A by the value being a well-formed id that belongs to a
different player row.

```sql
-- Class B: conflicts that are NOT explained by the player-code mix-up
SELECT w.wjpf_id, w.wjpf_name_url, w.checked_email,
       w.conflicting_my_speed_puzzling_id AS their_value,
       w.player_id AS our_player_id,
       (SELECT count(*) FROM player x WHERE x.id::text = w.conflicting_my_speed_puzzling_id)
           AS their_value_is_a_real_player
FROM wjpf_identity w
JOIN player p ON p.id = w.player_id
WHERE w.status = 'conflict'
  AND lower(coalesce(w.conflicting_my_speed_puzzling_id, '')) <> lower('#' || p.code);
```

---

## Class C — e-mail mismatch (invisible misses)

**Found: at least 1, by hand — not detectable in our data**

The pairing key is the address. A member registered with us under one address and with WJPF
under another can never be matched, and shows up indistinguishably from a genuine non-member.

| Player | MSP e-mail | WJPF e-mail | Their IdJugador |
|---|---|---|---|
| Cris Roura (`018dc357-dcfd-70a4-97bf-b6a2c8f0a48e`) | `llunetapuzzles@gmail.com` | `cristinarourasuarez@gmail.com` | 189 |

This is the main reason the `not_found` bucket overstates "not a WJPF member", and the
strongest argument for the connect UI carrying an **editable** e-mail field rather than
silently using the MySpeedPuzzling address.

Note their record for IdJugador 189 already holds the correct MSP UUID — so someone paired
it previously by another route, which our e-mail-only lookup will never reproduce.

There is no query for this class: by construction these look like ordinary `not_found` rows.
Quantifying it needs a list of WJPF member e-mails from Alfonso, or per-player confirmation.

---

## Class D — one IdJugador claimed by several of our players

**Found: 0 so far**

`wjpf_id` is deliberately non-unique so this cannot abort a run; duplicates are logged at
warning level and are visible here.

```sql
SELECT wjpf_id, count(*) AS claimed_by, array_agg(player_id) AS players, array_agg(checked_email) AS emails
FROM wjpf_identity
WHERE wjpf_id IS NOT NULL
GROUP BY wjpf_id
HAVING count(*) > 1;
```

---

## Run summary

```sql
SELECT status, count(*) FROM wjpf_identity GROUP BY status;

SELECT count(*) FILTER (WHERE wjpf_id IS NOT NULL)  AS mapped,
       count(*) FILTER (WHERE claimed_at IS NOT NULL) AS claims_landed_on_their_side,
       count(*) FILTER (WHERE wjpf_id IS NOT NULL
                          AND coalesce(last_response->>'MySpeedPuzzlingId','') <> ''
                          AND lower(last_response->>'MySpeedPuzzlingId') = lower(player_id::text))
           AS were_already_paired_before_us,
       count(*) AS checked
FROM wjpf_identity;
```

`were_already_paired_before_us` counts records their side had already linked — mostly from
the older WJPC `update_player_id` integration — which is a useful cross-check that our
e-mail matching agrees with a pairing done by a different mechanism. At the 121-player
sample it agreed on all 27, with zero mismatches.

Progress snapshot at 1,259 checked: 542 paired, 716 not found, 1 conflict, 0 failures.
