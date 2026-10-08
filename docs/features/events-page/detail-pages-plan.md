# Series, edition and event pages - implementation plan

The build contract for [detail-pages.md](detail-pages.md) (design of record, Jan's decisions of 2026-10-08). One
foundation agent builds the shared parts, the read-model changes, the builders and working skeleton pages; then three
agents (A series page, B edition + event pages, C cleanup/SEO/tests/docs) work in parallel worktrees, each owning a
fixed set of files. Every class, route and file name below is binding - if a workstream needs a change to a file it
does not own, it writes it into its final report (or asks the integrator) instead of editing it.

Binding rules from `CLAUDE.md`: CQRS through Messenger (nothing here writes), single-action controllers,
`ClockInterface`, `ResetInterface` for per-request caches, every user text in translation files (all 6 locales before
the PR), `json_ld` for every value in a script element, `ReturnUrl::tryFrom()` / `safe_return_url()` for every
`return`, Turbo form rules (no 200 to a full-page POST, explicit `action` inside frames), logged exceptions with
`'exception' => $e`. **The repo is public: made-up event and person names only** - in code, fixtures, tests, docs and
commit messages (`grep` the diff for the names of real organisers before every merge).

## 0. Ground rules for all agents

- **Stacking.** The sessions fix (branch `events-live-sessions`: `OccurrenceDates::sessions()`, `OccurrenceRounds`,
  `OccurrenceSession`, `EventUrls::occurrence()` with `#round-<id>`, the Live badge, "Ongoing") is **merged to `main`
  first**. This work starts from that `main`. Before starting, the foundation agent re-reads `OccurrenceDates`,
  `OccurrenceRounds`, `EventOccurrence`, `EventsPageBuilder`, `EventUrls` and the two page templates on `main` - review
  fixes may have renamed something since this plan was written; a renamed member is followed, not reintroduced.
- Integration branch `events-detail-pages`, worktree `.claude/worktrees/ev2-detail`. Foundation commits there.
  Workstreams branch from the foundation commit: `git worktree add .claude/worktrees/ev2-<x> -b ev2-<x>
  events-detail-pages` with `<x>` = `series` (A), `rounds` (B), `cleanup` (C).
- Run PHP with `.claude/worktrees/ev2-tools/run.sh <worktree> <db-suffix> …`, the gates with
  `.claude/worktrees/ev2-tools/gates.sh <worktree> <db-suffix>` (own test DB `speedpuzzling_ev2<suffix>_test`; suffixes
  `f`, `a`, `b`, `c`). Never touch the shared checkout or the dev DB; never drop a DB whose name does not end in
  `_test`; `docker run --rm` only (no leaked volumes).
- Gates before every commit: cache warmup, phpstan, phpcs (cs-fix), schema validate, `--testsuite "Project Test
  Suite"`; JS: `node --check` on changed files and the node harness tests (`EventsIndexScriptTest`,
  `EventsCalendarScriptTest`).
- CSS: every new class is prefixed `ev-` (Bootstrap owns `.row`, `.card`, `.badge`). Stimulus controllers not needed
  for first paint start with `/* stimulusFetch: 'lazy' */`.
- No feature flag, no migration, no new route: the three pages change in one release, their URLs stay.
- Measure before claiming: statement budgets are measured with `QueryCountAssertions` and pinned.

## 1. Foundation (one agent, first - worktree `ev2-detail`, DB suffix `f`)

### 1.1 Shared parts under shared names (the events page must not change)

Move with `git mv`, then update every include (`grep -rn "events/_<name>.html.twig" templates src tests`):

| From `templates/events/` | To `templates/event_parts/` | Change while moving |
|---|---|---|
| `_date_leaf.html.twig` | `_date_leaf.html.twig` | none |
| `_row.html.twig` | `_row.html.twig` | renders `row.time` when set: `{{ include('event_parts/_event_time.html.twig', {time: row.time, online: row.place.isOnline}) }}` in `.ev-row-meta` |
| `_row_tags.html.twig` | `_row_tags.html.twig` | none |
| `_when.html.twig` | `_when.html.twig` | none (the new `today` type renders through `translationKey`) |
| `_place.html.twig` | `_place.html.twig` | none |
| `_archive_line.html.twig` | `_archive_line.html.twig` | none |
| `_row_actions.html.twig` | `_row_actions.html.twig` | none |
| `_follow_star.html.twig` | `_follow_star.html.twig` | optional `labelled: bool` (default false): a visible label in `<span data-follow-text>` ("Follow series" / "Following series", from new optional `text_follow` / `text_following`), class `ev-star-labelled`, **no `aria-label`** in that variant (the text is the name); guests get the same labelled link |
| `_manage_button.html.twig` | `_manage_button.html.twig` | optional `delete_return` (passed on as a query parameter of `event_manage_menu`) |
| `_manage_menu_host.html.twig` | `_manage_menu_host.html.twig` | variable `page` → `show_menu: bool`; `events.html.twig` passes `show_menu: page.organizedCount > 0 or is_granted('ADMIN_ACCESS')` |

New in `templates/event_parts/`: `_live.html.twig` ("Live" with `.live-dot`, `aria-hidden` dot - `_when.html.twig`
uses it), `_event_time.html.twig` (1.4), `_crumbs.html.twig` (1.6), `_detail_header.html.twig` (1.6),
`_event_json_ld.html.twig` and `_series_json_ld.html.twig` (1.6). The ⋯ menu's own templates
(`events/_manage_menu*.html.twig`, `events/_manage_items.html.twig`, `events/manage_menu.html.twig`) stay where they are.

SCSS: new `assets/styles/_event-parts.scss`, imported in `app.scss` **before** `events-page`. It takes over from
`_events-page.scss` the tokens (`$ev-*`), the mixins (`ev-stretched-link`, `ev-hit-area`), the focus ring (now
`.ev-page, .ev-detail { … }`), `.ev-leaf*`, `.ev-row*`, `.ev-month-header`, `.ev-month-n`, `.ev-place*`, `.ev-when*`,
`.ev-sessions`/`.ev-session`, `.ev-tags`/`.ev-tag*`, `.ev-lines`/`.ev-line*`, `.ev-more`; and from
`_events-organizer.scss` `.ev-row-actions`, `.ev-star*`, `.ev-manage`, `.ev-signin-note`. Pure moves (the CSS output
differs only in order); then `.ev-star-labelled` (a 40 px pill with the star and the text). Screenshot the events page
before and after at 390 and 1280 px - no visible change.

JS (foundation owns these edits):

- `assets/controllers/event_follow_controller.js`: `flip()` sets `aria-label` only when `data-label-follow` is present,
  and the text of a `[data-follow-text]` child from `data-text-follow` / `data-text-following`; `rowOf()` also matches
  `.ev-detail-actions` and `noteHostOf()` returns it (the guest note appears under the header's actions).
- `src/Controller/Events/EventManageMenuController.php` + `templates/events/_manage_items.html.twig`: optional
  `delete_return` query parameter (`ReturnUrl::tryFrom()`, else `return_url`), used as the `return` of the Delete form
  only - deleting the page you are on returns to its parent.

### 1.2 Read-model changes

**`OccurrenceRounds::SQL_JOIN`** - the JSON object of each round gains `has_results` (alias the inner table `cr_j`):

```sql
json_build_object('id', cr_j.id, 'name', cr_j.name, 'starts_at', cr_j.starts_at, 'timezone', cr_j.timezone,
    'has_results', (EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.competition_round_id = cr_j.id AND pst.suspicious = false)
                    OR {GetPublishedRoundResults::sqlShowsOfficialResults('cr_j')}))
```

`OccurrenceRounds::fromJson()` also reads it and `RoundTimezone::isAssumed()` for the zone. `EXPLAIN ANALYZE` of
`GetEventOccurrences::all(false)` on the dev DB before and after goes into the PR description; if the events page
statement grows by more than 5 ms, the flag moves into `forSeries()` only (README conflict 5).

**`src/Value/OccurrenceRound.php`** + `public bool $zoneAssumed = false`, `public bool $hasResults = false`.

**`src/Value/OccurrenceSession.php`** + `public bool $hasResults` (any of its rounds).

**`src/Value/OccurrenceDates.php`** + `public null|OccurrenceRound $firstRound = null` (the first round of the
occurrence or session) - `sessions()` sets it in both branches.

**`src/Results/EventOccurrence.php`** + `public null|OccurrenceRound $firstRound = null`, `public null|string
$registrationLink = null`; `hasResults` of a session = `results_link` of the competition OR the session's
`hasResults` (one session: unchanged). `dates()` passes `firstRound` through.

**`src/Query/GetEventOccurrences.php`**:

- the SELECT moves into `private function fetch(string $where, array $parameters): list<EventOccurrence>`; `all()` calls
  it unchanged; new column `CASE WHEN c.registration_managed THEN NULL ELSE c.registration_link END AS registration_link`;
- **`public function forSeries(string $seriesId): list<EventOccurrence>`** - one statement, `WHERE c.series_id =
  :seriesId AND c.rejected_at IS NULL` (no visibility filter: an unapproved series' own page lists its editions as
  today; `isPublic` says what is public), same sort as `all()`. Invalid uuid → `[]` without a statement.

**`src/Query/GetEventAttendance.php`** - both statements (`forPlayer()` and the registration one) gain

```sql
EXISTS (SELECT 1 FROM followed_competition fc WHERE fc.player_id = :playerId
        AND (fc.competition_id = :competitionId
             OR fc.series_id = (SELECT f_c.series_id FROM competition f_c WHERE f_c.id = :competitionId))) AS is_following
```

`src/Results/EventAttendance.php` + `public bool $isFollowing = false` (guests: false, no statement). The registration
statement runs for a guest too - `:playerId` is then null and the EXISTS is false.

### 1.3 Values, builders and view models

| Class | Contract |
|---|---|
| `src/Value/EventTime.php` (readonly) | `instant: DateTimeImmutable` (UTC), `zone: string`, `zoneAssumed: bool`; `static fromRound(EditionRoundDetail)`, `static fromOccurrenceRound(OccurrenceRound)`, `isoInstant(): string` (`Y-m-d\TH:i:s\Z`) |
| `src/Value/RoundStatus.php` (enum string) | `Past = 'past'`, `Live = 'live'`, `Next = 'next'`, `Later = 'later'` |
| `src/Value/SeriesCadence.php` (enum string) | `Weekly = 'weekly'`, `TwiceAMonth = 'twice_a_month'`, `Monthly = 'monthly'`; `static fromStarts(list<DateTimeImmutable> $starts): ?self` - the last 8 starts (sorted), at least 3; median gap ≤ 9 days → Weekly, ≤ 20 → TwiceAMonth, ≤ 40 → Monthly, else null; `translationKey()` = `series_page.cadence.<value>` |
| `src/Results/EventsPage/WhenLabel.php` | + `TODAY = 'today'` (rounds only; the events page never produces it) |
| `src/Results/EventsPage/AgendaRow.php` | + `public null|EventTime $time = null` (last parameter) |
| `src/Value/RowContext.php` (enum) | `EventsPage`, `SeriesPage` |

**`src/Services/EventsPage/EventRowFactory.php`** (readonly service, `EventUrls`) - the row rules **moved out of**
`EventsPageBuilder` (its private `row()`, `tags()`, `registrationTag()`, `when()`, `tone()`, `titleOf()`,
`singleArchiveLine()` become public methods here; `EventsPageBuilder` delegates). `EventsPageBuilderTest` must pass
**unchanged** - it is the guard of the move.

```php
public function row(EventOccurrence $occurrence, EventOccurrenceStatus $status, int $indexId, array $goingCounts,
    ?EventsViewerData $viewer, EventsScope $scope, DateTimeImmutable $now, DateTimeImmutable $day, string $locale,
    ?string $logo, RowContext $context = RowContext::EventsPage): AgendaRow;
public function archiveLine(EventOccurrence $occurrence, int $indexId, EventsScope $scope, string $locale,
    RowContext $context = RowContext::EventsPage): ArchiveLine;
public static function when(EventOccurrenceStatus $status, ?DateTimeImmutable $start, DateTimeImmutable $day): ?WhenLabel;
```

`RowContext::SeriesPage`: `title` = `subtitle() ?? name` and `editionName` = null; no `Recurring` tag; no
`WaitingForApproval` tag; `followTarget` = null; `time` = `EventTime::fromOccurrenceRound($occurrence->firstRound)`
when set; `visible` = true; the archive line's `title` the same way, `url` = `EventUrls::occurrence()` (sessions keep
`#round-<id>`).

**`src/Services/EventDetail/SeriesPageBuilder.php`** (readonly, `EventRowFactory`, `EventUrls`):

```php
/** @param list<EventOccurrence> $occurrences  @param array<string,int> $goingCounts */
public function build(CompetitionSeriesOverview $series, array $occurrences, array $goingCounts,
    ?EventsViewerData $viewer, DateTimeImmutable $now, string $locale): SeriesPage;
/** @param list<EventOccurrence> $occurrences @return list<string> lower-case ids of live/upcoming competitions */
public static function comingCompetitionIds(array $occurrences, DateTimeImmutable $now): array;
```

View models in `src/Results/EventDetail/` (readonly):

| Class | Fields |
|---|---|
| `SeriesPage` | `next: ?SeriesNextCard`, `months: list<AgendaMonth>` (live + upcoming without the Next one, by start), `ongoing: list<AgendaRow>`, `dateNotSet: list<AgendaRow>`, `pastYears: list<ArchiveYear>` (one line per past session, newest year and line first), `facts: SeriesFacts`, `followTarget: ?FollowTarget` (null unless the series is public), `following: bool`, `subEvents: list<JsonLdSubEvent>`, `isEmpty(): bool` (no occurrence at all) |
| `SeriesNextCard` | `row: AgendaRow` (its `when` - or null beyond 30 days, then the template writes the full date), `competitionId`, `isGoing: bool`, `registrationManaged: bool`, `registrationLink: ?string`, `isPublic: bool` |
| `SeriesFacts` | `editionCount` (distinct competitions, undated included), `since: ?DateTimeImmutable`, `comingCount` (live + upcoming sessions), `next: ?DateTimeImmutable`, `nextIsLive: bool`, `cadence: ?SeriesCadence`, `isOnline`, `place: Place`, `website: ?string` |
| `JsonLdSubEvent` | `name`, `url` (absolute is made in the template), `path: string`, `startDate`, `endDate: ?`, `image: ?string` (logo path), `isOnline` |

Rules (each with a unit test): Next = first Live, else first Upcoming (long spans never); months = the other live and
upcoming occurrences via `row(…, RowContext::SeriesPage)`, Live rows first; ongoing = status Ongoing; dateNotSet =
status DateNotSet, by name; past = status Past, one `archiveLine(…, SeriesPage)` each, grouped by start year; facts as
in the table; cadence from every dated session start up to and including the next one; `subEvents` = public dated
occurrences, name = `reference()->displayName()` + (`subtitle()` when it differs) - one per session.

**`src/Services/EventDetail/RoundsTimelineBuilder.php`** (readonly, `UrlGeneratorInterface`):

```php
/** @param list<EditionRoundDetail> $rounds  @param array<string,int> $resultsPerRound */
public function build(CompetitionReference $event, string $competitionId, array $rounds, bool $isOnline,
    bool $isPublic, array $resultsPerRound, bool $canAddTime, ?DateTimeImmutable $dateFrom,
    ?DateTimeImmutable $dateTo, DateTimeImmutable $now): RoundsTimeline;
```

| Class (`src/Results/EventDetail/`) | Fields |
|---|---|
| `RoundsTimeline` | `rounds: list<TimelineRound>`, `nextRoundId: ?string`, `foldedCount: int`, `sessions: list<OccurrenceDates>` (`OccurrenceDates::sessions($dateFrom, $dateTo, rounds as OccurrenceRound)`), `start: ?DateTimeImmutable`, `end: ?DateTimeImmutable` (first session's start, last session's end ?? start; no rounds: `date_from`/`date_to` as `sessions()` gives them), `isLive: bool`, `roundsWithResults: int`, `zone: ?string` + `zoneAssumed: bool` (of the first round), `hasRounds(): bool` |
| `TimelineRound` | `round: EditionRoundDetail`, `leaf: DateLeaf` (the round's local day; tone `online`/`in_person`, `muted` when past), `status: RoundStatus`, `when: ?WhenLabel` (Live and Next only: live → `live`; same local day → `today`; else `EventRowFactory::when()` rules by local days), `time: EventTime`, `folded: bool`, `resultsUrl: ?string`, `officialResults: bool` (= `round.resultsPublished`), `addTimeUrl: ?string`, `puzzlesAnnounced: bool` (≥ 1 visible puzzle) |

Rules (unit-tested with synthetic rounds and a fixed `$now`): past = `startsAt + minutesLimit ≤ now`; live = started and
not past; Next = the first not past (Live counts); folded = past rounds before the latest past one, only when a Next
exists; `resultsUrl` only when `$isPublic`, the round has a slug, the reference has a route, and
`resultsPerRound[id] > 0` or `resultsPublished` (`event_round_results` with `slug`, `edition_round_results` with
`seriesSlug`/`editionSlug`, from `CompetitionReference::routeName()`); `addTimeUrl` only when `$canAddTime` and the round
started: `puzzle_add` with `competition` = id, plus `puzzleId` when the round has exactly one puzzle and its
`imageHidden` is false.

**`src/Services/EventDetail/EventPagePuzzles.php`** (readonly; `GetPuzzleOverview`, `GetCompetitionPuzzles`) - one
rule for both pages: `resolve(CompetitionEvent $event, array $rounds): list<PuzzleOverview>` = tagged puzzles
(`byTagId`), else - only when there are **no** rounds - `solvedPuzzleOverviews($id, 24)`; then without every puzzle id
of a round. (`roundPuzzleOverviews()` is no longer called.) `difficultyIds()` = round puzzle ids ∪ these ids.

### 1.4 Times: server and browser

- `EventsPageDates::time(DateTimeInterface $instant, string $zone, ?string $locale = null): string` - ICU skeleton
  `jm` in `$zone` (en → en_GB "18:45", de "18:45", ja "18:45"), cached formatters like `format()`.
- `src/Twig/EventDateTwigExtension.php` + function `event_time(EventTime $time): string` → translation
  `event_detail.time.in_zone` with `%time%` and `%zone%` (`ZonedDateTimeFormatter::timezoneName($zone,
  $zoneAssumed)`).
- `templates/event_parts/_event_time.html.twig` (`time: EventTime`, `online: bool`):
  `<span class="ev-time"><time datetime="{{ time.isoInstant }}" data-event-time data-event-zone="{{ time.zone }}">…</time>`
  + the zone in `<span data-round-zone>` (no parentheses), assembled from `event_detail.time.in_zone` with both values
  escaped before substitution; when `online`: `<span class="ev-time-yours" data-local-time hidden></span>`.
- `assets/events_index.js` (the locale-aware date helpers) + exports
  `formatTime(instantIso, zone, locale)` (`Intl.DateTimeFormat(dateLocale(locale), {hour: 'numeric', minute: '2-digit',
  timeZone: zone})` - the same text as the server's `jm`), `zoneLabel(zone, locale)` (`timeZoneName: 'longGeneric'`
  part, else the zone id's last segment with spaces), and `visitorTime(instantIso, eventZone, locale, visitorZone)` →
  `null` (invalid/missing visitor zone, or the same wall date+time) or `{time, zone, dayShift: -1|0|1}` (`dayShift` =
  the visitor's calendar day minus the event's for that instant).
- `assets/controllers/event_local_time_controller.js` (lazy), on the page root of **online** events only:
  values `messages` (`yours`, `yours_next_day`, `yours_previous_day`); on connect fills every `[data-local-time]`
  after a `[data-event-time]` and removes `hidden`. Nothing else (no timers).

### 1.5 Controllers (data wiring - foundation owns them; A/B ask for changes)

**`CompetitionSeriesDetailController`**: drop `#[MapEntity]`; take `string $slug`; `GetCompetitionSeries::bySlug()`
(throws `CompetitionSeriesNotFound`, a 404); `GetEventOccurrences::forSeries()`;
`GetEventGoingCounts::forCompetitions(SeriesPageBuilder::comingCompetitionIds(…))`; signed in:
`GetEventsViewerData::forPlayer()`; `SeriesPageBuilder::build()`; sections as today. Renders
`competition_series_detail.html.twig` with `series`, `page: SeriesPage`, `page_sections`, `manage: ManageRef`
(series), `today`.

**`EditionDetailController`** and **`EventDetailController`**: as today, plus `EventPagePuzzles::resolve()` instead of
the inline tag/round/solved logic; `CountCompetitionResults::perRound()` when some round has a slug and the page is
public (both pages); `RoundsTimelineBuilder::build()`; pass `timeline`, `puzzles` (outside rounds), `difficulty_data`
over `EventPagePuzzles::difficultyIds()`, `follow_target` (edition: series; event: itself unless past; null unless
public), `manage: ManageRef` (competition), `delete_return` (event: `events`; edition: the series page). The event page
drops `puzzle_rounds`, `round_results_urls`, `result_rounds`; the latest-round-first sort goes (the grid holds no round
puzzles any more).

### 1.6 Skeleton templates and the shared header (foundation, working and styled)

`templates/event_parts/_crumbs.html.twig` - `{items: list<{label, url}>}`: `<nav class="ev-crumbs"
aria-label="{{ 'event_detail.crumbs'|trans }}"><ol>` of links (the H1 is the current page).

`templates/event_parts/_detail_header.html.twig`, used with `embed`:

```twig
{% embed 'event_parts/_detail_header.html.twig' with {crumbs, logo, name, description, return_fallback_url, return_fallback_title} %}
    {% block facts %}…{% endblock %}
    {% block actions %}…{% endblock %}
{% endembed %}
```

Renders `_return_back_button.html.twig` only when `app.request.query.has('return')`, the crumbs, logo (`puzzle_small`,
`alt=""`), H1 `.ev-detail-name`, `<p class="ev-detail-facts">{block facts}</p>`, `<div class="ev-detail-actions">{block
actions}</div>`, the description (`nl2br`, `data-event-description`). Styles in new `assets/styles/_event-detail.scss`
(foundation; also `.ev-detail` page root, `.ev-detail-body` two columns from 992 px: main + `.ev-detail-side` 300 px,
`.ev-facts-strip` hidden from 992 px, `.ev-side-card`).

Action partials (foundation): `event_parts/_follow_action.html.twig` (`follow_target`, `following`, `kind:
'series'|'event'`, labelled star), `event_parts/_going_action.html.twig` (`competition_id`, `attendance`, `is_past`,
`is_public`) - "I'm going!" link / "✓ You're going!" link to `#taking-part` / managed: "Registration" link to
`#registration`.

Skeleton page templates (A and B take them over): every section present and correct, unstyled beyond the header:

- `competition_series_detail.html.twig`: header; `{% include 'series/_next.html.twig' %}`, `series/_upcoming.html.twig`,
  sections, `series/_past.html.twig`, `series/_side.html.twig`; JSON-LD block = `{{ include('event_parts/_series_json_ld.html.twig') }}`.
- `edition_detail.html.twig`, `event_detail.html.twig`: header; `{{ include('event_parts/_rounds_timeline.html.twig',
  {timeline, event, online}) }}`; `event_parts/_more_puzzles.html.twig`; `event_parts/_taking_part.html.twig`; offers;
  sections; participants; `event_parts/_detail_side.html.twig`; JSON-LD block = `{{ include('event_parts/_event_json_ld.html.twig') }}`.
- Both JSON-LD partials start as today's JSON-LD moved verbatim (byte-identical output), so C changes only them.
- Every page root: `<div class="ev-detail" data-controller="{{ online ? 'event-local-time' }}" …>`; `{{
  include('event_parts/_manage_menu_host.html.twig', {show_menu: …}) }}` at the end.

### 1.7 Translations (English; foundation writes these blocks)

Three top-level blocks in `translations/messages.en.yml` after `events_archive:`, each with an owner comment, a blank
line between them; workstreams add keys **inside their own block** only. Plurals: the site's `one|many` with `%count%`.

```yaml
# Event, edition and series pages - shared (foundation, docs/features/events-page/detail-pages.md)
event_detail:
    crumbs: "Breadcrumb"
    facts:
        recurring: "Recurring"
        rounds: "%count% round|%count% rounds"
        runs_until: "Runs until %date%"
    follow:
        series: "Follow series"
        series_following: "Following series"
        event: "Follow"
        event_following: "Following"
    going:
        registration: "Registration"
    time:
        in_zone: "%time% %zone%"
        yours: "%time%, %zone% (yours)"
        yours_next_day: "%time% next day, %zone% (yours)"
        yours_previous_day: "%time% previous day, %zone% (yours)"
    side:
        part_of: "Part of"
        dates: "Dates"
        place: "Place"
        website: "Website"
        times_online: "Times are in %zone%. Your own time is shown next to each round."
        times_in_person: "Times are in %zone%, where the event takes place."

# Series page (workstream A)
series_page:
    facts:
        editions: "%count% edition|%count% editions"
        since: "since %date%"
        coming: "%count% coming|%count% coming"
        next: "next %date%"
        live_now: "live now"
    cadence:
        weekly: "about every week"
        twice_a_month: "about twice a month"
        monthly: "about once a month"
    next: "Next"
    upcoming: "Upcoming"
    ongoing: "Ongoing"
    date_not_set: "Date not set"
    no_upcoming: "No upcoming dates yet."
    past: "Past"
    years: "Years"
    side:
        about: "About"
        editions: "Editions"
        how_often: "How often"
        since: "Since"
        next: "Next"
        following_note: "You get the next date of this series under “Your events” on the events page."

# Edition and event pages - rounds timeline (workstream B)
event_rounds:
    title: "Rounds"
    next_round: "Next round:"
    show_earlier: "Show %count% earlier round|Show %count% earlier rounds"
    minutes: "%count% min"
    not_announced: "Puzzles not announced yet"
    picture_hidden: "Picture revealed when the round starts"
    official_results: "Official results"
    more_puzzles: "More puzzles of this event"
    taking_part: "Taking part"
```

Plus `events_page.when.today: "Today"` (foundation, in the existing block). Reused as they are: `events.website_link`,
`events.registration_link`, `events.results_link`, `events.add_my_time`, `events.competition_puzzles`,
`events.no_puzzle_text`, `competition.join.*`, `competition_registration.card.*`, `edition.date_not_set`,
`series.no_editions`, `round_results.link`, `competition.round.category.*`, `events_page.*` (tags, when, archive
`show_all`, place). JavaScript texts reach the controller as `data-…-value` (`|trans`), never hard-coded.

### 1.8 Fixtures

New `tests/DataFixtures/EventDetailFixture.php` (ids `018d0041-0000-0000-0000-0000000000NN`, depends on
`EventsPageFixture`, `PuzzleFixture`, `PlayerFixture`, `PuzzleSolvingTimeFixture`); existing constants untouched.

| Const | What | Purpose |
|---|---|---|
| `ROUND_PUZZLE_SPRINT_1` (01) | `PUZZLE_500_01` on `ROUND_SPRINT_1` | past round with a puzzle |
| `ROUND_PUZZLE_SPRINT_2` (02) | `PUZZLE_500_02` on `ROUND_SPRINT_2` | |
| `ROUND_PUZZLE_SPRINT_3_SECRET` (03) | `PUZZLE_500_03` on `ROUND_SPRINT_3`, `hideUntilRoundStarts`, `PuzzleHideMode::Entirely`, automatic reveal | the next round: "Puzzles not announced yet", the puzzle nowhere in the HTML |
| `TIME_SPRINT_1` (11) | PLAYER_REGULAR, solo, `PUZZLE_500_01`, competition `EDITION_SPRINT_SEASON`, round `ROUND_SPRINT_1`, not suspicious | Results on Sprint 1 only - per-session Results on the series page |
| `COMPETITION_HILLTOP_WEEKEND` (21) "Hilltop Puzzle Weekend" | one-time, in person, `cz`, Friday-Sunday 5 weeks ahead (`next friday +4 weeks`), approved, slug `hilltop-puzzle-weekend`, created by PLAYER_ADMIN (like every `EventsPageFixture` series - nobody's "You organize" count changes) | a multi-day championship: one session |
| `ROUND_HILLTOP_FRI/SAT/SUN` (22–24) | 18:00 / 10:00 / 10:00 `Europe/Prague`, slugs, categories solo / duo / solo; `PUZZLE_500_04` on Saturday with `PuzzleHideMode::ImageOnly` | in person: no second time; "Picture revealed when the round starts"; no `subEvent` |

`EDITION_SPRINT_SEASON` (online, New York 22:00 = next day UTC) is the multi-session edition; `SERIES_SPRINT_LEAGUE`
its series page; `SERIES_HARBOR_NIGHTS` the series with editions in two years and an undated edition;
`SERIES_CLOCK_MARATHON` the long span; `SERIES_SUMMIT_LEAGUE` the series without editions;
`COMPETITION_RIVERSIDE_OPEN` managed registration. Update `.claude/fixtures.md` (subsection "Event detail pages
(`EventDetailFixture`, ids `018d0041-…`)"). Fix in the same commit every test that counts round puzzles, solving times
of PLAYER_REGULAR or one-time events (expect: player statistics/insights counts, `GetCompetitionEventsTest`,
`GetSelectableCompetitionsTest`, sitemap, the events page tests that count Upcoming).

### 1.9 Foundation tests

- `tests/Value/OccurrenceDatesTest.php` (extend): `firstRound` per session and for one session.
- `tests/Query/OccurrenceRoundsTest.php` (new, unit): `fromJson()` reads `has_results`, `zoneAssumed`.
- `tests/Query/GetEventOccurrencesTest.php` (extend): `forSeries()` = the Sprint League's four sessions with
  `hasResults` true only for Sprint 1; Harbor incl. the undated edition; an unapproved series' editions with
  `isPublic` false; Old Mill's (rejected series) edition still listed by `forSeries()` but not by `all()`;
  `registrationLink` hidden while managed.
- `tests/Query/GetEventAttendanceTest.php` (new or extend): `isFollowing` for PLAYER_REGULAR on a Harbor edition (follows
  the series) and on Meadow (follows the event); false for a guest and a stranger.
- `tests/Services/EventsPage/EventsPageBuilderTest.php` **unchanged and green**; `EventRowFactoryTest.php` (new):
  `RowContext::SeriesPage` title/tags/no star/time; `when()` incl. `today` is never produced for occurrences.
- `tests/Services/EventDetail/SeriesPageBuilderTest.php`, `RoundsTimelineBuilderTest.php`,
  `tests/Value/SeriesCadenceTest.php` - every rule of 1.3, synthetic data, fixed now.
- `tests/Twig/EventTimeTest.php`: "22:00 New York Time" for a Sprint round on an English page, "22:00" + the German
  zone name on `de`; assumed zone named "Central European Time".
- `tests/EventsIndexScriptTest.php` + `tests/events-index-harness.mjs` (extend): `formatTime()` equals the server's
  `time()` in all 6 locales (the PHP side computes the expected strings, as for dates); `visitorTime()` - same zone →
  null; `2026-06-17T02:00:00Z` with event zone New York (22:00 on 16 June) and a Prague visitor → `{time: '04:00',
  dayShift: 1}`, a Los Angeles visitor → `{time: '19:00', dayShift: 0}`, a Tokyo visitor → `dayShift: 1`; invalid zone →
  null; `zoneLabel()` fallback (fixed instants only - never the fixture's moving dates).
- `tests/Controller/DetailPagesQueryBudgetTest.php` (1.10).
- The existing page tests must stay green on the skeleton: it keeps the `data-*` hooks they select
  (`data-series-edition` on each series row, `data-edition-date-not-set`, `data-round-zone`, `data-round-category`,
  `data-event-description`, `data-page-sections`, the join/leave links and forms) and today's texts. Where a test
  asserts the old structure itself (`h2 + .row` cards, `#puzzle-list-item-*` round pills, the zone in parentheses), the
  foundation changes only what the skeleton forces and lists each change in its commit message; A and B rewrite those
  tests to the new structure.

### 1.10 Statement budgets (measured on the skeleton, pinned exactly)

`tests/Controller/DetailPagesQueryBudgetTest.php` (pattern of `EventsPageQueryBudgetTest`, `QueryCountAssertions`,
the second request counted). Ceilings - the measured value is pinned with `assertSame`; a higher measurement is a bug
to explain in the PR, not a new number:

| Page | Guest | Player (PLAYER_REGULAR) | Organiser (PLAYER_ADMIN, the creator) | Today (guest / player) |
|---|---|---|---|---|
| Series `harbor-jigsaw-nights` (no sections) | **3** | **9** | **9** | 4 / 8 |
| Series `summit-puzzle-league` (no editions) | **2** (no going counts) | **8** | - | 4 / 8 |
| Edition `moonlight-sprint-league/season-one` (sessions, results) | today's edition + 1 (`perRound`) | guest + 4 overhead + 1 permissions | same | - |
| Event `hilltop-puzzle-weekend` | ≤ today's `wjpc-2024` (16) | guest + 5 | same | - |
| `PageSectionsOnPagesTest` cases | series 4→3; event/edition: today's ± the documented deltas | series 8→9; event 19→20; edition 18→20 at most | | |

Plus two "does not grow" tests: 10 more editions (with rounds on separate days) of one series add no statement to its
series page; 6 more rounds with puzzles add none to an edition page. Signed-in overhead = 4 (account, profile, unread
conversations, unread notifications), as measured for the events page.

Foundation done = gates green, the three pages render every section (header styled, the rest plain), the events page
unchanged (screenshots), one commit on `events-detail-pages` ("Event detail pages: foundation - shared parts,
series occurrences, rounds timeline, event times").

### Foundation deviations (2026-10-08, as built)

Contracts A, B and C build on - where they differ from 1.1-1.10 above:

1. **Worktree/branch**: `.claude/worktrees/detail-pages`, branch `event-detail-pages` (not `ev2-detail` / `events-detail-pages`).
2. **Zone names follow Answers 2**: `event_time()` names the zone with ICU's localised *generic* name
   (`EventsPageDates::zoneName()`, `IntlTimeZone::DISPLAY_LONG_GENERIC`) - "22:00 Eastern Time", "Central European Time"
   for Prague (assumed or not), never "New York Time" / "Czechia Time". `ZonedDateTimeFormatter` is unchanged (the
   registration card and manage pages keep it). Tests of A/B expecting "New York Time" / "Czechia Time" read
   "Eastern Time" / "Central European Time".
3. **Two-digit hours** on both sides ("04:00"): `EventsPageDates::time()` pads `jm`'s `H` to `HH`, the browser uses
   `hour: '2-digit'` - Intl's numeric hour drops the zero where ICU keeps it (en-GB), so the two would differ.
4. **No `SeriesCadence`** (Answers 3: no "how often"): no class, no `SeriesFacts::$cadence`, no `series_page.cadence.*`
   / `series_page.side.how_often` keys. `SeriesFacts` = editionCount, since, comingCount, next, nextIsLive, isOnline,
   place, website.
5. **`OccurrenceRounds::SQL_JOIN` is unchanged**; the per-round `has_results` is in `sqlJoinWithResults()`, used by
   `GetEventOccurrences` only (events page + series page) - the sitemap years and "You organize" keep the light join.
   `GetEventOccurrences` no longer runs its own per-competition results `EXISTS`: the competition's flag = results link
   OR any round's `has_results` (computed in PHP), a session's = results link OR its own rounds'. `EXPLAIN ANALYZE` on
   the dev DB was not run (agents never touch it) - for the PR.
6. **`EventsPageBuilder`** keeps its two-argument constructor (`EventsPageBuilderTest` and `EventsIndexFactoryTest`
   construct it unchanged); a third optional `EventRowFactory` defaults to `new EventRowFactory($urls)`.
   `EventsPageBuilder::place()` stays (delegates to `EventRowFactory::place()`). `EventRowFactory` also has public
   `tags()`, `registrationTag()`, static `titleOf(…, RowContext)`, `tone()`, `place()`.
7. **`JsonLdSubEvent`** has `path` only (no `url`); the template makes it absolute with `absolute_url()`. Its name is
   `displayName()` + " · " + the session's round label (`sessionLabel()`), not `subtitle()` (which would repeat the
   edition's name).
8. **`SeriesPageBuilder::comingCompetitionIds()`** also includes Ongoing long spans (they take registrations and show
   "N going", as on the events page). `SeriesPage` adds `upcomingCount()` / `pastCount()`.
9. **`RoundsTimeline`** adds `foldedRounds()` / `shownRounds()`; `isLive` without rounds = the event's span is live.
   A running round has status `Live` and is `nextRoundId` (Live counts as the next); several running rounds are all Live.
10. **Series JSON-LD**: the foundation's `_series_json_ld.html.twig` already reads `page.subEvents` (the old
    `upcoming_editions` / `past_editions` are no longer loaded - they would cost two statements); the sub-event names
    changed accordingly ("Euro Jigsaw Jam · EJJ #69 — May 2026"). `_event_json_ld.html.twig` is the two pages' JSON-LD
    verbatim, branching on `series` (null on the event page) and taking `meta_description` as a variable (`block()`
    does not reach the page's blocks from an include): `include('event_parts/_event_json_ld.html.twig',
    {meta_description: block('meta_description')|trim})`.
11. **Header ⋯ / host**: the series controller passes `show_menu` (admin, series editor, or organiser of one of its
    editions - from the viewer rows, no statement); edition/event templates compute it with the voters.
    `_manage_menu_host` is included inside `.ev-detail`.
12. **`_row.html.twig`** gained two optional flags used only on the series page: `series_rows` (`data-series-edition`
    = competition id on the row) and `show_logo` (the edition's logo, `.ev-row-logo`); a `notset` row shows
    "Date not set" with `data-edition-date-not-set`. The events page passes neither - unchanged.
13. **`_follow_star`** labelled variant takes `text_follow` / `text_following` (`_follow_action` passes the
    `event_detail.follow.*` texts); `_going_action` renders nothing on a page that is not public.
14. **`_taking_part.html.twig`** puts `id="registration"` on its wrapper while registration is managed; B moves it to
    the card (`_event_registration_card.html.twig`) and drops it from the wrapper.
15. **Fixture**: as planned (`PUZZLE_500_01` + PLAYER_REGULAR, 1800 s). `PuzzleStatisticsFixture` now depends on
    `EventDetailFixture`. Tests updated for it: solve counts of PUZZLE_500_01 (11→12) and of PLAYER_REGULAR (17→18
    results, 3→4 solves of 500_01), the puzzle picker's solve-count ranges, the 500-piece distribution (40→41),
    "Used at" of PUZZLE_500_01 (+ Season One, first), `RoundResultsReconcilerTest` (5→6 linked),
    `CatalogueCrossLinksTest` (tags PUZZLE_1000_05 - a round's puzzle is no card any more).
16. **Statement budgets** (measured, pinned in `DetailPagesQueryBudgetTest`): series Harbor guest 3 / player 9 /
    organiser 9; Summit guest 2 / player 8; Season One guest 12 / player 19 / organiser 19; Hilltop guest 12 / player
    19 / organiser 19 (signed in = guest + 4 overhead + statuses + attendance + permissions).
    `PageSectionsOnPagesTest`: wjpc-2024 16→15, czech-nationals 13→12, euro-jigsaw-jam 10→9, ejj-68 edition 11→12,
    series 4→3, edition signed in 18→19, series signed in 8→9 (event signed in unchanged 19).
17. `RoundRevealMomentComputedOnlyHereTest` allowlists `RoundsTimelineBuilder` (start + time limit = over; not a reveal).

## 2. Workstreams (parallel, after the foundation commit)

Nothing outside the "Owns" list is edited. Read-only use of every foundation file.

### A. Series page (`ev2-series`, DB suffix `a`)

**Owns:** `templates/competition_series_detail.html.twig` (except its `json_ld` block line);
`templates/series/_next.html.twig`, `_upcoming.html.twig`, `_past.html.twig`, `_side.html.twig`,
`_facts_strip.html.twig`; `assets/controllers/series_archive_controller.js`; `assets/styles/_series-page.scss`
(imported after `event-detail`); the `series_page:` block; `tests/Controller/SeriesPageUiTest.php`; the markup tests of
`tests/Controller/CompetitionSeriesDetailControllerTest.php` (robots, undated edition, logo) - not its JSON-LD tests (C).

Builds (README "Series page"; visual spec = the mock-up's CSS mapped to the `ev-` tokens):
- Header via `_detail_header` (facts: place/Online, "Recurring", cadence; actions: follow (series), Website ↗, ⋯
  series with `delete_return` = `events`).
- Facts strip (phones) and the side column "About" + the follower note (from 992 px).
- Next card (`.ev-next`): leaf, eyebrow, title, place/Online + `_event_time` (second time on online series), the when
  label or the full date (`events_date('yMMMMEEEEd')`), `_going_action` for its competition (from `page.next.isGoing`
  - no attendance statement on this page), the registration tag/link; links to the occurrence URL.
- Upcoming: month headers (`.ev-month-header`, `h3`, "3 dates"), rows through `event_parts/_row.html.twig` (row ⋯ for
  organisers through `_row_actions`), Ongoing and Date not set groups (`data-edition-date-not-set` on the "Date not
  set" text), the empty state `series_page.no_upcoming`, `series.no_editions` when the series has none.
- Sections slot between Upcoming and Past (`{% if page_sections is not empty %}` - nothing at all without them).
- Past: year chips (`button[aria-pressed][aria-controls]`), a `<section data-year="2026">` per year with its `h3` and
  `event_parts/_archive_line.html.twig` lines; `series_archive_controller.js` (lazy): shows the chosen year, the newest
  by default, its first 5 lines and `events_page.archive.show_all`; without JS all years and lines show.

Tests (`SeriesPageUiTest`): Sprint League shows the Next card for Sprint 3 with "22:00 New York Time" and a
`[data-local-time]` slot, no Sprint 3 row repeated below, Sprint 4 under its month, Sprint 1 and 2 as past lines with
Results only on Sprint 1, each line linking `…/season-one#round-<id>`; Harbor: the three sessions two months ahead as
three rows (no roll-up), last year's two editions under that year, the undated edition under "Date not set"; Clock
Marathon under Ongoing with "Runs until"; Summit: "No editions yet."; facts strip counts; a follower (PLAYER_REGULAR on
Harbor) sees "Following series" pressed; guests get the sign-in link; the organiser (PLAYER_ADMIN, its creator) sees the header ⋯ and row ⋯,
PLAYER_WITH_FAVORITES none; an unapproved series (`SERIES_UNAPPROVED`) has no star; an in-person series page has no
`data-controller="event-local-time"`.

### B. Edition and event pages, rounds timeline (`ev2-rounds`, DB suffix `b`)

**Owns:** `templates/edition_detail.html.twig`, `templates/event_detail.html.twig` (except their `json_ld` block lines);
`templates/event_parts/_rounds_timeline.html.twig`, `_round.html.twig`, `_round_puzzle.html.twig`,
`_more_puzzles.html.twig`, `_taking_part.html.twig`, `_detail_side.html.twig`, `_facts_strip_event.html.twig`;
`assets/controllers/event_rounds_controller.js`; `assets/styles/_event-rounds.scss`; the `event_rounds:` block;
`tests/Controller/EventPagesUiTest.php`; the markup tests of `EditionDetailControllerTest`,
`EventDetailControllerTest`, `CompetitionRoundAssumedTimezoneTest::testEditionPageNamesAnAssumedZoneWithoutACountry`,
`EventOffersCardTest` (kept green), `CompetitionRegistrationControllerTest` / `JoinCompetitionControllerTest` /
`LeaveCompetitionControllerTest` page assertions (kept green).

Builds (README "Edition page and one-time event page"):
- Header via `_detail_header`: edition crumbs `Events › series`; facts (Online/place, `events_date_range(timeline.start,
  timeline.end, 'yMMMd')` or "Date not set" with `data-edition-date-not-set`, Live, Recurring, N rounds); actions
  `_going_action`, follow (`follow_target`), Registration ↗, Website ↗, Results ↗ (past), Add my time (past), ⋯ with
  `delete_return`.
- Timeline `<ol class="ev-rounds">`: one `<li id="round-<id>" class="ev-round ev-round-<status>">` per round (leaf;
  badge in `round.color`/`round.textColor`; category pill `data-round-category`; `event_rounds.minutes`; `_event_time`
  with `online`; when label; puzzles via `_round_puzzle` - thumbnail (`thumbnail('puzzle_small')`, `alt` = name), name
  link, pieces · brand, `_difficulty_icon` for members (`difficulty_data`), `puzzle/_badges.html.twig`
  (`puzzle_statuses`); "not announced" / "picture hidden" states; links Results / Official results, Add my time,
  `official_results/_organiser_round_links.html.twig`, the event's Registration ↗ on the next round).
- Folding: rounds with `folded` inside `<details class="ev-rounds-earlier"><summary>{{ show_earlier }}</summary>`
  before the first shown round; `event_rounds_controller.js` (lazy, only when `timeline.foldedCount > 0`) opens it on
  connect and on `hashchange` when the hash names a round inside.
- "More puzzles of this event" (`_puzzle_item.html.twig`, unchanged include) / "Competition puzzles" without rounds /
  `events.no_puzzle_text` (event page, no puzzles at all).
- Taking part `<section id="taking-part">`: `_event_attendance.html.twig` (the registration card gets
  `id="registration"` - one attribute added in `_event_registration_card.html.twig`, B owns that line), Add my time on
  the event page.
- Offers, sections, participants in that order; the side column (`_detail_side`: Part of, dates, place, website, the
  times note).

Tests (`EventPagesUiTest`): Season One - 4 rounds; the next is Sprint 3, so Sprint 1 is folded behind "Show 1 earlier
round" and Sprint 2 is shown; Sprint 3 `.ev-round-next` with its when label and "Puzzles not announced yet", the
secret puzzle's name and id nowhere in the HTML, Sprint 1 links its results page and Add my time with `puzzleId` for
PLAYER_REGULAR, no Results on Sprint 2 (no results), every round's `data-round-zone` = "New York Time", the
`event-local-time` controller and `[data-local-time]` present; Hilltop Weekend - three rounds, one leaf each,
"Czechia Time", no `[data-local-time]`, no controller, Saturday's puzzle with the hidden-picture tile and no link;
headers: Follow series pressed for PLAYER_REGULAR on a Harbor edition, "Follow" on Meadow; managed registration
(Riverside): the header "Registration" links `#registration` and the card has that id; an unapproved event: no star, no
Results links, no Add my time; `?return=/en/puzzle/…` renders the back button above the crumbs.

### C. Cleanup, SEO, budgets, docs (`ev2-cleanup`, DB suffix `c`)

**Owns:** `templates/event_parts/_event_json_ld.html.twig`, `_series_json_ld.html.twig`; deletions below;
`tests/Controller/DetailPagesJsonLdTest.php`; the JSON-LD tests of `CompetitionSeriesDetailControllerTest`;
`tests/Controller/PageSectionsOnPagesTest.php` (order + budgets); `tests/Controller/DetailPagesQueryBudgetTest.php`
(keeps it green after A and B merge); docs: `docs/features/events-page/detail-pages.md` (as-built notes),
`docs/features/competitions-management/README.md` ("Public series page" / "Public edition detail page" bullets → a
short paragraph linking detail-pages.md; "Results by round" and "Puzzles on the standalone event page" bullets
updated), `public-page.md` ("What a page shows": the new slot), `round-results.md` §"Event pages",
`participants.md` (the button's place), `CLAUDE.md` (one entry), `.claude/fixtures.md` (if A/B add rows),
`docs/TODO.md`.

Builds:
- **Event JSON-LD**: dates from `timeline.start` / `timeline.end` (date-only), emitted when public and `timeline.start`
  is set (editions dated by rounds included); everything else as today; `subEvent` when `timeline.sessions|length >= 2`:
  one `Event` per session - `name` "{event_title.name} · {single round name or the session's date}", `startDate`,
  `endDate` when several days, `url` = page URL `#round-<firstRound.id>`, the same `eventAttendanceMode` and
  `location`.
- **EventSeries JSON-LD**: `subEvent` from `page.subEvents` (absolute `url(...)` of the path), the rest as today.
- **Deletions** (grep every name first, update every reference): `templates/_series_edition_card.html.twig`; the
  `puzzle_rounds` / `round_results_urls` block of `templates/_puzzle_item.html.twig`;
  `GetCompetitionPuzzles::roundPuzzleOverviews()` and its tests when nothing calls it; translation keys in **all 6
  locales** once `grep -rn` shows no use: `events.results_by_round`, `competition.recurring`, `competition.online`;
  `series.upcoming_editions` / `series.past_editions` stay (manage page). Keep `_event_date_range.html.twig` (manage
  page) and `GetCompetitionSeries::upcomingEditions()` / `pastEditions()` (manage page, tests).
- **Docs**: `CLAUDE.md` feature list gets "**Series, edition and event pages**: `docs/features/events-page/detail-pages.md`
  (+ plan) — the events page's parts on the three detail pages: header (crumbs, labelled follow, ⋯ with
  `delete_return`), series = `GetEventOccurrences::forSeries()` → `SeriesPageBuilder` (Next card, upcoming by month,
  past by year, one line per session), edition/event = `RoundsTimelineBuilder` (`#round-<id>` rows, next highlighted,
  earlier folded, puzzles in their round, secret rules from `GetEditionRounds`), times in the event's zone named
  (`event_time()`), online events add the visitor's time in the browser (`event_local_time_controller.js`), budgets
  pinned by `DetailPagesQueryBudgetTest`"; the "Events page" entry mentions that `templates/event_parts/` is shared.
  `docs/TODO.md`: tick "Event pages still format dates with `_event_date_range.html.twig`"; new items: format chips /
  Organisation (waiting for organisers), organiser hint on long spans without rounds, BreadcrumbList JSON-LD,
  spectators' live link.

Tests (`DetailPagesJsonLdTest`): every JSON-LD block parses; Season One has an `Event` with four `subEvent`s whose
`url`s end in `#round-<id>` and whose `startDate`s equal `EventsPageFixture::storedSprintRoundDays()`; Hilltop has an
`Event` without `subEvent`; the Sprint League's `EventSeries` lists four sessions; Harbor's leaves out the undated
edition; a name with `</script>` stays inside its string (`json_ld`); non-public pages have no Event JSON-LD (as today).
`PageSectionsOnPagesTest`: sections after the marketplace card position and before the participants (event, edition),
between Upcoming and Past (series); its budgets updated to the measured numbers with a comment pointing here.

## 3. Tests per layer

| Layer | Where | Owner |
|---|---|---|
| Values (`OccurrenceDates`, `SeriesCadence`, `EventTime`) | `tests/Value/` | Foundation |
| Queries (`forSeries`, rounds JSON, attendance follow flag) | `tests/Query/` | Foundation |
| Builders and the row factory (unit, synthetic, fixed now) | `tests/Services/EventsPage/`, `tests/Services/EventDetail/` | Foundation |
| Time formatting (server Twig, browser parity, visitor time) | `tests/Twig/EventTimeTest.php`, `tests/EventsIndexScriptTest.php` | Foundation |
| Statement budgets | `tests/Controller/DetailPagesQueryBudgetTest.php` | Foundation (C keeps it green) |
| Events page unchanged | `EventsPageBuilderTest`, `EventsListUiTest`, `EventsCalendarTest`, `EventsPageQueryBudgetTest` | Foundation (guard of the moves) |
| Series page UI | `tests/Controller/SeriesPageUiTest.php` + existing series tests | A |
| Edition/event UI, timeline, secrets, times | `tests/Controller/EventPagesUiTest.php` + existing page tests | B |
| JSON-LD, sections order, docs | `DetailPagesJsonLdTest`, `PageSectionsOnPagesTest` | C |
| Privacy canaries | `BlocklistCanaryTest`, `PrivateProfileCanaryTest` (the participants list is unchanged) - must stay green | everyone |

## 4. Translation plan

1. Foundation writes the English blocks (1.7); A and B add English keys only inside `series_page:` / `event_rounds:`.
2. After A, B and C are merged, one agent translates every new key into cs, de, es, fr, ja (natural, not literal;
   keep `%placeholders%`; Czech plurals in 3 forms like the existing cs keys; the order of `%time% %zone%` per locale
   - ja/cs/de may put the zone first), and deletes in the 5 other files the keys C removed in English.
3. Parity: `run.sh ev2-detail f php bin/console debug:translation <locale> --only-missing --domain=messages | grep -E
   "event_detail|series_page|event_rounds|events_page.when.today"` prints nothing for each locale (the
   `missing-translations` skill fills gaps).
4. Visual check of the header, the Next card and a round row in all 6 locales at 320/360/390 px and 1280 px: German
   and French weekday bands in the leaf, the labelled follow button, long zone names ("Mitteleuropäische Zeit").

## 5. Order of operations and merge plan

1. The sessions fix is merged to `main` (its own PR). `events-detail-pages` branches from that `main`.
2. **Foundation** (1.1 → 1.10), gates, one commit.
3. **A** and **B** in parallel from the foundation commit; **C** starts with the JSON-LD partials and the docs at the
   same time, and finishes the deletions and budget/section tests after A and B are merged.
4. **Merge** into `events-detail-pages` with `git merge --no-ff`, gates after each: **B** (the edition/event pages
   and the timeline - the biggest test churn) → **A** → **C**. Expected conflicts only in `translations/messages.en.yml`
   (separate blocks) and `app.scss` (imports pre-created by the foundation) - keep both sides.
5. **Translations** (section 4).
6. **Final verification**: full gates; browser check (dev Selenium recipe, guest / player / organiser / admin) of the
   Sprint League series page, Season One, Hilltop Weekend, Riverside Open, Harbor, Summit, at 320/390/1280 px in
   en/de/fr/ja, with the browser's zone set to Europe/Prague and to America/New_York (Season One: second time shown /
   not shown); the events page unchanged; `EXPLAIN ANALYZE` of `GetEventOccurrences::all()` and `forSeries()` before
   and after; page weight of the three pages before and after; then the PR, which lists README "Conflicts and open
   questions" for Jan.

## 6. Contract gaps found while planning (decided here)

- `EventsPageBuilder`'s row rules are private - extracted to `EventRowFactory` (1.3) rather than duplicated.
- `EventOccurrence` had neither the first round's time nor the registration URL - added (1.2), both from the
  statement that runs anyway.
- `_manage_menu_host.html.twig` read `page.organizedCount` - now a `show_menu` flag (1.1).
- The follow star had no labelled form and its controller assumed rows - extended (1.1), events page markup unchanged.
- Deleting from the ⋯ menu returned to the page it was opened on - a deleted page would 404; `delete_return` (1.1).
- The follow state on edition/event pages rides on the attendance statement (1.2) instead of a viewer statement - one
  statement less for every signed-in visitor of an event page.
- The fixtures had no round puzzles, no round results on a multi-session edition and no in-person multi-day event with
  rounds - `EventDetailFixture` (1.8).

## Answers to the open questions (orchestrator, 2026-10-08 - Jan delegated delivery; listed in the PR)

1. Times use 24-hour format (the site's convention, e.g. "20:00"), through the locale-aware date helpers.
2. Zones are named with the localized generic zone name, on the server via ICU (`IntlTimeZone::getDisplayName`, generic long) and in the browser via `Intl.DateTimeFormat` (`timeZoneName: 'longGeneric'`): "18:45 Eastern Time · 00:45 next day, Central European Time (yours)". Same source (ICU) on both sides, works in all 6 locales.
3. No "how often" fact (a guess from dates can be wrong); facts = editions, since, next date.
4. Round puzzles become compact items inside their round with a link to the puzzle page; fine.
The "Conflicts I resolved" decisions stand as written.
