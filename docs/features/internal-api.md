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

Endpoints that perform *moderation* (currently the puzzle merge queue) record a reviewer on the domain object and notify the affected player. The internal API has no logged-in user, so the player to credit comes from:

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
| `POST` | `/internal-api/puzzle-merge-requests/{id}/approve` | Merge the puzzles |
| `POST` | `/internal-api/puzzle-merge-requests/{id}/reject` | Decline the report |

`GET` takes `limit` (1-100, default 25) and `offset`. It returns `totalPending` plus, per request, every candidate puzzle with its name, piece count, EAN, catalogue number, manufacturer, **a ready-to-fetch `imageUrl`**, and the weight of its history (`solvedTimesCount`, `collectionItemsCount`, …). Whether two puzzles are the same product is usually settled by comparing the artwork, so the image URL is the point of the endpoint. `actionable` is false when fewer than two of the reported puzzles still exist (an earlier merge already deleted one) — such a request cannot be merged, only rejected.

Approve body:

| Field | Required | Notes |
|---|---|---|
| `survivorPuzzleId` | yes | The puzzle that stays. Normally the one carrying the most history |
| `mergedName` | yes | Name the survivor ends up with |
| `mergedPiecesCount` | yes | Positive integer |
| `mergedEan` | no | Leave out to keep the survivor's own |
| `mergedIdentificationNumber` | no | As above |
| `mergedManufacturerId` | no | Leave out to keep the survivor's own |
| `selectedImagePuzzleId` | no | Take the cover image from this puzzle |
| `decisionConfidence` | no | `high`, `medium` or `low` |
| `decisionNote` | no | Why — stored on the audit row, not shown to players |

Blank strings count as absent, so a blank `mergedEan` never blanks a real one. Whatever the reviewer omits, the merge still carries over any EAN, catalogue number, alternative name, cover image or manufacturer that **only** a deleted puzzle had — a merge never loses product data it could have kept.

Reject body: `rejectionReason` (required). **It is shown to the player who reported the duplicate**, as a notification, so write it for them.

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
| `400 Bad Request` | Body present but not valid JSON object | `{"error": "..."}` |
| `401 Unauthorized` | Missing / wrong / unconfigured token | `{"error": "..."}` |
| `400 Bad Request` | Missing/invalid field, or `INTERNAL_API_REVIEWER_PLAYER_ID` unset on a moderation endpoint | `{"error": "..."}` |
| `404 Not Found` | Unknown `featureRequestId` / `mergeRequestId` | standard Symfony 404 |

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
