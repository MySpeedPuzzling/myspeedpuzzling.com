# Internal Admin API

Admin-only HTTP API for triggering privileged ops from anywhere — primarily Claude Code (automating ops in agent sessions), with curl as a fallback. Authenticated with a single static bearer token, completely separate from the public `/api/v1/*` OAuth2 API.

## Auth

Every request must carry:

```
Authorization: Bearer $INTERNAL_API_TOKEN
```

- The token comes from the `INTERNAL_API_TOKEN` environment variable.
- Comparison uses `hash_equals()` (constant-time, no timing leak).
- **Closed-by-default**: if `INTERNAL_API_TOKEN` is empty/unset, the authenticator never matches and all `/internal-api/*` requests return 401. A forgotten env var cannot accidentally open the API.

Generate a token:

```sh
openssl rand -hex 32
```

Set in production env. For local dev, drop it in `.env.local`:

```
INTERNAL_API_TOKEN=dev-secret-just-for-local
```

### Reviewer identity

Endpoints that perform *moderation* (the puzzle merge queue and the brand endpoints) record a reviewer on the domain object and notify the affected player. The internal API has no logged-in user, so the player to credit comes from:

```
INTERNAL_API_REVIEWER_PLAYER_ID=<player uuid>
```

Also closed-by-default: while it is empty, the moderation endpoints return `400` and dispatch nothing. The feature-request endpoints do not need it.

## Endpoints

Base path: `/internal-api/`. Write endpoints take `POST` with an optional JSON body and return `204 No Content`; read endpoints take `GET` and return JSON.

| Method | Path | Purpose | Body fields (all optional) |
|---|---|---|---|
| `POST` | `/internal-api/feature-requests/{id}/mark-in-progress` | Transition feature request to `in_progress` | `githubUrl`, `adminComment` |
| `POST` | `/internal-api/feature-requests/{id}/mark-completed` | Transition feature request to `completed` | `githubUrl`, `adminComment` |
| `POST` | `/internal-api/feature-requests/{id}/mark-declined` | Transition feature request to `declined` | `githubUrl`, `adminComment` |

### Puzzle merge requests

Players report duplicate puzzles; approving a report merges them. **A merge is destructive** — the merged puzzles are deleted, and their solving times, collection items, wish-list/sell-swap entries and lendings move onto the survivor. Every approval therefore writes a `puzzle_merge_audit` row holding the full before/after state (see [Audit trail](#audit-trail)).

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/internal-api/puzzle-merge-requests` | Review queue: pending requests with every reported puzzle |
| `POST` | `/internal-api/puzzle-merge-requests` | File a duplicate report yourself (`201` + `{"mergeRequestId"}`) |
| `POST` | `/internal-api/puzzle-merge-requests/{id}/approve` | Merge the puzzles |
| `POST` | `/internal-api/puzzle-merge-requests/{id}/reject` | Decline the report |

`GET` takes `limit` (1-100, default 25) and `offset`. It returns `totalPending` plus, per request, `reportedNameLanguages` (what the reporter said each puzzle's name is in: an object puzzle id → base language, `{}` when nothing was said) and every candidate puzzle with its name, `nameLanguage` (the main title's language, null = English or not known), its other names (`alternativeNames`: `[{"name", "language"}]` in order, language a BCP 47 tag or null; `alternativeName` keeps the one other name of old - the first Czech one, else the first), piece count, EAN, catalogue number, manufacturer, **a ready-to-fetch `imageUrl`**, the weight of its history (`solvedTimesCount`, `collectionItemsCount`, …) and its `recordVersion` (a fingerprint of the record - names, brand, pieces, codes, image - to send back on approve). Whether two puzzles are the same product is usually settled by comparing the artwork, so the image URL is the point of the endpoint. `actionable` is false when fewer than two of the reported puzzles still exist (an earlier merge already deleted one) — such a request cannot be merged, only rejected.

Approve body:

| Field | Required | Notes |
|---|---|---|
| `survivorPuzzleId` | yes | The puzzle that stays. Normally the one carrying the most history. Must be one of the request's reported puzzles (any letter case) - any other id is a `400` |
| `mergedName` | yes | Name the survivor ends up with - its main title, the English one when the box has one |
| `mergedNameLanguage` | no | BCP 47 tag of the main title when it is not English (`"cs"`); an explicit `null` = English or not known. **Left out**, the main title keeps the language the puzzles know for `mergedName` (the reporter's language of a main title, else the puzzle's own, else the language of the other name it is) - with or without `mergedAlternativeNames` |
| `mergedAlternativeNames` | no | Every other name of the survivor, `[{"name", "language"}]` in order - **replaces** the union below, so list every name to keep. More than 20 only when the union already holds more |
| `mergedPiecesCount` | yes | Positive integer |
| `mergedEan` | no | Leave out to keep the survivor's own. A list of codes (`["4005556147090", "4005555001997"]`) or one comma-separated string; the merged puzzle's codes are unioned in either way |
| `mergedIdentificationNumber` | no | As above (brand codes) |
| `mergedManufacturerId` | no | Leave out to keep the survivor's own |
| `selectedImagePuzzleId` | no | Take the cover image from this puzzle |
| `decisionConfidence` | no | `high`, `medium` or `low` |
| `decisionNote` | no | Why — stored on the audit row, not shown to players |
| `recordVersions` | no | `{"<puzzle id>": "<recordVersion>"}` - every candidate's `recordVersion` as the queue showed it. A puzzle changed since (a moderator's edit, an EAN link) answers `409` and nothing is merged; a puzzle left out is not checked. Always send them |

Blank strings count as absent, so a blank `mergedEan` never blanks a real one (a list of blank entries only is a `400`,
so is a list longer than 255 characters). Codes are stored in their canonical form (barcodes as digits without leading
zeros, any other value as typed, brand codes in upper case, `", "`-separated, each once - docs/features/puzzle-names/
README.md, decision 6).

**A puzzle may legitimately carry several EANs or catalogue numbers**, held as a comma-separated list, because the same puzzle gets its own code per edition or region. A merge therefore takes the *union* of both records' codes rather than choosing between them, and `mergedEan` may itself be such a list. Never reduce an existing list to a single value — the codes you drop identify real editions, and the record holding them is deleted moments later. The merge likewise carries over any cover image or manufacturer that **only** a deleted puzzle had, and - unless `mergedAlternativeNames` is given - keeps every name of every merged puzzle (main title and other names) as an other name of the survivor, the survivor's previous main title too when `mergedName` is another one; each main title in the language the reporter gave it (`reportedNameLanguages`), else in its own `nameLanguage` (docs/features/puzzle-names/). Re-read the queue right before approving and send every candidate's `recordVersion` as `recordVersions`: a puzzle saved in between answers `409` with `{"error": "…"}` - read the queue again and decide again.

Reject body: `rejectionReason` (required). **It is shown to the player who reported the duplicate**, as a notification, so write it for them.

File body: `puzzleIds` (required, at least two distinct puzzle ids; the first is the request's source puzzle) and
`reportedNameLanguages` (optional: `{"<puzzle id>": "cs"}` - the language a reported puzzle's name is in, null = not
known; stored as the base language; a puzzle outside `puzzleIds` or no language tag is a `400`). The reviewer
player is the reporter - like a moderator merging from the approval queue - so nobody is notified about the request or
its approval. Answers `201` with `{"mergeRequestId": "…"}`; settle it with the approve/reject endpoints above. Use it for
duplicates no player reported, e.g. the same puzzle left twice under one brand after a brand merge. An unknown puzzle id
answers `404` and files nothing.

### Puzzle change requests

Players propose corrections to a puzzle ("Suggest a change": every name with its language, brand, pieces, EAN, catalogue
number, photo; "Suggest another name": one more name).

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/internal-api/puzzle-change-requests` | File a proposal yourself (`201` + `{"changeRequestId"}`, plus the names as filed) |
| `POST` | `/internal-api/puzzle-change-requests/{id}/reject` | Decline the proposal |
| `POST` | `/internal-api/puzzle-change-requests/{id}/approve` | Approve the proposal, applying only the fields you list |

File body: `puzzleId` (required) and any of `name`, `nameLanguage`, `alternativeNames`, `manufacturerId`, `piecesCount`,
`ean`, `identificationNumber`. **A field left out keeps the puzzle's current value**, so the review shows only what the
proposal changes. `ean` and `identificationNumber` are each the whole list of codes as it should end up: a JSON list,
one code per entry (`"ean": ["4005556147090", "4005555001997"]`, `[]` removes every code), or - as before the lists -
one string with the codes comma-separated (a blank string counts as left out; a list of blank entries only is a
`400` - only `[]` removes every code; so is a value or a list longer than 255 characters). Every EAN not already on the
puzzle must be a valid EAN/UPC. The proposal is stored in the canonical form (barcodes as digits without leading zeros,
any other value as typed, brand codes in upper case, each once); a list equal to the puzzle's in that form proposes
nothing for the field. The reviewer player is the reporter. Answers `400` for an invalid field or when nothing differs,
`404` for an unknown puzzle and `409` when the puzzle already has a pending merge request or a pending change request of
more than its names (the web form allows one at a time too). A proposal of the names only (`name`, `nameLanguage`,
`alternativeNames` - nothing else differs) is filed regardless and holds up nothing: names apply as a diff, so several
may wait at once. No photo. Use it for catalogue corrections found by an analysis, so they go through moderator review
instead of a database write - the `puzzle-change-proposal` skill wraps it.

The `201` answer also carries `recordVersion`: the puzzle's record the proposal was filed against (a fingerprint of
names, brand, pieces, codes and image) - send it back on approve to approve only while the puzzle is still so.

Names ([`puzzle-names/README.md`](./puzzle-names/README.md)): `name` is the main title (the English title of the box when
it has one). `nameLanguage` is the main title's BCP 47 language when the box has no English title (`"cs"`, `"pt-BR"`;
`null` = English or not known). `alternativeNames` is the **whole list of the other names as it should end up**, in
order: `[{"name": "Kruh barev: Mušle", "language": "cs"}, {"name": "Seashells", "language": null}]` - re-read the
puzzle's names first (API v1 `alternative_names`, or `puzzle.alternative_names`), then add, re-tag, edit or remove
entries. An entry holds `name` and `language` only (any other key - a typo like `lang` - is a `400`, in every names list
of this API). Names are cleaned like the puzzle stores them (spaces, a name equal to the main title or to another name
dropped); at most 20 when the list grows; an order of its own is no change. The two are proposed together: the `201`
answer then also holds `nameLanguage` and `alternativeNames` as filed. On approval the list is applied **as a diff**
against the names when it was filed (added, re-tagged/edited and removed names), never as a replacement - names
somebody else changed in between stay.

Reject body: `rejectionReason` (required). **It is shown to the player who proposed the change**, as a notification, so
write it for them. Only send pending requests - like the merge-request reject, it does not check the status.

Approve body: `selectedFields` (required) - the fields to apply, any of `name`, `nameLanguage`, `alternativeNames`,
`manufacturer`, `piecesCount`, `ean`, `identificationNumber`, `image`, like ticking them in the admin review; `[]`
approves without touching the puzzle (a proposal something else already satisfied, e.g. a brand fix a brand merge made).
Optional `decisionNote`. Optional corrections of the proposal, each for a selected field only (`400` otherwise):
`alternativeNames` (the list as it should end up, same shape as when filing - applied as a diff against the names when
the request was filed, like the proposal) and `nameLanguage` (a tag or `null`); they are kept in the decision's
`details.overrides`. Optional `recordVersion`: the puzzle's record as you read it (the `201` answer of the filing) - a
puzzle changed since answers `409` with `{"error": "…"}` and nothing is applied; left out, nothing is checked. A
proposal of another main title ("make main title": the English title from the other names, the old main title into
them) needs `name`, `nameLanguage` and `alternativeNames` selected **together** - `name` alone drops the old main title. The player who proposed it is notified, the decision is logged with `source = internal_api`.
Answers `409` when the request was already approved or rejected - unlike reject, approve checks the status - or when
the puzzle changed since `recordVersion`, and `422` when the result breaks a rule of the record (e.g. more than 20 other
names).

### Brands

Duplicate brands (the same brand created by several players, see [`brand-duplicates.md`](./brand-duplicates.md)) are
merged here. Every endpoint writes a `puzzle_moderation_decision` row (`source = internal_api`) and needs
`INTERNAL_API_REVIEWER_PLAYER_ID`.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/internal-api/manufacturers/{id}/merge` | Fold duplicate brands into the brand `{id}` |
| `POST` | `/internal-api/manufacturers/{id}/approve` | Approve a genuinely new brand |
| `POST` | `/internal-api/manufacturers/{id}/delete` | Delete a brand nothing uses any more |

Merge body:

| Field | Required | Notes |
|---|---|---|
| `mergedManufacturerIds` | yes | List of brand ids to fold in (approved or not); de-duplicated |
| `name` | no | The survivor's name as the brand itself writes it. Leave out to keep it. Its slug never changes |
| `decisionConfidence` | no | `high`, `medium` or `low` (stored in the decision's `details`) |
| `decisionNote` | no | Why - stored as the decision's `note` |

**A brand merge is destructive**: every merged brand is deleted after its puzzles and the change requests proposing it
move to the survivor. The survivor keeps the logo and EAN prefixes only a merged brand had, becomes approved if a merged
brand was, and every merged slug answers `301` to the survivor's brand pages from then on (`manufacturer_slug_redirect`).
One `brand_merged` decision per merged brand holds its id, name, slug, approval and what moved - the only trace of it
once it is deleted. Pick as survivor the brand whose slug has no `-2` suffix where you can: the survivor's slug is the
address that stays.

Refusals change nothing: `404` for an unknown brand id, `422` when the survivor is in its own merge list, `409` when an
approved brand outside the merge has the survivor's final name (merge that one in the same request too).

Approve body (both optional): `name` (fix the spelling on the way), `decisionNote`. Answers `409` when the brand is
already approved, or when an approved brand of that name (case-insensitive) exists - it is then a duplicate to merge, not
a new brand. Use it for new brands whose puzzles were approved without them: the approval queue only shows brands with a
pending puzzle, so those stay unreviewed until approved here.

Delete body (optional): `decisionNote`. For an empty brand, e.g. a copy whose only puzzle was moved or merged away.
Answers `409` while anything still points at it - a puzzle, a change request proposing it, or a merged brand's slug
redirecting to it (the database would quietly null the proposal and cascade the redirect away); merge it into the right
brand instead, which moves all of those. The deleted brand's slug gets no redirect (there is nothing to send it to) and
is free for a new brand again. One `brand_deleted` decision holds its name, slug, approval and `added_at` - the only
trace of it.

### Examples

```sh
# Mark in progress, attach the GitHub PR link
curl -X POST "$APP_URL/internal-api/feature-requests/01950000-0000-7000-8000-000000000000/mark-in-progress" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"githubUrl": "https://github.com/janmikes/speedpuzzling.cz/pull/123"}'

# Mark completed with admin comment
curl -X POST "$APP_URL/internal-api/feature-requests/01950000-0000-7000-8000-000000000000/mark-completed" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"githubUrl": "https://github.com/janmikes/speedpuzzling.cz/pull/123", "adminComment": "Shipped in v2.4."}'

# Mark declined, no body needed
curl -X POST "$APP_URL/internal-api/feature-requests/01950000-0000-7000-8000-000000000000/mark-declined" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN"

# Review queue - the imageUrl values are what you actually compare
curl -s "$APP_URL/internal-api/puzzle-merge-requests?limit=25" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN"

# Approve: keep the puzzle with the most history, take the better cover image
curl -X POST "$APP_URL/internal-api/puzzle-merge-requests/019e281a-6b16-7324-8265-0f06673a49cb/approve" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "survivorPuzzleId": "018eb4c9-d751-70db-ab5d-0b8a4e55d2a7",
    "mergedName": "East of the Sun and West of the Moon",
    "mergedPiecesCount": 500,
    "selectedImagePuzzleId": "018eb4c9-d751-70db-ab5d-0b8a4e55d2a7",
    "decisionConfidence": "high",
    "decisionNote": "Same artwork; manufacturer recorded under two spellings."
  }'

# Brands: fold both copies of "Lluneta" into the oldest, under the name the brand uses
curl -X POST "$APP_URL/internal-api/manufacturers/019a0000-0000-7000-8000-000000000001/merge" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mergedManufacturerIds": ["019a0000-0000-7000-8000-000000000002", "019a0000-0000-7000-8000-000000000003"],
    "name": "Lluneta Puzzles",
    "decisionConfidence": "high",
    "decisionNote": "Same name typed by three players; same EAN company prefix."
  }'

# Brands: approve a new brand whose puzzles are already approved
curl -X POST "$APP_URL/internal-api/manufacturers/019a0000-0000-7000-8000-000000000004/approve" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Pusselbolaget", "decisionNote": "Real publisher, no approved brand of that name."}'

# Brands: delete an empty copy (409 if anything still uses it)
curl -X POST "$APP_URL/internal-api/manufacturers/019a0000-0000-7000-8000-000000000005/delete" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"decisionNote": "Typo copy of Trefl, its only puzzle was merged away."}'

# File a duplicate report, then approve it like any other
curl -X POST "$APP_URL/internal-api/puzzle-merge-requests" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"puzzleIds": ["01977f76-0d5a-73a0-953d-7d655a5c6dc3", "0197acfd-1132-7007-9610-c6ce24443e69"]}'

# Reject: not duplicates at all
curl -X POST "$APP_URL/internal-api/puzzle-merge-requests/019e32be-0000-7000-8000-000000000001/reject" \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"rejectionReason": "These are different products - one is 500 pieces, the other 900."}'
```

## Audit trail

Table `puzzle_merge_audit`, one row per approved merge, written by `ApprovePuzzleMergeRequestHandler` — for merges done through the admin UI as well as the API.

| Column | Holds |
|---|---|
| `snapshot_before` | Full row of every puzzle involved, **plus** `migrated`: the ids of every solving time, collection item, wish-list/sell-swap entry, lending and transfer that moved, and of those dropped as duplicates |
| `snapshot_after` | The survivor as it ended up |
| `decision_source` | `admin_ui` or `internal_api` |
| `decision_confidence` / `decision_note` | Only set when the caller supplied them |
| `performed_by_id` / `performed_at` | Who and when |

The merged puzzle rows are deleted seconds later, so this snapshot is the only remaining trace of them — it is what makes a wrong merge reconstructable by hand. Review the doubtful ones first:

```sql
SELECT id, merge_request_id, decision_confidence, decision_note, performed_at
FROM puzzle_merge_audit
WHERE decision_confidence IN ('low', 'medium')
ORDER BY performed_at DESC;
```

## Responses

| Status | When | Body |
|---|---|---|
| `204 No Content` | Success | empty |
| `201 Created` | A duplicate report / change proposal was filed | `{"mergeRequestId": "..."}` / `{"changeRequestId": "...", "recordVersion": "..."}` |
| `400 Bad Request` | Body present but not valid JSON object | `{"error": "..."}` |
| `401 Unauthorized` | Missing / wrong / unconfigured token | `{"error": "..."}` |
| `400 Bad Request` | Missing/invalid field, or `INTERNAL_API_REVIEWER_PLAYER_ID` unset on a moderation endpoint | `{"error": "..."}` |
| `404 Not Found` | Unknown `featureRequestId` / `mergeRequestId` / brand / puzzle id | standard Symfony 404 |
| `409 Conflict` | Brand already approved, or its name is taken by an approved brand, or a brand to delete is still in use; a change request already reviewed | standard Symfony 409 |
| `409 Conflict` | A puzzle changed since the `recordVersion(s)` sent with a merge / change-request approve; a change proposal for a puzzle with a pending one | `{"error": "..."}` |
| `422 Unprocessable Entity` | A brand merge that cannot be done (survivor in its own list) | standard Symfony 422 |

## Adding a new endpoint

The auth layer (firewall + access_control + `INTERNAL_API_TOKEN`) covers the whole `/internal-api/*` prefix. New endpoints are pure controller-drops — no security config changes:

1. Create `src/Controller/InternalApi/<Verb><Thing>Controller.php` — single `__invoke`, `#[Route(path: '/internal-api/<resource>/<action>', methods: ['POST'])]`.
2. Parse the JSON body via `InternalApiJsonBody::parse($request)` (throws `BadRequestHttpException` for malformed JSON).
3. Dispatch the relevant Messenger message via `MessageBusInterface`. Keep all business logic in the handler — controllers stay thin (per project CQRS convention).
4. Return `new Response(null, Response::HTTP_NO_CONTENT)` (or `JsonResponse` if the caller genuinely needs data).
5. Add a row to the table above and a path entry to `internal-api.openapi.yaml`.

That's it. The route is auto-discovered by `config/services.php` (the controller loader scans `src/Controller/**/*Controller.php`), and the firewall protects it automatically.

## OpenAPI spec

A hand-written OpenAPI 3.1 spec lives next to this doc at [`internal-api.openapi.yaml`](./internal-api.openapi.yaml). It's not served by the app — load it in Swagger Editor / Redoc / your IDE if you want a rendered view. Keep it in sync when adding endpoints.

## Why not `/api/docs`?

The public Swagger UI at `/api/docs` is generated by API Platform from resources in `src/Api/`. Internal API controllers live in `src/Controller/InternalApi/` (plain Symfony controllers), so they're invisible to API Platform's discovery — they intentionally do not appear in the public docs. This API is for the project owner and Claude Code only.

## Files

- `src/Security/InternalApiAuthenticator.php` — bearer token check
- `src/Controller/InternalApi/` — endpoint controllers + `InternalApiJsonBody` helper
- `config/packages/security.php` — `internal_api` firewall + `^/internal-api/` access_control rule
- `tests/Security/InternalApiAuthenticatorTest.php` — auth tests
- `src/Entity/PuzzleMergeAudit.php` + `src/Services/PuzzleMergeSnapshotBuilder.php` — merge audit trail
- `src/Query/GetPuzzleMergeReviewQueue.php` — review queue read model
- `src/Services/ManufacturerMerger.php` — the one brand merge (also used by the approval queue)
- `src/EventSubscriber/ManufacturerSlugRedirectSubscriber.php` — merged slugs answer 301
