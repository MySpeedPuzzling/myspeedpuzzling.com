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

Also closed-by-default: while it is empty, the moderation endpoints return `400` and dispatch nothing. The feature-request endpoints do not need it. The competition endpoints need it to create a competition (its creator), approve one and create a puzzle (who added and approved it) - see [Competitions and events](#competitions-and-events). The organization and series endpoints need it to create an organization or a series, approve an organization, assign an organization (the `organization` PUTs and a `PATCH` changing `organizationId`), move an edition or a round and turn a series into an organization (the acting player - see [Organizations, series and drafts](#organizations-series-and-drafts)); publishing, unpublishing and a `PATCH` of only `draft` do not. The [time verification](#time-verification) endpoints credit their decisions to it. It is also the player the [audit log](#audit-log-of-every-write) names.

## Endpoints

Base path: `/internal-api/`. Write endpoints take `POST` with an optional JSON body and return `204 No Content`; read endpoints take `GET` and return JSON. The competition endpoints are resource-shaped instead (`PATCH`/`PUT`/`DELETE`) and answer with the changed competition or round. Field names are camelCase everywhere.

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

`GET` takes `limit` (1-100, default 25) and `offset`. It returns `totalPending` plus, per request, `reportedNameLanguages` (what the reporter said each puzzle's name is in: an object puzzle id → base language, `{}` when nothing was said) and every candidate puzzle with its name, `nameLanguage` (the main title's language, null = English or not known), its other names (`alternativeNames`: `[{"name", "language"}]` in order, language a BCP 47 tag or null; `alternativeName` keeps the one other name of old - the first Czech one, else the first), piece count, EAN, catalogue number, manufacturer, **a ready-to-fetch `imageUrl`**, the weight of its history (`solvedTimesCount`, `collectionItemsCount`, …) and its `recordVersion` (a fingerprint of the record - names, brand, pieces, codes, image - to send back on approve). Whether two puzzles are the same product is usually settled by comparing the artwork, so the image URL is the point of the endpoint. The candidates are the reported puzzles **as they are now**: one merged into another puzzle since the report is that puzzle (`mergedMeanwhile`: an object reported id → the puzzle it was merged into, `{}` when none), one deleted without a merge is listed in `missingPuzzleIds`. `actionable` is false when fewer than two puzzles are left — such a request cannot be merged; it is closed as already done by itself (docs/features/puzzle-approvals.md, "Outdated requests"), or can be rejected. `survivorPuzzleId` on approve is one of the candidates.

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
number, photo).

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
more than its names (the web form allows one at a time too). A secret competition puzzle (hidden until a round reveals
it) is in no queue, and approving a change request of it answers `409` - unless the reviewer player
(`INTERNAL_API_REVIEWER_PLAYER_ID`) is an admin: then the API approves it like the admin UI does (a correction the
organiser asked for). Approving the puzzle itself and merges wait for the reveal for everybody. A proposal of the names only (`name`, `nameLanguage`,
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

### Time verification

Mark a solving time "needs verification" or unmark it without the queue's card at `/admin/time-verification`
([`suspicious-time-review.md`](./suspicious-time-review.md)) - for times a person looked at outside the queue (a
player's e-mail answer, an outreach round). Keyed by the **solving time id** (the id in `/en/result/{timeId}`), they
go through the same entity methods as the queue: the flag (statistics, insights and round results follow its event),
the case, the notices and one `suspicious_time_decision` row credited to `INTERNAL_API_REVIEWER_PLAYER_ID` (required).
Each answers the time's verification state (`200`, the same JSON as the `GET`).

| Method | Path | Purpose | Body fields (all optional) |
|---|---|---|---|
| `GET` | `/internal-api/solving-times/{timeId}/verification` | Flag, case (status, origin, reasons, reasons shown, note) and who was told about the current mark | - |
| `POST` | `/internal-api/solving-times/{timeId}/mark-suspicious` | "Needs verification" | `note`, `reasonCodes`, `toldByHand` |
| `POST` | `/internal-api/solving-times/{timeId}/unmark-suspicious` | "Looks fine" | `note` |

- **mark-suspicious** works on any time: a pending case is marked with the scan's reasons, any other case (trusted,
  gone, corrected) or none gets a mark of its own - a time the scan never raised gets a case with origin `moderator`
  ("Flagged by hand" on the card) and no reasons. `reasonCodes` picks which of the scan's reasons the player reads
  (default: every one a player may read; only reasons the case has, and only while they are about this very entry).
  `note` (≤ 1000 characters) is **read by the player**. The time stops counting at once; the player is told by the next
  notice run (end of the scan, 04:19 / 16:19 UTC) - **`toldByHand: true`** records the notices as already sent
  (`manual_email`) for every registered person of the time, so somebody you e-mail yourself is never told twice. `409`
  when the time is already marked.
- **unmark-suspicious**: a flagged time is unmarked - a flag set by SQL that the scan has not given a case yet too (it
  gets one, origin `manual`, first) - counts again, and the player's open reply ("The time is correct", an edit) is
  answered "Your time counts again" with the `note`; a pending case is trusted. Either way this entry is never raised
  again; an edit of the time lapses that. `409` when the time is neither flagged nor waiting in the queue.
- `404` for an unknown time, `400` for an unknown field or reason code (nothing changes).

```bash
# What is going on with this time?
curl -s -H "Authorization: Bearer $INTERNAL_API_TOKEN" \
  https://myspeedpuzzling.com/internal-api/solving-times/<timeId>/verification | jq

# The player answered our e-mail - it was the 500-piece edition, she moved the time: it counts again
curl -s -X POST -H "Authorization: Bearer $INTERNAL_API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"note": "Thanks for the correction!"}' \
  https://myspeedpuzzling.com/internal-api/solving-times/<timeId>/unmark-suspicious

# Mark a time we e-mail the player about ourselves - the app does not tell them again
curl -s -X POST -H "Authorization: Bearer $INTERNAL_API_TOKEN" -H 'Content-Type: application/json' \
  -d '{"toldByHand": true}' \
  https://myspeedpuzzling.com/internal-api/solving-times/<timeId>/mark-suspicious
```

### Competitions and events

Create and edit competitions (events), their rounds and puzzles without the admin UI - e.g. set an event's links, move
its dates, enter a past championship with its rounds, or add the puzzle a round used. Every write goes through the
same messages and handlers as the web forms (`AddCompetition`, `EditCompetition`, `ApproveCompetition`,
`AddCompetitionRound`, `EditCompetitionRound`, `DeleteCompetitionRound`), the whole-list writes no form has get
their own messages (`SetCompetitionRoundPuzzles`, `SetCompetitionPuzzles`, `AddApprovedPuzzle`), and fields are validated by the web forms' own rules (`CompetitionFormData`,
`CompetitionRoundFormData`). Domain rules: [competitions-management/](./competitions-management/README.md).

| Method | Path | Purpose | Answer |
|---|---|---|---|
| `GET` | `/internal-api/competitions?q=&status=&limit=&offset=` | List / search every competition, unapproved ones too | `200` list |
| `GET` | `/internal-api/competitions/{idOrSlug}` | One competition: every field, approval state, maintainers, rounds with puzzles, its own puzzles | `200` competition |
| `POST` | `/internal-api/competitions` | Create a standalone competition (`"approve": true` approves it at once) | `201` competition |
| `PATCH` | `/internal-api/competitions/{competitionId}` | Change only the fields sent | `200` competition |
| `DELETE` | `/internal-api/competitions/{competitionId}` | Delete an event or edition nobody has a result in (with its rounds, teams, participants) - `409` otherwise | `204` |
| `POST` | `/internal-api/competitions/{competitionId}/approve` | Approve a pending competition | `204` |
| `PUT` | `/internal-api/competitions/{competitionId}/puzzles` | Set the competition's own ("Competition puzzles") puzzles | `200` competition |
| `POST` | `/internal-api/competitions/{competitionId}/rounds` | Add a round (optionally with its `puzzleIds`) | `201` round |
| `PATCH` | `/internal-api/rounds/{roundId}` | Change only the round fields sent | `200` round |
| `DELETE` | `/internal-api/rounds/{roundId}` | Delete a round nobody has a result in | `204` |
| `PUT` | `/internal-api/rounds/{roundId}/puzzles` | Set the round's puzzles | `200` round |
| `POST` | `/internal-api/rounds/{roundId}/move` | Move the round to another event or edition - see [Organizations, series and drafts](#organizations-series-and-drafts) | `200` round |
| `GET` | `/internal-api/puzzles?q=&ean=&brand=&limit=&offset=` | Find puzzles (name, EAN, brand code; by brand) | `200` list |
| `POST` | `/internal-api/puzzles` | Create an approved puzzle without a photo | `201` puzzle |

Creating a competition, approving it and creating a puzzle need `INTERNAL_API_REVIEWER_PLAYER_ID` (the player added as
the competition's creator / the puzzle's adder, credited with the approval), and so do a `PATCH` changing
`organizationId` and moving a round (the acting player); the other endpoints do not - a `PATCH` of only `draft`
neither.

**Slugs - published URLs depend on them.** This API **keeps the slug when the name changes**, like the web forms
(their "URL" field is the web counterpart of an explicit `slug`). Only an explicit `slug` changes it: lower-case words joined by hyphens
(`^[a-z0-9]+(?:-[a-z0-9]+)*$`), free (`409` when another competition holds it - for an edition, another edition of
the same series or a standalone event, whose `/en/events/{slug}` must keep reaching it). **A slug change breaks every old URL of the event** (`/en/events/{old-slug}`, its round results
pages, links shared or indexed): there is no redirect from the old slug, so change it only when the old one is wrong.
A slug cannot be cleared (`null` / `""` is a `400`). `POST` generates the slug from the name unless `slug` is sent.
Round slugs are generated once from the name and never change.

**Fields.** The bodies use the field names of the answers (camelCase). A `PATCH` changes only the fields it holds;
`null` or `""` clears a field - except `slug` (a `400`) and `maintainerIds` (`null` keeps the list, `[]` empties it).
A field the endpoint does not know is a `400` - a typo would otherwise change nothing silently - and so is a body that
is no JSON object (`{"error": "The body must be a JSON object."}`). Every invalid field is listed at once in
`errors`, e.g. an invalid id in a list names it (`"puzzleIds": "must contain only ids - \"abc\" is not one."`).

| Competition field | Notes |
|---|---|
| `name` | Required on create |
| `shortcut`, `description` | |
| `location`, `locationCountryCode` | ISO 3166-1 alpha-2, any letter case (`"cz"`) |
| `dateFrom`, `dateTo` | ISO 8601 days (`"2026-11-14"`; a date-time counts by its day). An in-person standalone event needs location and both dates (like the form; an edition of a series needs neither - its series holds the place); `dateTo` not before `dateFrom`, at most 30 days later |
| `link` (website), `registrationLink`, `resultsLink` | URLs |
| `isOnline` | `false` by default. **An online event has no place**, like in the web form: `true` clears `location` (sent or stored - a `PATCH` of just `{"isOnline": true}` clears it too); `locationCountryCode` stays. **Its dates stay** - optional for an online event (leave them out / `null` for an ongoing one), never cleared by `isOnline` |
| `slug` | See above |
| `maintainerIds` | Player ids who may manage the event - a list replaces the whole list, `[]` removes everyone, left out or `null` keeps it |
| `approve` | Create only: `true` approves right away (no "approved" e-mail - the reviewer player is the creator, `ApproveCompetition::$notifyCreator = false`). Not needed under an approved organization - it is approved at once there |
| `eligibility` | "Who can enter" (≤ 120 characters, `"18+"`, `"Members of the club"`) - an edition without its own shows its series' |
| `organizationId` | A one-time event's organization: on create it is created under it (approved at once when the organization is approved - the reviewer player is an admin, `OrganizationApprovalPolicy`), on `PATCH` it moves into it or out of it (`null`, `AssignEventToOrganization`). An edition refuses one (`409` - it is its series'). An unknown id is a `400`; `403` when the reviewer player is neither an admin nor on the organization's team |
| `draft` | Create: `true` creates a draft (only its team sees it). `PATCH`: `false` publishes, `true` takes it back to draft - `409` while somebody joined it or official results / solving times are linked to it (checked before anything of the `PATCH` is written) |

Not settable here: the logo (upload it in the UI), rejection. An edition moves to another series with
`POST …/competitions/{id}/move`, an edition is created with `POST /internal-api/series/{seriesId}/editions` (see
[Organizations, series and drafts](#organizations-series-and-drafts)); `isRecurring` + `series` in the answer tell an
edition.

**A `PATCH` validates only when it holds competition fields** (any field besides `organizationId` and `draft`, even one
sent with its stored value) - and then the whole record as it would be stored: a record saved before a rule existed (a
span over 30 days, say, or an edition created through `POST …/series/{seriesId}/editions`, which does not check the
span) must be fixed in the same `PATCH` that changes any of its fields. A `PATCH` of only `organizationId` and / or
`draft` validates nothing else. Everything a `PATCH` checks - the fields, the right to the target organization, whether
it may go back to draft - is checked before anything of it is written.

| Round field | Notes |
|---|---|
| `name` | Required on create |
| `category` | `solo` (default), `duo`, `team` |
| `startsAt` | Required on create. ISO 8601 date-time, **stored and answered in UTC** like the round form stores it: with an offset (`"2026-11-14T10:00:00+01:00"`, `"…Z"`) it is that moment; without one (`"2026-11-14T10:00"`) a wall-clock time in the round's zone (the `timezone` sent along, else the round's own) - a `400` when a daylight-saving change skips or repeats that time there (send it with an offset). Left out of a `PATCH`, the start stays exactly as stored |
| `timezone` | The round's own IANA zone (`"America/Chicago"`) - its times are typed and shown in it, on the organiser's form and the event pages, and the answer carries it. A new round gets the zone of the event's other rounds, else of its country (`CountryCode::defaultTimezone()`, the series' country for an edition), else `Europe/Prague` - like the form. Only together with `startsAt` (a `400` alone: it would leave open whether the round keeps its moment or its wall-clock time); `null` is a `400` too |
| `minutesLimit` | Required on create, ≥ 1 |
| `revealDelayMinutes` | When the round's secret puzzles with an automatic reveal come out: whole minutes after `startsAt`, from `0` (when the round starts) to `240` (the longest round on record - anything later is an own reveal time, set in the UI); `10` when left out on create. Anything else (`-1`, `241`, `2.5`, `"10"`, `null`) is a `400`. Left out of a `PATCH`, the round keeps its delay. Moving the automatic reveal earlier needs `"confirmReveal": true` (see below) |
| `teamSize` | Team rounds: how many people a team is expected to have, `2` to `20` - a hint for the participants sheet, never a limit. Ignored for `solo` and `duo` rounds (a pair always has 2) - a round changed to one of them loses it. `null` takes it away; left out of a `PATCH`, the round keeps it. Anything else for a team round is a `400` |
| `badgeBackgroundColor` | The round's badge colour, `"#rrggbb"` or `"#rgb"` (anything else is a `400`); `null` or left out on create = a distinct colour picked automatically by the round's place in the schedule (`"#fe696a"`, the old form default, counts as none) - `RoundBadgeColor` |
| `badgeTextColor` | Stored and answered, but never shown: every page picks black or white for contrast with the badge colour. The round form no longer asks for it |
| `resultsLink` | The organiser's results page of this round |
| `puzzleIds` | Create only: attach these puzzles right away |

**Round puzzles and the round invariant.** `PUT …/rounds/{roundId}/puzzles` makes the round's puzzles exactly
`puzzleIds` (`[]` removes all): a puzzle no longer listed is removed, a new one attached, the others keep their secret
reveal (new ones are not hidden - a secret puzzle and its reveal are set on the round's page in the UI). A hidden
puzzle - a competition's secret one, or a placeholder hidden by hand - is never attached unhidden: the event page
would show it (`409`, nothing changes; on create, no round is created). A puzzle may be in only **one round
per category per competition** (that is what lets a solving time's round follow from its competition + puzzle,
[round-results.md](./competitions-management/round-results.md)): a list breaking it is a `409` naming the other
round, and **nothing** changes (`SetCompetitionRoundPuzzles` is one transaction). Creating a round with `puzzleIds` checks them before the round is created, and creates the round and attaches them in one transaction (`AddCompetitionRoundWithPuzzles`): a puzzle that changes in the very same moment still refuses the list (`409`, or `404` for one deleted meanwhile) - and then no round is created either. A round `PATCH` changing the category is refused the same way. Unknown puzzle ids are a
`404` listing them. Solving times follow automatically: every attach/removal reconciles the competition's round results
(`CompetitionRoundsChanged` → `RoundResultsReconciler`), so `resultsCount` is current in the answer.

**Deleting a round** is refused (`409`) while any solving time belongs to it or the organiser recorded an official
result in it (`resultsCount` > 0 counts both, docs/features/competitions-management/official-results.md); the
organiser's own delete button in the UI deletes it after a confirmation listing the official results. A round `PATCH`
changing the category of a round with official results is refused (`409`).

**Secret puzzles are never revealed by accident.** A round puzzle may keep its puzzle secret until its reveal:
- `hideUntilRoundStarts` marks it secret.
- `revealMode` is `automatic` (the round's `revealDelayMinutes` after it starts, 10 unless set otherwise; it follows the round's start and delay), `scheduled` or `manual`.
- `revealsAt` is the reveal moment, in UTC.
- `hidesEverywhere` means it is hidden on the whole site, not only on the event pages.

See the [competitions docs](./competitions-management/README.md), "Hide Until Round Starts".

A change that would reveal such a puzzle **earlier than planned** is refused with a `409` that lists the puzzles, and
nothing changes:
- **Deleting the round or removing the puzzle** (`PUT …/puzzles`) while another round has revealed it already. The
  puzzle comes out right away.
- **A round `PATCH` that moves the round's automatic reveal** (`startsAt` + `revealDelayMinutes`) **earlier.** That is
  an earlier start, a shorter delay, or both; the net moment decides (a start 15 minutes earlier with 15 minutes more
  delay asks nothing). This also applies when the new moment is still in the future, since 2026-10; before that, only
  a start that moved the reveal into the past asked.
  - Only rows that are still secret and have an automatic reveal are listed. Own reveal times and manual reveals
    never follow the round.
  - A later moment needs no yes. It keeps the puzzles hidden longer on the whole site too (`hiddenUntil` /
    `imageHiddenUntil` follow).

Each item of `revealedPuzzles` has:
- `puzzleId`, `name`.
- **When:** `rightAway` (`true` = the moment the request is applied) and `revealsAt` (the new moment in UTC, `null`
  when right away).
- **From when:** `previousRevealsAt` - the moment it moves from (UTC): for a round `PATCH`, the round's automatic
  reveal as it is now. `null` for a removal or a deleted round (the puzzle leaves the round). The organiser's form binds
  its yes to it too, so a round whose reveal moved meanwhile is asked again.
- **How far:** `scope` is one of:
  - `everywhere`: everything the round hid comes out on the whole site.
  - `name_everywhere`: its name comes out on the whole site; its picture stays hidden elsewhere until
    `stillHiddenElsewhereUntil`.
  - `event`: it comes out on this event only; another round keeps it hidden elsewhere until
    `stillHiddenElsewhereUntil` - or, when that is `null`, it was public elsewhere all along (a public catalogue puzzle
    the round keeps secret on its event pages only).
- `revealedEverywhere` (`true` when `scope` is `everywhere`) and `stillHiddenElsewhereUntil`. Both existed before
  `scope` and stay. `stillHiddenElsewhereUntil` = `9999-12-31T00:00:00+00:00` means until a manual reveal: another
  round waits for its organiser's "Reveal now".

To go ahead, send the same request again with `"confirmReveal": true` (in the `DELETE` body too). The organiser's form
asks the same question. The check runs in the handler after its locks (`refuseToReveal`), so a list that changed in
the meantime is decided on as it is at that point - `confirmReveal` is a blanket yes, not bound to the list the 409
showed: one that grew before the resend is applied unseen. Changing a reveal, revealing now and making a round keep its puzzle
secret on the whole site stay in the UI.

**The competition's own puzzles** ("Competition puzzles" on the standalone event page) are the puzzles of the
competition's **tag**. `PUT …/competitions/{competitionId}/puzzles` makes them exactly `puzzleIds`; a competition
without a tag gets a new one. Tag names show as badges on the puzzles, so the new tag is named like the competition's
badge - its shortcut, else its name - but never like a tag that exists already (any letter case): a taken shortcut
gives way to the name, a taken name to `"<name> (2)"`, `(3)`, …. An existing tag is never reused, even an unused one
of the same name: its puzzles may be a hand-made list somebody filters by, and two competitions would end up sharing
it. When other competitions or series carry the competition's tag the request is refused (`409`, nothing changes):
changing it would change their puzzles too. An edition gets a tag of its own; its series' tag is never touched.

**Puzzles.** `GET /internal-api/puzzles` is the site's own search (`SearchPuzzle`): every name in every language, EANs
with or without leading zeros, brand codes; approved or not; best match first. `q` and `ean` are the same search -
send one. `brand` is a brand id or a brand's exact name (any letter case; an unknown name finds nothing). Secret
competition puzzles (`hide_until` in the future) are not found, as on the site. Each puzzle: `puzzleId`, `name`,
`alternativeNames`, `piecesCount`, `manufacturerId`, `manufacturerName`, `ean`, `identificationNumber` (brand code),
`approved`, `solvedTimes`.

`POST /internal-api/puzzles` adds a puzzle **approved and without a photo** (`AddApprovedPuzzle` - the only way besides
a competition round's new puzzle to add one without a box photo; players always add through `AddPuzzle`, whose photo
is required). Body: `name` (required), `piecesCount` (required, 10-25000), the brand as `manufacturerId` **or**
`brand` (a name: an existing brand of that name - any letter case, the add form's `ManufacturerResolver` - else a new,
unapproved brand; the answer's `brandCreated` says which; approve a new one with
`POST /internal-api/manufacturers/{id}/approve`), optional `ean` / `identificationNumber` (one code, a comma-separated
string or a list; EANs must be valid barcodes), `nameLanguage`, `alternativeNames` (as for change requests). An EAN
another puzzle already carries is a `409` naming that puzzle - usually it is the same puzzle - unless
`"allowDuplicateEan": true`. The approval is recorded like any other: a `puzzle_approved` row in
`puzzle_moderation_decision` with `source = internal_api`, shown in the puzzle's history.

Competition answer (`GET`, and the answer of create / update / set puzzles):

```json
{
  "competitionId": "018d0004-0000-0000-0000-000000000001",
  "name": "WJPC 2024",
  "slug": "wjpc-2024",
  "shortcut": "WJPC24",
  "description": "World Jigsaw Puzzle Championship 2024",
  "location": "Prague",
  "locationCountryCode": "cz",
  "dateFrom": "2024-09-20",
  "dateTo": "2024-09-22",
  "link": "https://wjpc2024.com",
  "registrationLink": "https://wjpc2024.com/register",
  "resultsLink": "https://wjpc2024.com/results",
  "isOnline": false,
  "isRecurring": false,
  "series": null,
  "organizationId": null,
  "organization": null,
  "eligibility": null,
  "logo": null,
  "tagId": "018d0001-0000-0000-0000-000000000001",
  "tagName": "WJPC",
  "status": "approved",
  "draft": false,
  "hiddenAsDraft": false,
  "approvedAt": "2024-08-01T10:12:00+00:00",
  "approvedByPlayerId": "018d0000-0000-0000-0000-000000000003",
  "rejectedAt": null,
  "rejectionReason": null,
  "publiclyVisible": true,
  "createdAt": "2024-07-30T08:00:00+00:00",
  "addedByPlayerId": "018d0000-0000-0000-0000-000000000003",
  "addedByPlayerName": "Admin User",
  "roundsCount": 1,
  "resultsCount": 3,
  "resultsWithoutRoundCount": 0,
  "seriesPickResultsCount": 0,
  "participantsCount": 4,
  "maintainers": [{"playerId": "018d0000-0000-0000-0000-000000000001", "name": "John Doe", "code": "player1"}],
  "rounds": [{
    "roundId": "018d0005-0000-0000-0000-000000000001",
    "competitionId": "018d0004-0000-0000-0000-000000000001",
    "slug": "qualification-round",
    "name": "Qualification Round",
    "category": "solo",
    "startsAt": "2024-09-20T08:00:00+00:00",
    "timezone": "Europe/Prague",
    "minutesLimit": 60,
    "revealDelayMinutes": 10,
    "teamSize": null,
    "badgeBackgroundColor": "#1e88e5",
    "badgeTextColor": "#000000",
    "resultsLink": null,
    "resultsCount": 3,
    "puzzles": [{
      "puzzleId": "018d0003-0000-0000-0000-000000000001",
      "name": "Puzzle 1",
      "piecesCount": 500,
      "manufacturerId": "018d0002-0000-0000-0000-000000000001",
      "manufacturerName": "Ravensburger",
      "ean": "4005556123456",
      "identificationNumber": null,
      "approved": true,
      "hiddenUntil": null,
      "imageHiddenUntil": null,
      "roundPuzzleId": "0199a1b2-0000-7000-8000-000000000001",
      "hideUntilRoundStarts": false,
      "hideMode": null,
      "revealMode": "automatic",
      "revealsAt": null,
      "hidesEverywhere": false
    }]
  }],
  "puzzles": []
}
```

`status` is the approval state - `approved`, `pending` or `rejected` (`IsCompetitionPubliclyVisible::SQL_APPROVED`; an
edition is approved through its series; **an approved draft is `approved`**). `draft` is the competition's own draft
flag, `hiddenAsDraft` = it or its series is a draft, `publiclyVisible` the whole rule (`IsCompetitionPubliclyVisible`,
drafts included). `organizationId` / `organization` is a one-time event's own organization (always `null` for an
edition - `series.organizationId` is its organization); `series` also carries `draft`. `resultsCount` counts the
solving times linked to the competition, `resultsWithoutRoundCount` those of them in none of its rounds (their puzzle is
in no round of their category), `seriesPickResultsCount` those of them that are series picks MySpeedPuzzling matched to
this edition (the player picked the series, not the edition - [high-frequency-series.md](./events-page/high-frequency-series.md);
always `0` for a one-time event; a series pick without an edition is in no competition's counts - the series answer
counts it), `participantsCount` the people who joined (not removed). The list answers
`{"total", "limit", "offset", "competitions": [...]}` with the same fields minus `maintainers`, `rounds` and `puzzles`;
its `status` filter takes `all`, `approved`, `pending` (not approved, not rejected - drafts included), `rejected` and
`draft` (the competition or its series is a draft). `GET …/{idOrSlug}` takes a slug too: a standalone competition's
first, else an edition's - several editions sharing the slug answer `409` with their ids.

Approving (`POST …/approve`) works like the admin approval queue - the competition becomes public and its creator gets
the "approved" e-mail, unless the creator is the reviewer player (`ApproveCompetition::$notifyCreator`, which only the
internal API sets to `false`; the admin approval queue always e-mails the creator). Refused (`409`) for an approved or
rejected competition and for an edition.

#### Competition examples

```sh
API="$APP_URL/internal-api"
AUTH="Authorization: Bearer $INTERNAL_API_TOKEN"

# Find an event, then read everything about it
curl -s "$API/competitions?q=wjpc" -H "$AUTH"
curl -s "$API/competitions/wjpc-2026" -H "$AUTH"

# Set its website / registration / results links (the slug stays)
curl -X PATCH "$API/competitions/019a0000-0000-7000-8000-000000000001" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"link": "https://example.com", "registrationLink": "https://example.com/register", "resultsLink": "https://example.com/results"}'

# Move it: new place and dates
curl -X PATCH "$API/competitions/019a0000-0000-7000-8000-000000000001" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"location": "Ostrava", "locationCountryCode": "cz", "dateFrom": "2026-11-14", "dateTo": "2026-11-15"}'

# Rename AND change the URL - only because the slug is sent explicitly
curl -X PATCH "$API/competitions/019a0000-0000-7000-8000-000000000001" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"name": "Ostrava Puzzle Open 2026", "slug": "ostrava-puzzle-open-2026"}'

# Enter a past championship, approved at once
curl -X POST "$API/competitions" -H "$AUTH" -H "Content-Type: application/json" -d '{
  "name": "Czech Jigsaw Puzzle Championship 2025", "shortcut": "CZJPC25",
  "location": "Brno", "locationCountryCode": "cz", "dateFrom": "2025-10-04", "dateTo": "2025-10-05",
  "link": "https://example.com/czjpc", "resultsLink": "https://example.com/czjpc/results", "approve": true
}'

# Its rounds - local times of the event, in the zone of the event's other rounds, else of its country (or send "timezone")
curl -X POST "$API/competitions/019a0000-0000-7000-8000-000000000002/rounds" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"name": "Individual Final", "category": "solo", "startsAt": "2025-10-05T10:00", "minutesLimit": 120,
       "puzzleIds": ["0199a000-0000-7000-8000-000000000010"]}'
curl -X POST "$API/competitions/019a0000-0000-7000-8000-000000000002/rounds" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"name": "Pairs", "category": "duo", "startsAt": "2025-10-04T14:00:00+02:00", "minutesLimit": 90}'

# Puzzles of a round (the whole list), and the event's own puzzles
curl -X PUT "$API/rounds/019a0000-0000-7000-8000-000000000003/puzzles" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"puzzleIds": ["0199a000-0000-7000-8000-000000000010", "0199a000-0000-7000-8000-000000000011"]}'
curl -X PUT "$API/competitions/019a0000-0000-7000-8000-000000000002/puzzles" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"puzzleIds": ["0199a000-0000-7000-8000-000000000010"]}'

# A round entered by mistake (409 once anybody has a result in it)
curl -X DELETE "$API/rounds/019a0000-0000-7000-8000-000000000004" -H "$AUTH"

# A US event: its rounds in Chicago time; a rename later keeps the start
curl -X PATCH "$API/rounds/019a0000-0000-7000-8000-000000000005" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"startsAt": "2026-10-17T08:05", "timezone": "America/Chicago"}'

# Secret puzzles out 15 minutes after the start instead of 10 (a later reveal needs no yes)
curl -X PATCH "$API/rounds/019a0000-0000-7000-8000-000000000005" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"revealDelayMinutes": 15}'

# A shorter delay or an earlier start lets them out earlier: 409 listing them, then the same request with the yes
curl -X PATCH "$API/rounds/019a0000-0000-7000-8000-000000000005" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"revealDelayMinutes": 5, "confirmReveal": true}'

# A 409 listed the secret puzzles a delete would reveal - delete anyway
curl -X DELETE "$API/rounds/019a0000-0000-7000-8000-000000000006" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"confirmReveal": true}'

# Find a puzzle by EAN or name, or add the missing one (approved, no photo)
curl -s "$API/puzzles?ean=4005556173495" -H "$AUTH"
curl -s "$API/puzzles?q=evening%20in%20paris&brand=ravensburger" -H "$AUTH"
curl -X POST "$API/puzzles" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"name": "Championship Puzzle 2025", "brand": "Ravensburger", "piecesCount": 500, "ean": "4005556173495"}'
```

### Organizations, series and drafts

Organizations (an association, a club, a shop or brand, a venue - the level above series and one-time events), series,
editions, drafts and the restructuring tools without the admin UI - e.g. turn a series an organiser used as a bucket
into an organization, give it new series, move editions and rounds where they belong. Design of record:
[organizations/README.md](./organizations/README.md). Every write goes through the web forms' messages and handlers
(`AddOrganization`, `EditOrganization`, `ApproveOrganization`, `DeleteOrganization`, `Add/RemoveOrganizationMaintainer`,
`Publish*` / `Unpublish*`, `AddCompetitionSeries`, `EditCompetitionSeries`, `ApproveCompetitionSeries`, `AddEdition`,
`AssignEventToOrganization`) and the restructuring messages (`MoveEditionToSeries`, `MoveRoundToCompetition`,
`CreateOrganizationFromSeries`); fields are validated by the forms' own rules (`OrganizationFormData`,
`CompetitionFormData` with "Recurring" ticked for a series, `EditionFormData`). The patterns of
[Competitions and events](#competitions-and-events) hold: camelCase fields, unknown fields `400`, every invalid field
listed in `errors`, JSON refusals, every write in the audit log (with `createdId` on a `201`).

| Method | Path | Purpose | Answer |
|---|---|---|---|
| `GET` | `/internal-api/organizations?q=&status=&limit=&offset=` | List / search organizations (`q`: name, short name, slug) | `200` list |
| `GET` | `/internal-api/organizations/{idOrSlug}` | One organization: fields, state, team, its series and one-time events | `200` organization |
| `POST` | `/internal-api/organizations` | Create an organization - **approved** (`"draft": true` keeps it a draft) | `201` organization |
| `PATCH` | `/internal-api/organizations/{organizationId}` | Change the fields sent (`draft` publishes / unpublishes) | `200` organization |
| `POST` | `/internal-api/organizations/{organizationId}/approve` | Approve a pending one - and its pending series and one-time events | `204` |
| `DELETE` | `/internal-api/organizations/{organizationId}` | Delete an empty organization (`409` while a series or event is under it) | `204` |
| `POST` | `/internal-api/organizations/{organizationId}/maintainers` | Add `{"playerId"}` to its team (idempotent; `409` for an 11th member) | `204` |
| `DELETE` | `/internal-api/organizations/{organizationId}/maintainers/{playerId}` | Remove a player from its team (idempotent) | `204` |
| `POST` | `/internal-api/organizations/{organizationId}/publish` · `/unpublish` | Draft off / on (always allowed) | `204` |
| `GET` | `/internal-api/series?q=&status=&limit=&offset=` | List / search series (`q`: name, slug, shortcut) | `200` list |
| `GET` | `/internal-api/series/{idOrSlug}` | One series with every edition | `200` series |
| `POST` | `/internal-api/series` | Create a series (approved at once under an approved organization, else with `"approve": true`) | `201` series |
| `PATCH` | `/internal-api/series/{seriesId}` | Change the fields sent (`organizationId` moves it, `draft` publishes / unpublishes) | `200` series |
| `POST` | `/internal-api/series/{seriesId}/publish` · `/unpublish` | Draft off / on (unpublish `409` while an edition has participants, results or solving times) | `204` |
| `POST` | `/internal-api/series/{seriesId}/editions` | Create an edition | `201` competition |
| `PUT` | `/internal-api/series/{seriesId}/organization` | `{"organizationId": "…" \| null}` - move the series into / out of an organization | `200` series |
| `POST` | `/internal-api/series/{seriesId}/create-organization` | Turn the series into an organization | `201` organization |
| `PUT` | `/internal-api/competitions/{competitionId}/organization` | `{"organizationId": "…" \| null}` - a one-time event into / out of an organization (`409` for an edition with an id; `null` on an edition changes nothing) | `200` competition |
| `POST` | `/internal-api/competitions/{competitionId}/publish` · `/unpublish` | Draft off / on (unpublish `409` while somebody joined, results or solving times are linked) | `204` |
| `POST` | `/internal-api/competitions/{competitionId}/move` | Move an edition to another series `{"seriesId", "slug"?}` | `200` competition |
| `POST` | `/internal-api/rounds/{roundId}/move` | Move a round to another event or edition `{"competitionId"}` | `200` round |
| `POST` | `/internal-api/competitions/{competitionId}/convert-to-series` | Turn a one-time event into a series `{"keepAsEdition"?, "dropParticipants"?}` - see [Converting an event into a series](#converting-an-event-into-a-series) | `201` series |

The writes that act as somebody need `INTERNAL_API_REVIEWER_PLAYER_ID` (an admin): creating an organization or a series
(its creator), approving an organization, assigning an organization (the `organization` PUTs, a `PATCH` changing
`organizationId`), both moves and turning a series into an organization (the acting player - `400` while it is empty).
Publish / unpublish (also a `PATCH` of only `draft`), team changes, editions, deletes and converting an event into a
series do not. Putting an item under
an organization answers `403` when the reviewer player is neither an admin nor on that organization's team
(`OrganizationNotManaged`) - configure an admin.

**Approval.** The reviewer player is an admin, so:
- an organization created here, or made from a series, is **approved at once** (no e-mail);
- a series or one-time event created under, or moved into, an approved organization is **approved at once**
  (`OrganizationApprovalPolicy`, D2) - `"approve": true` is needed only outside an approved organization (under a
  pending one the item stays pending until the organization is approved); a rejected item stays rejected; an edition is
  approved through its series. There is no endpoint approving an existing pending series: send `"approve": true` on
  create, move it into an approved organization, or approve its organization;
- approving an organization (`POST …/approve`) approves its pending series and one-time events too (P2), and its creator
  gets the "approved" e-mail unless that is the reviewer player. `409` for an approved or rejected organization.

**Drafts.** A draft is visible only to its team (and admins): its page answers 404 to everyone else, it is in no list,
sitemap, picker or public API. `status` stays the approval state - **an approved draft is `approved` with
`draft: true`** and `publiclyVisible: false`. `"draft": true` on create starts a draft; `draft` on `PATCH` (or the
`publish` / `unpublish` endpoints) switches it. Publishing an item that still waits for approval puts it into the
approval queue - nobody is e-mailed (an admin acts through this API); publishing a published item changes nothing. Back to draft is refused (`409`,
`CannotUnpublish` naming `participants`, `results`, `solving_times`) for a competition somebody joined or with official
results or linked solving times, and for a series one of whose editions has any; an organization can always go back.
A draft series hides its editions; a draft organization hides only its own page (its series and events keep their own
state). The `status` filter of every list takes `draft`.

**Slugs.** As for competitions: a rename keeps the slug, only an explicit `slug` changes it, **without a redirect** from
the old one (`409` when taken - organizations among organizations, series among series; an edition within its series and
among one-time events). Organizations and series have separate addresses (`/en/organizations/{slug}`,
`/en/series/{slug}`), so an organization may take a series' slug. Only the three restructuring tools write redirects:

| Tool | Old address | Answers 301 to |
|---|---|---|
| Move an edition | `/series/{old series}/{edition}` and each `/series/{old series}/{edition}/results/{round}` | the edition / round results page where it is now |
| Move a round | its old results page (`/events/{event}/results/{round}` or `/series/{s}/{e}/results/{round}`) | its results page in the new event or edition |
| Turn a series into an organization with `newSeriesSlug` | `/series/{old slug}`; every `/series/{old slug}/{edition}` and its round results pages | the organization page; each edition's / round's current page |

A redirect leads to the target's **current** address (chained moves keep working: an edition moved twice is found from
both old addresses) and only when the old address would answer 404 - a live page, also a draft, always wins. A redirect
never leads to a draft: while the target (or its series, or the target organization) is a draft, the old address
answers 404. Deleting an organization deletes the redirects to it (the old series address of "create-organization"
answers 404 again). An explicit slug change (`PATCH`) writes no redirect.

| Organization field | Notes |
|---|---|
| `name` | Required on create, ≤ 120 characters |
| `shortName` | ≤ 30 characters ("RJA") |
| `slug` | Create: generated from the name unless sent; `PATCH`: an explicit change (cannot be cleared) |
| `about` | Plain text, ≤ 5,000 characters |
| `website` | `http(s)` URL, ≤ 255 characters |
| `socialLinks` | A list of `http(s)` URLs (each ≤ 255 characters), at most 10 after duplicates are dropped (the icon comes from the host: Instagram, Facebook, Discord, YouTube, …). A list replaces the whole list; `[]` or `null` removes every link |
| `countryCode` | ISO 3166-1 alpha-2, any letter case |
| `region` | Free text ≤ 120 characters (a state, a region, a city) |
| `kind` | `association`, `club`, `organizer` (runs events and competitions as its main activity), `shop`, `venue`, `community`, `other` - or `null` |
| `maintainerIds` | The team besides its creator, at most 10 - a list replaces it, `[]` empties it, left out or `null` keeps it; an unknown player id is a `404` |
| `draft` | See Drafts |

The logo is uploaded in the UI. The organization answer: `organizationId`, `name`, `shortName`, `slug`, `logo`,
`about`, `website`, `socialLinks`, `countryCode`, `region`, `kind`, `status`, `draft`, `approvedAt`,
`approvedByPlayerId`, `rejectedAt`, `rejectionReason`, `publiclyVisible`, `createdAt`, `addedByPlayerId` (its creator),
`addedByPlayerName`, `seriesCount`, `eventsCount`, `maintainers` (`playerId`, `name`, `code`), `series` (the series
answer without `maintainers` / `editions`) and `events` (its one-time events with the competition list's fields). The
list answers `{"total", "limit", "offset", "organizations": [...]}` without `maintainers`, `series` and `events`.

| Series field | Notes |
|---|---|
| `name` | Required on create |
| `shortcut`, `description` | |
| `link` | The series' website (URL) |
| `isOnline` | `false` by default; `true` clears `location` |
| `location`, `locationCountryCode` | An in-person series needs a location; editions take the series' place when they are created |
| `eligibility` | "Who can enter", ≤ 120 characters - shown by editions without their own |
| `schedule` | "When it happens", ≤ 160 characters ("Second Thursday of the month, 7:30 pm") - free text, each date is still its own edition |
| `slug`, `maintainerIds` | As for competitions |
| `organizationId` | Create: under that organization; `PATCH`: moves it (`null` = out). An unknown id is a `400` |
| `draft`, `approve` | See Drafts and Approval (`approve` on create only) |

The series answer: `seriesId`, `name`, `slug`, `shortcut`, `description`, `link`, `isOnline`, `location`,
`locationCountryCode`, `logo`, `organizationId`, `organization` (`organizationId`, `name`, `slug`), `eligibility`,
`schedule`, `status`, `draft`, `approvedAt`, `approvedByPlayerId`, `rejectedAt`, `rejectionReason`, `publiclyVisible`
(`IsSeriesPubliclyVisible`), `createdAt`, `addedByPlayerId`, `addedByPlayerName`, `editionsCount`, `resultsCount`,
`resultsWithoutEditionCount`, `maintainers` and `editions` - every edition with
the competition list's fields (`competitionId`, `name`, `slug`, `dateFrom`, `dateTo`, `status`, `draft`,
`roundsCount`, `resultsCount`, `seriesPickResultsCount`, `participantsCount`, …; read one with
`GET /internal-api/competitions/{id}` for its rounds), by date, undated ones last. The list answers
`{"total", "limit", "offset", "series": [...]}` without `maintainers` and `editions`.

The series' `resultsCount` is all its results, **each time once**: the times linked to one of its editions (explicitly
or matched by MySpeedPuzzling) and its series picks no edition was found for - those also as
`resultsWithoutEditionCount` (a normal, permanent state, matched as soon as an edition fits -
[high-frequency-series.md](./events-page/high-frequency-series.md)). After a conversion with `"keepAsEdition": false`
every result of the event is in `resultsWithoutEditionCount` until editions exist; the editions' `seriesPickResultsCount`
tells how many the reconcile has matched since.

**Creating an edition** (`POST …/series/{seriesId}/editions`): `name` (required), `dateFrom` and `dateTo` (required,
ISO days, `dateTo` not before `dateFrom` - like the "Add edition" form; a `PATCH` of the competition can clear them
afterwards), `slug` (else generated from the name; `409` when taken in the series or by a one-time event), `link`,
`registrationLink`, `resultsLink`, `description`, `eligibility`, `draft`. It takes the series' place and online flag.
The 30-day span rule of the event form is not checked here - a later `PATCH` of the edition validates the whole record
with it. Rounds follow with `POST /internal-api/competitions/{competitionId}/rounds`.

**Moving an edition** (`POST …/competitions/{competitionId}/move`, `{"seriesId": "…", "slug": "…"?}`): its rounds,
participants, results and solving times stay with it. Its slug stays unless an edition of the target series or a
one-time event holds it - then `409`, send `slug`. Its place follows the target series where it was its old series' (a place set for the edition stays), it is
online when the target series is, its organization and visibility are the target series'. `409` for a one-time event
(moving one into a series is not part of this), for the series it is in already, and for a **draft** target series
while the edition has participants, official results or linked solving times (a draft never holds those - their
listings would show its name; an empty edition may move into a draft); `400` for an unknown series. The answer is the
competition where it is now.

**Moving a round** (`POST …/rounds/{roundId}/move`, `{"competitionId": "…"}`): the round moves with its puzzles (and their
secret reveal), its table layout and **every explicit solving time that belongs to it** - their `competitionId` changes,
their round stays. "Belongs" is the round results rule ([round-results.md](./competitions-management/round-results.md)): a
time linked to the round, and a time of the old competition solved in the round's category on one of its puzzles that was
not linked yet (it gets the link). Series picks matched to the old edition (automatic links,
[high-frequency-series.md](./events-page/high-frequency-series.md) P29) do not move with the round - the series
reconcile both competitions get matches them again by the series' rule. Both competitions' round results are reconciled afterwards
(`CompetitionRoundsChanged` → `RoundResultsReconciler`). The round keeps the wall-clock zone it is shown in and its slug
(`-2`, `-3`, … when the target has it). Refused with `409`, nothing changed: the target is the same competition;
participants are entered in the round (round entries or pairs/teams - they belong to the competition, moving them is a
later step); its stopwatch is running; one of its puzzles is already in a round of the same category in the target (the
one-round-per-category invariant, naming that round); the target is hidden as a **draft** (a draft event, or an edition
of a draft series) and the round has solving times (a draft never gets linked times - an empty round may move there).
`404` for an unknown target. The answer is the round where it is
now (`competitionId`, `slug`, `resultsCount`).

**Results linked to an event but to no round.** A solving time linked to a competition whose puzzle is in none of its
rounds has no round (`resultsWithoutRoundCount`). Attaching its puzzle to a round (`PUT …/rounds/{roundId}/puzzles`)
links it: every attach reconciles the competition's round results, so the round's `resultsCount` includes it at once -
and a move of that round takes it along.

**Turning a series into an organization** (`POST …/series/{seriesId}/create-organization`): `name` (required, ≤ 120),
`shortName` (≤ 30), `slug` (else from the name - it may be the series' own slug), `kind`, `countryCode` and `region`
(≤ 120; left out = the series' country and location, `null` = none), `newSeriesName` (≤ 250), `newSeriesSlug`. The
organization gets the series' logo, its description as `about`, its link as `website`, its maintainers as the team, its
creator as creator; it is **approved**. The series' follows **move** to the organization (the followers no longer
follow the series itself - a player following both keeps one row); the series is attached to it (a pending series is
approved by the policy) and renamed / re-slugged when asked - with a new slug, its old address answers 301 to the organization and its editions' and round
results' old addresses to where they are now. `409` for a series that has an organization already or a taken slug (an
organization's or a series'). Social links and the rest follow with a `PATCH` of the organization. The answer is the
organization (with its series).

#### Converting an event into a series

`POST …/competitions/{competitionId}/convert-to-series`, body `{"keepAsEdition"?: true, "dropParticipants"?: false}`
(`ConvertCompetitionToSeries`; design of record [high-frequency-series.md](./events-page/high-frequency-series.md) "The
conversion tool"). The series is created from the one-time event like the web's "Convert to series" button does: its
name, slug (the event's when free), logo, description, website, place, shortcut, tag, maintainers, creator, approval or
rejection, draft state, organization and "Who can enter"; the event's followers follow the series. Then:

- `"keepAsEdition": true` (the default, the web button): the event becomes the series' first edition - its rounds,
  participants and solving times stay with it, its old address answers 301 to the edition page;
- `"keepAsEdition": false` - **the event becomes the series** (an umbrella event whose results belong to many contests):
  every solving time of the event becomes a **series-level** result of the new series (`competition_series_id`, no
  edition - matched to an edition by the series reconcile once editions exist), its old address `/events/{slug}` and
  every old address that led to it answer 301 to the series page (`event_url_redirect`), and the competition row is
  deleted. **Refused** with `409` (`CompetitionNotConvertible`, the reasons in `error`, nothing changes) while the event
  has rounds, official results, referees, page sections, marketplace marks (listings people bring to it) or
  participants - participants only without `"dropParticipants": true`, which deletes them (and the participant sheet's
  change trail; removed participants never refuse it).

`409` (`CompetitionAlreadyInSeries`) for an edition, `404` for an unknown competition, `400` for an unknown field or a
non-boolean value. No reviewer player is needed - the series takes the event's creator and approval. The answer (`201`)
is the series answer (`GET /internal-api/series/{seriesId}`), the audit log's `createdId` is the new series. Every
`FOREIGN KEY` to `competition` is listed with what the conversion does with it in
`tests/ConvertCompetitionForeignKeyCoverageTest.php`.

```bash
# The umbrella event becomes the series - its results become series-level results of it
curl -X POST "$API/competitions/019a0000-0000-7000-8000-000000000031/convert-to-series" -H "$AUTH" -H "$JSON" \
  -d '{"keepAsEdition": false}'
# 409 "it has: participants. Send \"dropParticipants\": true to delete its participants." - when they may go:
curl -X POST "$API/competitions/019a0000-0000-7000-8000-000000000031/convert-to-series" -H "$AUTH" -H "$JSON" \
  -d '{"keepAsEdition": false, "dropParticipants": true}'
```

#### Restructuring example

An organiser keeps a monthly online contest and two venue nights in one series `quarry-hollow-puzzlers`, all contests
as rounds of one undated edition, each venue night an edition of its own. The organization takes over the series'
address, the series becomes the online contest, the venue nights get their own series, each contest its own edition:

```sh
API="$APP_URL/internal-api"
AUTH="Authorization: Bearer $INTERNAL_API_TOKEN"
JSON="Content-Type: application/json"

# 0. Read it all: the series with its editions, an edition with its rounds (resultsCount, resultsWithoutRoundCount)
curl -s "$API/series/quarry-hollow-puzzlers" -H "$AUTH"
curl -s "$API/competitions/019a0000-0000-7000-8000-00000000000a" -H "$AUTH"

# 1. The organization, on the series' old address; the series gets a new name and address (its old one redirects)
curl -X POST "$API/series/019a0000-0000-7000-8000-000000000001/create-organization" -H "$AUTH" -H "$JSON" -d '{
  "name": "Quarry Hollow Jigsaw Association", "shortName": "QHJA", "slug": "quarry-hollow-puzzlers",
  "kind": "association", "countryCode": "us", "region": "Quarry Hollow",
  "newSeriesName": "Quarry Hollow Virtual Contest", "newSeriesSlug": "quarry-hollow-virtual-contest"
}'
curl -X PATCH "$API/organizations/019a0000-0000-7000-8000-000000000002" -H "$AUTH" -H "$JSON" -d '{
  "socialLinks": ["https://www.instagram.com/quarryhollowpuzzles", "https://discord.gg/quarryhollow"],
  "about": "The jigsaw puzzle association of Quarry Hollow.", "website": "https://qhja.example"
}'

# 2. A series per venue night - approved at once under the approved organization (the second one alike:
#    "Old Mill Pub Puzzle", id …0007)
curl -X POST "$API/series" -H "$AUTH" -H "$JSON" -d '{
  "name": "Copper Kettle Puzzle Night", "organizationId": "019a0000-0000-7000-8000-000000000002",
  "isOnline": false, "location": "Copper Kettle Brewing, Millbrook", "locationCountryCode": "us",
  "link": "https://quarry-hollow.example/nights", "eligibility": "18+",
  "schedule": "Second Thursday of the month, 7:30 pm", "description": "Casual puzzle nights - no results kept."
}'

# 3. An edition with a round
curl -X POST "$API/series/019a0000-0000-7000-8000-000000000003/editions" -H "$AUTH" -H "$JSON" -d '{
  "name": "Copper Kettle Puzzle Night - November", "dateFrom": "2026-11-12", "dateTo": "2026-11-12",
  "registrationLink": "https://quarry-hollow.example/register/november", "eligibility": "18+"
}'
curl -X POST "$API/competitions/019a0000-0000-7000-8000-000000000004/rounds" -H "$AUTH" -H "$JSON" \
  -d '{"name": "Night Round", "startsAt": "2026-11-12T19:30", "timezone": "America/New_York", "minutesLimit": 60}'

# 4. A round without a puzzle gets the puzzle of the results linked to the edition only - they join the round -
#    then the round moves to its own edition, its results with it
curl -X PUT "$API/rounds/019a0000-0000-7000-8000-000000000005/puzzles" -H "$AUTH" -H "$JSON" \
  -d '{"puzzleIds": ["0199a000-0000-7000-8000-000000000010"]}'
curl -X POST "$API/series/019a0000-0000-7000-8000-000000000001/editions" -H "$AUTH" -H "$JSON" \
  -d '{"name": "Virtual Contest July 2026", "dateFrom": "2026-07-15", "dateTo": "2026-07-15"}'
curl -X POST "$API/rounds/019a0000-0000-7000-8000-000000000005/move" -H "$AUTH" -H "$JSON" \
  -d '{"competitionId": "019a0000-0000-7000-8000-000000000006"}'

# 5. Every other round with results moves the same way, one new edition each (repeat the two calls above per round);
#    the old edition keeps its last round - new name, address and date (an explicit slug change writes no redirect,
#    but the addresses the moves and the organization wrote keep leading to it)
curl -X PATCH "$API/competitions/019a0000-0000-7000-8000-00000000000a" -H "$AUTH" -H "$JSON" -d '{
  "name": "Virtual Contest January 2027", "slug": "virtual-contest-january-2027",
  "dateFrom": "2027-01-20", "dateTo": "2027-01-20", "registrationLink": "https://quarry-hollow.example/register/january"
}'

# 6. The venue night editions move to their series, then get a one-day date, a name and an address (a place set by
#    hand for the edition stays)
curl -X POST "$API/competitions/019a0000-0000-7000-8000-00000000000b/move" -H "$AUTH" -H "$JSON" \
  -d '{"seriesId": "019a0000-0000-7000-8000-000000000003"}'
curl -X PATCH "$API/competitions/019a0000-0000-7000-8000-00000000000b" -H "$AUTH" -H "$JSON" -d '{
  "name": "Copper Kettle Puzzle Night - October", "slug": "copper-kettle-october-2026",
  "dateFrom": "2026-10-05", "dateTo": "2026-10-05"
}'
curl -X POST "$API/competitions/019a0000-0000-7000-8000-00000000000c/move" -H "$AUTH" -H "$JSON" \
  -d '{"seriesId": "019a0000-0000-7000-8000-000000000007"}'
curl -X PATCH "$API/competitions/019a0000-0000-7000-8000-00000000000c" -H "$AUTH" -H "$JSON" -d '{
  "name": "Old Mill Pub Puzzle - November", "slug": "old-mill-november-2026",
  "dateFrom": "2026-11-24", "dateTo": "2026-11-24"
}'

# Check: the organization with its three series, each series with its editions
curl -s "$API/organizations/quarry-hollow-puzzlers" -H "$AUTH"
curl -s "$API/series/quarry-hollow-virtual-contest" -H "$AUTH"
```

`tests/Controller/InternalApi/RestructureWalkThroughInternalApiTest.php` walks this sequence in full (every round,
both venue series, the redirects of the old addresses).

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

### Audit log of every write

Every request of a writing method (`POST`, `PATCH`, `PUT`, `DELETE`) under `/internal-api/` that got past the token
check - for every endpoint, refusals included - writes one log line (`InternalApiAuditSubscriber`): `method`, `path`,
`route`, `status`, `actingPlayerId` (`INTERNAL_API_REVIEWER_PLAYER_ID` - the API has no user of its own, so this is the
admin it acts as), `targetIds` (the ids in the path) and `createdId` (what a create made). Monolog channel
`internal_api_audit`, level `info`: never a Sentry issue, and in production its own stderr handler (JSON → Loki) - not
the `fingers_crossed` one that drops info records of requests without a warning. Search Loki for
`channel="internal_api_audit"`.

## Responses

| Status | When | Body |
|---|---|---|
| `204 No Content` | Success | empty |
| `201 Created` | A duplicate report / change proposal was filed | `{"mergeRequestId": "..."}` / `{"changeRequestId": "...", "recordVersion": "..."}` |
| `200 OK` | A competition read or changed (`GET`/`PATCH`/`PUT`), a round changed | the competition / round |
| `201 Created` | A competition, round or puzzle created | the competition / round / puzzle |
| `400 Bad Request` | Body present but not valid JSON object | `{"error": "..."}` |
| `400 Bad Request` | Invalid fields of a competition / round / puzzle request (all of them at once; an unknown field too) | `{"error": "...", "errors": {"field": "what is wrong"}}` |
| `401 Unauthorized` | Missing / wrong / unconfigured token | `{"error": "..."}` |
| `400 Bad Request` | Missing/invalid field, or `INTERNAL_API_REVIEWER_PLAYER_ID` unset on a moderation endpoint | `{"error": "..."}` |
| `404 Not Found` | Unknown `featureRequestId` / `mergeRequestId` / brand / puzzle / competition / round id or slug | `{"error": "..."}` |
| `409 Conflict` | Brand already approved, or its name is taken by an approved brand, or a brand to delete is still in use; a change request already reviewed | `{"error": "..."}` |
| `409 Conflict` | A taken competition slug; the round invariant (a puzzle in two rounds of one category); a round with results to delete; a shared tag; a competition already approved / rejected / an edition to approve; a known EAN for a new puzzle | `{"error": "..."}` |
| `409 Conflict` | A puzzle changed since the `recordVersion(s)` sent with a merge / change-request approve; a change proposal for a puzzle with a pending one | `{"error": "..."}` |
| `409 Conflict` | A round change, a round delete or a puzzle removal that would reveal secret puzzles earlier than planned, sent without `"confirmReveal": true` | `{"error": "...", "revealedPuzzles": [...]}` |
| `422 Unprocessable Entity` | A brand merge that cannot be done (survivor in its own list) | `{"error": "..."}` |

Every refusal is JSON, whatever the client accepts: `InternalApiErrorResponseSubscriber` renders each 4xx HTTP
exception under `/internal-api/` (from a controller, a handler or the firewall) as `{"error": "…"}` with its status.
Anything else (a bug, any 5xx) is left to Symfony and stays a reported 500. Both it and the audit log recognise the
API by the **decoded** path, like the firewall and the router do (`InternalApiAuthenticator::isInternalApiRequest()`),
so `/internal%2Dapi/…` is answered and logged the same.

The refusals of the competition endpoints are expected answers, not bugs: their exceptions (`CompetitionSlugTaken`,
`CompetitionSlugAmbiguous`, `CompetitionNotApprovable`, `CompetitionRoundHasResults`, `CompetitionTagShared`,
`PuzzleInTwoRoundsOfCategory`, `PuzzleEanAlreadyInCatalogue`; 400s anyway) are logged at `info` -
`config/packages/framework.php` `exceptions`, pinned by `ClientErrorLogLevelTest` - so they never become Sentry issues.

## Adding a new endpoint

The auth layer (firewall + access_control + `INTERNAL_API_TOKEN`) covers the whole `/internal-api/*` prefix. New endpoints are pure controller-drops — no security config changes:

1. Create `src/Controller/InternalApi/<Verb><Thing>Controller.php` — single `__invoke`, `#[Route(path: '/internal-api/<resource>/<action>', methods: ['POST'])]`.
2. Parse the JSON body via `InternalApiJsonBody::parse($request)` (throws `BadRequestHttpException` for malformed JSON).
3. Dispatch the relevant Messenger message via `MessageBusInterface`. Keep all business logic in the handler — controllers stay thin (per project CQRS convention).
4. Return `new Response(null, Response::HTTP_NO_CONTENT)` (or `JsonResponse` if the caller genuinely needs data).
5. Add a row to the table above and a path entry to `internal-api.openapi.yaml`.

For an id in the path use `requirements: ['xId' => FirstTryConflictsController::ID_REQUIREMENT]` (any UUID-shaped id,
as `Uuid::isValid()` - `Requirement::UUID` refuses ids outside the RFC versions: some older rows and the test fixtures'). A body of several
fields reads best through `InternalApiInput` (every field error at once, unknown fields refused). Every refusal is
rendered as JSON and every write is audited automatically - nothing to do per endpoint.

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
- `src/Controller/InternalApi/InternalApiInput.php` — field-by-field body reader collecting every field error (400 with `errors`)
- `src/Controller/InternalApi/CompetitionInput.php`, `RoundInput.php` — competition / round fields onto the web forms' data objects
- `src/Query/GetAdminCompetitions.php`, `src/Query/GetAdminPuzzles.php` + `src/Results/Admin*.php` — the competition read model (everything, unapproved too)
- `src/Services/CompetitionSlugGenerator.php` — competition and series slugs (generated or explicit + uniqueness), shared with the web forms' handlers and their "URL" field (`CompetitionUrlField`)
- `src/Message/SetCompetitionRoundPuzzles.php`, `src/Message/SetCompetitionPuzzles.php`, `src/Message/AddApprovedPuzzle.php` (+ handlers) — the writes no form had
- `src/EventSubscriber/InternalApiErrorResponseSubscriber.php` — JSON refusals
- `src/EventSubscriber/InternalApiAuditSubscriber.php` — the audit log (channel `internal_api_audit`)
- `tests/Controller/InternalApi/*InternalApiTest.php` — functional tests of the competition, round, puzzle, organization, series, draft and move endpoints (`RestructureWalkThroughInternalApiTest` = the restructuring example)
- `src/Controller/InternalApi/OrganizationInput.php`, `SeriesInput.php` — organization / series fields onto the web forms' data objects
- `src/Query/GetAdminOrganizations.php`, `src/Query/GetAdminSeries.php` + `src/Results/AdminOrganization*.php`, `AdminSeries*.php` — the organization and series read models (everything, drafts and unapproved too)
- `src/Message/MoveEditionToSeries.php`, `MoveRoundToCompetition.php`, `CreateOrganizationFromSeries.php` (+ handlers) — the restructuring tools (also the web pages `move_edition`, `move_competition_round`, `create_organization_from_series`)
- `src/Services/Restructuring/EventUrlRedirects.php`, `src/Query/GetEventUrlRedirect.php`, `src/EventSubscriber/EventUrlRedirectSubscriber.php` — old event addresses answer 301 after a move
- `src/EventSubscriber/ManufacturerSlugRedirectSubscriber.php` — merged slugs answer 301
