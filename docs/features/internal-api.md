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

Also closed-by-default: while it is empty, the moderation endpoints return `400` and dispatch nothing. The feature-request endpoints do not need it. The competition endpoints need it to create a competition (its creator), approve one and create a puzzle (who added and approved it) - see [Competitions and events](#competitions-and-events). It is also the player the [audit log](#audit-log-of-every-write) names.

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
| `GET` | `/internal-api/puzzles?q=&ean=&brand=&limit=&offset=` | Find puzzles (name, EAN, brand code; by brand) | `200` list |
| `POST` | `/internal-api/puzzles` | Create an approved puzzle without a photo | `201` puzzle |

Creating a competition, approving it and creating a puzzle need `INTERNAL_API_REVIEWER_PLAYER_ID` (the player added as
the competition's creator / the puzzle's adder, credited with the approval); the other endpoints do not.

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
| `approve` | Create only: `true` approves right away (no "approved" e-mail - the reviewer player is the creator, `ApproveCompetition::$notifyCreator = false`) |

Not settable here: the logo (upload it in the UI), the series of an edition (recurring events are `CompetitionSeries`;
`isRecurring` + `series` in the answer tell an edition), rejection.

| Round field | Notes |
|---|---|
| `name` | Required on create |
| `category` | `solo` (default), `duo`, `team` |
| `startsAt` | Required on create. ISO 8601 date-time, **stored and answered in UTC** like the round form stores it: with an offset (`"2026-11-14T10:00:00+01:00"`, `"…Z"`) it is that moment; without one (`"2026-11-14T10:00"`) a wall-clock time in the round's zone (the `timezone` sent along, else the round's own) - a `400` when a daylight-saving change skips or repeats that time there (send it with an offset). Left out of a `PATCH`, the start stays exactly as stored |
| `timezone` | The round's own IANA zone (`"America/Chicago"`) - its times are typed and shown in it, on the organiser's form and the event pages, and the answer carries it. A new round gets the zone of the event's other rounds, else of its country (`CountryCode::defaultTimezone()`, the series' country for an edition), else `Europe/Prague` - like the form. Only together with `startsAt` (a `400` alone: it would leave open whether the round keeps its moment or its wall-clock time); `null` is a `400` too |
| `minutesLimit` | Required on create, ≥ 1 |
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

**Secret puzzles are never revealed by accident.** A round puzzle may keep its puzzle secret until its reveal
(`hideUntilRoundStarts`, `revealMode` `automatic` = 10 minutes after the round starts / `scheduled` / `manual`,
`revealsAt` in UTC, `hidesEverywhere` = the whole site, not only the event pages -
[competitions docs](./competitions-management/README.md) "Hide Until Round Starts"). A change that would reveal such
a puzzle **right away** - deleting the round or removing the puzzle (`PUT …/puzzles`) while another round has revealed
it already, or a `PATCH` moving the start so that its automatic reveal is over - is refused with a `409` that lists them (`revealedPuzzles`: `puzzleId`, `name`,
`revealedEverywhere`, `stillHiddenElsewhereUntil`), and nothing changes. Send the same request again with
`"confirmReveal": true` (in the `DELETE` body too) to go ahead - the organiser's form asks the same question. Changing
a reveal, revealing now and making a round keep its puzzle secret on the whole site stay in the UI.

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
  "logo": null,
  "tagId": "018d0001-0000-0000-0000-000000000001",
  "tagName": "WJPC",
  "status": "approved",
  "approvedAt": "2024-08-01T10:12:00+00:00",
  "approvedByPlayerId": "018d0000-0000-0000-0000-000000000003",
  "rejectedAt": null,
  "rejectionReason": null,
  "publiclyVisible": true,
  "createdAt": "2024-07-30T08:00:00+00:00",
  "addedByPlayerId": "018d0000-0000-0000-0000-000000000003",
  "addedByPlayerName": "Admin User",
  "roundsCount": 1,
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

`status` is `approved`, `pending` or `rejected` (an edition is approved through its series - `publiclyVisible` is
`IsCompetitionPubliclyVisible`). The list answers `{"total", "limit", "offset", "competitions": [...]}` with the same
fields minus `maintainers`, `rounds` and `puzzles`. `GET …/{idOrSlug}` takes a slug too: a standalone competition's
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

# A 409 listed the secret puzzles a delete would reveal - delete anyway
curl -X DELETE "$API/rounds/019a0000-0000-7000-8000-000000000006" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"confirmReveal": true}'

# Find a puzzle by EAN or name, or add the missing one (approved, no photo)
curl -s "$API/puzzles?ean=4005556173495" -H "$AUTH"
curl -s "$API/puzzles?q=evening%20in%20paris&brand=ravensburger" -H "$AUTH"
curl -X POST "$API/puzzles" -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"name": "Championship Puzzle 2025", "brand": "Ravensburger", "piecesCount": 500, "ean": "4005556173495"}'
```

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
- `tests/Controller/InternalApi/*InternalApiTest.php` — functional tests of the competition, round and puzzle endpoints
- `src/EventSubscriber/ManufacturerSlugRedirectSubscriber.php` — merged slugs answer 301
