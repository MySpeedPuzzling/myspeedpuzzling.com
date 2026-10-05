---
name: puzzle-change-proposal
description: File a MySpeedPuzzling puzzle change proposal (the same review item a player's "Suggest a change" creates) through the internal API, or reject one. Use when catalogue data found wrong by an analysis (EAN, name, other names and their languages, pieces, brand, catalogue number) should be corrected through moderator review instead of a direct database write, or when the user asks to file/propose/reject puzzle change requests.
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

`POST /internal-api/puzzle-change-requests` → `201 {"changeRequestId": "…", "recordVersion": "…"}`

**Re-read the puzzle right before filing** (production `puzzle.*`, or API v1) - every field you send is compared with
the puzzle as it is, and the names list is applied as a diff against the names when filed: a stale read proposes
removing names you did not see. Keep the `recordVersion` of each `201` answer: it is the puzzle as the proposal was
filed against it, and approving with it refuses a puzzle that changed since.

| Field | Notes |
|---|---|
| `puzzleId` | required |
| `name`, `manufacturerId`, `piecesCount`, `ean`, `identificationNumber` | optional - **a field left out keeps the puzzle's current value**, so the review shows only your change |
| `alternativeNames` | optional - the **whole list** of the other names as it should end up: `[{"name": "Kruh barev: Mušle", "language": "cs"}, …]` |
| `nameLanguage` | optional - the main title's BCP 47 language when the box has no English title (`"cs"`, `"pt-BR"`), `null` = English or not known |

`ean` is the puzzle's **whole** comma-separated list as it should end up (several codes are valid - one per edition or
region; never reduce a list to one value). Every code not already on the puzzle must be a valid EAN/UPC (`400` otherwise).
No photo through the API.

### Names (docs/features/puzzle-names/README.md)

The main title (`name`) is the English title of the box when it has one, otherwise the title printed on the box (then
set `nameLanguage`). Every other title is an entry of `alternativeNames` with the language of its box (`null` only when
nobody knows it). **Re-read the current names first** - production `puzzle.alternative_names` / `puzzle.name_language`,
or API v1 `alternative_names` - and send the list you want to end up with:

- **add a name**: the current list plus the new entry
- **tag a language**: the same entry with `language` set (a name without a language is never shown as the second line)
- **edit / remove**: change or leave out that entry
- **swap the main title** ("make main title"): `name` = the English title, the old main title as an entry with its
  language, and `nameLanguage: null` - approving it needs `name`, `nameLanguage` **and** `alternativeNames` selected
  together - `name` without `alternativeNames` loses the old main title, without `nameLanguage` the English title
  keeps the old title's language

The list is applied as a diff against the names when the proposal was filed, so names somebody else changed meanwhile
stay. An order of its own is no change; at most 20 names when the list grows. The `201` answer echoes the names as filed
(cleaned like the puzzle stores them) - check them.

```sh
-d '{"puzzleId": "<uuid>", "alternativeNames": [{"name": "Kouzelná zahrada", "language": "cs"}, {"name": "Zauberhafter Garten", "language": "de"}]}'
```

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
| `409` | The puzzle already has a pending merge request or a pending proposal of more than its names - report it, do not retry. A names-only proposal (`name`, `nameLanguage`, `alternativeNames`) is never refused for this, and several may wait at once |

## Reject a proposal

`POST /internal-api/puzzle-change-requests/{id}/reject` with `{"rejectionReason": "..."}` → `204`. The reason is shown to the
player who proposed it, so write it for them. It does not check the status: confirm in the DB that the request is still
`pending` first.

## Approve a proposal

`POST /internal-api/puzzle-change-requests/{id}/approve` with `{"selectedFields": [...], "decisionNote": "...", "recordVersion": "..."}` → `204`.
**Re-read the puzzle and the request right before approving** and send `recordVersion` - the one from the filing's
`201` answer (the puzzle as the proposal was made against it): a puzzle changed since answers `409` with
`{"error": "…"}` and nothing is applied; read it again and decide again. Left out, nothing is checked.
`selectedFields` is required and lists what to apply to the puzzle (`name`, `nameLanguage`, `alternativeNames`,
`manufacturer`, `piecesCount`, `ean`, `identificationNumber`, `image`) - `[]` approves without changing the puzzle, for a
proposal something else already satisfied. To correct the proposed names before they go in, add `alternativeNames` (the
list as it should end up, applied as a diff against the names when the request was filed) and/or `nameLanguage` - each
only together with that field in `selectedFields`. A proposal of another main title needs `name`, `nameLanguage` and
`alternativeNames` selected together (see "swap the main title"). The proposer is notified. `409` = already approved
or rejected (approve checks the status, reject does not) or the puzzle changed since `recordVersion`, `422` = the result
breaks a record rule (e.g. more than 20 names).

## Rules

- Before filing in bulk, read the current values from production (`puzzle.ean`, `puzzle.alternative_names`,
  `puzzle.name_language` etc.) - the proposal replaces the whole field (the names list is applied as a diff, but against
  the list when filed: a stale read proposes removing names you did not see).
- Pace bulk calls ≥ 2.5 s apart; prod rate-limits bursts on `/internal-api` (HTTP 429 - wait 60 s and retry).
- List the review URLs of what you filed back to the user.
