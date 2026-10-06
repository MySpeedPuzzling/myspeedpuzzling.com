# Competitions Management

Community-driven competition and event management. Any logged-in player can submit a competition; it becomes publicly visible after admin approval. Maintainers (the creator + named co-maintainers) can then manage rounds, assign puzzles, plan table layouts, and run a live stopwatch during the event.

## Competition Lifecycle

### 1. Submission

Any authenticated player can submit a new competition with:
- **Required:** name, location
- **Optional:** shortcut (e.g. "WJPC"), description, website/registration/results links, country, date range, online flag, recurring flag, logo image
- **Maintainers:** other players who should have edit access (searchable autocomplete)

A URL slug is auto-generated from the name (with a random suffix if collisions exist). The competition is stored with `approvedAt = null` (pending state) and is **not visible** in the public listing.

### 2. Admin Review (Approve or Reject)

Admins see all pending competitions in a dedicated approval queue (`/admin/competition-approvals`).

- **Approve:** Sets `approvedAt`, makes the competition publicly visible. The creator receives an email notification with a link to the public event page.
- **Reject:** Admin must provide a reason. Sets `rejectedAt` and `rejectionReason`. The creator receives an email notification with the rejection reason. Rejected competitions are removed from the approval queue and remain invisible in public listings. The rejection reason is displayed on the edit page.

An admin notification email is sent automatically when a new competition is submitted, linking to the approval queue.

### 3. Editing

Maintainers and admins can edit all competition fields. While unapproved, a warning banner is shown on the edit page. If rejected, a danger banner with the rejection reason is shown instead. The edit page provides navigation to round management and participant management.

Changing the name regenerates the slug in the web form. The internal API (`PATCH /internal-api/competitions/{id}`, [internal-api.md](../internal-api.md#competitions-and-events)) keeps it - published links depend on it - and changes it only to an explicitly sent, free slug (`EditCompetition::$slug` / `$regenerateSlugOnRename`, `CompetitionSlugGenerator`). Maintainer lists are fully replaced on each save (clear + re-add).

### 4. Public Listing

The events page shows four sections:
- **Live** — one-time events where today's date falls within the event date range
- **Upcoming** — one-time events starting in the future
- **Recurring** — all approved recurring events (sorted alphabetically)
- **Past** — one-time events that have ended

Recurring events are excluded from Live/Upcoming/Past sections. All sections only show approved competitions. External links (website, registration, results) automatically get `utm_source=myspeedpuzzling` appended. Online and recurring badges are displayed on event cards. Recurring series cards display the next upcoming edition date (derived from the nearest future round's `starts_at` across all editions).

Each competition also appears in "My Competitions" for its creator/maintainers regardless of approval status.

## Access Control

| Action | Who |
|--------|-----|
| Browse public events listing | Everyone |
| Submit a new competition | Any authenticated player |
| Edit competition & manage rounds/tables/stopwatch | Admin, original creator, or named maintainer |
| View public stopwatch page | Everyone (no auth required) |
| Approve or reject a competition | Admin only |

Access is enforced via a `CompetitionEditVoter` that checks whether the player is admin, the creator, or in the maintainers list. All management controllers use this same voter, including round-level controllers (which resolve the competition from the round).

## Event Types

**Online and offline are never combined** — a competition is either fully online or fully offline. Users must create separate competitions for each format. The "Recurring event" checkbox is available for both online and offline events. Date fields (dateFrom/dateTo) are shown for non-recurring events — they are hidden when recurring is selected (toggled via `competition-form` Stimulus controller's `offlineFields`, `dateFields`, and `recurringField` targets).

### Standalone Competitions (One-Time Events)

One-time events (`Competition` with `series_id = NULL`) represent individual competitions:
- **Offline events** (e.g. WJPC 2024): Have location, dateFrom/dateTo, table layouts, multiple rounds
- **Online events** (e.g. Online Challenge 2024): No location, have dateFrom/dateTo, multiple rounds
- The only behavioral difference: **table layout management is offline-only**

### Competition Series (Recurring Events)

Recurring events use a **`CompetitionSeries` entity** that groups multiple editions. Both online and offline events can be recurring. Each edition is a full `Competition` with its own participants, rounds, and metadata.

```
CompetitionSeries ("Euro Jigsaw Jam")
├── name, slug, description, logo, website link
├── isOnline, location, country
├── maintainers, approval workflow
└── Competition ("EJJ #68")          ← edition = full Competition
     ├── name, dateFrom/dateTo
     ├── registrationLink, resultsLink  ← per-edition
     ├── series_id (FK → CompetitionSeries)
     ├── CompetitionRound[]            ← 0..N rounds (0 for info-only events)
     │    ├── category (solo/duo/team)
     │    └── CompetitionTeam[]        ← for duo/team rounds
     └── CompetitionParticipant        ← edition-scoped
```

**Key design**: Participants always belong to a `Competition`, whether it's a standalone event or a series edition. Zero behavioral branching in participant handlers/queries.

**Creating a series:**
1. User submits a new event with "recurring event series" checked (available for both online and offline)
2. A `CompetitionSeries` is created (pending approval)
3. From the series management page, organizer adds editions
4. Each edition creates a `Competition` with name, dates, and links
5. Rounds are managed separately via the round management page (typically 1+, but 0 is valid for info-only events without participants)

**Adding an edition:**
- Name (e.g. "EJJ #68 — March 2026")
- Date from / date to
- Registration link, results link (optional)
- After creation, organizer adds rounds from the edition management page

**Public series page** (`/en/series/{slug}`):
- Series header with name, description, logo, website link, badges
- Upcoming editions as cards (2-column grid on desktop, single column on mobile): name, date with relative time, time limit, puzzle count, participant count, registration link
- Past editions as cards (same layout): with results link instead of registration link
- Each edition card links to the edition detail page

**Public edition detail page** (`/en/series/{seriesSlug}/{editionSlug}`):
- Edition header with link back to series
- Puzzle grid (from the edition's round)
- Participants component (competition-scoped)
- Legacy URLs (`/en/edition/{competitionId}`) 301 redirect to the new slug-based URL

**Editions get auto-generated slugs** — when an edition is created via `AddEditionHandler`, a unique slug is generated from the edition name. Slug uniqueness is scoped to the parent series (not globally), enforced by a composite unique constraint on `(series_id, slug)`.

**Events listing:**
- Standalone competitions appear in Live/Upcoming/Past sections
- Series appear in a dedicated "Recurring" section as single cards, showing the next upcoming edition date
- Editions (competitions with `series_id`) are excluded from Live/Upcoming/Past

### Event badge on solving times

A solving time may be linked to a standalone competition **or to a series edition** (`puzzle_solving_time.competition_id` points at the edition's `Competition` row). Every list that shows a time's event (player results, puzzle leaderboards, ladders, recent activity) renders the one partial `templates/_competition_badge.html.twig` (`{{ include('_competition_badge.html.twig', {time: x}) }}`, where `x` is a `SolvedPuzzle`, `PuzzleSolver`, `PuzzleSolversGroup` or `RecentActivityItem` — all carry `competitionName/Shortcut/Slug` plus `competitionSeriesName/Shortcut/Slug`, selected by every read model via `LEFT JOIN competition_series cs ON cs.id = competition.series_id`):

- **Label** — standalone: `shortcut ?? name` (e.g. `WJPC24`); edition: `<series shortcut ?? series name> · <edition name>` (e.g. `Euro Jigsaw Jam · EJJ #68 — February 2026`); when the edition is named exactly like its series (competitions converted to a series keep the name) only the series label is shown.
- **Link** — standalone: `event_detail` (`/en/events/{slug}`); edition: `edition_detail` (`/en/series/{seriesSlug}/{editionSlug}`) — **never** `event_detail`, an edition slug is only unique within its series. An edition whose series has no slug renders the badge unlinked.
- Nothing is rendered when the time has no competition.

**Public visibility of a competition row** (standalone or edition) is decided in one place, `IsCompetitionPubliclyVisible` (`check($competitionId)` + the reusable `SQL_CONDITION` fragment): a standalone competition is visible when approved and not rejected; an edition is visible iff its **series** is approved and not rejected — editions are never approved individually (their own `approved_at` stays `NULL`). The API competition detail uses this rule to decide what is readable.

### Linking solving times to events

The "Competition / event" picker on the add-time form (`PuzzleAddFormType`, routes `puzzle_add` + `finish_stopwatch`) and the edit-time form (`EditPuzzleSolvingTimeFormType`, route `edit_time`) is one TomSelect field whose options are baked server-side (no remote endpoint, no caching):

- **Selectable set** = exactly `IsCompetitionPubliclyVisible::SQL_CONDITION`: every approved & not-rejected standalone competition regardless of its date (live, past, upcoming, undated) **plus every edition whose series is approved & not rejected** (the edition's own `approved_at` is ignored, its own `rejected_at` is respected). The series umbrella itself is never selectable — a time links to a concrete edition. Read model: `GetSelectableCompetitions::all(?$alwaysIncludeCompetitionId)` → `SelectableCompetition` DTOs.
- **Include-current rule (edit form)**: `EditTimeController` passes the time's current `competition_id` (server-derived from the owner-checked row, never from the request) as the form option `current_competition_id`; the query adds that row unconditionally, so a link to a competition that is not (or no longer) publicly visible survives a re-save instead of rendering an empty control and silently detaching the time.
- **Validation**: `CompetitionChoicesBuilder::build()` returns a `CompetitionChoices` value (`options`, `optgroups`, `contains(id)`); the form types' `POST_SUBMIT` rule rejects any non-null submitted id the picker did not offer with the generic `forms.competition_not_selectable` error (never echoes names). The handlers' `CompetitionNotFound → null` fallback stays only for the render→submit race and logs a warning.
- **Ordering** (global, one SQL `ORDER BY`): live → undated standalone ("perpetual" online umbrellas, the most-used entries) → past (newest first) → upcoming (soonest first) → undated editions. Undated editions with rounds are dated by their first round (`MIN(competition_round.starts_at)`). Editions carry `optgroup` = series id and TomSelect renders a series' block where its best-ranked edition sits (`lockOptgroupOrder` off); standalone events are ungrouped.
- **Rendering**: option cards are built in `CompetitionChoicesBuilder` (every organiser-authored string HTML-escaped, lazy-loaded 48px logo falling back to the series logo, series name on edition cards, "live" badge, `keywords` = series name/shortcut + name/shortcut + location as extra `searchField`). `assets/controllers/competition_picker_controller.js` patches the TomSelect config on `autocomplete:pre-connect` (`maxOptions: null`, optgroup header with series logo, blur on select) — ux-autocomplete forces `maxOptions: 50` and its own `render` for `<input>`-based pickers, so these cannot come from PHP.
- **Deep link** `puzzle_add?competition=<uuid>` (`/en/puzzle-add?competition=…`, built with `path('puzzle_add', {competition: id})`): `PuzzleAddController` pre-selects the competition in the picker when the form opens in speed-puzzling mode and `IsCompetitionPubliclyVisible::check()` passes — the `_solving_time_form` template then renders the competition section expanded. Any other value (not a uuid, unknown, unapproved, edition of an unapproved series, `?mode=relax|collection`) is ignored silently: no flash, no error, the form just opens without a pre-selection. It only seeds the GET render; on POST `handleRequest()` overwrites the data, so a cleared field is never re-filled from the URL.
- **"Add my time from this event" CTA** (`events.add_my_time`) on the standalone event page (`EventDetailController` → `event_detail.html.twig`, next to the "I'm going" / "You are going" buttons) and the edition page (`EditionDetailController` → `edition_detail.html.twig`, in the registration/results link row) links to that deep link. Shown only when `can_add_time` = signed in **and** the competition row is publicly visible (`IsCompetitionPubliclyVisible::check()`) **and** the event has started — `CompetitionEvent::startsAfter(now)` is false, i.e. `COALESCE(date_from, date_to)` is not a later calendar day than today (`ClockInterface`; an undated event is perpetual and always qualifies). No per-edition CTA on the series page or in the editions table — a time links to a concrete edition, so the CTA lives on the edition page.

## Round Results

Every round with a slug has a public results page — `/en/events/{slug}/results/{roundSlug}` for standalone events, `/en/series/{seriesSlug}/{editionSlug}/results/{roundSlug}` for editions. A solving time's round follows from its competition + puzzle + solo/duo/team; it is stored in `puzzle_solving_time.competition_round_id` and kept current automatically. Full design, decisions and the WJPC 2026 data: [round-results.md](round-results.md).

## Event pages: titles, puzzles, indexing (SEO, 2026-09-30)

- **Titles** (`Value\EventTitle`, used by the event, edition and round results pages): the full name, never shortened; an edition gets its series in front unless its name mentions it (`Piece-off · #21 - May 2026`); the year follows unless the name already carries a standalone 19xx/20xx year. Once the event is over — `date_to ?? date_from` before today, by calendar day — **and** has at least one (not suspicious) result here, the title says Results (`EventTitle::saysResults()`, `events.meta.event_detail_title_results`, word order per locale); an event that is over without results is named like an upcoming one — a "Results" title over a page without any would disappoint searchers. Editions without own dates are dated by their rounds. Round results: `{event} – {round} Results`. The H1 stays the organiser's name.
- **Meta descriptions**: the same rule — events that say Results quote the number of results (`CountCompetitionResults`); all others keep date + location.
- **Sitemap**: `sitemap-events.xml` lists every edition that passes `IsCompetitionPubliclyVisible` (editions are never approved individually).
- **Puzzles on the standalone event page**: tagged puzzles; else the puzzles of its rounds (`GetCompetitionPuzzles::roundPuzzleOverviews`, round hide rules applied); else the puzzles people logged times for there, most logged first, max 24 (`solvedPuzzleOverviews`) - same cards as tagged puzzles.
- **Results by round** (standalone event page): one compact row of small round buttons in the event header (not a section of its own - the puzzle cards link their round too): rounds with a slug and ≥ 1 result, only on a publicly visible event — round results pages of a non-public event answer 404, so nothing links to them.
- **Indexing**: unapproved/rejected events and series, and editions failing `IsCompetitionPubliclyVisible`, render `noindex, nofollow`. Event/edition/series JSON-LD `image` is the 1200 px `puzzle_large` preset (JPEG, metadata stripped; Google wants event images ≥ 720 px wide) - never the uploaded original, which may carry EXIF/GPS (see `docs/TODO.md`, Image storage).
- **WJPC hub** lists every edition's tagged + round puzzles with public solo median/fastest (`GetCompetitionPuzzles::forCompetitions`, publicly visible competitions only), each edition folded in a `<details>` so ~140 puzzles do not push "how to take part" and the FAQ out of reach.

## Round Management

A competition has multiple **rounds**, each with:
- **Name** and **start time** with its **time zone** — see "Start time and time zone" below
- **Minutes limit** — the time limit for solving (drives the stopwatch countdown)
- **Category** — `solo`, `duo`, or `team` (`RoundCategory` enum, default `solo`)
- **Badge colors** — optional background/text hex colors for visual distinction in round lists

Rounds are displayed sorted by start time. Each round can be edited or deleted. The round list shows action buttons for: Puzzles, Teams (for duo/team rounds only), Tables (only for in-person events), Stopwatch, Edit, Delete.

### Start time and time zone

The organiser types the **local** start (a one-day event asks only for the time, `CompetitionEvent::singleDay()`) and picks the time zone. `competition_round.starts_at` stores the **instant in UTC**; `competition_round.timezone` keeps the zone it was typed in (`RoundTimezone` is the one place for both conversions, `CompetitionRoundFormData::fromCompetitionRound()` / `startsAtInstant()`):
- The edit form pre-selects the round's own zone and shows the same local time - saving an untouched form never moves a round. A new round pre-selects the zone of the event's other rounds, else the default of the event's country (`CountryCode::defaultTimezone()`).
- Every page shows a round's start in its zone (`|date(format, round.timezone)` - read models carry the resolved zone), the round pages name the zone, so a Wisconsin event shows Chicago time to everybody. The zone select shows each zone's offset on the round's date.
- A typed time that does not exist exactly once in the zone (skipped or repeated by a daylight-saving change, or overflowing like 31.02. 25:70) is refused with a form error (`RoundTimezone::parseLocal()`).
- A one-day event asks for the time only when the round is on the event's day in its zone; a round on another local day gets the full date and time, so an untouched save never moves it by a day.
- Rounds saved before 2026-10 have no zone (`NULL`): they are read in the default zone of the event's country, else its series' country - the zone the form pre-selected then.
- Until 2026-10 the zone was not kept: the edit form showed the UTC time with the country's zone, so every save of an untouched form moved a round by the zone's offset (reported by the Wisconsin State Jigsaw Puzzle Championship).

### Round Categories

Each round has a category that determines the solving format:
- **Solo** (default) — individual solving, no team assignment
- **Duo** — pairs solving together, teams of 2
- **Team** — group solving, teams of any size

For duo and team rounds, a "Manage Teams" button appears in the round management page, linking to the team management UI.

### Team Management

Teams are managed per-round via `CompetitionTeam` entity. Each team belongs to a round and has an optional name.

**Entity structure:**
```
CompetitionTeam
  id: UUID (PK)
  round: CompetitionRound (FK, non-null)
  name: string (nullable — unnamed teams allowed)
```

**Participant-team assignment:** `CompetitionParticipantRound` has a nullable FK to `CompetitionTeam`. For solo rounds, team is always null. For duo/team rounds, participants on the same team share the same `CompetitionTeam` FK.

**Management UI** (`/en/manage-round-teams/{roundId}`):
- Create teams (with optional name)
- Assign participants to teams (from those assigned to the round)
- Remove participants from teams
- Delete teams
- View unassigned participants

**Import/Export**: The Excel import supports optional `round_name` and `team_name` columns. When provided, participants are auto-assigned to the named round, and for duo/team rounds, teams are created or matched by name.

## Puzzle Assignment

Puzzles are assigned to rounds via a `CompetitionRoundPuzzle` join. When adding a puzzle to a round:

- **Existing puzzle:** select by UUID
- **New puzzle on the fly:** provide name, piece count, manufacturer (existing or new), optional photo, EAN, identification number. The new puzzle is created with `approved = false`

### Hide Until Round Starts (secret puzzles and their reveal)

Each puzzle assignment has a `hideUntilRoundStarts` flag and a `hideMode` enum (`PuzzleHideMode`):

| Mode | Enum value | While secret |
|------|-----------|--------------|
| **Hide image only** | `image_only` | Name and brand are public, the picture is replaced with a placeholder |
| **Hide entirely** | `entirely` | Name, brand and picture are secret |

**One reveal moment per secret puzzle, every surface obeys it** (since 2026-10, `RoundPuzzleReveal`):
- `competition_round_puzzle.reveal_mode` = `automatic` (default: 10 minutes after the round starts, follows the round when its start moves), `scheduled` (the organiser's own moment in `reveal_at`, never moved by the round; "Reveal now" leaves `scheduled` + now), `manual` (no moment - hidden until the organiser clicks "Reveal now").
- PHP `RoundPuzzleReveal::revealAt()` / `CompetitionRoundPuzzle::revealsAt()` and SQL `RoundPuzzleReveal::sqlRevealAt()` / `sqlHidden()` compute the same moment - event pages (`GetEditionRounds`, so the API and round results too), `GetCompetitionPuzzles`, `GetPuzzleSummary` ("used at") and the organiser's status line all use them. Never add another `starts_at + 10 minutes`.
- **Where the secret holds - it belongs to the PUZZLE:** a round puzzle that made the puzzle secret while it was not public (created on the fly for the round, or added while another round still kept it hidden) has `hides_everywhere = true`. `SecretPuzzleHides::resync()` sets the puzzle's site-wide hide to the **latest** reveal among all such rows still secret: `hide_image_until` = the latest of all of them, `hide_until` = the latest of those hiding it entirely ("entirely" beats "image only"); a manual reveal not made yet counts as never (`CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED`, 9999-12-31). It works on the unit of work as the handler left it (rows added, removed or moved in the handler count). A public catalogue puzzle is hidden on the event pages only and its columns are never touched.
- **Re-synced on every change:** adding a puzzle to a round, `ChangeRoundPuzzleReveal`, `RevealRoundPuzzleNow`, `EditCompetitionRound` (automatic reveals follow the new start), removing a puzzle from a round, deleting a round, an event or a series (those delete rows by SQL - the puzzles are collected first), a merge. With no such row left the dates stay as they were - never revealed by accident (the removal flash says until when; a manual one stays hidden until an admin clears it).
- **Every surface obeys the columns:** search, brand picker, barcode lookup, brand/pieces hubs, sitemap, API v1, the event's tag list (`GetPuzzleOverview::byTagId` and the "used at" tag branch apply the round rules too). Every page of one puzzle (detail, suggest a change, report a duplicate, QR, stopwatch / add time, a saved stopwatch, the marketplace filter) answers **404** while a competition keeps its name hidden - except for admins, the puzzle's adder and maintainers of a competition with it in a round (`SecretPuzzleAccess`; an approved placeholder hidden by hand, the Ravensburger Puzzle Month box link, still opens). The pages and actions that show or set its **codes** (suggest a change, report a duplicate, the admin edit and history, linking an EAN in multiscan, adding it to another competition's round) are strict: they count a hidden picture too (`alsoWhileImageHidden`). While the picture is hidden its **EAN and brand code** are blank everywhere (`PuzzleOverview::fromDatabaseRow($row, $now)`, the brand picker) and no code search finds it (`PuzzleTextSearch`, `allByEan`); the duplicate-code guard still sees it. A new secret puzzle's image gets a random file name (`PuzzleImageNamer::secretFilename()`). Brand pickers count visible puzzles only and leave out a brand whose every puzzle is secret (the add-to-round form keeps the brands of its own competition's round puzzles).
- **Moderation waits for the reveal:** a secret puzzle (`PuzzleSecrecy` - either column ahead, and a competition's: in a round keeping it hidden everywhere, or unapproved; an approved placeholder hidden by hand is not) is out of the approval queue and its count, of possible duplicates, of the change-request and merge-review queues (and their detail pages), and `/admin/puzzles/{id}/edit|history` answer 404 to non-admin moderators. Approval and merges in either direction (`ApprovePuzzleMergeRequestHandler`) are refused for everyone (`PuzzleIsStillSecret`, 409) until the reveal; a change-request approval, a direct edit and a name suggestion (`PuzzleRecordUpdater`) are refused for everyone but an **admin** - an admin may correct a secret puzzle (a typo the organiser reports), its picture keeps a random file name.
- **No silent early reveal:** a scheduled reveal at or before now is refused ("use Reveal now"); a revealed round puzzle cannot be hidden again (no "Change reveal" for it, `RoundPuzzleAlreadyRevealed`); a round puzzle that was not secret becomes secret only before its round starts and while no other round shows the puzzle (`RoundPuzzleAlreadyShown`, `RoundPuzzleOwnership::sqlShownByAnotherRound()` - the form is offered only then); a round edit whose new start would reveal secret puzzles right away needs the "Yes, reveal them now" box, listing them; the add-puzzle form says when the round has already started.
- **Organiser control** on the round's puzzles page (`manage_round_puzzles`): per puzzle the EFFECTIVE truth (`RoundPuzzleStatus` - this round's reveal combined with the puzzle's site-wide columns: never "already public elsewhere" while the site hides it, never "Revealed" while it is still hidden on this event page, "another round keeps it hidden until then"), the exact moment in the round's zone with the zone named (`zoned_datetime()`, e.g. "Hidden everywhere until Saturday, October 24, 2026 at 8:15 AM (Chicago Time)"), "Change reveal" (what stays secret + automatic / own time typed in the round's zone / manual) and "Reveal now". The maintainer's picker also offers their own secret puzzles that are in no round any more.
- **Concurrency:** every handler changing a secret row or a round's start locks first and only then reads (`SecretPuzzleHides`), so the read-compute-write never works on a state another transaction is changing - always rounds first, then puzzles, each ordered by id, so two handlers never deadlock; then the entity manager is cleared (rows read afterwards are the committed ones, the caller re-reads its entities). A change of the round itself - edit, delete (also of an event or series), the internal API's "set puzzles" - takes its round rows `FOR NO KEY UPDATE` (`lockRoundsForChange()`) and reads the round's secret puzzles only then, so no secret row can join meanwhile; adding a puzzle, a reveal change, Reveal now, keep hidden everywhere and a removal take the round `FOR SHARE` (its start must not move; they may run side by side) and then the puzzle (`lockForAddingTo()`, `lockRoundPuzzle()`). Puzzles are locked `FOR NO KEY UPDATE` by plain SQL - never `FOR UPDATE`, which would also wait for every time or collection item inserted for the puzzle (their foreign key takes `FOR KEY SHARE`) - and only puzzles that have a secret row or are getting one. Merges lock their puzzles too. Chosen over `SerializedByLock`, which takes one key per message - a round edit touches every puzzle of the round.
- **A placeholder hidden by hand is no round's:** a puzzle hidden by hide dates while it is not a competition's (approved, no row hiding it everywhere - Ravensburger Puzzle Month) cannot be added secret to a round nor have a round's reveal changed (`PuzzleHiddenByHand`); "hides everywhere" for an existing puzzle follows `IsPuzzleKeptSecret`, never just a hide date.
- **Nothing public is hidden again:** a round edit pins automatic reveals that already happened (`CompetitionRoundPuzzle::pinRevealAt()`); merged rows never hide the survivor; Reveal now on a revealed or non-secret row is refused; a non-secret row shown on the event page does not turn secret (above); "Keep it hidden everywhere" works only while the site hides the puzzle now and that hide ends before this round's reveal (`KeepRoundPuzzleHiddenEverywhereHandler` checks the same `RoundPuzzleStatus::$elsewhereUntil` the button is shown for) - it extends a hide, it never starts one.
- **No early reveal without a yes:** removing a puzzle from a round, deleting a round and moving a round's start into the past show the puzzles they would reveal (`SecretRevealPreview` - also "on this event only" when another round keeps it hidden elsewhere) and need a tick bound to exactly that list (hash of each puzzle with how far it comes out - everywhere, or on this event until when); the flash says what came out. The internal API asks the same: `DELETE`/`PATCH /internal-api/rounds/{id}` and `PUT …/puzzles` answer 409 with `revealedPuzzles` unless the body says `"confirmReveal": true` - checked in the handler after its locks (`refuseToReveal`). An automatic reveal that is over already, like a past own time, is refused ("use Reveal now").
- **The status line is the truth** (`RoundPuzzleStatus`): "hidden on your event page until X, but elsewhere only until Y" when the site's hide ends sooner (prod right after the deploy, before the backfill) - with a one-click "Keep it hidden everywhere until X" when the puzzle is the competition's own (`RoundPuzzleOwnership`: unapproved, added by its organisers or created by the row); "another round keeps it hidden" only when one does.
- **Using a secret puzzle:** its organisers see it and prepare the event with it (round pages, a stopwatch, its codes); everybody else gets 404 (`SecretPuzzleAccess::assertPuzzleUsableBy()`, also for EAN linking and the marketplace page and filter). **Nothing personal is recorded on it before its name is revealed - by anybody, organisers and admins included:** times (the add form, relax mode, a saved stopwatch, API v1, moving a time onto it), collections, the wishlist, sell/swap listings, lending and borrowing (also through multiscan) are refused in the handlers (`SecretPuzzleAccess::assertWritableBy()`): `PuzzleNotFound` for whoever may not see it, `PuzzleNotRevealedYet` (409) for its organisers - "This puzzle is still secret until … – you can add it after the reveal.", shown as a flash, in the modal frame (`SecretPuzzleWriteRefusedSubscriber`), on the edit-time form or in the multiscan tray; the APIs answer 409. "Image only" keeps the name public, so it does not apply there. Admins may correct a secret puzzle (direct edit, change-request approval); moderators never see it, approval and merges wait for the reveal.
- Rows from before 2026-10: `reveal_mode` defaulted to `automatic` (= the old rule on the event pages, while the site-wide columns ended at the start itself). `myspeedpuzzling:backfill-round-puzzle-reveals` (dry run unless `--write`) marks the round puzzles that created their puzzle - unapproved, both UUIDv7 ids within 2 minutes - and reveal in the future as `hides_everywhere`, plus that puzzle's other future secret rows, re-syncs those puzzles, moves their guessable image names to random ones (the copy and the new name are committed first; the command deletes the old objects only after the transaction - a failed delete is logged), and lists every other puzzle hidden in the future for review. **Run it with the deploy** (dry run, then `--write`): until it has run, old rows have `hides_everywhere = false`, and moving such a round moves its event page only, not the puzzle's site-wide hide.

## Table Layout System

For **in-person events only**, organizers can plan the physical seating layout. The hierarchy is:

```
Round
  -> Table Rows  (e.g. "Row 1", "Row 2")
       -> Tables  (e.g. "Table 1", "Table 2", numbered globally)
            -> Spots  (individual seats, assignable to players)
```

### Generation

A form lets the organizer specify rows count (1-20), tables per row (1-20), and spots per table (1-10). Generating a layout **replaces the entire existing layout** for that round (destructive, no confirmation).

### Manual Editing

A Symfony Live Component provides real-time inline editing:
- Add/remove rows, tables, spots
- Assign a player to a spot via inline search (min 2 characters, up to 10 results)
- Assign a manual name (for participants not registered on the platform)
- Clear spot assignments
- Player and manual name are mutually exclusive on a spot

### Print View

A standalone, minimal HTML page (no base layout, print-optimized CSS) showing the full table grid. Empty spots show a blank line for handwriting. Opens in a new browser tab.

## Round Stopwatch

A real-time countdown/count-up timer for running competition rounds live.

### Server State

Each round tracks `stopwatchStartedAt` (UTC timestamp) and `stopwatchStatus` (`null` / `running` / `stopped`):

- **Not started** (`null`): only "Start" available
- **Running**: only "Stop" available
- **Stopped**: "Start" (resume) and "Reset" available

### Real-Time Sync via Mercure

Every state change (start, stop, reset) publishes an SSE event on topic `/round-stopwatch/{roundId}`. All connected browsers receive the update instantly.

### Client-Side Timer

A Stimulus controller handles the display:
- Computes server/client clock offset on page load for accurate timing
- Uses `requestAnimationFrame` for smooth `HH:MM:SS` rendering
- When elapsed time reaches the round's `minutesLimit`, the display shows "Time's up" (client-side only, no server event)
- Subscribes to Mercure SSE for real-time start/stop/reset events

### Two Views

- **Public view** (`/en/round-stopwatch/{roundId}`): large timer display, accessible to everyone — useful for projecting at events
- **Management view** (`/en/manage-round-stopwatch/{roundId}`): shows status + control buttons, requires edit permission

## Participant Management

`CompetitionParticipant` always belongs to a `Competition`. This means:
- **For standalone events**: participants belong to the competition, assigned to rounds via `CompetitionParticipantRound`
- **For series editions**: participants belong to the edition (which IS a Competition), completely independent from other editions

This eliminates all behavioral branching — the same participant handlers, queries, and components work for both standalone events and series editions.

**Full specification:** See [participants.md](participants.md) for the complete participant management design including:
- Unified "I'm going" + pairing flow (replaces old `CompetitionConnectionController`)
- Organizer management UI with inline editing (Live Component)
- Excel import/export with upsert logic
- Soft delete mechanism
- Secret/private player handling fix
- Replaces admin-only import routes (`/admin/import-competition-puzzlers`)

## Email Notifications

Three email notifications are sent during the competition lifecycle:

1. **New submission (to admin):** When a player submits a new competition, an email is sent to `jan.mikes@myspeedpuzzling.com` with the event name, location, submitter name, and a link to the admin approval queue.
2. **Approved (to creator):** When an admin approves a competition, the creator receives an email with a link to their public event page. Sent in the creator's locale.
3. **Rejected (to creator):** When an admin rejects a competition, the creator receives an email with the rejection reason. Sent in the creator's locale.

All emails use the `transactional` mailer transport and follow the standard Inky email template structure.

## Key Business Rules

1. **Unapproved competitions are invisible** in public listings but accessible to their maintainers
2. **Table layout is only for in-person events** — the tables button is hidden when `isOnline = true`
3. **Layout generation is destructive** — it wipes the entire existing layout before creating a new grid
4. **`times_up` is client-only** — the server does not track when time expires; it's purely a display state
5. **New puzzles created via round assignment need separate approval** — they are created with `approved = false`
6. **Puzzle hiding is competition-scoped** — `CompetitionRoundPuzzle` flags control visibility only on competition pages; the `Puzzle` entity is never modified
7. **External links get automatic UTM tracking** — `utm_source=myspeedpuzzling` is appended
8. **Rejected competitions are excluded from the approval queue** — they no longer appear as "pending"
9. **Email notifications require creator to have an email** — if the creator has no email on their profile, no notification is sent (no error)
10. **Series get their own listing section** — `CompetitionSeries` appear in a dedicated "Recurring" section; editions are excluded from Live/Upcoming/Past
11. **Online and offline are mutually exclusive** — one competition cannot be both; users create separate events. Both types can be recurring.
12. **Series editions don't need individual approval** — the series approval controls visibility for all editions
13. **Series maintainers manage all editions** — `GetCompetitionPermissions` (behind `CompetitionEditVoter`) counts series owners and maintainers for edition-level operations. It loads everything the player may manage in one query per request, because the event listings ask the voters about every card they render
14. **Each edition is a full Competition** — has its own participants, rounds, registration/results links
15. **Editions never auto-create rounds** — the edition form creates only the Competition, rounds are always managed separately via the round management UI
16. **Round category defaults to solo** — existing rounds get `solo` category via migration default
18. **Teams are scoped to rounds** — `CompetitionTeam` belongs to a `CompetitionRound`, participants are assigned to teams via `CompetitionParticipantRound.team_id`
19. **A solving time can be linked to any publicly visible competition row** — the add/edit-time picker offers every approved & not-rejected standalone competition (any date) and every edition of an approved & not-rejected series (`IsCompetitionPubliclyVisible::SQL_CONDITION`), never the series umbrella itself; the edit form additionally keeps the currently linked competition selectable; the submitted id is validated against exactly that set
