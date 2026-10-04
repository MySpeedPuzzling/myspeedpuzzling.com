---
name: puzzle-change-proposal
description: File a MySpeedPuzzling puzzle change proposal (the same review item a player's "Suggest a change" creates) through the internal API, or reject one. Use when catalogue data found wrong by an analysis (EAN, name, pieces, brand, catalogue number) should be corrected through moderator review instead of a direct database write, or when the user asks to file/propose/reject puzzle change requests.
argument-hint: "<what to change, e.g. 'EAN of puzzle <uuid> to 4005555011897'>"
allowed-tools: Bash, Read
---

# Puzzle change proposals

Catalogue corrections land as **change proposals**, never as SQL: a moderator approves or rejects them in
`/admin/puzzle-change-requests`, and the decision is logged in `puzzle_moderation_decision` like any other.
The proposal is filed as the player in `INTERNAL_API_REVIEWER_PLAYER_ID` (Jan's player on production).

Spec: `docs/features/internal-api.md` § Puzzle change requests. Token handling as in the `internal-api` skill
(read `INTERNAL_API_TOKEN` from `.env.local` at call time, never print it, default to production).

## File a proposal

`POST /internal-api/puzzle-change-requests` → `201 {"changeRequestId": "…"}`

| Field | Notes |
|---|---|
| `puzzleId` | required |
| `name`, `manufacturerId`, `piecesCount`, `ean`, `identificationNumber` | optional - **a field left out keeps the puzzle's current value**, so the review shows only your change |

`ean` is the puzzle's **whole** comma-separated list as it should end up (several codes are valid - one per edition or
region; never reduce a list to one value). Every code not already on the puzzle must be a valid EAN/UPC (`400` otherwise).
No photo through the API.

```sh
TOKEN=$(grep '^INTERNAL_API_TOKEN=' .env.local | cut -d= -f2)
STATUS=$(curl -sS -o /tmp/change-proposal.json -w '%{http_code}' \
  -X POST "https://myspeedpuzzling.com/internal-api/puzzle-change-requests" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"puzzleId": "<uuid>", "ean": "4005555011903"}')
echo "HTTP $STATUS"; cat /tmp/change-proposal.json
```

| Status | Meaning |
|---|---|
| `201` | Filed - review URL: `https://myspeedpuzzling.com/admin/puzzle-change-requests/<changeRequestId>` |
| `400` | Invalid field, an invalid new EAN code, or nothing differs from the puzzle as it is |
| `404` | Unknown puzzle |
| `409` | The puzzle already has a pending change or merge request - report it, do not retry |

## Reject a proposal

`POST /internal-api/puzzle-change-requests/{id}/reject` with `{"rejectionReason": "..."}` → `204`. The reason is shown to the
player who proposed it, so write it for them. It does not check the status: confirm in the DB that the request is still
`pending` first.

## Rules

- Before filing in bulk, read the current values from production (`puzzle.ean` etc.) - the proposal replaces the whole field.
- Pace bulk calls ≥ 2.5 s apart; prod rate-limits bursts on `/internal-api` (HTTP 429 - wait 60 s and retry).
- List the review URLs of what you filed back to the user.
