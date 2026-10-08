# Events page - implementation plan

The build contract for [README.md](README.md) (design of record, Jan's decisions of 2026-10-08). One foundation agent
builds the shared parts first; then four agents (A list UI, B calendar, C organiser + follow, D archive/SEO/cleanup)
work in parallel worktrees, each owning a fixed set of files. Every class, route and file name below is binding - if a
workstream needs a change to a foundation file, it asks the integrator instead of editing it.

Binding rules from `CLAUDE.md`: CQRS through Messenger, repositories never flush, single-action controllers,
`Uuid::uuid7()`, `ClockInterface`, `ResetInterface` for per-request caches, no DQL updates, migrations generated (never
hand-written), Turbo form rules (no 200 answers to full-page POSTs, explicit form `action` inside frames), every user
text in translation files, `json_ld` for anything in a script element, `ReturnUrl::tryFrom()` for every `return`.
**The repo is public: made-up event and person names only** in code, fixtures, tests and docs.

## 0. Ground rules for all agents

- Branch `events-page-redesign` is the integration branch (worktree `.claude/worktrees/events-redesign`).
  Foundation commits there. Workstreams branch from it after the foundation commit:
  `git worktree add .claude/worktrees/events-<x> -b events-<x> events-page-redesign` with
  `<x>` = `list` (A), `calendar` (B), `organizer` (C), `seo` (D).
- Run PHP through `.claude/worktrees/ev-tools/run.sh <worktree> <db-suffix> …` and the gates through
  `.claude/worktrees/ev-tools/gates.sh <worktree> <db-suffix>` (own test DB `speedpuzzling_ev<suffix>_test`; suffixes
  `f`, `a`, `b`, `c`, `d`). Never touch the shared checkout or the dev DB. Never drop a DB whose name does not end
  in `_test`.
- Gates before every commit: phpstan, phpcs (cs-fix), schema validate, `--testsuite "Project Test Suite"`, cache
  warmup. JS: `node --check` on changed controllers, the node harness tests.
- CSS: every new class is prefixed `ev-` (Bootstrap owns `.row`, `.badge`, `.card`; `_compact-bar.scss` owns a global
  `.more-btn`). No `id` attributes inside row/line partials (B clones rows).
- Stimulus controllers that are not needed on first paint start with `/* stimulusFetch: 'lazy' */`.
- No feature flag: the page is replaced in one release (nothing to gate - no data migration, old URLs redirect).

## 1. Foundation (one agent, first)

### 1.1 Entity, migration, repository

`src/Entity/FollowedCompetition.php` (table `followed_competition`, default naming):

```php
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'competition_id'])]
#[UniqueConstraint(columns: ['player_id', 'series_id'])]
class FollowedCompetition
{
    private function __construct(
        #[Id] #[Immutable] #[Column(type: UuidType::NAME, unique: true)] public UuidInterface $id,
        #[Immutable] #[ManyToOne] #[JoinColumn(nullable: false, onDelete: 'CASCADE')] public Player $player,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[ManyToOne] #[JoinColumn(nullable: true, onDelete: 'CASCADE')] public null|Competition $competition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[ManyToOne] #[JoinColumn(name: 'series_id', nullable: true, onDelete: 'CASCADE')] public null|CompetitionSeries $series,
        #[Immutable] #[Column(type: Types::DATETIME_IMMUTABLE)] public DateTimeImmutable $createdAt,
    ) {}
    public static function ofCompetition(UuidInterface $id, Player $player, Competition $competition, DateTimeImmutable $createdAt): self;
    public static function ofSeries(UuidInterface $id, Player $player, CompetitionSeries $series, DateTimeImmutable $createdAt): self;
    // ConvertCompetitionToSeriesHandler: the event became the first edition of a new series
    public function moveToSeries(CompetitionSeries $series): void; // competition = null, series = $series
    public function target(): FollowTarget;
}
```

Exactly one target is set - the named constructors are the only way in (the `ComparisonSubject` pattern; no CHECK
constraint, Doctrine would not keep it). Doctrine adds the FK indexes on `competition_id` and `series_id` (needed by
the phase-3 notifications: "who follows series X").

Migration: **generated**, never hand-written, against a scratch DB built from committed migrations (the dev DB has
drift from other branches):

```bash
S='postgresql://postgres:postgres@postgres:5432/speedpuzzling_events_migcheck?serverVersion=16&charset=utf8'
run.sh events-redesign f sh -c "DATABASE_URL='$S' APP_ENV=dev php bin/console doctrine:database:create && \
  DATABASE_URL='$S' APP_ENV=dev php bin/console doctrine:migrations:migrate -n && \
  DATABASE_URL='$S' APP_ENV=dev php bin/console doctrine:migrations:diff -n"
```

Read the generated file line by line: it must contain only `CREATE TABLE followed_competition`, its two unique
indexes, two FK indexes and three FKs with `ON DELETE CASCADE`. Then drop `speedpuzzling_events_migcheck` (scratch URL
written literally in that one command).

`src/Repository/FollowedCompetitionRepository.php` (readonly, wraps `EntityManagerInterface`, never flushes):
`find(Player $player, FollowTarget $target): null|FollowedCompetition`, `save()`, `delete()`,
`listForCompetition(Competition $competition): list<FollowedCompetition>`.

### 1.2 Values

| Class | Contract |
|---|---|
| `src/Value/FollowTarget.php` (readonly) | `kind: FollowTargetKind`, `id: string`; `competition(string)`, `series(string)`, `tryFromString(string): ?self` (`competition:<uuid>` / `series:<uuid>`, invalid uuid → null), `toString()` |
| `src/Value/FollowTargetKind.php` (enum string) | `Competition = 'competition'`, `Series = 'series'` |
| `src/Value/EventsScope.php` (readonly) | `everywhere()`, `online()`, `country(CountryCode)`, `fromQuery(mixed $country, mixed $onlineOnly)` (onlineOnly `1`/`true` wins; unknown country → everywhere), `matches(bool $isOnline, null\|CountryCode $country): bool` (online scope: online only; country scope: **in person** and that country), `key(): string` (`all` / `online` / `cz`), `toQuery(): array<string,string>`, `isEverywhere()`, `countryCode(): ?CountryCode` |
| `src/Value/EventsView.php` (enum string) | `List = 'list'`, `Calendar = 'calendar'`; `fromQuery(mixed)` |
| `src/Value/EventOccurrenceStatus.php` (enum string) | `Live = 'live'`, `Upcoming = 'upcoming'`, `Past = 'past'`, `Tba = 'tba'`, `Ongoing = 'ongoing'`, `DateNotSet = 'notset'`; `isComing()` (live/upcoming/tba) |
| `src/Value/CountryRegion.php` (enum string) | the region lists moved out of `EventsListing::getCountryChoicesGroupedByRegion()`: `CentralEurope`, `WesternEurope`, `SouthernEurope`, `NorthernEurope`, `EasternEurope`, `NorthAmerica`, `RestOfWorld`; `forCountry(CountryCode): self`, `translationKey(): string` (the existing `sell_swap_list.settings.region.*` keys - already in 6 locales), `cases()` order = display order |
| `CountryCode::localizedName(string $locale): string` (new method on the existing enum) | `Countries::getName(strtoupper($this->name), $locale)` when `Countries::exists()`, else `$this->value` |

### 1.3 Messages, handlers, exceptions

- `src/Message/FollowCompetition.php`, `src/Message/UnfollowCompetition.php`: `readonly final`, `string $playerId`,
  `string $target` (`FollowTarget::toString()`), `implements SerializedByLock`, `lockKey()` =
  `'followed-competitions-' . strtolower($playerId)`.
- `src/MessageHandler/FollowCompetitionHandler.php`: parse target (invalid → `FollowTargetNotAvailable`); competition:
  must exist, be standalone (`series === null`; an edition is followed through its series) and publicly visible
  (`IsCompetitionPubliclyVisible::check()`); series: exists, approved and not rejected. Already followed → no-op.
  Creates `FollowedCompetition::of…(Uuid::uuid7(), …, $clock->now())`, `save()`.
- `src/MessageHandler/UnfollowCompetitionHandler.php`: delete the row when present; no-op otherwise (an unfollow of a
  target that is no longer visible must still work).
- `src/Exceptions/FollowTargetNotAvailable.php` extends `NotFoundHttpException` (one answer for unknown, edition,
  unapproved, rejected).
- `ConvertCompetitionToSeriesHandler`: before its flush, `moveToSeries($series)` for every
  `FollowedCompetitionRepository::listForCompetition($competition)`.

### 1.4 Read models (`src/Query/`, results in `src/Results/`)

All statements are plain DBAL, ids compared lower-case, no `src/Query` statement returns player identity (only ids of
the viewer's own rows and counts).

**`GetEventOccurrences::all(bool $includeUnapproved): list<EventOccurrence>`** - one statement, one-time events and
editions together, ordered `start_date NULLS LAST, name`:

```sql
SELECT c.id, c.name, c.slug, c.logo, c.series_id, cs.name AS series_name, cs.slug AS series_slug,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS country_code,
    c.location_country_code AS own_country_code, cs.location_country_code AS series_country_code,
    CASE WHEN c.series_id IS NULL THEN c.is_online ELSE cs.is_online END AS is_online,
    c.date_from, c.date_to,
    r.first_starts_at, r.last_starts_at, r.round_timezone, COALESCE(r.round_count, 0) AS round_count,
    (c.registration_link IS NOT NULL AND c.registration_managed = false) AS has_registration_link,
    c.registration_managed, c.capacity, c.registration_opens_at, c.registration_closes_at, c.registration_timezone,
    (c.results_link IS NOT NULL OR EXISTS (
        SELECT 1 FROM competition_round rr WHERE rr.competition_id = c.id AND (
            EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.competition_round_id = rr.id AND pst.suspicious = false)
            OR {GetPublishedRoundResults::sqlShowsOfficialResults('rr')}))) AS has_results,
    ({IsCompetitionPubliclyVisible::SQL_CONDITION}) AS is_public
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN (SELECT competition_id, MIN(starts_at) AS first_starts_at, MAX(starts_at) AS last_starts_at,
           MIN(timezone) AS round_timezone, COUNT(*) AS round_count
           FROM competition_round GROUP BY competition_id) r ON r.competition_id = c.id
WHERE {IsCompetitionPubliclyVisible::SQL_CONDITION}
-- $includeUnapproved (admins): WHERE c.rejected_at IS NULL AND (c.series_id IS NULL OR cs.rejected_at IS NULL)
```

Mapping (PHP, in the query class): an edition's start = the day of `first_starts_at` in
`RoundTimezone::resolve(round_timezone, own_country_code, series_country_code)`, else `date_from`, else `date_to`;
its end = the later of `date_to` and the local day of `last_starts_at`, kept only when after the start. A one-time
event: start = `date_from ?? date_to`, end = `date_to ?? date_from`, null when equal to the start. Dates are
date-only `DateTimeImmutable` at 00:00 UTC.

`src/Results/EventOccurrence.php` (readonly): `competitionId`, `name`, `slug`, `logo`, `seriesId`, `seriesName`,
`seriesSlug`, `location`, `countryCode: ?CountryCode`, `isOnline`, `startDate: ?DateTimeImmutable`,
`endDate: ?DateTimeImmutable`, `roundCount`, `hasRegistrationLink`, `registrationManaged`, `capacity: ?int`,
`registrationOpensAt`, `registrationClosesAt`, `registrationTimezone`, `hasResults`, `isPublic`. Methods:
`isEdition()`, `status(DateTimeImmutable $today): EventOccurrenceStatus` (README "Dates" table; undated: edition →
DateNotSet, online → Ongoing, else Tba), `isLongRunning(): bool` (end − start > `EventsPageBuilder::LONG_RUN_DAYS`),
`editionName(): ?string` (null for a one-time event or when equal to the series name, case-insensitive),
`registrationAvailability(DateTimeImmutable $now): ?RegistrationAvailability` (managed only; window end from
`RegistrationAvailability::eventEndsAt()` in `RoundTimezone::resolve(registrationTimezone, country)`),
`reference(): CompetitionReference`.

**`GetEventSeriesDirectory::all(bool $includeUnapproved): list<EventSeriesRow>`** - one statement:

```sql
SELECT cs.id, cs.name, cs.slug, cs.is_online, cs.location, cs.location_country_code,
       (cs.approved_at IS NOT NULL) AS is_public
FROM competition_series cs
WHERE cs.rejected_at IS NULL AND (cs.approved_at IS NOT NULL /* OR :includeUnapproved */)
ORDER BY cs.name
```

Edition counts, next/last dates come from the occurrences in the builder - no correlated subqueries. Note: the old
`GetCompetitionSeries::allApproved()` lists a series rejected after its approval; this one does not (the
`IsCompetitionPubliclyVisible` rule). `src/Results/EventSeriesRow.php`: `id`, `name`, `slug`, `isOnline`, `location`,
`countryCode`, `isPublic`.

**`GetEventGoingCounts::forCompetitions(list<string> $competitionIds): array<string, int>`** (empty list → `[]`
without a statement):

```sql
SELECT cp.competition_id, COUNT(*) AS spots_taken
FROM competition_participant cp
WHERE cp.competition_id IN (:ids) AND {CompetitionParticipantGoing::sql('cp')}
GROUP BY cp.competition_id
```

Called with the ids of live, upcoming and tba occurrences only.

**`GetEventsViewerData::forPlayer(string $playerId): EventsViewerData`** - one statement (UNION ALL of `kind, id,
series_id`): `going` (competition_participant of the player, going rule), `follow_competition`, `follow_series`
(followed_competition), `organize_competition` (competition created by the player or in `competition_maintainer`,
with its `series_id`), `organize_series` (competition_series created by the player or in
`competition_series_maintainer`). `src/Results/EventsViewerData.php`: `isGoing(string $competitionId)`,
`follows(FollowTarget)`, `followedSeriesIds()`, `followedCompetitionIds()`,
`organizedCompetitionIds(): list<string>` (standalone, plus editions whose series the player does not organise),
`organizedSeriesIds(): list<string>`, `organizedCount(): int` (= the two lists' sizes - the one rule shared by the
header button and the "You organize" page).

**`GetOrganizedEvents::byIds(list<string> $competitionIds, list<string> $seriesIds): list<OrganizedEvent>`** - for
the `organized_events` page (two statements: competitions incl. unapproved and rejected, series incl. unapproved and
rejected with their editions' dates for next/last/count by the first-round rule). `src/Results/OrganizedEvent.php`:
`kind: 'event'|'edition'|'series'`, `id`, `name`, `seriesId`, `seriesName`, `slug`, `seriesSlug`, `isOnline`,
`location`, `countryCode`, `startDate`, `endDate`, `roundCount`, `isApproved`, `rejectionReason: ?string`,
`isRejected`, `editionCount`, `nextEditionDate`, `lastEditionDate`; `badge(DateTimeImmutable $today):
OrganizerBadge`. `src/Value/OrganizerBadge.php` (enum): `WaitingForApproval`, `Rejected`, `Live`, `Upcoming`,
`Past`, `DateNotSet` (rejected wins over everything, then waiting).

### 1.5 Builder and view models

`src/Services/EventsPage/EventUrls.php` (readonly, `UrlGeneratorInterface`): `occurrence(EventOccurrence): ?string`
(via `CompetitionReference::routeName()/routeParameters()`), `series(null|string $slug): ?string`,
`archive(int $year): string`.

`src/Services/EventsPage/EventsPageBuilder.php` (readonly service; pure apart from `EventUrls`):

```php
public const int LONG_RUN_DAYS = 14;
public const int CHIP_COUNTRIES = 6;
public const int ARCHIVE_PREVIEW_LINES = 5;
public const int SOON_DAYS = 14;      // "In 12 days" in coral
public const int RELATIVE_DAYS = 30;  // no "when" label beyond

/** @param list<EventOccurrence> $occurrences  @param list<EventSeriesRow> $series  @param array<string,int> $goingCounts */
public function build(array $occurrences, array $series, array $goingCounts, null|EventsViewerData $viewer,
    EventsScope $scope, DateTimeImmutable $today, string $locale, null|CountryCode $homeCountry): EventsPage;

/** @param list<EventOccurrence> $occurrences  (public ones only are used) */
public function buildArchive(array $occurrences, int $year, DateTimeImmutable $today, string $locale): null|EventsArchivePage; // null = no such year (404)
```

`src/Services/EventsPage/EventsIndexFactory.php`: `create(EventsPage $page): list<array<string,mixed>>` (called by
the builder; separate class so its format is unit-tested on its own).

View models (`src/Results/EventsPage/`, readonly):

| Class | Fields |
|---|---|
| `EventsPage` | `summary: EventsSummary`, `yourEvents: list<YourEvent>`, `live: list<AgendaRow>`, `months: list<AgendaMonth>`, `tba: list<AgendaRow>`, `seriesInPerson: list<SeriesLine>`, `seriesOnline: list<SeriesLine>`, `ongoing: list<AgendaRow>` (undated online events + long spans running now), `archiveYears: list<ArchiveYear>` (all public past, newest first), `countryCounts: list<CountryCount>` (sheet: every country with an in-person occurrence), `chipCountries: list<CountryCount>` (≤ 6 with upcoming, + the active scope's country when not among them), `homeCountry: ?CountryCount` (with zeros when nothing there), `onlineUpcoming: int`, `everywhereUpcoming: int`, `organizedCount: int`, `scope: EventsScope`, `scopeUpcoming: int`, `scopeLast: ?ArchiveLine` (empty state), `index: list<array>`, `itemListUrls: list<string>`, `regions: list<CountryRegionGroup>` |
| `EventsSummary` | `upcomingDates`, `countries` (in person), `hasOnline`, `series` (public) |
| `AgendaRow` | `indexIds: list<int>`, `isGroup`, `title`, `editionName: ?string`, `url: ?string`, `leaf: DateLeaf`, `place: Place`, `tags: list<RowTag>`, `when: ?WhenLabel`, `sessions: list<SessionChip>` (group only), `status: EventOccurrenceStatus`, `scopeKey: string` (`online` / country code / `''`), `from: ?string`, `to: ?string` (`Y-m-d`; long-running: `to` = `from`), `followTarget: ?FollowTarget`, `followName: string`, `following: bool`, `manage: ?ManageRef`, `isPending`, `visible: bool` (in the request's scope), `logo: ?string` (Your events only) |
| `AgendaMonth` | `year`, `month`, `firstDay: DateTimeImmutable`, `rows: list<AgendaRow>`, `visibleCount: int` |
| `DateLeaf` | `from: ?DateTimeImmutable`, `to: ?DateTimeImmutable` (null: one day, long-running or group), `tone: 'in_person'\|'online'\|'muted'` |
| `Place` | `city: ?string` (null when the location contains the country name, folded compare), `country: ?string` (localised), `countryCode: ?CountryCode`, `isOnline` |
| `RowTag` | `type: RowTagType` enum (`WaitingForApproval`, `Going`, `Recurring`, `Registration`, `RegistrationOpen`, `RegistrationOpens`, `RegistrationClosed`, `FullWaitlist`, `Results`, `RunsUntil`, `GoingCount`), `date: ?DateTimeImmutable`, `count: ?int` |
| `WhenLabel` | `type: 'live'\|'tomorrow'\|'this_weekend'\|'in_days'`, `days: int`, `soon: bool` |
| `SessionChip` | `indexId`, `url`, `date: DateTimeImmutable`, `title` |
| `SeriesLine` | `indexId`, `seriesId`, `name`, `url`, `place: Place`, `isOnline`, `editionCount`, `next: SeriesNext`, `followTarget`, `following`, `manage: ?ManageRef`, `isPending`, `scopeKey`, `visible` |
| `SeriesNext` | `type: 'next'\|'live'\|'last'\|'none'`, `date: ?DateTimeImmutable` |
| `ArchiveYear` | `year`, `lines: list<ArchiveLine>` |
| `ArchiveLine` | `indexIds`, `title` (rolled up: series name), `url` (rolled up: series page), `from`, `to: ?`, `editionCount` (1 = single), `monthFrom`, `monthTo` (roll-up label), `hasResults`, `place: Place`, `scopeKey`, `visible` |
| `YourEvent` | `row: AgendaRow`, `mark: 'going'\|'following'` |
| `CountryCount` | `code: CountryCode`, `name` (localised), `upcoming`, `tba`, `past`, `region: CountryRegion` |
| `CountryRegionGroup` | `region: CountryRegion`, `countries: list<CountryCount>` |
| `ManageRef` | `kind: 'competition'\|'series'`, `id`, `name` |
| `EventsArchivePage` | `year`, `lines: list<ArchiveLine>`, `years: list<int>`, `itemListUrls: list<string>`, `count: int` |

Builder rules (each one has a unit test):

- Only public occurrences/series are counted (summary, chips, sheet, months' counts). Pending ones (admins) are
  rows with the `WaitingForApproval` tag and `isPending`, never counted, never in "Your events", never in the archive.
- Online occurrences count under Online only (README "Scope").
- Live = live, by start. Months = upcoming, by start; **roll-up**: ≥ 2 upcoming editions of one series in
  one calendar month → one group row at the first one's position (leaf = first date, sessions = all, follow =
  series, manage = series, tags: Going when the viewer goes to any, Recurring). TBA = tba, by name. Ongoing =
  ongoing (undated online events, long spans running now), by name; a long span running now counts as upcoming in the
  scope, country and summary counts. `DateNotSet` editions only count in their series' edition count.
- When labels: live → `live` (shown as "Live" with the pulsing `.live-dot`); n = days to start: 1 → tomorrow; n ≤ 6 and start
  is Fri/Sat/Sun → this_weekend; n ≤ 30 → in_days (soon when ≤ 14); else none.
- Tags in this order: WaitingForApproval, Going (viewer), Recurring (edition), registration (managed: Open / Opens
  `date` / Closed, and FullWaitlist when spots taken ≥ capacity while Open; external link only: Registration; none
  for past or going), Results (past only), RunsUntil (long-running, not past), GoingCount (> 0, not past).
- Follow: a one-time event follows itself (not when past), an edition follows its series; `following` from the
  viewer. Manage: `ManageRef` for every row; whether ⋯ shows is decided by the voters in the template (C).
- Series directory: public (+ pending for admins) series split by `isOnline`; edition count = every edition incl.
  date-not-set; next = earliest upcoming edition, `live` when one is live and none upcoming, else last = latest
  past, else none; sorted next-by-date, then last (newest first), then none, then name.
- Archive: past public occurrences by start year; within a year, ≥ 2 editions of one series → one roll-up line placed
  at its newest edition; lines newest first.
- Your events (viewer, coming only): going occurrences (live/upcoming/tba of any kind); followed one-time events
  (live/upcoming/tba/ongoing); for every followed series its next live-or-upcoming edition. Deduplicated by
  occurrence, `going` wins; ordered by start, undated last.
- Country counts: upcoming (live + upcoming), tba, past per in-person country; chips = countries with upcoming > 0,
  by upcoming desc, then name; `regions` groups the sheet by `CountryRegion`, inside by upcoming desc, then name.
- `itemListUrls`: URLs of public live, upcoming and tba occurrences in agenda order (each edition separately).
- Index: one entry per occurrence shown on any view (public, + pending for admins) and per series line, positions =
  ids. Format (keys are short on purpose, ~150 B per entry):

```json
{"id": 12, "k": "e|d|s", "n": "Harbor Jigsaw Nights", "en": "Session 3", "sid": 40, "u": "/en/series/harbor-jigsaw-nights/session-3",
 "f": "2026-12-05", "t": null, "lr": false, "sc": "online", "c": "ca", "p": "Online",
 "st": "upcoming", "r": false, "w": false, "x": "harbor jigsaw nights session 3 online canada kanada 2026"}
```

`k` e = one-time event, d = edition, s = series (no dates, `st` = null); `sc` = scope key; `p` = the place label
("Hamburg, Germany" / "Online" / localised); `x` = `SearchText::fold()` of name, edition name, series name, location,
the country's localised **and** English name, the start year and "online" when online.

### 1.6 Controllers and routes

| Route | Controller | Path(s) | Notes |
|---|---|---|---|
| `events` (rewrite) | `src/Controller/EventsController.php` | unchanged 6 localized paths | `LegacyEventsQueryRedirect::redirectFor($request)` first (returns `?RedirectResponse`); then 4 statements (3 for guests) + builder; renders `events.html.twig` with `page`, `view: EventsView`, `month: ?string` (`Y-m` from `?month=`, validated), `query: string` (`?q=`) |
| `events_archive` | `src/Controller/Events/EventsArchiveController.php` | `cs` `/eventy/archiv/{year}`, `en` `/en/events/archive/{year}`, `es` `/es/eventos/archivo/{year}`, `ja` `/ja/イベント/アーカイブ/{year}`, `fr` `/fr/evenements/archives/{year}`, `de` `/de/veranstaltungen/archiv/{year}`; `requirements: ['year' => '\d{4}']` | `buildArchive()` null → `NotFoundHttpException`; renders `events/archive.html.twig` with `archive`; public occurrences only |
| `organized_events` | `src/Controller/Events/OrganizedEventsController.php` | `/{_locale}/you-organize` | `#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]`; viewer data → `GetOrganizedEvents::byIds()`; renders `events/organized.html.twig` with `items`, `today`; `noindex` |
| `event_follow` | `src/Controller/Events/FollowEventController.php` | `/{_locale}/follow-event`, POST | see below |
| `event_unfollow` | `src/Controller/Events/UnfollowEventController.php` | `/{_locale}/unfollow-event`, POST | see below |

`/{_locale}/follow-event`, not `/{_locale}/events/follow`: `/en/events/{slug}` (`event_detail`) has no method
restriction and would take it.

Follow/unfollow: `IS_AUTHENTICATED_REMEMBERED`; stateless CSRF token id `event_follow` (add to
`config/packages/csrf.php` `stateless_token_ids` with a comment - the star sits on a page every player opens);
fields `_token`, `target`, `return`. Invalid token or `FollowTargetNotAvailable` → JSON `{"error": "…"}` 422/404, or a
`warning`/`danger` flash + redirect. `UniqueConstraintViolationException` (race past the lock) = success. Success:
when `Accept` contains `application/json` → `200 {"following": bool, "target": "series:…"}`; otherwise
`303` to `ReturnUrl::tryFrom($return)` or `events`, flash `events_organizer.follow.flash_followed` /
`flash_unfollowed`.

`src/Services/EventsPage/LegacyEventsQueryRedirect.php` - foundation ships the stub `redirectFor(Request): ?RedirectResponse { return null; }`
with its docblock; D implements it.

### 1.7 Page skeleton, partials, assets (foundation creates working minimal versions)

The skeleton renders every section unstyled but correct, so the controller tests and the query budget pass before
any UI work. Each file then belongs to exactly one workstream (column "Owner"); others only `include` it with the
variables listed.

`templates/events.html.twig` (owner A) - fixed structure:

```twig
{% extends 'base.html.twig' %}
{% block title %}{{ 'events.meta.title'|trans }}{% endblock %}
{% block meta_description %}{{ 'events.meta.description'|trans }}{% endblock %}
{% block robots %}{{ include('events/_robots.html.twig') }}{% endblock %}
{% block json_ld %}{{ parent() }}{{ include('events/_item_list_json_ld.html.twig', {urls: page.itemListUrls}) }}{% endblock %}
{% block content %}
<div class="ev-page" data-controller="events-page" {# values: scope, view, query, month, home, signedIn, messages #}>
    {{ include('events/_header.html.twig') }}
    {{ include('events/_toolbar.html.twig') }}
    <div class="ev-body">
        <div class="ev-main">
            <div class="ev-list-view" data-events-page-target="listView" {{ view.value == 'calendar' ? 'hidden' }}>
                {{ include('events/_your_events.html.twig') }}
                {{ include('events/_agenda.html.twig') }}
                {{ include('events/_series_directory.html.twig') }}
                {{ include('events/_archive_section.html.twig') }}
                {{ include('events/_search_results.html.twig') }}
                {{ include('events/_page_foot.html.twig') }}
            </div>
            {{ include('events/_calendar.html.twig') }}
        </div>
        <aside class="ev-rail">
            {{ include('events/_rail_countries.html.twig') }}
            {{ include('events/_rail_calendar.html.twig') }}
        </aside>
    </div>
    {{ include('events/_country_sheet.html.twig') }}
    {{ include('events/_manage_menu_host.html.twig') }}
    <template data-events-archive-line-template>{{ include('events/_archive_line.html.twig', {line: null, template: true}) }}</template>
    <script type="application/json" data-events-index>{{ page.index|json_ld }}</script>
</div>
{% endblock %}
```

| Partial | Owner | Variables (contract) |
|---|---|---|
| `events/_header.html.twig` | A | `page`; includes `events/_organize_button.html.twig` with `{count: page.organizedCount}` |
| `events/_toolbar.html.twig`, `_country_sheet.html.twig`, `_rail_countries.html.twig` | A | `page`, `view`, `query` |
| `events/_your_events.html.twig` | A | `page.yourEvents`; each card includes `events/_your_event_mark.html.twig` with `{mark}` |
| `events/_agenda.html.twig`, `_series_directory.html.twig`, `_archive_section.html.twig`, `_search_results.html.twig`, `_page_foot.html.twig` | A | `page` |
| `events/_row.html.twig` | A | `{row: AgendaRow}`. Root `.ev-row` carries `data-ev-ids="3 4"`, `data-ev-scope`, `data-ev-from`, `data-ev-to`, `data-ev-status`, `hidden` when `not row.visible`; includes `_date_leaf` and `_row_actions`; no `id` attributes |
| `events/_date_leaf.html.twig` | A | `{leaf: DateLeaf}` |
| `events/_archive_line.html.twig` | A | `{line: ?ArchiveLine, template: bool, with_year: bool}`; root `.ev-line` with `data-ev-ids`; in template mode every value is an empty element with `data-slot="date|title|results|place"` and the link `data-slot="link"` - `fillArchiveLine()` fills exactly these |
| `events/_row_actions.html.twig` | C | `{follow_target: ?FollowTarget, follow_name: string, following: bool, show_star: bool, manage: ?ManageRef}` - star + ⋯; foundation stub renders the star form only |
| `events/_organize_button.html.twig`, `_your_event_mark.html.twig`, `_manage_menu_host.html.twig` | C | as above; stubs render the plain link / label / nothing |
| `events/_calendar.html.twig`, `_rail_calendar.html.twig` | B | `page`, `view`, `month`; stubs render an empty container `hidden` unless calendar view |
| `events/_robots.html.twig`, `_item_list_json_ld.html.twig` | D | `{urls}`; stubs: `index, follow` / nothing |
| `events/archive.html.twig` | D | `archive: EventsArchivePage`; stub: H1 + lines via `_archive_line` |
| `events/organized.html.twig` | C | `items`, `today`; stub: a plain list |

Assets created empty by foundation (each with a header comment naming its owner and this document):

- SCSS: `assets/styles/_events-page.scss` (A), `_events-calendar.scss` (B), `_events-organizer.scss` (C),
  `_events-archive.scss` (D); imported in `app.scss` after `@import "results-desk";` in that order.
- Stimulus: `assets/controllers/events_page_controller.js` (A), `events_calendar_controller.js` (B),
  `event_follow_controller.js` (C), `event_manage_menu_controller.js` (C), `inline_confirm_controller.js` (C) - each
  an empty `Controller` subclass.
- `assets/events_index.js` (foundation, **complete**; A and B import it, nobody else edits it):
  `readEventsIndex(root): Array`, `scopeMatches(scopeKey, scope): boolean`, `createQueryMatcher(text): (entry) =>
  boolean` (folds with `foldSearchText()`; every token must be in `entry.x`, `wjpc` / `ejpc` also match
  `world jigsaw puzzle championship` / `european jigsaw puzzle championship`), `occursOn(entry, isoDay)`,
  `overlapsMonth(entry, year, month0)`, `fillArchiveLine(templateRoot, entry, {locale, withYear, resultsLabel,
  onlineLabel}): HTMLElement`, `LONG_RUN_DAYS`.

### 1.8 Translations (English, foundation adds all of it)

Four top-level blocks in `translations/messages.en.yml`, right after the `events:` block, each starting with a
comment line naming its owner and separated by a blank line (so later edits of different blocks never touch adjacent
lines). Plurals use the site's `one|many` form with `%count%`. Workstreams only add keys **inside their own block**.

```yaml
# Events page - list (workstream A, docs/features/events-page/)
events_page:
    title: "Events"
    summary:
        dates: "{0}No upcoming dates|{1}1 upcoming date|]1,Inf[%count% upcoming dates"
        countries: "in %count% country|in %count% countries"
        and_online: "and online"
        only_online: "online"
        series: "%count% series|%count% series"
    add_event: "Add event"
    guide: "Guide for organisers"
    search:
        label: "Search events"
        placeholder: "Search events, series, places"
        clear: "Clear search"
        upcoming: "Upcoming"
        past: "Past"
        series: "Series"
        no_upcoming: "No upcoming event matches."
        no_past: "No past event matches."
        past_limited: "The %shown% most recent of %count%. Add a year or a country to narrow it down."
        nothing: "Nothing found for “%query%”"
        nothing_hint: "Try a city or a country, or add the event yourself."
    view:
        label: "View"
        list: "List"
        calendar: "Calendar"
    scope:
        label: "Where"
        everywhere: "Everywhere"
        online: "Online"
        all_countries: "All countries"
        more_countries: "More countries…"
        guessed: "Guessed from your browser"
    sheet:
        title: "Countries"
        done: "Done"
        search_label: "Search countries"
        search_placeholder: "Search countries"
        upcoming: "%count% upcoming|%count% upcoming"
        tba: "%count% date TBA|%count% dates TBA"
        past: "%count% past|%count% past"
        empty: "No events in a country with that name yet."
        note: "Countries with at least one in-person event or edition. Online ones are under Online."
    your_events:
        title: "Your events"
    agenda:
        upcoming: "Upcoming"
        upcoming_in: "Upcoming · %scope%"
        dates_count: "%count% date|%count% dates"
        events_count: "%count% event|%count% events"
        without_date: "%count% without a date|%count% without a date"
        live: "Live"
        tba: "Date to be announced"
        no_upcoming_in: "No upcoming dates in %scope% yet."
        no_upcoming_online: "No upcoming dates online yet."
    empty:
        none_in: "No upcoming events in %scope%"
        last_one: "The last one was %name% (%month%)."
        none_yet: "None on MySpeedPuzzling yet."
        know_one: "Know about one?"
    home_callout:
        nothing_planned: "Nothing planned in %country% yet."
        know_one: "Know about an event?"
        add_it: "Add it to the calendar"
    when:
        live: "Live"
        tomorrow: "Tomorrow"
        this_weekend: "This weekend"
        in_days: "In %count% day|In %count% days"
    tag:
        waiting_for_approval: "Waiting for approval"
        going: "Going"
        recurring: "Recurring"
        registration: "Registration"
        registration_open: "Registration open"
        registration_opens: "Opens %date%"
        registration_closed: "Registration closed"
        full_waitlist: "Full · waitlist"
        results: "Results"
        runs_until: "Runs until %date%"
        going_count: "%count% going|%count% going"
    row:
        sessions: "%count% session|%count% sessions"
    place:
        online: "Online"
    leaf:
        tba: "TBA"
        date: "date"
    series:
        title: "Series"
        in_person: "In person"
        online: "Online"
        ongoing_title: "Ongoing"
        editions: "%count% edition|%count% editions"
        no_editions: "no editions yet"
        next: "Next: %date%"
        last: "Last: %date%"
        live: "Live"
        no_dates: "No dates yet"
        no_fixed_dates: "Online · no fixed dates"
        ongoing: "Ongoing"
    archive:
        title: "Archive"
        show_all: "Show all %year% (%count%)"
        past_in: "Past · %scope%"
        none: "None yet."
        editions_in: "%series% · %count% edition in %year%|%series% · %count% editions in %year%"
    foot:
        title: "Organising a puzzle event?"
        text: "Add it to the calendar. It's free, and puzzlers can say they're going."
    rail:
        countries: "Countries"

# Events page - calendar (workstream B)
events_calendar:
    prev_month: "Previous month"
    next_month: "Next month"
    today: "Today"
    key_in_person: "in person"
    key_online: "online"
    key_past: "past"
    day_label: "%day%: %count% event|%day%: %count% events"
    more: "+%count% more"
    highlighted: "%date% is highlighted"
    whole_month: "Show the whole month"
    empty: "Nothing on the calendar in %month%"
    empty_matching: "Nothing matching in %month%"
    empty_hint: "Try the next month."
    empty_hint_scope: "Try the next month, or Everywhere."
    run_all_month: "runs all month, until %until%"
    run_from: "from %from%, until %until%"
    run_until: "all month, until %until%"
    noscript: "The calendar needs JavaScript. The list has every upcoming date."

# Events page - organisers and following (workstream C)
events_organizer:
    organize:
        button: "You organize"
        title: "You organize"
        note: "Events and series you created or maintain. The ⋯ button on their rows opens the same actions."
        empty: "You don't organise any event yet."
        reason: "Reason: %reason%"
        next: "Next: %date%"
        editions: "%count% edition|%count% editions"
    type:
        event: "Event"
        edition: "Edition"
        series: "Series"
    badge:
        waiting_for_approval: "Waiting for approval"
        rejected: "Rejected"
        live: "Live"
        upcoming: "Upcoming"
        past: "Past"
        date_not_set: "Date not set"
    menu:
        open: "Manage %name%"
        waiting_suffix: "waiting for approval"
        edit_event: "Edit event"
        rounds: "Rounds & puzzles"
        participants: "Participants"
        results: "Results"
        page_content: "Page content"
        delete: "Delete"
        manage_series: "Manage series"
        add_edition: "Add edition"
        delete_series: "Delete series"
        approve_event: "Approve event"
        reject_event: "Reject event…"
        approve_series: "Approve series"
        reject_series: "Reject series…"
        reject_reason: "Reason for the organiser"
        reject_submit: "Reject"
        delete_confirm: "Delete “%name%”? This cannot be undone."
        cancel: "Cancel"
        close: "Close"
    follow:
        follow: "Follow %name%"
        following: "Following %name%"
        title_follow: "Follow"
        title_following: "Following"
        sign_in_note: "Sign in to follow events and series."
        sign_in: "Sign in"
        flash_followed: "You follow %name%."
        flash_unfollowed: "You no longer follow %name%."
        not_available: "This event can't be followed."
        try_again: "That didn't work. Please try again."
    mark:
        going: "Going"
        following: "Following"

# Events page - year archive (workstream D)
events_archive:
    meta:
        title: "Speed puzzling events %year% - results and archive"
        description: "Every speed puzzling competition and event of %year% on MySpeedPuzzling: dates, places and results, in person and online."
    heading: "Events in %year%"
    intro: "%count% event or edition|%count% events and editions"
    years: "Other years"
    back: "Upcoming events"
```

Strings for JavaScript (counts that change in the browser) go through `browser_translation()` +
`assets/translation_choice.js` (`chooseTranslation`); plain strings through `|trans` into a `messages` Object value.
Month and weekday names come from ICU (Twig `format_datetime` patterns `LLLL yyyy`, `EEE`, `d`, `LLL`; JS
`Intl.DateTimeFormat(document.documentElement.lang, {timeZone: 'UTC'})`), never from translation keys.

### 1.9 Fixtures

New `tests/DataFixtures/EventsPageFixture.php` (ids `018d0040-0000-0000-0000-0000000000NN`, depends on
`PlayerFixture`, `CompetitionFixture`, `CompetitionSeriesFixture`). Existing fixture constants stay untouched. Dates
are anchored so they hold for weeks after the test DB is built (the cache keeps a DB for days):

| Const | What | Purpose |
|---|---|---|
| `SERIES_HARBOR_NIGHTS` (01) "Harbor Jigsaw Nights" | online, `ca`, approved, created by PLAYER_ADMIN | online series with a country → counts under Online only |
| `EDITION_HARBOR_1..3` (11–13) "Session 1..3" | days 5, 12, 19 of the month 2 months ahead (`first day of +2 months`); session 1 has `ROUND_HARBOR_1` (21) at 23:30 America/Toronto that day | month roll-up; date in the round's zone (UTC is the next day) |
| `EDITION_HARBOR_PAST_A/B` (14, 15) | 10 June and 24 June of last year | archive roll-up "2 editions in YYYY" |
| `EDITION_HARBOR_UNDATED` (16) | no date, no rounds | date not set: counted, never listed |
| `SERIES_CLOCK_MARATHON` (02) "Lakeside Clock Marathon" | in person, `us`, approved | |
| `EDITION_CLOCK_LONG` (17) "Lakeside Clock Marathon" | −30 days to +400 days | live + long-running; edition name equals series name |
| `COMPETITION_RIVERSIDE_OPEN` (31) "Riverside Puzzle Open" | in person, Hamburg, `de`, +20..+21 days, managed registration open since −10 days, capacity 2; participants `PARTICIPANT_RIVERSIDE_A` (connected PLAYER_WITH_FAVORITES), `_B` (unlinked), `_WAITLISTED` (waitlisted) | Full · waitlist, going count 2 |
| `COMPETITION_MEADOW_TBA` (32) "Meadow Puzzle Championship" | in person, `ro`, no dates, external registration link | Date to be announced + "Registration" |
| `COMPETITION_ENDLESS_RELAY` (33) "Endless Online Puzzle Relay" | online, no dates | Ongoing |
| `COMPETITION_VALLEY_CUP_LAST_YEAR` (34) "Valley Speed Puzzle Cup" | in person, `cz`, 14 March last year, results link | archive, Results tag |
| `COMPETITION_VALLEY_CUP_TWO_YEARS_AGO` (35) | the same, two years ago, no results | second archive year |
| `COMPETITION_GARDEN_SWAP_REJECTED` (36) "Garden Swap Evening" | in person, `cz`, +50 days, created by PLAYER_REGULAR, rejected with reason "A swap meet without timed rounds." | "You organize" shows Rejected + reason; never listed |
| `SERIES_SUMMIT_LEAGUE` (03) "Summit Puzzle League" | in person, `at`, approved, no editions | "No dates yet" |
| `SERIES_OLD_MILL_REJECTED` (04) "Old Mill Puzzle Nights" | approved **and** rejected later, one edition (18) +10 days | never listed (the old `allApproved()` bug) |
| `FOLLOW_REGULAR_HARBOR` (41), `FOLLOW_REGULAR_MEADOW` (42), `FOLLOW_FAVORITES_RIVERSIDE` (43) | `FollowedCompetition` rows | Your events: next Harbor session (Following), Meadow (Following, undated last); PLAYER_REGULAR is already going to WJPC 2024 (CompetitionParticipantFixture) |

Existing ones reused: `COMPETITION_UNAPPROVED` / `SERIES_UNAPPROVED` (admin pending rows), `COMPETITION_RECURRING_ONLINE`
(live online event maintained by PLAYER_REGULAR), `EDITION_PAST_ONLY_1` etc. Update `.claude/fixtures.md`
("Competitions" → new subsection "Events page (`EventsPageFixture`, ids `018d0040-…`)"). Expect other tests that
count competitions to change (API v1 competitions, `GetCompetitionSeriesTest`, sitemap, marketplace events, Players
page upcoming events, `GetSelectableCompetitions`, admin queue counts) - the foundation fixes them in the same commit.

### 1.10 Foundation tests

- `tests/MessageHandler/FollowCompetitionHandlerTest.php`: follow event; follow series; twice = one row; edition →
  `FollowTargetNotAvailable`; unapproved event, rejected series, unknown id, malformed target → not available.
- `tests/MessageHandler/UnfollowCompetitionHandlerTest.php`: removes; unknown/not followed = no-op; works for a
  target that became unapproved.
- `tests/MessageHandler/ConvertCompetitionToSeriesHandlerTest.php` (extend): followers move to the series.
- `tests/Query/GetEventOccurrencesTest.php`: public set (no unapproved, no rejected, no edition of a rejected series,
  no Old Mill), admin set adds pending with `isPublic=false`; Harbor session 1 dated by its round in Toronto time;
  Clock edition long-running; statuses of Meadow (tba), Relay (ongoing), Harbor undated (notset); `hasResults` for
  Valley last year.
- `tests/Query/GetEventSeriesDirectoryTest.php`, `GetEventGoingCountsTest.php` (Riverside = 2, waitlisted excluded),
  `GetEventsViewerDataTest.php` (going, follows, organised incl. rejected, `organizedCount()`),
  `GetOrganizedEventsTest.php` (badges incl. Rejected + reason).
- `tests/Services/EventsPage/EventsPageBuilderTest.php` - unit, synthetic occurrences, fixed today (no DB): every
  rule of 1.5 (roll-up in a month and not across months, live never rolled up, archive roll-up and single lines,
  online counted under Online only, pending never counted, chips order/limit + active country appended, home
  country with zeros, when labels incl. weekend and 30-day cut, tags order and registration states, Your events
  dedup/marks/order, series next/live/last/none and sort, `itemListUrls`, place city/country rule).
- `tests/Services/EventsPage/EventsIndexFactoryTest.php`: keys, folding (`ø`, `ß`, accents), localised + English
  country, scope keys.
- `tests/EventsIndexScriptTest.php` + `tests/events-index-harness.mjs` (node, like `SearchFoldParityTest`): scope
  matching, every-token matching, aliases, `occursOn`/`overlapsMonth` incl. long-running, a PHP-folded entry matched
  by a typed query with accents.
- `tests/Controller/EventsControllerTest.php` - **rewritten** (the old markup assertions go): 200 for guest, player,
  admin in all 6 locales; admin sees `Unapproved Puzzle Event`, others do not; `?country=de` renders Riverside
  visible and Harbor hidden; follow/unfollow controllers (JSON and redirect paths, bad token, edition target, guest
  → login); `organized_events` 200 for PLAYER_REGULAR, redirect for guests; `events_archive` 200 for last year, 404
  for `1999` and a future year.
- `tests/Controller/EventsPageQueryBudgetTest.php` (pattern of `PlayersPageQueryBudgetTest`): guest, player,
  maintainer, admin - measured and pinned with `assertQueryCountAtMost` (expected guest = 3, signed in = guest + 1
  viewer + 1 permissions + the fixed signed-in overhead); plus "10 more events and series add no statement" (the old
  `testListingQueryCountDoesNotGrowWithTheNumberOfEvents`, moved here). `events_archive` = 1.
- `tests/Controller/EventsPagePrivacyTest.php`: the `<main>` of the page (guest and player) contains no fixture
  player name or code - the page lists no people, so no blocklist/private canary is needed; if a later phase adds
  people ("3 of your favourite puzzlers"), it must join `BlocklistCanaryTest` and `PrivateProfileCanaryTest`.

`SuspiciousTimeQueryCoverageTest`: `GetEventOccurrences` reads `puzzle_solving_time` and filters
`suspicious = false` - it passes by mentioning it; no allow-list entry.

Foundation done = gates green, page renders every section unstyled, commit on `events-page-redesign`.

### Foundation deviations (as built - read before starting a workstream)

Contracts that differ from the text above, or that the text left open. Everything else is as written.

1. **`EventsIndexFactory`** has no `create(EventsPage)`: the view models do not carry what an entry needs (the
   editions inside an archive roll-up). It builds one entry at a time - `occurrence(int $id, EventOccurrence,
   EventOccurrenceStatus, ?string $url, Place, ?int $seriesIndexId, string $locale)`, `series(SeriesLine,
   EventSeriesRow, string $locale)`, `placeLabel(Place, string $locale)` - and the builder puts the list into
   `page.index`. The entry format is exactly 1.5 (`p` of an online entry = the translated "Online"; `x` holds each
   folded part once).
2. **`build()` / `buildArchive()` take the request's instant as `$today`** (the controller passes `$clock->now()`):
   its UTC date is "today", the instant decides registration windows (opens at 22:30 is not "open" at 10:00).
3. **`src/Value/OccurrenceDates`** is the one dating rule (README "Dates"): `sessions()` (rounds in their
   `RoundTimezone::resolve()` zone, grouped into sessions; one-time events and editions alike), `current()`,
   `status()`, `today()`, `localDay()`. `GetEventOccurrences`, `GetOrganizedEvents`, `OrganizedEvent::badge()` and
   `GetCompetitionSlugsForSitemap::archiveYears()` use it, fed by the shared rounds join `OccurrenceRounds`.
4. **Extra members** (additions only): `EventOccurrence::registrationZone()`; `AgendaRow::idsAttribute()`,
   `datesCount()`; `ArchiveLine` + `editionName`, `year`, `isRollUp()`, `idsAttribute()`; `ArchiveYear::occurrenceCount()`;
   `EventsScope::keyOf(bool $isOnline, ?CountryCode)` (static, the scope key of an item) and `isOnline()`;
   `FollowTarget::isSeries()`, `equals()`; `OrganizedEvent::KIND_*`, `isSeries()`, `reference()`;
   `translationKey()` on `OrganizerBadge`, `RowTagType`, `WhenLabel`, `CountryRegion`; constants for the string types
   (`DateLeaf::TONE_*`, `WhenLabel::*`, `SeriesNext::*`, `YourEvent::MARK_*`, `ManageRef::KIND_*`);
   `CountryRegion::countries()`; `EventsPageBuilder::place()` is public static (the place rule).
5. `RowTagType` lives next to `RowTag` in `src/Results/EventsPage/`.
6. Field meanings the text left open: `AgendaMonth.visibleCount` = dates (a group counts each session) of the public
   rows in the request's scope. `AgendaRow.to` = null for one day, the last day for several, `from` for long-running,
   the last session's date for a group. `ArchiveLine.from/to` are dates (a roll-up: first and last edition of the
   year), `monthFrom/monthTo` ints. `EventsArchivePage.itemListUrls` newest first, like its lines. `scopeLast` = the
   newest past line in scope; a roll-up there is replaced by its newest edition's single line.
7. **Registration tags**: `FullWaitlist` replaces `RegistrationOpen` (never both). A pending row has no registration tag.
8. **"Your events" rows share index ids with their agenda rows.** The stub renders them as `.ev-your-event`, not
   `.ev-row` - keep it that way (A), or B's clone selector `.ev-row[data-ev-ids~="N"]` must be scoped to the agenda.
9. Follow/unfollow share `src/Controller/Events/AbstractEventFollowController.php` (each route stays a single-action
   controller). The success flash is `success`; bad token → `warning`, not available → `danger` (JSON: 422 / 404 with
   `{"error": …}`). Guests are redirected to `login` by `IsGranted`.
10. The `_row_actions` stub renders the star form for signed-in players only (`data-follow-target` on the button, as
    C's star needs); C adds the guest button and the ⋯. Series lines include `_row_actions` too.
11. `organized_events` sorts its items in the controller: rejected, waiting, live, upcoming, date not set, past; then
    name.
12. `events.html.twig` passes `data-events-page-messages-value` with `online`, `results`, `everywhere` - A extends it.
    The skeleton's chips, view switch and search are plain links / a GET form, so the page works without JavaScript.
13. **Query budget** (measured): guest 3, signed in 8 (guest 3 + viewer 1 + overhead 4: account, profile, unread
    conversations, unread notifications), admin 9 (+ review queue counts), archive 1. The pinned signed-in budgets
    already include **+1 for C's permissions statement** (nothing calls the voters yet).
14. The migration also has Doctrine's FK index on `player_id` (3 FK indexes, 2 unique indexes, 3 cascading FKs).
15. Fixture additions: `*_NAME` constants, `EDITION_OLD_MILL` (18), `PARTICIPANT_RIVERSIDE_A/B/WAITLISTED` (51–53),
    `GARDEN_SWAP_REJECTION_REASON`; the past Harbor editions are "Spring Session" / "Summer Session"; the Valley Cups
    carry their year in the name ("Valley Speed Puzzle Cup 2025").
16. Other tests changed by the fixture: `GetMarketplaceEventsTest` / `GetMarketplaceEventsHintStateTest` (PLAYER_WITH_FAVORITES
    now goes to Riverside Open, nearer than WJPC) and `GetSelectableCompetitionsTest` (a second undated edition).
    The old `EventsControllerTest` markup and Live Component tests are gone with the rewrite; `EventsListingTest`
    stays until D deletes the component.

## 2. Workstreams (parallel, after the foundation commit)

Nothing outside the "Owns" list is edited. Read-only use of every foundation file. If a contract is missing
something, the agent writes it down in its final report instead of changing a foundation file.

### A. List page UI (`events-list`)

**Owns:** `templates/events.html.twig`; `templates/events/_header.html.twig`, `_toolbar.html.twig`,
`_country_sheet.html.twig`, `_rail_countries.html.twig`, `_your_events.html.twig`, `_agenda.html.twig`,
`_row.html.twig`, `_date_leaf.html.twig`, `_series_directory.html.twig`, `_archive_section.html.twig`,
`_archive_line.html.twig`, `_search_results.html.twig`, `_page_foot.html.twig`;
`assets/controllers/events_page_controller.js`; `assets/styles/_events-page.scss`; the `events_page:` translation
block; `tests/Controller/EventsListUiTest.php`.

Builds:
- Header (title, summary, Add event → `add_competition` with `return`, guide link), "You organize" include point.
- Toolbar sticky at `top: var(--header-height)`, publishes `--ev-toolbar-height`; phone (< 992 px): slides away on
  scroll down (after 160 px, 6 px hysteresis), back on the first scroll up, never while the search has focus or the
  sheet is open; month headers stick below it (`top: calc(var(--header-height) + var(--ev-toolbar-height))`, moving
  up only after the bar finished sliding - prototype `measureBar`); `prefers-reduced-motion` = no transitions.
- Search input (16 px font, no iOS zoom), clear ×, List | Calendar segmented buttons (`aria-pressed`), chips with
  counts (`aria-pressed`, `fi fi-xx` flags `aria-hidden`), the home chip (profile) and the **guest guess chip**
  (`guessCountry()` from `assets/country_guess.js`, offered only for a country in `page.countryCounts`, labelled
  "Guessed from your browser", never applied); "All countries ▾" opens the sheet.
- Country sheet: `role="dialog" aria-modal="true"`, bottom sheet on phones, centred 440 px on desktop, search
  (folded), grouped by `page.regions` (region names from `CountryRegion::translationKey()`), counts line, focus moves
  in and back, Escape and backdrop close.
- Your events strip (cards: leaf, title, edition, mark; logo when present), Live, months (sticky header
  "Czechia · November 2026" with "3 dates"), month roll-up rows with session chips, TBA group, empty states and the
  home callout, Series directory (two columns on desktop), Ongoing, archive (year chips as links to
  `events_archive`, newest year's 5 lines + "Show all 2026 (58)", other years rendered from the index in place;
  country/online scope: "Past · Country" with all years), footer card, desktop rail Countries card.
- `events_page_controller.js` (values `scope`, `view`, `query`, `month`, `home`, `signedIn`, `messages`): owns the
  state; reads the index (`readEventsIndex`); on every change shows/hides rows, series lines, months, groups and
  recounts headers (`chooseTranslation`), renders search results and past lines with `fillArchiveLine()` into
  `_search_results` containers (Upcoming rows = the existing rows filtered; Past = newest 30 index entries; Series;
  Ongoing), keeps the URL in step (`history.replaceState(history.state, '', url)`: `country`, `onlineOnly`,
  `view`, `q`, `month`), and dispatches **`events-page:state`** (detail `{scope, query, view}`) on its element on
  connect and after every change. Listens to **`events-calendar:day`** (detail `{day: 'YYYY-MM-DD'|null, ids:
  number[]}`) from the rail calendar: scrolls to the first matching row and flashes them, opening the archive year
  when needed (prototype `jumpToDay`).
- Visual spec = the prototype CSS (`ev-` prefixed), mapped to the theme: coral `$primary`, Rubik, 44 px targets,
  date leaf 56 px (64 px desktop), band colours with ≥ 4.5:1 for white text (coral `#c9393f`, online `#2463a6`,
  muted `#5d6679`). Check the leaf in all 6 locales (German "SA.–SO.", French "SAM.–DIM.", Japanese) at 320, 360,
  390 px - `.long` sizes when the weekday string exceeds 3 characters.
- Accessibility: one link per row (the name, stretched with `::after`), star/⋯/session chips above it; month headers
  are `h3`; the agenda is a list per month; visible focus; sheet focus trap; live region announcing the result count
  after search ("12 events").
- Measuring: `window.gtag?.('event', 'events_scope' | 'events_view' | 'events_search', {value})` on user changes only
  (debounced 1 s for search) - see section 6.

Tests (`EventsListUiTest`): summary text; Harbor sessions as one row with 3 chips; Clock edition under Ongoing with
"Runs until"; Meadow under "Date to be announced" with "Registration"; Riverside "Full · waitlist" and "2 going";
Relay under Ongoing; Summit "No dates yet"; Old Mill absent; `?country=ca` hides Harbor (online); `?onlineOnly=1`
shows it; Your events order and marks for PLAYER_REGULAR; home callout for a player whose country has nothing;
archive preview limited to 5 lines with "Show all"; rows carry the data attributes of the contract.

### B. Calendar view (`events-calendar`)

**Owns:** `templates/events/_calendar.html.twig`, `templates/events/_rail_calendar.html.twig`;
`assets/controllers/events_calendar_controller.js`; `assets/styles/_events-calendar.scss`; the `events_calendar:`
block; `tests/Controller/EventsCalendarTest.php`; `tests/events-calendar-harness.mjs` +
`tests/EventsCalendarScriptTest.php` (if the grid logic is extracted into `assets/events_calendar.js`, B owns that file
too).

Builds:
- `?view=calendar`: header ‹ Month Year › and "Today" (disabled on the current month), Monday-first grid built from
  the index; phone grid (44 px cells, up to 3 dot kinds: coral in person, blue online, grey past); desktop (≥ 992 px)
  big grid with up to 2 names per cell (coloured left border) and "+N more"; key line; long-running entries as bars
  under the grid with "from …, until …" texts; a day button highlights its rows ("… is highlighted · Show the whole
  month"), toggling off on a second tap; the month's rows below: live/upcoming rows **cloned** from the list
  (`.ev-row[data-ev-ids~="N"]`), past entries as archive lines (`fillArchiveLine`, with year); empty state with the
  scope hint. Initial month = `?month=` else today's month; prev/next/today update `month` in the URL.
- Honours scope and search: listens to `events-page:state` (`data-action="events-page:state->events-calendar#update"`).
- Rail mini calendar (list view, desktop only): small grid with a dot per day (in person wins over online over past),
  own ‹ ›, a day dispatches `events-calendar:day` with the matching index ids (non-long-running occurrences of that
  day in scope).
- No JS: `_calendar.html.twig` shows the `events_calendar.noscript` line and the list stays visible
  (`<noscript>` + the list view not hidden when the calendar cannot run: the server hides the list only when `view`
  is calendar, and a `<noscript><style>` block shows it again).
- Measuring: `gtag` events `events_calendar_day`, `events_calendar_month` (direction).

Tests: `?view=calendar` renders the calendar container visible and the list hidden; the index contains Harbor's three
sessions with their dates (calendar data); node tests of month building (leading blanks, 28–31 days, dot kinds,
long-running bars, selection of rows for a day, scope + query filtering).

### C. Organiser tools and follow UI (`events-organizer`)

**Owns:** `templates/events/_row_actions.html.twig`, `_follow_star.html.twig`, `_manage_button.html.twig`,
`_manage_menu_host.html.twig`, `_manage_menu.html.twig`, `_organize_button.html.twig`, `_your_event_mark.html.twig`,
`templates/events/organized.html.twig`; new `src/Controller/Events/EventManageMenuController.php`; edits of
`src/Controller/Admin/ApproveCompetitionController.php`, `RejectCompetitionController.php`,
`ApproveCompetitionSeriesController.php`, `RejectCompetitionSeriesController.php`,
`src/Controller/DeleteCompetitionController.php`, `DeleteCompetitionEditionController.php`,
`DeleteCompetitionSeriesController.php` (optional `return` only); `assets/controllers/event_follow_controller.js`,
`event_manage_menu_controller.js`, `inline_confirm_controller.js`; `assets/styles/_events-organizer.scss`; the
`events_organizer:` block; `tests/Controller/EventManageMenuControllerTest.php`,
`tests/Controller/OrganizedEventsPageTest.php`, `tests/Controller/EventsAdminReturnTest.php`.

Builds:
- **Star** (`_follow_star`): `<form method="post" action="{{ path(following ? 'event_unfollow' : 'event_follow') }}">`
  with `_token` (`csrf_token('event_follow')`), `target`, `return` (`app.request.requestUri`), a 44 px button
  (`aria-pressed`, `aria-label` "Follow %name%" / "Following %name%"). `event_follow_controller.js`: on submit
  `preventDefault()` (before Turbo), `fetch` with `Accept: application/json`, flips every star of the same target on
  the page (`[data-follow-target="series:…"]`), restores focus, shows `try_again` on failure; guests get a
  `type="button"` that toggles the sign-in note under the row (`login` with `return`). No star on past rows.
- **⋯ button** (`_manage_button`): shown when `is_granted('COMPETITION_EDIT', id)` (event/edition/group of a
  competition) or `is_granted('COMPETITION_SERIES_EDIT', id)` (series lines, group rows); a link to
  `event_manage_menu` with `data-turbo-frame="event-manage-menu"`, `aria-haspopup="menu"`, `aria-expanded`.
- **Menu route** `event_manage_menu`: `/{_locale}/event-actions/{kind}/{id}` (`kind` `competition|series`, `id`
  `FirstTryConflictsController::ID_REQUIREMENT`), GET, `IS_AUTHENTICATED_REMEMBERED`, 403 without
  `COMPETITION_EDIT` / `COMPETITION_SERIES_EDIT`; renders `<turbo-frame id="event-manage-menu">` with the menu (full
  page with a back link when not a frame request - the no-JS fallback). Items per README "The ⋯ menu", each only when
  its route exists for the item and its voter allows it (Delete: `COMPETITION_DELETE` / `COMPETITION_SERIES_DELETE`;
  Results: `roundCount > 0`; Approve/Reject: admin and not approved). Every link carries `return` =
  `return_title`-style back to the page it was opened from (`events` or `organized_events`). Forms inside the frame
  have explicit `action` and `data-turbo-frame="_top"`. Delete = `inline_confirm_controller.js` (in place: "Delete
  “X”? This cannot be undone." Cancel / Delete; no `confirm()`), posts the existing per-id session token
  (`delete_competition_<id>` / `delete_competition_series_<id>`). Reject shows a required reason textarea in place.
- `event_manage_menu_controller.js`: positions the frame host as a popover under the button on ≥ 992 px, as a bottom
  sheet below; Escape/outside click closes and returns focus to the ⋯ button; a frame load error shows `try_again`.
- **Return support** (small edits): the 4 admin controllers and the 3 delete controllers redirect to
  `ReturnUrl::tryFrom($request->request->getString('return'))` when valid, else exactly as today. Reject keeps
  requiring a reason (empty → flash + back to `return` or the queue).
- **"You organize"**: the header button (labelled, count, calendar icon on desktop) only when
  `page.organizedCount > 0`; the `organized_events` page lists `items` with badge (`OrganizerBadge`), type, date ·
  place or "Next: … · 3 editions", "Reason: …" for rejected, and the actions inline (same routes, same voters, delete
  in place); breadcrumb back to `events`; `noindex`.
- Your-events marks (`_your_event_mark`): "Going" (coral) / "Following".

Tests: menu items per role (owner, maintainer - no Delete, admin - Approve/Reject on pending, stranger → 403,
guest → login); delete/approve/reject honour `return` and reject an off-site `return`; star forms present for players,
sign-in buttons for guests, none on past rows; "You organize (n)" count for PLAYER_REGULAR equals the page's item
count and shows Garden Swap as Rejected with its reason; the page for a player organising nothing has no button.

### D. Archive route, SEO and cleanup (`events-seo`)

**Owns:** `templates/events/archive.html.twig`, `_robots.html.twig`, `_item_list_json_ld.html.twig`;
`src/Services/EventsPage/LegacyEventsQueryRedirect.php` (implementation); `src/Query/GetCompetitionSlugsForSitemap.php`
(new `archiveYears()`), `src/Controller/SitemapEventsController.php`; `assets/styles/_events-archive.scss`; the
`events_archive:` block; deletions below; `tests/Controller/EventsArchiveControllerTest.php`,
`tests/Controller/EventsLegacyRedirectTest.php`, `tests/Controller/SitemapEventsControllerTest.php` (extend).

Builds:
- Archive page: H1 "Events in 2025", intro count, year navigation (every year, current one marked, links), the year's
  lines through `events/_archive_line.html.twig` (roll-ups link the series page), "Upcoming events" back link,
  ItemList JSON-LD of every occurrence URL of the year (rolled-up editions included), `index, follow`, title/meta
  from `events_archive.meta.*`.
- `_robots.html.twig`: `noindex, follow` when `app.request.query.count > 0`, else `index, follow`.
- `_item_list_json_ld.html.twig`: `{"@context": "https://schema.org", "@type": "ItemList", "itemListElement": [{"@type":
  "ListItem", "position": n, "url": absolute url}]}` - every value through `json_ld`; nothing when `urls` is empty.
- `LegacyEventsQueryRedirect::redirectFor()`: README "URL parameters and redirects" (301; `timePeriod=past` without a
  scope → `events_archive` of the newest year with a past public occurrence (one cheap `MAX` statement, only on this
  redirect path), with a scope → the same URL without `timePeriod`; other `timePeriod` values dropped;
  `showCalendar=1` → `view=calendar`; `showCalendar` other values dropped; other parameters kept).
- Sitemap: `archiveYears(): list<int>` (years with past public occurrences, the occurrence dating rule in SQL) and the
  `events_archive` entries in all locales.
- **Cleanup** (grep every name before deleting, update every reference): `src/Component/EventsListing.php`,
  `templates/components/EventsListing.html.twig`, `tests/Component/EventsListingTest.php`,
  `templates/_competition_event.html.twig`, `templates/_competition_series_card.html.twig`, the `.events-calendar*`
  block in `assets/styles/_user.scss`; `GetCompetitionEvents::allForPlayer()`, `GetCompetitionSeries::allForPlayer()`
  and `GetCompetitionSeries::allApproved()` + `filterConditions()` with their tests (`allUnapproved()` and `search()`
  stay - admin queue and API v1 use them); translation keys in **all 6 locales**: `events.wjpc_hub_link`,
  `events.heading`, `events.live`, `events.upcoming`, `events.past`, `events.recurring`, `events.filter.*`,
  `events.calendar.*`, `events.more_info_for_organizers`, `events.no_results`, `competition.my_competitions`,
  `competition.next_edition`, `competition.last_edition` (each only after `grep -rn` shows no use left; keep
  `events.title`, `events.meta.*`, `events.website_link`, `events.results_link`, `events.registration_link`,
  `competition.add_event`, `competition.rejected`, `competition.pending_approval`, `competition.recurring`,
  `competition.online`, `_competition_delete_modal.html.twig`, `_competition_series_delete_modal.html.twig`,
  `_event_date_range.html.twig` - all still used elsewhere); the "Events listing" mention in
  `docs/features/competitions-management/participants.md`.

Tests: archive 200 for last year and two years ago, 404 for a year without past occurrences and `1999`; lines and
roll-up text ("Harbor Jigsaw Nights · 2 editions in YYYY"); ItemList JSON-LD parses and contains Valley Cup's URL;
`/en/events` has `index, follow`, `/en/events?country=cz` has `noindex, follow`; every legacy redirect; sitemap
contains the archive years in 6 locales.

## 3. Tests summary per layer

| Layer | Where | Owner |
|---|---|---|
| Handlers (follow/unfollow, convert) | `tests/MessageHandler/` | Foundation |
| Queries (occurrences, series, going counts, viewer data, organized) | `tests/Query/` | Foundation |
| Builder + index factory (unit) | `tests/Services/EventsPage/` | Foundation |
| Index JS module (node) | `tests/EventsIndexScriptTest.php` | Foundation |
| Controllers: events, archive 404, organized, follow/unfollow | `tests/Controller/EventsControllerTest.php` | Foundation |
| Query budget (guest/player/maintainer/admin, does not grow) | `tests/Controller/EventsPageQueryBudgetTest.php` | Foundation (A keeps it green) |
| No player identity on the page | `tests/Controller/EventsPagePrivacyTest.php` | Foundation |
| List UI, calendar, organiser, archive/SEO/redirects | section 2 | A, B, C, D |

## 4. Performance notes

- Statements per view: guest 3 (occurrences, series, going counts); signed in +1 viewer statement, +1 permissions
  statement (shared by every voter call of the request, `GetCompetitionPermissions` is memoised and `ResetInterface`);
  archive 1. Pinned by the budget test; adding events never adds statements.
- Occurrences: ~250 rows today (every public competition). The round aggregate is a hash join over
  `competition_round` (hundreds of rows); `has_results` uses the FK index on
  `puzzle_solving_time (competition_round_id)`. Expect < 10 ms; run `EXPLAIN ANALYZE` on the dev DB and paste the plan into the foundation PR
  description. Going counts use the `competition_participant (competition_id)` FK index with ~25 ids.
- HTML: the old page was 448 KB. Target < 150 KB for the full page. The index is ~150 B × ~230 entries ≈ 35 KB raw
  (< 10 KB gzipped). Past 1,000 entries, move the index to its own cached JSON endpoint (TODO note).
- The ⋯ menus are loaded on demand: no per-row forms or CSRF tokens in the page (an admin would otherwise carry ~230
  menus and session tokens).
- No new Live Component; `events_calendar`, `event_manage_menu` and `inline_confirm` controllers are lazy.

## 5. Translation plan

1. Foundation writes the complete English blocks (1.8). Workstreams add English keys only inside their own block.
2. After the four merges, one agent translates every new key into cs, de, es, fr, ja (natural, not literal; Czech
   "Akce", German "Veranstaltungen" matching the route words; keep `%placeholders%` and plural forms per locale -
   Czech needs 3 pipe forms, as the existing cs keys do: `%count% datum|%count% data|%count% dat`), and deletes the keys D removed in English from the 5 other files if D missed any.
3. Parity check: `run.sh events-redesign f php bin/console debug:translation <locale> --only-missing
   --domain=messages | grep -E "events_(page|calendar|organizer|archive)"` must print nothing for each of the 5
   locales; the `missing-translations` skill can fill gaps.
4. Visual check of the date leaf and chips in all 6 locales at 320/360/390 px.

## 6. Measuring after launch

Compare with the README baseline after 4 weeks: `?view=calendar` page loads (Tempo/Loki, server side) and the GA
events `events_scope`, `events_view`, `events_search`, `events_calendar_day`, `events_calendar_month` (client side -
chip taps no longer reach the server). Open question for Jan: GA events are enough, or a Loki beacon like the
navigation-failure one? (section 9).

## 7. Order of operations and merge plan

1. **Foundation** in `.claude/worktrees/events-redesign` (DB suffix `f`): 1.1 → 1.10, gates, one commit
   ("Events page: foundation - follow, occurrences read model, builder, skeleton").
2. **Workstreams** A, B, C, D in parallel worktrees from that commit (DB suffixes `a`–`d`), each committing on its
   own branch with gates green. Each agent's final report lists: files changed, contract gaps found, screenshots
   taken (A, B, C).
3. **Merge** into `events-page-redesign` with `git merge --no-ff`, gates after each: **A** (the page) → **C** (slots
   into A's rows) → **B** (listens to A's state) → **D** (deletes what nobody uses any more). Conflicts can only be in
   `translations/messages.en.yml` (separate blocks) and `app.scss` (pre-created imports) - resolve by keeping both.
4. **Translations** (section 5).
5. **Docs**: `CLAUDE.md` feature list - one entry "Events page" pointing here (agenda of occurrences, client-side
   search/scope/calendar over the embedded index, `followed_competition` + "Your events", "You organize" +
   lazy ⋯ menu, `events_archive`, statement budget); `docs/features/competitions-management/README.md` - replace
   "4. Public Listing" and the "Events listing" bullets with a short "Events page" section linking here;
   `.claude/fixtures.md`; `docs/TODO.md` - new section "Events page" (phase 2: marketplace "sellers bringing puzzles"
   tag, "Live results" tag; phase 3: notifications for followed series - new edition, registration opens - and
   "N of your favourite puzzlers are going" (join the blocklist/private canaries then); data clean-up list from the
   README; index endpoint past 1,000 entries; post-launch measurement on the date 4 weeks after deploy; the other
   copies of the region grouping in `MarketplaceListing`/`ManageCompetitionParticipants` could use `CountryRegion`);
   `docs/features/feature_flags.md` unchanged (no flag).
6. **Final verification**: full gates; browser check (dev Selenium recipe) of guest/player/admin, phone 320/390 and
   desktop 1280, list + calendar + sheet + ⋯ + star, in en/de/fr/ja; `EXPLAIN ANALYZE`; page weight; then the PR.

## 8. Spec conflicts found while planning (decided here, flagged to Jan)

1. **Results desk is per round** (`results_desk` takes `{roundId}`). The ⋯ item "Results" opens
   `competition_results_overview` (`/en/manage-event-results/{competitionId}`, the per-event control room linking
   every round's desk), shown when the event has rounds.
2. **"Round start times show in the viewer's time zone"** cannot hold for server-rendered, shared guest HTML. Dates
   are the event's local dates (`RoundTimezone::resolve()`), which fixes the "evening round is the next UTC day"
   problem the spec cites. Rows show no times.
3. **`GetCompetitionSeries::allApproved()` is not reusable as the spec suggests**: it lists series rejected after
   their approval, dates "next edition" by any round (not the first) and has no edition count. The directory uses its
   own statement + the occurrences; `allApproved()` is deleted (D).
4. **The proposal's "Phases" put the guessed home chip in "Polish"**, the final decisions put it in phase 1. Plan:
   phase 1 (cheap - `country_guess.js` exists).
5. **Approve/Reject (admin) redirect to the admin queue and the delete controllers to fixed pages**; C adds optional
   `return`. Approve/Reject have no CSRF check today (admin-only, POST) - unchanged; worth a separate hardening.
6. **Delete forms use session-backed per-id CSRF tokens**; rendering them in every row for admins would write ~230
   tokens into the session. Hence the lazy ⋯ menu (one Turbo Frame).
7. The prototype opens "You organize" as a sheet; the spec makes it a page (`organized_events`) - the plan follows
   the spec.
8. The prototype groups the country sheet by continents; the plan uses the site's existing regions (already
   translated in 6 locales). Country names become localised (they are English enum values elsewhere on the site).
9. In the calendar view, past months list compact archive lines, not full rows (full rows exist only for upcoming
   occurrences in the HTML).

## 9. Open questions for Jan

1. Measuring client-side interactions: GA events (plan) or a server beacon into Loki?
2. H1 is "Events" (prototype) instead of today's "Speed Puzzling Competitions & Events" - the `<title>` keeps the long
   form. OK?
3. Followed one-time events that are past drop out of "Your events" (only coming ones are listed) - OK?
4. Should a maintainer of a single edition (not of its series) see that edition under "You organize"? Plan: yes.

### Answers (orchestrator, 2026-10-08 - Jan delegated delivery; flagged to him in the PR)

1. GA events through the existing GA setup (`window.gtag` when loaded, guarded - never an error when GA is absent or blocked). No new beacon.
2. H1 "Events"; `<title>` and meta keep the long form.
3. Yes: "Your events" lists only live and coming occurrences.
4. Yes: an edition maintainer sees that edition under "You organize".
Section 8 decisions stand as written.
