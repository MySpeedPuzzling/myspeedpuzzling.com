# Competitions Management

Community-driven competition and event management. Any logged-in player can submit a competition; it becomes publicly visible after admin approval. Maintainers (the creator + named co-maintainers) can then manage rounds, assign puzzles, plan table layouts, run a live stopwatch during the event, manage registrations, enter official results, and compose the public page.

The feature set is **tiered and opt-in**: a competition with everything off is just a listing with the lightweight "I'm going" flow. Each capability is enabled separately:

| Capability | How it's enabled | Docs |
|-----------|------------------|------|
| Managed registration (capacity, reserved/paid, waitlist, check-in) | "Manage registration on MySpeedPuzzling" on the event's own Registration page (`manage_competition_registration`) | [registration.md](registration.md) |
| Official round results (live entry, results desk, qualification and advancing, seating, publishing) | Recorded by the organiser per round, published per round | [official-results.md](official-results.md) |
| Custom public page content (rich text, FAQ, gallery, venue, sponsors, links, contact) | "Page content" on the event/edition edit page or the series management page - a page shows nothing new until a section is added | [public-page.md](public-page.md) |
| Participant management, import/export, pairing | Always available | [participants.md](participants.md) |

One permanent product boundary: **MySpeedPuzzling never processes payments.** Managed registration only records the organizer's payment confirmation ("mark paid") — collecting entry fees is entirely the organizer's responsibility.

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

**A rename never changes the URL (slug)** - not in the web forms (event, edition, series) and not in the internal API (`PATCH /internal-api/competitions/{id}`, [internal-api.md](../internal-api.md#competitions-and-events)): published links and search engines know the event by it. The address changes only on purpose: the event/edition and series edit forms have a **"URL" field** (`CompetitionFormType` option `url_field`, never on the add form - the first slug comes from the name) pre-filled with the current slug behind the real route's prefix (`myspeedpuzzling.com/en/events/`, `…/en/series/{seriesSlug}/`, `…/en/series/`). What is typed is normalised like a generated slug ("My New URL" → `my-new-url`, always transliterated in English; a pasted full address keeps only its last part) and must be free in its scope - any competition for a standalone event, the series' editions and the standalone events for an edition (`/en/events/{slug}` resolves a slug shared with an older edition to the standalone event), the other series for a series (`CompetitionUrlField` → `CompetitionSlugGenerator::isTaken()` / `isSeriesSlugTaken()`, the same checks as the handlers and the API's explicit `slug`); a clash or an empty/unusable URL is a form error (422), a clash found by the handler (`CompetitionSlugTaken`, another save in between) too. Old URLs do not redirect - the help text says so. Maintainer lists are fully replaced on each save (clear + re-add).

The edit forms show the current logo above the file input ("Leave empty to keep the current logo") - the handlers keep the stored logo unless a new file is uploaded.

### 4. Public Listing

The events page shows four sections:
- **Live** — one-time events where today's date falls within the event date range
- **Upcoming** — one-time events starting in the future
- **Recurring** — all approved recurring events (sorted alphabetically)
- **Past** — one-time events that have ended

Recurring events are excluded from Live/Upcoming/Past sections. All sections only show approved competitions. External links (website, registration, results) automatically get `utm_source=myspeedpuzzling` appended. Online and recurring badges are displayed on event cards. An online event never prints its location (older online events still carry location "Online" in the data - no migration): the event cards, "My events", the event page header and the admin approval queue show the Online badge instead; the event page JSON-LD of an online event is a `VirtualLocation`, never a `Place`. Recurring series cards display the next upcoming edition date (derived from the nearest future round's `starts_at` across all editions).

Each competition also appears in "My Competitions" for its creator/maintainers regardless of approval status.

## Access Control

| Action | Who |
|--------|-----|
| Browse public events listing | Everyone |
| Submit a new competition | Any authenticated player |
| Edit competition & manage rounds/tables/stopwatch | Admin, original creator, or named maintainer |
| Manage registrations (mark paid, promote, check-in) | Admin, creator, or maintainer |
| Record/publish official round results, qualify, advance, seat | Admin, creator, or maintainer |
| Enter results in the live entry (live entry pages, name-tag QR, round state, result changes only) | Admin, creator, maintainer, or one of the event's **referees** |
| Add/remove referees | Admin, creator, or maintainer |
| Edit public page content | Admin, creator, or maintainer (series voter for series pages) |
| View public stopwatch page | Everyone (no auth required) |
| View published official results (round results page) | Everyone, while the competition is publicly visible |
| Approve or reject a competition | Admin only |

Access is enforced via a `CompetitionEditVoter` that checks whether the player is admin, the creator, or in the maintainers list. All management controllers use this same voter, including round-level controllers (which resolve the competition from the round).

**Referees** (`CompetitionReferee`, per competition - an edition is a competition) are volunteers who enter results on their phones and nothing else: `CompetitionResultsEntryVoter` (`COMPETITION_RESULTS_ENTRY` = everybody with `COMPETITION_EDIT` plus the referees) guards only the live entry, its round state and result changes; a referee's table number and qualified changes are refused. Organisers add them on the event's Referees page (linked from the edit page and the results overview), which also shows the link for referees with a QR. Details: [live-results.md](live-results.md) "Referees".

## Event Types

**Online and offline are never combined** — a competition is either fully online or fully offline. Users must create separate competitions for each format. The "Recurring event" checkbox is available for both online and offline events. Date fields (dateFrom/dateTo) are shown for non-recurring events — they are hidden when recurring is selected (toggled via `competition-form` Stimulus controller's `offlineFields`, `dateFields`, and `recurringField` targets).

**An online event keeps its dates.** Saving an online event clears its location only - never dateFrom/dateTo (web add/edit forms for standalone events and editions, internal API). The dates are required for an in-person one-time event only; for an online event they are optional - an ongoing standalone one leaves them empty. The form drops the required marker of the date labels and says so under them when "Online" is picked (`dateLabel` / `onlineDatesHelp` targets). Until 2026-10 every save of an online event (so of every edition of an online series) silently wiped its dates.

**A series has no registration or results link** - those belong to its editions. With "recurring" ticked the add form hides both fields and shows "Registration and results links are set for each edition separately" in their place (`editionLinks` / `editionLinksNote` targets); the server ignores them for a series. The series edit form leaves the date, registration/results and recurring fields out (`CompetitionFormType` option `series`), so an in-person series is never asked for dates.

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
- Description and website ("Info") link (optional, the link validated as a URL of at most 250 characters; blank = none) - the same fields the full event edit form has, so a new edition needs no second edit
- Registration link, results link (optional)
- After creation, organizer adds rounds from the edition management page

**Public series page** (`/en/series/{slug}`):
- Series header with name, description (plain text, line breaks kept), logo, website link, badges
- Upcoming editions as cards (2-column grid on desktop, single column on mobile, `_series_edition_card.html.twig`): name, date with relative time, time limit, puzzle count, participant count, registration link, and the edition's **own** logo on the right when it has one (no series logo repeated on every card - it is in the header)
- Past editions as cards (same layout): with results link instead of registration link
- Each edition card links to the edition detail page
- **No edition is ever hidden.** An edition is dated by its first round, else by its own `date_from` (shown as a date range when it has no round yet). One with neither - no date and no rounds, e.g. a draft or a duplicate - is listed **with the upcoming editions, last**, labelled "Date not set" (`edition.date_not_set`) - on this page and on the organiser's management page (`manage_competition_series`, `_series_editions_table.html.twig`, where it keeps its edit and delete buttons so it can be fixed or removed). `GetCompetitionSeries::fetchEditions()`; `SeriesEdition::isUndated()`. Such an edition is left out of the series `EventSeries` JSON-LD (`subEvent` needs a `startDate`); `subEvent` carries an edition's own logo as `image`.

**Public edition detail page** (`/en/series/{seriesSlug}/{editionSlug}`):
- Edition header with link back to series; the edition's own logo, else the series logo (the JSON-LD `image` follows the same rule)
- Links: Info (the edition's own website link), Register, Results, Add my time - external ones with `utm_source=myspeedpuzzling` like everywhere
- The edition's description below the header, as plain text: `{{ description|nl2br }}` (Twig escapes before adding `<br>`; never `|raw`, no linkifying) - the standalone event page shows its description the same way
- "Date not set" when the edition has neither a date nor a round
- Each round heading shows its category pill - Solo too
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
- **Badge colour** — see "Round badge" below

Rounds are displayed sorted by start time. Each round can be edited or deleted. The round list shows each round's badge and its category pill (Solo too, like Pair and Team) and action buttons for: Puzzles, Teams (for duo/team rounds only), Tables (only for in-person events), Stopwatch, Edit, Delete.

### Round badge

The round's name is shown on a badge in the round's colour wherever the round appears (event / edition page, round results, the participants list's round chips, the organiser's round list) - one rule, `RoundBadgeColor`:
- **Background** = the organiser's colour (`competition_round.badge_background_color`, any `#rgb`/`#rrggbb`), else a distinct palette colour by the round's position in the schedule (by start, ties by id - `GetEditionRounds`, `GetCompetitionRounds` and `GetCompetitionRoundsForManagement` order alike). `#fe696a`, what the form pre-filled until 2026-10 and most rounds still store, counts as no colour (`RoundBadgeColor::chosen()`).
- **Text** = black or white, whichever contrasts more (WCAG luminance) - always automatic. The round form asks only for the colour (help text says the text colour is picked for readability; empty = automatic, Coloris "Automatic color" button empties it, no `#fe696a`/white swatch). `competition_round.badge_text_color` stays (blue-green, no migration): the web form stores `RoundBadgeColor::textForChosen()` (null without a colour), the internal API stores what it is sent, nothing reads it for display - the organiser's round list uses the same computed colours as the public pages.
- **Live preview** on the add/edit round form (`competition/_round_badge_field.html.twig`, `round_badge_preview_controller.js` on the form): the typed name on the chosen colour with the automatic text colour, or on the automatic colour with a "picked automatically" note. The browser half of the rule is `assets/round_badge_color.js`, kept identical to the PHP one by `RoundBadgeColorParityTest` (node); the server hands it the automatic colour (`round_badge()` Twig function, from the round's schedule position - a new round is assumed last).

### Start time and time zone

The organiser types the **local** start (a one-day event asks only for the time, `CompetitionEvent::singleDay()`) and picks the time zone. `competition_round.starts_at` stores the **instant in UTC**; `competition_round.timezone` keeps the zone it was typed in (`RoundTimezone` is the one place for both conversions, `CompetitionRoundFormData::fromCompetitionRound()` / `startsAtInstant()`):
- The edit form pre-selects the round's own zone and shows the same local time - saving an untouched form never moves a round. A new round pre-selects the zone of the event's other rounds, else the default of the event's country (`CountryCode::defaultTimezone()`).
- Every page shows a round's start in its zone (`|date(format, round.timezone)` - read models carry the resolved zone), the round pages name the zone, so a Wisconsin event shows Chicago time to everybody. The zone select shows each zone's offset on the round's date.
- A typed time that does not exist exactly once in the zone (skipped or repeated by a daylight-saving change, or overflowing like 31.02. 25:70) is refused with a form error (`RoundTimezone::parseLocal()`).
- A one-day event asks for the time only when the round is on the event's day in its zone; a round on another local day gets the full date and time, so an untouched save never moves it by a day.
- Rounds saved before 2026-10 have no zone (`NULL`): they are read in the default zone of the event's country, else its series' country - the zone the form pre-selected then. `myspeedpuzzling:backfill-round-timezones` (dry run unless `--write`, run on prod 2026-10-07) saves exactly that zone on them (`CompetitionRound::saveDisplayedTimezone()`) - nothing shown changes - and lists starts that look moved by the old bug (at night, or outside the event's dates) for checking by hand.
- With no country either (an online series - Ou La La Puzzles) the zone is only the fallback `Europe/Prague` - not saved, or saved as the `Europe/Prague` the form pre-selects for such an event (`RoundTimezone::isAssumed()`, carried as `timezoneAssumed` by `EditionRoundDetail` / `CompetitionRoundForManagement`): `timezone_name(zone, assumed)` names it without a place ("Central European Time", ICU long generic name) instead of "Czechia Time", on the round results page, the organiser's round list and the edition page; the round's edit form says no zone was saved yet while none is (`timezone_assumed` form option, `CompetitionRound::isTimezoneNeverSaved()`). The same holds for every other naming of the zone - the round puzzles page (start, reveal times, the reveal form), the add-puzzle help, reveal confirmations, round flashes and the "still secret" refusal (`CompetitionRound::isTimezoneAssumed()`, `PuzzleNotRevealedYet::$timezoneAssumed`). A zone the organiser picks other than the fallback is named by its place ("Toronto Time").
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
- Rename a team, or name an unnamed one (pencil on the team card, `RenameCompetitionTeam`; empty = unnamed, max 255 characters). Two teams of one round may share a name - different groups really do; every such card says so and the assign dropdown adds the members' names to tell them apart
- Delete teams - a team with members can be deleted: its members go back to "unassigned" in that round, nothing else about them changes (`DeleteCompetitionTeamHandler`, one transaction; the confirmation says how many). This includes removed (soft-deleted) participants, who keep their round entries and team while hidden from the page - such a team used to look empty and fail to delete (Sentry WEB-D5, 2026-10-07)
- View unassigned participants
- Every form carries the page's CSRF token (`ManageRoundTeamsController::csrfTokenId()`, one per round); a team is assigned only within its own round

**Import/Export**: The Excel import reads optional `round_names` (comma-separated, what the template and the export write; the old single `round_name` still works) and `team_name` columns, plus what the export adds: one `team_name: <round>` column per duo/team round and `participant_id`, so an export imported back changes nothing. Participants are added to every listed round (several rows of one person add up), and for duo/team rounds teams are created or matched by name. An upload (`.xlsx`, `.csv`, `.tsv`, `.txt`) first goes to a **preview** - sheet, column mapping, exactly what will change, warnings - and nothing is written before the organiser confirms; *Update only* never removes anything, *Full sync – the file is the truth* also removes participants, round entries and emptied pairs/teams the file does not have (never anybody with results). See [participant-import-preview.md](participant-import-preview.md) and `participants.md` §Excel Import.

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
- **Every surface obeys the columns:** search, brand picker, barcode lookup, brand/pieces hubs, sitemap, API v1, the event's tag list (`GetPuzzleOverview::byTagId` and the "used at" tag branch apply the round rules too). Every page of one puzzle (detail, suggest a change, report a duplicate, QR, stopwatch / add time, a saved stopwatch, the marketplace filter) answers **404** while a competition keeps its name hidden - except for admins, the puzzle's adder and maintainers of a competition with it in a round (`SecretPuzzleAccess`; an approved placeholder hidden by hand, the Ravensburger Puzzle Month box link, still opens). The pages and actions that show or set its **codes** (suggest a change, report a duplicate, the admin edit and history, linking an EAN in multiscan, adding it to another competition's round) are strict: they count a hidden picture too (`alsoWhileImageHidden`). While the picture is hidden its **EAN and brand code** are blank everywhere (`PuzzleOverview::fromDatabaseRow($row, $now)`, the brand picker) and no code search finds it (`PuzzleTextSearch`, `allByEan`); the duplicate-code guard still sees it. A new secret puzzle's image gets a random file name (`PuzzleImageNamer::secretFilename()`), and so does an older one the moment it becomes secret on the whole site (`SecretPuzzleHides::hideGuessableImage()`, on every re-sync - "Keep it hidden everywhere", adding to a round, the backfill): copied to the random name inside the transaction, the old object deleted only after the commit (`DeleteObsoletePuzzleImage`, async, never while anything still references it). The image caches (images-cache nginx 365 days, Cloudflare on img.*) may still serve the old name - the backfill prints the exact purge list (`ImageCachePurgeList`). Brand pickers count visible puzzles only and leave out a brand whose every puzzle is secret (the add-to-round form keeps the brands of its own competition's round puzzles).
- **Moderation waits for the reveal:** a secret puzzle (`PuzzleSecrecy` - either column ahead, and a competition's: in a round keeping it hidden everywhere, or unapproved; an approved placeholder hidden by hand is not) is out of the approval queue and its count, of possible duplicates, of the change-request and merge-review queues (and their detail pages), and `/admin/puzzles/{id}/edit|history` answer 404 to non-admin moderators. Approval and merges in either direction (`ApprovePuzzleMergeRequestHandler`) are refused for everyone (`PuzzleIsStillSecret`, 409) until the reveal; a change-request approval, a direct edit and a name suggestion (`PuzzleRecordUpdater`) are refused for everyone but an **admin** - an admin may correct a secret puzzle (a typo the organiser reports), its picture keeps a random file name.
- **No silent early reveal:** a scheduled reveal at or before now is refused ("use Reveal now"); a revealed round puzzle cannot be hidden again (no "Change reveal" for it, `RoundPuzzleAlreadyRevealed`); a round puzzle that was not secret becomes secret only before its round starts and while no other round shows the puzzle (`RoundPuzzleAlreadyShown`, `RoundPuzzleOwnership::sqlShownByAnotherRound()` - the form is offered only then); a round edit whose new start would reveal secret puzzles right away needs the "Yes, reveal them now" box, listing them; the add-puzzle form says when the round has already started.
- **Organiser control** on the round's puzzles page (`manage_round_puzzles`): per puzzle the EFFECTIVE truth (`RoundPuzzleStatus` - this round's reveal combined with the puzzle's site-wide columns: never "already public elsewhere" while the site hides it, never "Revealed" while it is still hidden on this event page, "another round keeps it hidden until then"), the exact moment in the round's zone with the zone named (`zoned_datetime()`, e.g. "Hidden everywhere until Saturday, October 24, 2026 at 8:15 AM (Chicago Time)"), "Change reveal" (what stays secret + automatic / own time typed in the round's zone / manual) and "Reveal now". The maintainer's picker also offers their own secret puzzles that are in no round any more.
- **Concurrency:** every handler changing a secret row or a round's start locks first and only then reads (`SecretPuzzleHides`), so the read-compute-write never works on a state another transaction is changing - always rounds first, then puzzles, each ordered by id, so two handlers never deadlock; then the entity manager is cleared (rows read afterwards are the committed ones, the caller re-reads its entities). A change of the round itself - edit, delete (also of an event or series), the internal API's "set puzzles" - takes its round rows `FOR NO KEY UPDATE` (`lockRoundsForChange()`) and reads the round's secret puzzles only then, so no secret row can join meanwhile; adding a puzzle, a reveal change, Reveal now, keep hidden everywhere and a removal take the round `FOR SHARE` (its start must not move; they may run side by side) and then the puzzle (`lockForAddingTo()`, `lockRoundPuzzle()`). Puzzles are locked `FOR NO KEY UPDATE` by plain SQL - never `FOR UPDATE`, which would also wait for every time or collection item inserted for the puzzle (their foreign key takes `FOR KEY SHARE`): the secret puzzles of changed rounds, and every puzzle being attached, secret or not (a non-secret attach decides whether another row may still turn secret). Merges lock their puzzles too. Chosen over `SerializedByLock`, which takes one key per message - a round edit touches every puzzle of the round.
- **A placeholder hidden by hand is no round's:** a puzzle hidden by hide dates while it is not a competition's (approved, no row hiding it everywhere - Ravensburger Puzzle Month) cannot be added secret to a round nor have a round's reveal changed (`PuzzleHiddenByHand`); "hides everywhere" for an existing puzzle follows `IsPuzzleKeptSecret`, never just a hide date.
- **Nothing public is hidden again:** a round edit pins automatic reveals that already happened (`CompetitionRoundPuzzle::pinRevealAt()`); merged rows never hide the survivor; Reveal now on a revealed or non-secret row is refused; a non-secret row shown on the event page does not turn secret (above); "Keep it hidden everywhere" works only while the site hides the puzzle now and that hide ends before this round's reveal (`KeepRoundPuzzleHiddenEverywhereHandler` checks the same `RoundPuzzleStatus::$elsewhereUntil` the button is shown for) - it extends a hide, it never starts one. A name already public ("image only") is never hidden "entirely" again - neither by changing a round's reveal nor by adding the puzzle to another round (`PuzzleNameAlreadyPublic`): times, collections and listings already show it.
- **No early reveal without a yes:** removing a puzzle from a round, deleting a round and moving a round's start into the past show the puzzles they would reveal (`SecretRevealPreview` - also "on this event only" when another round keeps it hidden elsewhere) and need a tick bound to exactly that list (hash of each puzzle with how far it comes out - everywhere, or on this event until when); the flash says what came out. The web yes is re-checked after the handler's locks too: the controller passes the hash it confirmed (`confirmedRevealHash` on the removal, round delete and round edit messages - also the hash of an empty list), the handler recomputes the list and refuses a different one (`SecretPuzzlesWouldBeRevealed` - the page asks again with the new list). The internal API asks the same: `DELETE`/`PATCH /internal-api/rounds/{id}` and `PUT …/puzzles` answer 409 with `revealedPuzzles` unless the body says `"confirmReveal": true` - checked in the handler after its locks (`refuseToReveal`). An automatic reveal that is over already, like a past own time, is refused ("use Reveal now").
- **The status line is the truth** (`RoundPuzzleStatus`): "hidden on your event page until X, but elsewhere only until Y" when the site's hide ends sooner (prod right after the deploy, before the backfill) - with a one-click "Keep it hidden everywhere until X" when the puzzle is the competition's own (`RoundPuzzleOwnership`: unapproved, added by its organisers or created by the row); "another round keeps it hidden" only when one does.
- **Using a secret puzzle:** its organisers see it and prepare the event with it (round pages, a stopwatch, its codes); everybody else gets 404 (`SecretPuzzleAccess::assertPuzzleUsableBy()`, also for EAN linking and the marketplace page and filter). **Nothing personal is recorded on it before its name is revealed - by anybody, organisers and admins included:** times (the add form, relax mode, a saved stopwatch, API v1, moving a time onto it), collections, the wishlist, sell/swap listings, lending and borrowing (also through multiscan) are refused in the handlers (`SecretPuzzleAccess::assertWritableBy()`): `PuzzleNotFound` for whoever may not see it, `PuzzleNotRevealedYet` (409) for its organisers - "This puzzle is still secret until … – you can add it after the reveal.", shown as a flash, in the modal frame (`SecretPuzzleWriteRefusedSubscriber` - never for the APIs, matched by route and decoded path), on the add-time and edit-time forms (422, everything typed and the photos kept) or in the multiscan tray; the APIs answer 409. Organisers are told up front where they would time or save it (the add-time form, the stopwatch: "you can save times after the reveal"); the round's puzzles page tells them that participants log their times only after the reveal (manual and own reveal times, a hidden row of a round that has started), and a round's results page leaves out a puzzle another round still keeps secret and says when it opens (`GetRoundPuzzlesHeldElsewhere`). A stopwatch on a puzzle hidden from its owner shows no puzzle, in the list too. Every route taking a puzzle id is exercised in `SecretPuzzleRoutesTest` or listed with a reason in `SecretPuzzleRouteCanaryTest`. "Image only" keeps the name public, so it does not apply there. Admins may correct a secret puzzle (direct edit, change-request approval); moderators never see it, approval and merges wait for the reveal.
- Rows from before 2026-10: `reveal_mode` defaulted to `automatic` (= the old rule on the event pages, while the site-wide columns ended at the start itself). `myspeedpuzzling:backfill-round-puzzle-reveals` (dry run unless `--write`) marks the round puzzles that created their puzzle - unapproved, both UUIDv7 ids within 2 minutes - and reveal in the future as `hides_everywhere`, plus that puzzle's other future secret rows, re-syncs those puzzles (which moves their guessable image names to random ones - the old objects go after the commit, async), lists what is already recorded on them (times, collection items, wishlist items, listings, loans), the round puzzles left secret on their event page only (image-only ones marked), the cache purge commands for the old image names, and lists every other puzzle hidden in the future for review. **Run it with the deploy** (dry run, then `--write`): until it has run, old rows have `hides_everywhere = false`, and moving such a round moves its event page only, not the puzzle's site-wide hide.

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
- Excel/CSV import (preview, column mapping, update-only or full sync) and export
- Soft delete mechanism
- Secret/private player handling fix
- Replaces admin-only import routes (`/admin/import-competition-puzzlers`)

## Official Round Results

The organiser's record of a round: one result per round entry (a person of a solo round, a pair/team of a pair/team round) - a time, pieces placed, or did not start - plus the qualified mark and the table number, written through one change-set write path (`RecordRoundResults`, three-way checked, offline-safe). Qualified entries are advanced into later rounds explicitly (`AdvanceQualified`), and a round's results are published per round on its round results page, where they lead the page (ranked, hidden players dropped without renumbering, private players by the organiser's name only) and the times puzzlers added fold below them. Players' own times stay theirs: nothing is copied onto profiles - "Add to my profile" only fills the normal add-time form in (puzzle, time, date, event, pair/team members and name) from a finished entry the player is linked to, or - for an entry nobody is linked to - a solo entry with the player's name, a pair/team entry with a member of the player's name, or a pair/team typed by its name only, and every first-try, duplicate and privacy rule of the add form applies. Organisers enter results on the live entry (phones, offline-safe, referees allowed - [live-results.md](live-results.md)), the results desk ([results-desk.md](results-desk.md)) and seat entrants by table number ([seating.md](seating.md)). Full design: [official-results.md](official-results.md).

Seating - table numbers before each in-person round, auto-assign by earlier rounds or MySpeedPuzzling times, printed lists: [seating.md](seating.md).

The organiser's results desk per round, the results overview of the whole event (the control room on the day) and advancing the qualified: [results-desk.md](results-desk.md).

## Managed Registration

Opt-in per event or edition on its own Registration page (`ChangeCompetitionRegistrationSettings` - `EditCompetition` and the internal API never touch it). While it is on, the "I'm going" block (`_event_attendance.html.twig`) is a registration card (spots, waitlist, entry fee, window in the event's zone) and "I'm going" becomes a confirmed registration: reserved under the capacity, waitlisted when full (first come, first served, under the participants lock), only while the event is publicly visible and its window is open. Organisers mark payments, give waitlisted people a spot and check people in on the day; MySpeedPuzzling never processes payments. A waitlisted row is not "going" anywhere (`CompetitionParticipantGoing`). Full design: [registration.md](registration.md).

## Public Page Content

Maintainers add content sections (rich text via Quill, FAQ, gallery, venue, sponsors, links, contact) to an event, edition or series page. They appear in one place, right after the description, in the order the maintainer sets (drag or move up/down), each one can be hidden; an edition shows its own sections, then its series'. The rest of the page is not reorderable. A page without a visible section renders and queries exactly as before (`CompetitionEvent::$hasPageSections`). Sections show only on publicly visible (approved) pages, with quotas (30 sections per page, 40 pictures per gallery/sponsors, 60 uploads an hour per player). All content is sanitised server-side. Full design: [public-page.md](public-page.md).

## Email Notifications

Email notifications sent during the competition lifecycle:

1. **New submission (to admin):** When a player submits a new competition, an email is sent to `jan.mikes@myspeedpuzzling.com` with the event name, location, submitter name, and a link to the admin approval queue.
2. **Approved (to creator):** When an admin approves a competition, the creator receives an email with a link to their public event page. Sent in the creator's locale.
3. **Rejected (to creator):** When an admin rejects a competition, the creator receives an email with the rejection reason. Sent in the creator's locale.
4. **Registration confirmed / waitlisted (to player):** on managed registration, with entry fee and payment instructions, or waitlist position.
5. **Payment confirmed / promoted from waitlist (to player):** when the organizer marks them paid or promotes them.

All emails use the `transactional` mailer transport and follow the standard Inky email template structure. Player-facing emails are sent in the player's locale and only when an email address exists.

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
20. **MSP never processes payments** — managed registration only records the organizer's manual payment confirmation
21. **Managed registration keeps the external registration link** — saved as it is, hidden on every page while registration is managed (one way to register), back when management is switched off
22. **Official results are the organiser's record** — stored on the round entry (`CompetitionParticipantRound` / `CompetitionTeam`), never written onto players' profiles
23. **Official data never disappears as a side effect** — an entry with a result or a qualified mark is never removed by taking somebody out of a round, deleting a pair/team, removing a person from the event, an import or leaving the event (the player is only disconnected)
24. **Draft results are private** — public only on the round results page after the round is published; the first publish tells the players with a finished result once (in-app notification)
