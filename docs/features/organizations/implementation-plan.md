# Organizations and drafts - implementation plan

The build contract for [README.md](README.md) (the design of record). One foundation agent builds the data model, the
visibility rules, the permissions, every message and handler the pages need, the read models, the fixtures and
skeletons of every new route; then four agents (A organization pages, B drafts, C organiser surfaces and forms, D
restructuring tools and the internal API) work in parallel worktrees, each owning a fixed set of files. **Every class,
route, template, translation namespace and file name below is binding** - a workstream that needs a change to a file it
does not own writes it into its final report instead of editing the file.

Binding rules: `CLAUDE.md` of the worktree and the delivery's `AGENTS.md` (Messenger for every write, repositories
never flush, single-action controllers, `ClockInterface`, `Uuid::uuid7()`, `ResetInterface` for per-request caches,
`json_ld` for every value in a script element, `ReturnUrl::tryFrom()` for every `return`, no 200 to a full-page POST,
explicit `action` inside frames, `'exception' => $e`). **The repository is public: made-up names only** - grep the diff
before every commit (`AGENTS.md`).

## 0. Ground rules for all agents

- Integration branch `feature/organizations`, worktree `.claude/worktrees/agent-a691fe2e21e122dc3` (D1). The foundation
  commits there (own test DB `speedpuzzling_orgf_test`). Workstreams branch from the foundation commit:
  `git worktree add .claude/worktrees/org-ws-<x> -b org-ws-<x> feature/organizations`, `<x>` = `a`, `b`, `c`, `d`, test DB
  `speedpuzzling_org<x>_test`.
- Run PHP with the delivery's `run.sh <worktree> <db> [dev|test] …`. Gates before every commit: cache warmup (dev) +
  phpstan, phpcbf + phpcs, `doctrine:schema:validate` and `cache:warmup` (test env), the targeted tests of what you
  touched. CI runs the full suite in 3 shards - never run it locally.
- **Only the foundation generates a migration** (`doctrine:migrations:diff` against a scratch DB built from the committed
  migrations). No workstream changes an entity's mapping or adds entity methods - everything is in the foundation; ask
  the orchestrator if something is missing.
- **Only the foundation adds fixtures** and edits `.claude/fixtures.md`. Workstreams create extra data inside their tests
  (through messages, like `CompetitionSeriesDetailControllerTest` does).
- **Translations**: only `translations/messages.en.yml` and `translations/validators.en.yml`, each agent in its own
  top-level namespace block(s) appended at the end of the file (section 4). Never edit the other 5 locales (D17).
- **Shared append-only files**: `tests/SuspiciousTimeQueryCoverageTest.php`, `tests/BlocklistQueryCoverageTest.php`,
  `tests/PrivateProfileQueryCoverageTest.php` (wherever it lives), `tests/Services/MessengerMiddleware/SerializedByLockMessagesTest.php`
  (foundation + D only): add your own entries in alphabetical position, never reorder or edit others'; merge conflicts
  are resolved by keeping both sides.
- CSS: new classes are prefixed `ev-` like the rest of the events family; the foundation pre-creates every new SCSS file
  and its `@import` in `assets/styles/app.scss` so nobody edits `app.scss` afterwards. Stimulus controllers not needed for
  the first paint start with `/* stimulusFetch: 'lazy' */`. Do not run encore - the browser-review agent builds assets.
- Measure before claiming: statement budgets with `QueryCountAssertions`, pinned with `assertSame`.

## 1. Foundation (one agent, sequential, in the integration worktree, DB `speedpuzzling_orgf_test`)

Read first: the README, then `src/Entity/Competition.php`, `CompetitionSeries.php`, `FollowedCompetition.php`,
`CompetitionRound.php`, `src/Query/IsCompetitionPubliclyVisible.php`, `GetCompetitionPermissions.php`,
`GetEventOccurrences.php`, `GetEventSeriesDirectory.php`, `GetEventsViewerData.php`, `src/Services/EventsPage/EventRowFactory.php`,
`EventsPageBuilder.php`, `src/Services/EventDetail/SeriesPageBuilder.php`, the three detail controllers and templates.

### 1.1 Entities

Follow the existing style: constructor-promoted public properties, `#[Immutable]` / `#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]`,
named methods for every change.

**`src/Entity/Organization.php`** (new)

```php
#[Entity]
class Organization
{
    // The other links (Instagram, Discord, …) as plain URLs - read them as socialLinks(), change them with edit()
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $socialLinks = [];          // list<string>

    /** @param Collection<int, Player> $maintainers */
    public function __construct(
        #[Id] #[Immutable] #[Column(type: UuidType::NAME, unique: true)] public UuidInterface $id,
        #[Column] public string $name,
        #[Column(unique: true)] public string $slug,
        #[Immutable] #[Column(type: Types::DATETIME_IMMUTABLE)] public DateTimeImmutable $createdAt,
        #[Column(nullable: true)] public null|string $shortName = null,
        #[Column(nullable: true)] public null|string $logo = null,
        #[Column(type: Types::TEXT, nullable: true)] public null|string $about = null,
        #[Column(nullable: true)] public null|string $website = null,
        SocialLinks $links = new SocialLinks([]),          // not promoted: stored as $socialLinks = $links->urls
        #[Column(nullable: true)] public null|string $countryCode = null,
        #[Column(nullable: true)] public null|string $region = null,
        #[Column(type: Types::STRING, nullable: true, enumType: OrganizationKind::class)] public null|OrganizationKind $kind = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[Column(options: ['default' => false])] public bool $isDraft = false,
        #[Immutable] #[ManyToOne] public null|Player $addedByPlayer = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)] public null|DateTimeImmutable $approvedAt = null,
        #[ManyToOne] public null|Player $approvedByPlayer = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)] public null|DateTimeImmutable $rejectedAt = null,
        #[ManyToOne] public null|Player $rejectedByPlayer = null,
        #[Column(type: Types::TEXT, nullable: true)] public null|string $rejectionReason = null,
        #[ManyToMany(targetEntity: Player::class)] #[JoinTable(name: 'organization_maintainer')] public Collection $maintainers = new ArrayCollection(),
    ) { /* $this->socialLinks = $links->urls; countryCode lower-cased like Competition::normalizeCountryCode() */ }
}
```

Methods: `approve(Player, DateTimeImmutable)`, `reject(Player, DateTimeImmutable, string $reason)`, `isApproved()`,
`isRejected()`, `publish()` (`isDraft = false`), `unpublish()` (`isDraft = true`), `isPubliclyVisible(): bool`
(approved, not rejected, not a draft - must equal `IsOrganizationPubliclyVisible::SQL_CONDITION`), `isOnTeam(Player):
bool` (creator or maintainer), `socialLinks(): SocialLinks`, `edit(string $name, string $slug, ?string $shortName,
?string $logo, ?string $about, ?string $website, SocialLinks $socialLinks, ?string $countryCode, ?string $region,
?OrganizationKind $kind)`.

**`src/Entity/Competition.php`** - three properties appended to the constructor (defaults, so existing `new` calls stay
valid), all `#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]`:

```php
#[ManyToOne] #[JoinColumn(nullable: true, onDelete: 'SET NULL')] public null|Organization $organization = null,
#[Column(options: ['default' => false])] public bool $isDraft = false,
#[Column(length: 120, nullable: true)] public null|string $eligibility = null,
```

The constructor throws `OrganizationOnEdition` when both `series` and `organization` are set. Methods:
`assignOrganization(?Organization)` (throws `OrganizationOnEdition` for an edition and a non-null organization),
`publish()`, `unpublish()`, `isHiddenAsDraft(): bool` (own flag or its series'), `isPubliclyVisible(): bool` (the PHP
mirror of `IsCompetitionPubliclyVisible::SQL_CONDITION` - used by handlers before the flush), `changeEligibility(?string)`,
`moveToSeries(CompetitionSeries $target, string $slug)` (editions only, `LogicException` otherwise; sets series and
slug; a `location` / `locationCountryCode` equal to the old series' takes the target's, `isOnline` = the target's - P21).
`edit()` keeps its signature (eligibility has its own method so `edit()` callers are untouched).

**`src/Entity/CompetitionSeries.php`** - appended: `organization` (as above, `SET NULL`), `isDraft`,
`eligibility` (`length: 120`), `schedule` (`#[Column(length: 160, nullable: true)]`). Methods: `assignOrganization(?Organization)`,
`publish()`, `unpublish()`, `isPubliclyVisible()` (mirror of `IsSeriesPubliclyVisible`), `changeEligibilityAndSchedule(?string $eligibility, ?string $schedule)`.

**`src/Entity/FollowedCompetition.php`** - a third target, `#[UniqueConstraint(columns: ['player_id', 'organization_id'])]`,
`#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[ManyToOne] #[JoinColumn(name: 'organization_id', nullable: true, onDelete: 'CASCADE')]
public null|Organization $organization` (private constructor gains it), `ofOrganization(UuidInterface, Player,
Organization, DateTimeImmutable)`, `moveToOrganization(Organization)` (series → organization; used by D). Update the
class comment ("exactly one of the three targets").

**`src/Entity/CompetitionRound.php`** - `moveToCompetition(Competition $target, string $slug): void` for D: calls
`saveDisplayedTimezone()` first (P22), records `CompetitionRoundsChanged` for the old competition, sets `competition` and
`slug`, records `CompetitionRoundsChanged` for the target.

**`src/Entity/PuzzleSolvingTime.php`** - `competitionRoundMovedTo(Competition $competition): void` for D: sets
`competition` only, records no domain event (comment: the round link stays, its round moved with it).

**`src/Entity/EventUrlRedirect.php`** (new, D6)

```php
#[Entity]
#[UniqueConstraint(name: 'event_url_redirect_path_unique', columns: ['series_slug', 'competition_slug', 'round_slug'])]
class EventUrlRedirect
{
    private function __construct(
        #[Id] #[Immutable] #[Column(type: UuidType::NAME, unique: true)] public UuidInterface $id,
        #[Immutable] #[Column(options: ['default' => ''])] public string $seriesSlug,
        #[Immutable] #[Column(options: ['default' => ''])] public string $competitionSlug,
        #[Immutable] #[Column(options: ['default' => ''])] public string $roundSlug,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[ManyToOne] #[JoinColumn(nullable: true, onDelete: 'CASCADE')] public null|Organization $organization,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[ManyToOne] #[JoinColumn(name: 'series_id', nullable: true, onDelete: 'CASCADE')] public null|CompetitionSeries $series,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[ManyToOne] #[JoinColumn(nullable: true, onDelete: 'CASCADE')] public null|Competition $competition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[ManyToOne] #[JoinColumn(name: 'round_id', nullable: true, onDelete: 'CASCADE')] public null|CompetitionRound $round,
        #[Immutable] #[Column(type: Types::DATETIME_IMMUTABLE)] public DateTimeImmutable $createdAt,
    ) {}

    public static function to(UuidInterface $id, EventUrlPath $path, Organization|CompetitionSeries|Competition|CompetitionRound $target, DateTimeImmutable $createdAt): self;
    public function pointTo(Organization|CompetitionSeries|Competition|CompetitionRound $target): void; // clears the other three
    public function path(): EventUrlPath;
}
```

### 1.2 Migration (generated - check the diff contains exactly this)

`doctrine:migrations:diff` against a scratch DB built from the committed migrations (memory "Migration diff vs local
drift"). Expected statements (names of generated indexes/constraints may differ):

- `CREATE TABLE organization` (all columns of README "Data model", `social_links JSONB DEFAULT '[]' NOT NULL`,
  `is_draft BOOLEAN DEFAULT false NOT NULL`, `created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL`), unique index on
  `slug`, indexes + FKs (no `ON DELETE`) for `added_by_player_id`, `approved_by_player_id`, `rejected_by_player_id`.
- `CREATE TABLE organization_maintainer (organization_id, player_id, PRIMARY KEY …)` + both indexes + FKs `ON DELETE CASCADE`.
- `CREATE TABLE event_url_redirect` + `event_url_redirect_path_unique` + 4 indexes + 4 FKs `ON DELETE CASCADE`.
- `ALTER TABLE competition ADD organization_id UUID DEFAULT NULL, ADD is_draft BOOLEAN DEFAULT false NOT NULL, ADD eligibility VARCHAR(120) DEFAULT NULL`
  + FK `ON DELETE SET NULL` + index.
- `ALTER TABLE competition_series ADD organization_id …, ADD is_draft …, ADD eligibility VARCHAR(120) …, ADD schedule VARCHAR(160) DEFAULT NULL`
  + FK `ON DELETE SET NULL` + index.
- `ALTER TABLE followed_competition ADD organization_id UUID DEFAULT NULL` + FK `ON DELETE CASCADE` + index + unique
  index (`player_id`, `organization_id`).
- Nothing else (no `COMMENT ON`, no drift). `down()` generated as Doctrine writes it.

### 1.3 Values and enums (`src/Value/`)

| Class | Contract |
|---|---|
| `OrganizationKind` (string enum) | `Association = 'association'`, `Club = 'club'`, `Shop = 'shop'`, `Venue = 'venue'`, `Community = 'community'`, `Other = 'other'`; `translationKey()` = `organization.kind.<value>` |
| `SocialLinkPlatform` (string enum) | `instagram, facebook, discord, youtube, tiktok, x, threads, bluesky, linkedin, reddit, twitch, whatsapp, pinterest, other`; `static fromUrl(string $url): self` - the host lower-cased without `www.` / `m.` / `mobile.`, matched on these hosts (and their subdomains): instagram.com; facebook.com, fb.com, fb.me; discord.gg, discord.com; youtube.com, youtu.be; tiktok.com; x.com, twitter.com; threads.net, threads.com; bsky.app; linkedin.com; reddit.com; twitch.tv; wa.me, whatsapp.com; pinterest.com, pin.it; else `other`. `iconClass()`: `bi-instagram`, `bi-facebook`, `bi-discord`, `bi-youtube`, `bi-tiktok`, `bi-twitter-x`, `bi-threads`, `bi-bluesky`, `bi-linkedin`, `bi-reddit`, `bi-twitch`, `bi-whatsapp`, `bi-pinterest`, `bi-link-45deg` (check each class exists in the mounted `node_modules/bootstrap-icons/font/bootstrap-icons.css`; a missing one maps to `bi-link-45deg`). `label(): ?string` - the brand name ("Instagram", "X", …), null for `other` |
| `SocialLink` (readonly) | `url`, `platform: SocialLinkPlatform`, `host` (for `other`'s accessible name) |
| `SocialLinks` (readonly) | `public const int MAX = 10`; `public array $urls` (list<string>); `static fromInput(list<string>|string $input): self` - a string is split on line breaks; each trimmed, empties dropped, duplicates (case-insensitive) dropped, order kept; `InvalidArgumentException` for more than 10 or a non-http(s) URL (validation happens before - this guards the invariant); `links(): list<SocialLink>` |
| `FollowTargetKind` | + `Organization = 'organization'` |
| `FollowTarget` | + `static organization(string $id)`, `isOrganization()`; `tryFromString()` accepts `organization:<uuid>` |
| `EventUrlPath` (readonly) | `seriesSlug`, `competitionSlug`, `roundSlug` (`''` = absent); `static event(string $slug)`, `series(string $slug)`, `edition(string $seriesSlug, string $editionSlug)`, `eventRound(string $slug, string $roundSlug)`, `editionRound(string $seriesSlug, string $editionSlug, string $roundSlug)` |
| `OrganizationItemKind` (string enum) | `Series = 'series'`, `Competition = 'competition'` - the target of `AssignEventToOrganization` |
| `UnpublishBlocker` (string enum) | `Participants = 'participants'`, `Results = 'results'`, `SolvingTimes = 'solving_times'`; `translationKey()` = `drafts_core.blocker.<value>` |
| `NewEdition` (readonly) | `UuidInterface $competitionId`, `string $name`, `DateTimeImmutable $date` - one edition of `AddEditions` |

### 1.4 Results (`src/Results/`)

| Class | Fields |
|---|---|
| `OrganizationRef` | `id`, `name`, `shortName: ?string`, `slug`, `isPublic: bool`; `static fromRow(array $row, string $prefix = 'organization_'): ?self` (null when `{prefix}id` is null) |
| `OrganizationDetail` | `id`, `name`, `shortName`, `slug`, `logo`, `about`, `website`, `socialLinks: list<SocialLink>`, `countryCode: ?CountryCode`, `region`, `kind: ?OrganizationKind`, `isDraft`, `approvedAt`, `rejectedAt`, `rejectionReason`, `addedByPlayerId`, `addedByPlayerName` (queue only), `createdAt`; `isPublic(): bool`, `isPending(): bool` |
| `OrganizationDirectoryRow` | `id`, `name`, `shortName`, `slug`, `logo`, `kind`, `countryCode: ?CountryCode`, `region`, `seriesCount` (public series), `eventCount` (public one-time events) |
| `OrganizationChoice` | `id`, `name`, `isDraft`, `isApproved` - one option of the "Organization" select |
| `DraftState` | `kind: 'organization'\|'series'\|'competition'`, `id`, `name`, `isDraft` (the item itself), `seriesId: ?string` (an edition whose series is a draft), `seriesName: ?string`; `isHidden(): bool` |
| `UnpublishCheck` | `participants: int`, `results: int`, `solvingTimes: int`; `blockers(): list<UnpublishBlocker>`, `allowed(): bool` |
| `EventOccurrence` (change) | + `public null|OrganizationRef $organization = null`, `public null|string $eligibility = null` (own, else the series'), `public bool $isDraft = false` (own or the series') |
| `EventSeriesRow` (change) | + `organization: ?OrganizationRef = null`, `eligibility: ?string = null`, `schedule: ?string = null`, `isDraft: bool = false` |
| `CompetitionSeriesOverview` (change) | + `organization: ?OrganizationRef = null`, `eligibility`, `schedule`, `isDraft = false`; `isPubliclyVisible(): bool` |
| `CompetitionEvent` (change) | + `organization: ?OrganizationRef = null` (a one-time event's own), `eligibility: ?string = null`, `isDraft: bool = false`; `fromDatabaseRow()` reads `is_draft`, `eligibility` and the optional `organization_ref_*` keys (only `byId()` selects them; update the `CompetitionEventDatabaseRow` shape) |
| `EventsPage\SeriesLine` (change) | + `organization: ?OrganizationRef = null` (only when the organization is publicly visible) |
| `EventsPage\RowTag` (change) | + `public null|string $text = null` (Eligibility) |
| `EventsPage\RowTagType` (change) | `WaitingForApproval, Draft, Going, Recurring, Eligibility, Registration…` - `Draft = 'draft'` after WaitingForApproval, `Eligibility = 'eligibility'` after Recurring |
| `EventsPage\ManageRef` (change) | + `KIND_ORGANIZATION = 'organization'` |
| `OrganizedEvent` (change, then C's) | + `KIND_ORGANIZATION = 'organization'`, `isDraft: bool = false`, `organizationId: ?string = null`; `badge()` returns `OrganizerBadge::Draft` first for a draft |
| `OrganizerBadge` (change) | + `Draft = 'draft'` |
| `EventsViewerData` (change) | + `followedOrganizationIds: list<string> = []`, `organizedOrganizationIds: list<string> = []`, `organizationOfItem: array<string, string> = []` (organized competition or series id → its organization id); `follows()` handles the organization kind; `followsOrganization(string)`, `followedOrganizationIds()`, `organizedOrganizationIds()`, `organizationOf(string $itemId): ?string`; `organizedCount()` = organizations + series not under one of them + `organizedCompetitionIds()` not under one of them (P9) |

### 1.5 Visibility constants (`src/Query/`)

```php
// IsCompetitionPubliclyVisible - aliases c, cs (LEFT JOIN on cs.id = c.series_id) as today
public const string SQL_CONDITION = <<<SQL
(c.rejected_at IS NULL
AND c.is_draft = false
AND (
    (c.series_id IS NULL AND c.approved_at IS NOT NULL)
    OR (c.series_id IS NOT NULL AND cs.approved_at IS NOT NULL AND cs.rejected_at IS NULL AND cs.is_draft = false)
))
SQL;
public const string SQL_APPROVED = <<<SQL
(c.rejected_at IS NULL
AND (
    (c.series_id IS NULL AND c.approved_at IS NOT NULL)
    OR (c.series_id IS NOT NULL AND cs.approved_at IS NOT NULL AND cs.rejected_at IS NULL)
))
SQL;
public const string SQL_NOT_DRAFT = '(c.is_draft = false AND (c.series_id IS NULL OR cs.is_draft = false))';
```

(The outer parentheses are new - every embedding stays correct, also behind `NOT` or `OR`.) The class comment explains
the three. `check()` unchanged otherwise.

```php
// IsSeriesPubliclyVisible - alias cs
public const string SQL_CONDITION = '(cs.approved_at IS NOT NULL AND cs.rejected_at IS NULL AND cs.is_draft = false)';
public function check(string $seriesId): bool;

// IsOrganizationPubliclyVisible - alias o
public const string SQL_CONDITION = '(o.approved_at IS NOT NULL AND o.rejected_at IS NULL AND o.is_draft = false)';
// The organization of a competition row: its own (one-time event) or its series' (edition). Needs c and cs.
public const string SQL_JOIN_OF_COMPETITION = 'LEFT JOIN organization o ON o.id = COALESCE(c.organization_id, cs.organization_id)';
public function check(string $organizationId): bool;
```

A parity test (`tests/Query/VisibilityParityTest.php`) checks `Competition::isPubliclyVisible()`,
`CompetitionSeries::isPubliclyVisible()`, `Organization::isPubliclyVisible()` against the three `check()` methods for
every fixture row.

### 1.6 Permissions

`GetCompetitionPermissions::forPlayer()` - the UNION gains (kept in the one statement):

```sql
UNION ALL
SELECT 'organization', id::text, true FROM organization WHERE added_by_player_id = :playerId
UNION ALL
SELECT 'organization', organization_id::text, false FROM organization_maintainer WHERE player_id = :playerId
UNION ALL
SELECT 'series', cs.id::text, true FROM competition_series cs
WHERE cs.organization_id IN (SELECT o.id FROM organization o WHERE o.added_by_player_id = :playerId
                             UNION SELECT om.organization_id FROM organization_maintainer om WHERE om.player_id = :playerId)
UNION ALL
SELECT 'competition', c.id::text, true FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE COALESCE(c.organization_id, cs.organization_id) IN (SELECT o.id FROM organization o WHERE o.added_by_player_id = :playerId
                                                          UNION SELECT om.organization_id FROM organization_maintainer om WHERE om.player_id = :playerId)
```

`CompetitionPermissions` gains (constructor params with `[]` defaults, so existing `new` calls stay valid)
`editableOrganizationIds`, `deletableOrganizationIds` and the methods `canEditOrganization(string)`,
`canDeleteOrganization(string)`, `organizationIds(): list<string>` (editable ones). Voters (new, the pattern of
`CompetitionSeriesEditVoter`): `src/Security/OrganizationEditVoter.php` (`ORGANIZATION_EDIT`) and
`OrganizationDeleteVoter.php` (`ORGANIZATION_DELETE`), admins always true.

### 1.7 Read models (`src/Query/`)

- **`GetOrganization`** (new): `bySlug(string $slug): OrganizationDetail`, `byId(string $id): OrganizationDetail` - one
  statement, any state (the page decides); `OrganizationNotFound` (404) otherwise.
- **`GetOrganizations`** (new): `publicDirectory(): list<OrganizationDirectoryRow>` (publicly visible, by name, counts of
  publicly visible series / one-time events in the same statement); `choicesForPlayer(string $playerId): list<OrganizationChoice>`
  (team member, not rejected, by name); `allChoices(): list<OrganizationChoice>` (admins: not rejected);
  `allUnapproved(): list<OrganizationDetail>` (the approval queue: pending, **not draft**, newest first, with the
  creator's name - list it in `BlocklistQueryCoverageTest` as admin tooling).
- **`GetEventOccurrences`** (change): the statement gains `COALESCE(c.eligibility, cs.eligibility) AS eligibility`,
  `(c.is_draft OR COALESCE(cs.is_draft, false)) AS is_draft`, `IsOrganizationPubliclyVisible::SQL_JOIN_OF_COMPETITION`
  (before the rounds join) with `o.id AS organization_id, o.name AS organization_name, o.short_name AS
  organization_short_name, o.slug AS organization_slug, COALESCE(({org SQL_CONDITION}), false) AS organization_public`;
  `hydrate()` fills the three new `EventOccurrence` fields.
  - `all(bool $includeUnapproved)`: the admin branch becomes `IsCompetitionPubliclyVisible::SQL_NOT_DRAFT . ' AND c.rejected_at IS NULL AND (c.series_id IS NULL OR cs.rejected_at IS NULL)'` - drafts nowhere, not even for admins.
  - `forSeries(string $seriesId, bool $includeDrafts = false)`: `AND c.is_draft = false` unless `$includeDrafts`.
  - **`forOrganization(string $organizationId, bool $includeDrafts = false)`** (new): `WHERE (c.organization_id = :organizationId OR cs.organization_id = :organizationId)` and either `IsCompetitionPubliclyVisible::SQL_CONDITION` (public) or `c.rejected_at IS NULL AND (c.series_id IS NULL OR cs.rejected_at IS NULL)` (team); rounds restricted like `forSeries()` (`cr_j.competition_id IN (SELECT o_c.id FROM competition o_c LEFT JOIN competition_series o_cs ON o_cs.id = o_c.series_id WHERE o_c.organization_id = :organizationId OR o_cs.organization_id = :organizationId)`); invalid uuid → `[]` without a statement.
  - **`forOrganizations(list<string> $organizationIds)`** (new, the directory): public occurrences of the given organizations (`IN (:ids)` on both columns), `[]` without a statement for an empty list.
- **`GetEventSeriesDirectory`** (change): + `cs.eligibility, cs.schedule, cs.is_draft`, `LEFT JOIN organization o ON o.id = cs.organization_id` with the `organization_*` columns; `is_public` = `(IsSeriesPubliclyVisible::SQL_CONDITION)`; `all()`: `WHERE cs.rejected_at IS NULL AND cs.is_draft = false` + `AND IsSeriesPubliclyVisible::SQL_CONDITION` unless `$includeUnapproved`; **`forOrganization(string $organizationId, bool $includeDrafts)`** (new): `WHERE cs.organization_id = :organizationId AND cs.rejected_at IS NULL` + the series condition unless `$includeDrafts`.
- **`GetEventsViewerData`** (change): rows get a fourth column `organization_id`; new kinds `follow_organization`
  (`fc.organization_id`) and `organize_organization` (created or maintained); `organize_series` also every series of
  an organization the player is on the team of; `organize_competition` also every one-time event of such an
  organization (`c.series_id IS NULL AND c.organization_id IN (…team organizations…)`), each with `COALESCE(c.organization_id, cs.organization_id)`.
  Still one statement.
- **`GetCompetitionSeries`** (change): `byId()` / `bySlug()` select `cs.eligibility, cs.schedule, cs.is_draft` and the
  organization (`LEFT JOIN organization o ON o.id = cs.organization_id`, `organization_*` columns); `allUnapproved()`
  adds `AND cs.is_draft = false`.
- **`GetCompetitionEvents`** (change): `byId()` adds `LEFT JOIN organization o ON o.id = c.organization_id` and the
  organization's columns prefixed **`organization_ref_`** (`c.*` already holds `organization_id`; it brings `is_draft`
  and `eligibility` too) - `OrganizationRef::fromRow($row, 'organization_ref_')`; `search()` (API v1 list) becomes
  `FROM competition c LEFT JOIN competition_series cs ON cs.id = c.series_id WHERE IsCompetitionPubliclyVisible::SQL_CONDITION AND c.series_id IS NULL`
  (select `c.*`, keep standalone-only); `allUnapproved()` adds `AND c.is_draft = false`.
- **`GetAdminQueueCounts`** (change): `competition_approvals` adds `AND c.is_draft = false` / `AND cs.is_draft = false` and
  `+ (SELECT COUNT(*) FROM organization o WHERE o.approved_at IS NULL AND o.rejected_at IS NULL AND o.is_draft = false)`;
  its comment and `GetAdminQueueCountsTest` follow.
- **`GetOrganizedEvents`** (change, then C's): `byIds(array $competitionIds, array $seriesIds, array $organizationIds = [])`
  - a third statement for organizations (only when ids are given; name, slug, approval, rejection, draft, counts of its
  series and one-time events), the two existing statements select `is_draft` (own or series') and the organization id;
  `is_approved` uses the approval columns as today (P1).
- **`src/Services/Drafts/UnpublishBlockers.php`** (new, readonly; DBAL + `OfficialResultsGuard`): `forCompetition(string
  $competitionId): UnpublishCheck`, `forSeries(string $seriesId): UnpublishCheck` (every edition). Participants =
  `competition_participant` rows with `deleted_at IS NULL`; solving times = rows with that `competition_id` or a round of
  it (suspicious included - list the file in `SuspiciousTimeQueryCoverageTest` with that reason); results =
  `OfficialResultsGuard::countEntriesWithOfficialDataInCompetition()` per competition.

### 1.8 Services

- **`src/Services/Organizations/OrganizationApprovalPolicy.php`** (readonly; repositories, `GetRoundsWithPublishedOfficialResults`,
  `MessageBusInterface`):
  ```php
  /** D2 - returns whether it approved the item */
  public function approveIfUnderTrustedOrganization(Competition|CompetitionSeries $item, Player $actor, DateTimeImmutable $now): bool;
  /** P2 - the organization's pending series and one-time events (not the rejected ones), after the organization itself */
  public function approvePendingItemsOf(Organization $organization, Player $approver, DateTimeImmutable $now): void;
  ```
  Conditions of the first, in this order: the item has an organization, approved and not rejected; the item is not an
  edition; it is neither approved nor rejected; the actor is an admin (`Player::$isAdmin`) or `$organization->isOnTeam($actor)`.
  The second also replays the official-results notification for each newly approved item that is now publicly visible
  (as `ApproveCompetitionHandler` does: `OfficialRoundResultsPublished` with `DispatchAfterCurrentBusStamp`).
- **`src/Services/Organizations/CompetitionSubmittedMailer.php`** (readonly): the "submitted for approval" admin e-mail,
  moved out of `AddCompetitionHandler` / `AddCompetitionSeriesHandler` unchanged (same template, subject, recipient,
  `X-Transport`): `notifyAdmin(string $name, string $submittedBy, ?string $location): void`. Used by those two handlers,
  `AddOrganizationHandler` and the Publish handlers.
- **`CompetitionSlugGenerator`** (change): `generateOrganizationSlug(string $name): string` (slugger, random suffix when
  taken - like `generate()`), `isOrganizationSlugTaken(string $slug, ?string $exceptOrganizationId = null): bool`.
- **`src/Services/EventsPage/EventRowFactory.php`** (change, frozen afterwards): new `RowContext::OrganizationPage`
  (`src/Value/RowContext.php`): title and line under it as the events page; Recurring kept; no star
  (`followTarget` null); `visible` true; `time` = the first round's start (as the series page). `tags()`: **Draft** when
  `$occurrence->isDraft` (every context); **Waiting for approval** only when not public **and not a draft** (and not on
  the series page, as today); **Eligibility** (`text` = `$occurrence->eligibility`) after Recurring, in every context.
  `isPending` on rows stays "not public".
- **`EventsPageBuilder`** (change, then C's): `seriesLines()` passes `organization` (only when `$row->organization?->isPublic`)
  into both `SeriesLine` constructions. Nothing else.
- **`SeriesPageBuilder`** (change, frozen afterwards): `isPublic()` = `$series->isPubliclyVisible()`.

### 1.9 Messages, handlers, exceptions

**Exceptions** (`src/Exceptions/`): `OrganizationNotFound` (extends `NotFoundHttpException`), `OrganizationSlugTaken`
(`ConflictHttpException`, `$slug`), `OrganizationNotEmpty` (`ConflictHttpException`, `$seriesCount`, `$eventCount`),
`OrganizationOnEdition` (`ConflictHttpException`: "An edition belongs to its series' organization."),
`OrganizationNotManaged` (`AccessDeniedHttpException`: the actor is not on the organization's team),
`OrganizationNotApprovable` (`ConflictHttpException`), `CannotUnpublish` (`ConflictHttpException`, `list<UnpublishBlocker> $blockers`),
`DraftNotVisible` (extends `NotFoundHttpException` - the 404 of a draft page; D's redirect subscriber ignores it, P5).

**New messages** (`src/Message/`, `readonly final`) and handlers (`src/MessageHandler/`):

| Message | Fields | Handler behaviour |
|---|---|---|
| `AddOrganization` | `UuidInterface $organizationId, string $playerId, string $name, ?string $shortName, ?string $about, ?string $website, list<string> $socialLinks, ?string $countryCode, ?string $region, ?OrganizationKind $kind, ?UploadedFile $logo, list<string> $maintainerIds, ?string $slug = null, bool $isDraft = false, bool $approve = false` | slug: explicit → `CompetitionSlugGenerator::isValid()` (`InvalidCompetitionSlug`) + free (`OrganizationSlugTaken`), else `generateOrganizationSlug()`; logo stored as `organizations/{id}-{timestamp}.{ext}` (the `AddCompetitionSeriesHandler` block); creator = `$playerId`; maintainers; `$approve` → approved by the creator, no e-mail (internal API); else unless a draft → `CompetitionSubmittedMailer` |
| `EditOrganization` | `string $organizationId, string $name, ?string $shortName, ?string $about, ?string $website, list<string> $socialLinks, ?string $countryCode, ?string $region, ?OrganizationKind $kind, ?UploadedFile $logo, list<string> $maintainerIds, ?string $slug = null` | null slug keeps it (also on rename); explicit slug validated + free; logo replaced when given; maintainers replaced (the series pattern) |
| `ApproveOrganization` | `string $organizationId, string $approvedByPlayerId, bool $notifyCreator = true` | `OrganizationNotApprovable` when approved or rejected; approve; `approvePendingItemsOf()`; the "approved" e-mail (`competition_approved`, link `organization_detail`) unless `$notifyCreator` is false |
| `RejectOrganization` | `string $organizationId, string $rejectedByPlayerId, string $reason` | reject + the "rejected" e-mail (as series) |
| `DeleteOrganization` | `string $organizationId` | `OrganizationNotEmpty` while any series or competition points at it (`OrganizationRepository::countItems()`); else delete (follows, maintainers, redirect rows cascade) |
| `AddOrganizationMaintainer` | `string $organizationId, string $playerId` | idempotent; the creator is never added as a maintainer row |
| `RemoveOrganizationMaintainer` | `string $organizationId, string $playerId` | idempotent |
| `PublishOrganization` | `string $organizationId` | `publish()`; pending → `CompetitionSubmittedMailer` |
| `UnpublishOrganization` | `string $organizationId` | `unpublish()` (always allowed) |
| `PublishCompetition` | `string $competitionId` | `publish()`; a pending one-time event → `CompetitionSubmittedMailer`; when `isPubliclyVisible()` after it → replay official results notifications (`GetRoundsWithPublishedOfficialResults::ofCompetition()`) |
| `UnpublishCompetition` | `string $competitionId` - `implements SerializedByLock`, `lockKey()` = `CompetitionParticipantsLock::key($this->competitionId)` | `UnpublishBlockers::forCompetition()` → `CannotUnpublish` with the blockers; else `unpublish()` |
| `PublishCompetitionSeries` | `string $seriesId` | `publish()`; pending → mail; when public → replay for `ofSeries()` |
| `UnpublishCompetitionSeries` | `string $seriesId` | `UnpublishBlockers::forSeries()` → `CannotUnpublish`; else `unpublish()`. Listed in `SerializedByLockMessagesTest::NOT_LOCKED` if detected: "several events in one message (one lock key per message); a join racing it leaves one participant on a hidden edition, visible to its organisers" |
| `AssignEventToOrganization` | `OrganizationItemKind $kind, string $itemId, ?string $organizationId, string $actingPlayerId` | an edition with an organization → `OrganizationOnEdition`; a target organization the actor is not on the team of (non-admin) → `OrganizationNotManaged`; `assignOrganization()`; `approveIfUnderTrustedOrganization()`. Editing the item itself is checked by the caller (voters / internal API) |
| `AddEditions` | `string $seriesId, list<NewEdition> $editions, ?string $eligibility, bool $isDraft` | 1-24 editions (`InvalidArgumentException` otherwise); each like `AddEdition` (series place, `dateFrom = dateTo = $date` at 00:00 UTC), slug from the name unique in the series - also among the batch (keep a local set, suffix `-2`, `-3`) |

**Changed messages** (named arguments everywhere; update every caller listed by `grep -rn "new <Message>("`):

| Message | Change | Handler |
|---|---|---|
| `AddCompetition` | + `?string $organizationId = null`, `?string $eligibility = null`, `bool $isDraft = false` (at the end) | an organization the creator is not on the team of (non-admin) → `OrganizationNotManaged`; sets organization, eligibility, draft; `approveIfUnderTrustedOrganization()`; the admin e-mail only when `notifyAdmin`, not a draft and not approved by the policy (through `CompetitionSubmittedMailer`) |
| `AddCompetitionSeries` | + `?string $slug = null`, `?string $organizationId = null`, `?string $eligibility = null`, `?string $schedule = null`, `bool $isDraft = false`, `bool $notifyAdmin = true` | explicit slug validated + free (`isSeriesSlugTaken()`, `CompetitionSlugTaken`), else today's generator; organization check and policy as above; e-mail as above |
| `AddEdition` | + `?string $eligibility = null`, `bool $isDraft = false`, `?string $slug = null` | explicit slug validated + free in the series (`isTaken($slug, $seriesId)`) |
| `EditCompetition` | + `?string $eligibility` (required, before `slug`) | `changeEligibility()` |
| `EditCompetitionSeries` | + `?string $eligibility, ?string $schedule` (required, before `slug`) | `changeEligibilityAndSchedule()` |
| `ApproveCompetitionSeries` | + `bool $notifyCreator = true` | e-mail only when true |
| `FollowCompetition` / `UnfollowCompetition` | target strings may be `organization:<uuid>` | Follow: organization → `IsOrganizationPubliclyVisible::check()` else `FollowTargetNotAvailable`; the series branch uses `IsSeriesPubliclyVisible::check()` (drafts) |
| `ConvertCompetitionToSeries` | - | the new series takes the event's organization, draft flag and eligibility; the event (now an edition) gets `assignOrganization(null)` **before** it gets the series, `publish()` and eligibility null |

`DeletePlayerHandler::anonymizeCompetitionSeries()` also nulls `Organization::$addedByPlayer`, `$approvedByPlayer`,
`$rejectedByPlayer` and deletes the player's `organization_maintainer` rows (same DQL/SQL style as the series lines).
`GetStoredFileReferences` gains `UNION SELECT logo FROM organization WHERE logo IN (:paths)`.
`src/Controller/Events/AbstractEventFollowController.php::nameOf()` gains the organization branch (B restricts it later).

Callers the foundation updates for the required parameters: `EditCompetitionController` (`eligibility: $data->eligibility`
- `fromCompetition()` fills it, so the value is kept until C adds the field), `EditCompetitionSeriesController` (it
builds its form data by hand: also set `$formData->eligibility` / `$formData->schedule` from the series, then pass
them), `InternalApi\UpdateCompetitionController` (`eligibility: $competition->eligibility` - D replaces it), tests.

### 1.10 Repositories

- `src/Repository/OrganizationRepository.php` (new, plain readonly): `get(string $id): Organization`
  (`OrganizationNotFound`), `save()`, `delete()`, `countItems(Organization): array{series: int, events: int}` (DQL COUNT).
- `src/Repository/EventUrlRedirectRepository.php` (new): `findByPath(EventUrlPath): ?EventUrlRedirect`, `save()`.
- `CompetitionSeriesRepository::listByOrganization(Organization): list<CompetitionSeries>`;
  `CompetitionRepository::listOneTimeByOrganization(Organization): list<Competition>`.
- `FollowedCompetitionRepository`: `find()` handles the organization kind; + `listForSeries(CompetitionSeries)`,
  `findForOrganization(Player, Organization): ?FollowedCompetition` (D's move of followers).

### 1.11 Form data (data classes only - the form fields are C's and A's)

- `CompetitionFormData` (+ C afterwards): `?string $organizationId = null`; `#[Assert\Length(max: 120)] ?string $eligibility = null`;
  `#[Assert\Length(max: 160)] ?string $schedule = null`; `fromCompetition()` fills `eligibility` and `organizationId`.
- `EditionFormData` (+ C afterwards): `#[Assert\Length(max: 120)] ?string $eligibility = null`.
- **`src/FormData/OrganizationFormData.php`** (new; A builds its form type, D validates the API with it):
  `#[NotBlank, Length(max: 120)] $name`, `#[Length(max: 30)] $shortName`, `#[Length(max: 5000)] $about`,
  `#[Url(protocols: ['http','https']), Length(max: 255)] $website`, `list<string> $socialLinks` with
  `#[Count(max: SocialLinks::MAX, maxMessage: 'organization_fields.social_links_too_many')]` and
  `#[All([new Url(protocols: ['http','https'], message: 'organization_fields.social_link_invalid'), new Length(max: 255)])]`,
  `?string $countryCode`, `#[Length(max: 120)] $region`, `?OrganizationKind $kind`, `?UploadedFile $logo`,
  `list<string> $maintainers`, `?string $slug`; `static fromOrganization(Organization)`.

### 1.12 Controllers and routes

Full (thin) controllers - the foundation builds them completely:

| Route | Path / method | Controller | Behaviour |
|---|---|---|---|
| `publish_competition` | `/{_locale}/publish-event/{competitionId}` POST | `src/Controller/Drafts/PublishCompetitionController.php` | `COMPETITION_EDIT`; CSRF `publish_competition_{id}` (session token, field `_token`); dispatch; flash `drafts_core.flash.published` or `published_waiting`; redirect to `return` (`ReturnUrl::tryFrom`) else the item's page (`CompetitionDetailUrl`) |
| `unpublish_competition` | `/{_locale}/unpublish-event/{competitionId}` POST | `…/UnpublishCompetitionController.php` | `COMPETITION_EDIT`; CSRF `unpublish_competition_{id}`; `CannotUnpublish` → flash `drafts_core.flash.cannot_unpublish` with the blockers joined; always a redirect (never 200) |
| `publish_competition_series` / `unpublish_competition_series` | `/{_locale}/publish-series/{seriesId}`, `/{_locale}/unpublish-series/{seriesId}` POST | `…/PublishCompetitionSeriesController.php`, `…/UnpublishCompetitionSeriesController.php` | `COMPETITION_SERIES_EDIT`; tokens `publish_competition_series_{id}` / `unpublish_competition_series_{id}` |
| `publish_organization` / `unpublish_organization` | `/{_locale}/publish-organization/{organizationId}`, `/{_locale}/unpublish-organization/{organizationId}` POST | `…/PublishOrganizationController.php`, `…/UnpublishOrganizationController.php` | `ORGANIZATION_EDIT`; tokens `publish_organization_{id}` / `unpublish_organization_{id}` |
| `delete_organization` | `/{_locale}/delete-organization/{organizationId}` POST | `src/Controller/Organizations/DeleteOrganizationController.php` | `ORGANIZATION_DELETE`; CSRF `delete_organization_{id}`; `OrganizationNotEmpty` → flash `organization.flash.not_empty`; success → `organization.flash.deleted`, redirect to `return` else `organized_events` |
| `admin_approve_organization` | `/admin/organizations/{organizationId}/approve` POST | `src/Controller/Admin/ApproveOrganizationController.php` | mirror of `ApproveCompetitionSeriesController` (return, flash `organization.flash.approved`) |
| `admin_reject_organization` | `/admin/organizations/{organizationId}/reject` POST | `src/Controller/Admin/RejectOrganizationController.php` | mirror of `RejectCompetitionSeriesController` (`reason`) |

Ids in the `/{_locale}/…` paths use `FirstTryConflictsController::ID_REQUIREMENT`.

**Skeletons** (final route attributes, voters and arguments; the body is the minimum named here; the owner replaces it):

| Route | Path | Controller | Owner | Skeleton body |
|---|---|---|---|---|
| `organization_detail` | cs `/organizace/{slug}`, en `/en/organizations/{slug}`, es `/es/organizaciones/{slug}`, ja `/ja/団体/{slug}`, fr `/fr/organisations/{slug}`, de `/de/organisationen/{slug}` | `src/Controller/Organizations/OrganizationDetailController.php` | A | `GetOrganization::bySlug()`; a draft for a viewer without `ORGANIZATION_EDIT` → `DraftNotVisible`; renders `organization_detail.html.twig`: robots `noindex, nofollow` unless public, the draft banner hook (`draft_state`), H1 name with `data-organization-id`, about |
| `organizations` | cs `/organizace`, en `/en/organizations`, es `/es/organizaciones`, ja `/ja/団体`, fr `/fr/organisations`, de `/de/organisationen` | `…/OrganizationsController.php` | A | `GetOrganizations::publicDirectory()` → `organizations.html.twig`: H1 + a plain list of linked names |
| `add_organization` | cs `/pridat-organizaci`, others `/{xx}/add-organization` | `…/AddOrganizationController.php` | A | `IS_AUTHENTICATED_REMEMBERED`; `NotFoundHttpException` |
| `edit_organization` | cs `/upravit-organizaci/{organizationId}`, others `/{xx}/edit-organization/{organizationId}` | `…/EditOrganizationController.php` | A | `ORGANIZATION_EDIT`; `NotFoundHttpException` |
| `move_edition` | `/{_locale}/move-edition/{competitionId}` GET+POST | `src/Controller/Restructure/MoveEditionController.php` | D | `COMPETITION_EDIT`; `NotFoundHttpException` |
| `move_competition_round` | `/{_locale}/move-round/{roundId}` GET+POST | `…/MoveCompetitionRoundController.php` | D | `COMPETITION_EDIT` on the round's competition; `NotFoundHttpException` |
| `create_organization_from_series` | `/{_locale}/series-to-organization/{seriesId}` GET+POST | `…/CreateOrganizationFromSeriesController.php` | D | `COMPETITION_SERIES_EDIT`; `NotFoundHttpException` |

`event_manage_menu`: requirement `kind` = `competition|series|organization`; `EventManageMenuController` asks
`ORGANIZATION_EDIT` for an organization and loads it through `GetOrganizedEvents::byIds([], [], [$id])`;
`_manage_items.html.twig` gets a minimal organization branch (Edit organization → `edit_organization`). C completes all
three.

**Detail controllers** (data wiring - the foundation passes, B adds the guards afterwards):

- `CompetitionSeriesDetailController`: `$canManage` = admin or `COMPETITION_SERIES_EDIT` (signed in only);
  `forSeries($series->id, includeDrafts: $canManage)`; page sections when `$series->isPubliclyVisible()`; passes
  `series_publicly_visible`, `organization` (`$series->organization`), `eligibility`, `schedule`, `draft_state`
  (`DraftState` when `$series->isDraft`, else null).
- `EditionDetailController`: passes `organization` (the series'), `eligibility` (own ?? the series'), `draft_state`
  (own draft and/or series draft).
- `EventDetailController`: passes `organization` (its own), `eligibility`, `draft_state`.
- The templates show an organization (byline, crumb) when `organization.isPublic or is_granted('ORGANIZATION_EDIT',
  organization.id)` - the voter is asked only for a non-public organization, so public pages pay no statement.

### 1.13 Templates (hooks and fixes)

Empty hook partials (a comment block naming the owner and the variables; render nothing):

| Partial | Owner | Variables |
|---|---|---|
| `templates/event_parts/_draft_banner.html.twig` | B | `draft_state: ?DraftState` |
| `templates/event_parts/_organized_by.html.twig` | A | `organization: ?OrganizationRef` |
| `templates/event_parts/_eligibility.html.twig` | C | `eligibility: ?string` |
| `templates/event_parts/_schedule.html.twig` | C | `schedule: ?string` |

- `_detail_header.html.twig`: a new block `byline` between the H1 and the facts, rendered as
  `<p class="ev-detail-byline">…</p>` only when it has content (`{% set byline = block('byline')|trim %}`).
- `competition_series_detail.html.twig`, `edition_detail.html.twig`, `event_detail.html.twig`: the draft banner first
  inside `.ev-detail`; the byline block = `_organized_by` + `_eligibility` (+ `_schedule` on the series page); crumbs
  per README P20 when `organization` is set (link `organization_detail`); robots: the series page uses
  `series_publicly_visible`, the event page `is_publicly_visible` (no more re-typed approval rules).
- `event_parts/_series_json_ld.html.twig`: gated by `series_publicly_visible`.
- `event_parts/_row_tags.html.twig`: `draft` → `{{ 'events_page.tag.draft'|trans }}`; `eligibility` →
  `<span class="visually-hidden">{{ 'events_page.tag.eligibility'|trans }}: </span>{{ tag.text }}`.
- SCSS stubs (imported in `app.scss` after `event-rounds`, in this order): `_organization-page.scss` (A),
  `_drafts.scss` (B), `_add-editions.scss` (C), `_restructure.scss` (D) - each with a one-line owner comment.

### 1.14 Fixtures (`tests/DataFixtures/OrganizationFixture.php`, ids `018d0042-0000-0000-0000-0000000000NN`)

Depends on `EventsPageFixture`, `PlayerFixture`, `PuzzleFixture`. Dates anchored like `EventsPageFixture` (upcoming at
least 20 days ahead, past = last year). Made-up names.

| Const (NN) | What | Purpose |
|---|---|---|
| `ORGANIZATION_RIVERBEND` (01) "Riverbend Jigsaw Association" | short "RJA", slug `riverbend-jigsaw-association`, association, `us`, region "Riverbend Valley", website `https://riverbend-jigsaw.example`, social links Instagram + Discord, approved, created by PLAYER_WITH_STRIPE, maintainer PLAYER_WITH_FAVORITES | the published organization |
| `SERIES_LANTERN_NIGHTS` (02) "Lantern Brewing Puzzle Night" | in person, `us`, "Riverbend", approved, org RIVERBEND, schedule "Second Thursday of the month, 7:30 pm", eligibility "18+" | series card, schedule, eligibility |
| `EDITION_LANTERN_1` (03), `EDITION_LANTERN_2` (04) | +22 / +50 days | Coming up, card "Next" |
| `EDITION_LANTERN_DRAFT` (05) "Lantern Night Special" | +36 days, **draft** | draft edition in a published series |
| `SERIES_RIVERBEND_VIRTUAL` (06) "Riverbend Virtual Contest" | online, approved, org RIVERBEND, eligibility "Residents of Riverbend Valley", schedule "Fourth Friday of the month, 8 pm" | |
| `EDITION_VIRTUAL_PAST` (07) / `EDITION_VIRTUAL_NEXT` (08) | 15 June last year / +30 days | Past, Coming up |
| `COMPETITION_RIVERBEND_OPEN` (09) "Riverbend Spring Open" | one-time, in person, `us`, +60..+61 days, approved, org RIVERBEND, eligibility "Residents of Riverbend Valley" | one-time card, byline, eligibility tag |
| `COMPETITION_DRAFT_NIGHT` (10) "Birchwood Puzzle Draft Night" | one-time, in person, `cz`, +25 days, approved, **draft**, no org, created by PLAYER_WITH_STRIPE | draft one-time event (would be public when published) |
| `ROUND_DRAFT_NIGHT` (11) + `ROUND_PUZZLE_DRAFT_NIGHT` (12) | a solo round on it with `PUZZLE_3000` (no other round and no round test uses it - see "Foundation deviations") | the puzzle page must not name the draft |
| `SERIES_QUIET_PINES_DRAFT` (13) "Quiet Pines Puzzle Series" | in person, `de`, approved, **draft**, created by PLAYER_WITH_STRIPE; `EDITION_QUIET_PINES_1` (14) +27 days (not a draft itself) | editions of a draft series |
| `ORGANIZATION_HARBOR_CLUB_DRAFT` (15) "Harbor Puzzle Club" | club, `ie`, approved, **draft**, created by PLAYER_WITH_STRIPE; `SERIES_HARBOR_CLUB_MEETS` (16) "Harbor Club Meets" (approved, published, org = it, `ie`) with `EDITION_HARBOR_CLUB_1` (17) +33 days | a draft organization hides only itself |
| `ORGANIZATION_MAPLE_PENDING` (18) "Maple Leaf Puzzlers" | community, `ca`, **pending**, created by PLAYER_WITH_FAVORITES; `SERIES_MAPLE_PENDING` (19) pending, org = it, one edition (20) +40 days | approval queue; P2 cascade |
| `ORGANIZATION_CEDAR_PENDING_DRAFT` (21) "Cedar Grove Puzzle Guild" | pending **and draft**, created by PLAYER_WITH_FAVORITES | never in the queue |
| `COMPETITION_WILLOW_PENDING_DRAFT` (22) "Willow Creek Draft Cup" | one-time, `at`, +45 days, pending **and draft**, created by PLAYER_WITH_STRIPE | not in the queue, not on the admin events page |
| `COMPETITION_DRAFT_PAST` (23) "Old Harbor Draft Classic" | one-time, `cz`, 20 May last year, approved, **draft** | not in the archive |
| `FOLLOW_REGULAR_RIVERBEND` (24) | PLAYER_REGULAR follows ORGANIZATION_RIVERBEND | "Your events" through the organization |
| `FOLLOW_REGULAR_LANTERN` (25) | PLAYER_REGULAR follows SERIES_LANTERN_NIGHTS | deduplication |
| `FOLLOW_REGULAR_QUIET_PINES` (26) | PLAYER_REGULAR follows SERIES_QUIET_PINES_DRAFT (a row from before it went back to draft) | a draft never reaches "Your events" |

PLAYER_REGULAR's "You organize (3)" is unchanged. Document the fixture in `.claude/fixtures.md` (subsection
"Organizations and drafts (`OrganizationFixture`, ids `018d0042-…`)"). In the same commit, fix every test whose counts
the new upcoming public items change (expect: the events page summary, chips, country counts and Upcoming counts,
`EventsListUiTest`, `EventsCalendarTest`, `GetEventOccurrencesTest`, `GetEventSeriesDirectoryTest`, the sitemap tests,
`GetSelectableCompetitionsTest`, `GetCompetitionEventsTest`, `GetAdminQueueCountsTest`, the players page's upcoming
count) and list each in the commit message.

### 1.15 Translations (English, foundation blocks)

At the end of `messages.en.yml`, block `organization:` (`kind.association` "Association or federation", `kind.club`
"Club", `kind.shop` "Shop or brand", `kind.venue` "Venue", `kind.community` "Community", `kind.other` "Other";
`byline.organized_by` "Organized by %name%"; `byline.who_can_enter` "Who can enter: %text%"; `byline.when_it_happens`
"When it happens: %text%"; `flash.approved` "The organization is approved.", `flash.rejected` "The organization is
rejected.", `flash.deleted` "The organization is deleted.", `flash.not_empty` "The organization still has series or
events - move them to another organization or out of it first.") and block `drafts_core:` (`flash.published`
"Published - everyone can see it now.", `flash.published_waiting` "Published - everyone can see it once an admin
approves it.", `flash.unpublished` "Back to draft - only you and your team can see it.", `flash.cannot_unpublish` "It
cannot go back to draft: %reasons%.", `blocker.participants` "people have joined it", `blocker.results` "it has
official results", `blocker.solving_times` "solving times are linked to it"). Inside existing blocks:
`events_page.tag.draft` "Draft", `events_page.tag.eligibility` "Who can enter", `events_organizer.badge.draft` "Draft".
`validators.en.yml`, block `organization_fields:` (`social_links_too_many` "Add at most %limit% links.",
`social_link_invalid` "\"{{ value }}\" is not a web address (http:// or https://).").

### 1.16 Foundation tests

- `tests/Entity/OrganizationTest.php` (team, publish, visibility), `tests/Entity/CompetitionOrganizationTest.php`
  (an edition refuses an organization; `moveToSeries()` place rule; `isHiddenAsDraft()`).
- `tests/Value/SocialLinkPlatformTest.php` (every host, subdomains, `other`), `SocialLinksTest.php` (lines, dedupe, max),
  `FollowTargetTest.php` (extend: organization), `EventUrlPathTest.php`.
- `tests/Query/VisibilityParityTest.php` (1.5); `IsCompetitionPubliclyVisibleTest` (extend: draft event, draft edition,
  edition of a draft series, approved draft, `SQL_APPROVED` ignores drafts), `IsSeriesPubliclyVisibleTest`,
  `IsOrganizationPubliclyVisibleTest`.
- `GetCompetitionPermissionsTest` (extend): RIVERBEND's creator and maintainer edit it, the creator deletes it, the
  maintainer does not; both edit + delete SERIES_LANTERN_NIGHTS, its editions and COMPETITION_RIVERBEND_OPEN; PLAYER_REGULAR
  nothing of it; still one statement.
- `GetOrganizationTest`, `GetOrganizationsTest` (directory: RIVERBEND only - not the draft, not the pending ones; counts;
  choices per player; the queue: MAPLE only).
- `GetEventOccurrencesTest` (extend): organization fields; `forOrganization()` public vs team (draft edition, pending);
  `all(true)` has no drafts; `forSeries(…, true)` lists EDITION_LANTERN_DRAFT, `false` does not.
- `GetEventSeriesDirectoryTest`, `GetEventsViewerDataTest` (organization follow; PLAYER_WITH_FAVORITES organizes
  RIVERBEND + MAPLE + CEDAR; `organizedCount()`), `GetCompetitionSeriesTest`, `GetAdminQueueCountsTest` (extend).
- Handlers: `AddOrganizationHandlerTest`, `EditOrganizationHandlerTest`, `ApproveOrganizationHandlerTest` (P2 cascade:
  SERIES_MAPLE_PENDING approved; a rejected item stays), `RejectOrganizationHandlerTest`, `DeleteOrganizationHandlerTest`
  (409 with items, deletes an empty one with its follows), `OrganizationMaintainerHandlersTest`,
  `DraftPublishingHandlersTest` (all six: flags, the queue e-mail on publishing a pending item, no e-mail for an
  approved one, every blocker refuses Unpublish), `AssignEventToOrganizationHandlerTest` (team member → approved at
  once; stranger → `OrganizationNotManaged`; admin → allowed; edition → `OrganizationOnEdition`; a rejected item stays
  rejected; moving out keeps the approval), `AddEditionsHandlerTest` (24 max, slugs unique in the batch, drafts),
  extend `AddCompetitionHandlerTest` (org + policy, draft → no e-mail), `AddEditionHandlerTest`,
  `EditCompetitionHandlerTest`, `EditCompetitionSeriesHandlerTest`, `ConvertCompetitionToSeriesHandlerTest`
  (organization/draft/eligibility move to the series), `FollowCompetitionHandlerTest` (organization: public yes, draft
  and pending no), `DeletePlayerHandlerTest` (an organization survives its creator's deletion).
- `tests/Services/Organizations/OrganizationApprovalPolicyTest.php` (every condition of 1.8).
- `tests/Services/EventsPage/EventRowFactoryTest.php` (extend: Draft, Eligibility, OrganizationPage context, no
  "Waiting for approval" on a draft); `EventsPageBuilderTest` green (series lines carry public organizations only).
- `tests/Controller/DraftActionsControllerTest.php` (the six routes: CSRF, voter, redirect, refusal flash, never 200),
  `tests/Controller/OrganizationAdminActionsTest.php` (approve, reject, delete).
- `EventsPageQueryBudgetTest` and `DetailPagesQueryBudgetTest` **unchanged and green** - the guard that organizations
  cost no statement on existing pages.

Foundation done = gates green, the existing pages unchanged apart from the documented test-count changes, the skeleton
routes answer, one commit on `feature/organizations` ("Organizations and drafts: foundation - data, visibility,
permissions, messages, read models, skeletons").

### Foundation deviations (what the foundation built differently from the text above - binding for A-D)

- **Fixtures**: the Harbor Puzzle Club, its series and edition are in `ie`, not `gb` (PLAYER_WITH_STRIPE's home country
  `gb` must keep nothing planned - `EventsListUiTest`); `ROUND_PUZZLE_DRAFT_NIGHT` uses `PUZZLE_3000`, not
  `PUZZLE_1000_03` (`SecretPuzzleSafeguardsTest` needs `PUZZLE_1000_03` in no round). Both are corrected in 1.14 and B's
  canary list. The Riverbend series and the Spring Open are created by PLAYER_WITH_STRIPE; approvals are by PLAYER_ADMIN.
  `EDITION_MAPLE_PENDING_1` is a named constant; every name/slug constant is in `OrganizationFixture` and `.claude/fixtures.md`.
- **Validators**: `organization_fields.social_links_too_many` is "Add at most {{ limit }} links." - the `Count` constraint's
  placeholder (`%limit%` would never be replaced).
- **Extra keys in the foundation blocks**: `organization.menu.edit` ("Edit organization", the minimal ⋯ branch) and
  `organization.directory_title` ("Organizations", the directory skeleton).
- **`CannotUnpublishMessage`** (`src/Services/Drafts/`) builds the `drafts_core.flash.cannot_unpublish` text from a
  `CannotUnpublish` - the unpublish controllers use it; C reuses it for the inline "You organize" refusal.
- **`OrganizedEvent`** also has `seriesCount`, `eventCount` (organization rows) and `isOrganization()`; `reference()` of an
  organization is its name only (C links `organization_detail`). `badge()`: Rejected first, then Draft, then Waiting for
  approval; an approved, published organization answers `DateNotSet` (C decides what an organization row shows).
  `GetOrganizedEvents::byIds()` returns organizations first. `OrganizedEventsController` (C's) got the `Draft` arm in its
  rank `match` (PHPStan) - order Rejected, Draft, Waiting, Live, Upcoming, Date not set, Past.
- **`EventsViewerData::organizedCount()`**: "not under one of them" = not under one of the viewer's own organizations
  (an item under somebody else's organization counts on its own).
- **`OrganizationApprovalPolicy::approveIfUnderTrustedOrganization()`** also tells the official results of an item that is
  public after the approval (as approving does); nothing for a draft (publishing tells them).
- **Publish handlers** are no-ops for an item that is not a draft (no second admin e-mail). Publish/unpublish/delete
  controllers answer a wrong CSRF token with 403, like the delete controllers. `ApproveOrganizationController` treats a
  second approve (`OrganizationNotApprovable`) as done (redirect, no flash). The "approved" e-mail of an organization links
  `organization_detail` in the creator's locale.
- **`AddEditions`** slugs: unique through `CompetitionSlugGenerator::isTaken($slug, $seriesId)` (the series' editions and
  standalone events - the rule explicit edition slugs follow) and within the batch, suffix `-2`, `-3`; an unsluggable name
  falls back to `edition`. Editions get no `createdAt`/`addedByPlayer` (as `AddEdition`).
- **Creators are never maintainer rows**: `AddOrganization` / `EditOrganization` skip the creator's id in the team list
  (like `AddOrganizationMaintainer`).
- **Edition page**: the series' draft flag comes from `CompetitionSeriesOverview::$isDraft` (the entity's `series` is a
  lazy proxy - reading it cost a statement, `DetailPagesQueryBudgetTest`). The series page's `$canManage` also feeds
  `show_menu`.
- **`UnpublishCompetitionSeries`** is not detected by `SerializedByLockMessagesTest` (its handler touches no participant
  model), so it is not listed in `NOT_LOCKED`; `UnpublishCompetition`'s lock key is pinned there.
- **Existing tests changed by the fixtures**: see the foundation commit message (UnfollowCompetitionHandlerTest,
  OrganizedEventsPageTest, GetEventsViewerDataTest, GetAdminQueueCountsTest, GetCompetitionPermissionsTest,
  BlocklistQueryCoverageTest, SuspiciousTimeQueryCoverageTest).

## 2. Workstreams (parallel, after the foundation commit)

Nothing outside a workstream's "Owns" list is edited (shared append-only files excepted, section 0). Read-only use of
every foundation file.

### A. Organization pages (`org-ws-a`, DB `speedpuzzling_orga_test`)

**Goal**: the organization page, the directory, the add/edit forms, "Organized by", the admin queue section, organization
names in the events page search and on series lines, the `Organization` JSON-LD and the `organizer` of event JSON-LD.

**Owns**:
- Controllers: `src/Controller/Organizations/OrganizationDetailController.php`, `OrganizationsController.php`,
  `AddOrganizationController.php`, `EditOrganizationController.php` (taking over the skeletons);
  `src/Controller/Admin/CompetitionApprovalsController.php`.
- `src/FormType/OrganizationFormType.php` (new), `src/FormType/CountryChoices.php` (new, optional: the country choices
  of `CompetitionFormType` as a reusable helper - `CompetitionFormType` itself is C's and is not changed).
- `src/Services/Organizations/OrganizationPageBuilder.php`, `OrganizationsDirectoryBuilder.php`; view models in
  `src/Results/Organizations/` (`OrganizationPage`, `OrganizationSeriesCard`, `OrganizationEventCard`,
  `OrganizationsDirectory`, `OrganizationsDirectoryItem`).
- `src/Services/EventsPage/EventsIndexFactory.php`; `src/Services/CompetitionUrlField.php` (+ `organizationSlug()`).
- Templates: `organization_detail.html.twig`, `organizations.html.twig`, `add_organization.html.twig`,
  `edit_organization.html.twig`, `templates/organization/` (all partials: `_header.html.twig`, `_coming_up.html.twig`,
  `_what_we_run.html.twig`, `_series_card.html.twig`, `_event_card.html.twig`, `_past.html.twig`, `_json_ld.html.twig`,
  `_social_links.html.twig`, `_form.html.twig`), `event_parts/_organized_by.html.twig`,
  `event_parts/_event_json_ld.html.twig` and `_series_json_ld.html.twig` (`organizer` only - keep the foundation's
  gating), `events/_series_directory.html.twig`, `admin/competition_approvals.html.twig`.
- `assets/styles/_organization-page.scss`; reuse `series_archive_controller.js` unchanged for the past (same markup).
- Translations: block `organization_page:`.
- Tests listed below.

**Builds**:
1. **Page** (`organization_detail`): `GetOrganization::bySlug()`; `$canManage` = signed in and `ORGANIZATION_EDIT`; the
   draft guard stays; `GetEventOccurrences::forOrganization($id, $canManage)`, `GetEventSeriesDirectory::forOrganization($id, $canManage)`,
   `GetEventGoingCounts::forCompetitions()` of the coming ones (none → no statement), `GetEventsViewerData` (signed in);
   `OrganizationPageBuilder::build(OrganizationDetail, list<EventOccurrence>, list<EventSeriesRow>, array $goingCounts, ?EventsViewerData, DateTimeImmutable $now, string $locale): OrganizationPage`
   with: `comingUp: list<AgendaMonth>` + `live: list<AgendaRow>` + `ongoing: list<AgendaRow>` (rows through
   `EventRowFactory::row(…, RowContext::OrganizationPage)`; month headers as the series page), `seriesCards` (name, url,
   place, schedule, eligibility, edition count, next/last/none like `SeriesLine`, follow target + following, isDraft /
   isPending tags for the team), `eventCards` (upcoming one-time events: name, url, place, date, eligibility, star),
   `pastYears: list<ArchiveYear>` (one `archiveLine()` per past occurrence, `RowContext::OrganizationPage`),
   `followTarget` (null unless public), `following`. Template per README "Organization page" (header with
   `_social_links` - each icon link has the platform label, else the host, as its accessible name; ⋯ via
   `event_parts/_manage_button.html.twig` with `ManageRef::KIND_ORGANIZATION`; the labelled follow star
   `_follow_action` with `kind: 'organization'`).
2. **SEO**: title/meta (`organization_page.meta.*`), robots (indexable only when `isPublic()`), `organization/_json_ld.html.twig`
   (`Organization`: name, alternateName, url, logo `puzzle_large`, description, sameAs, address) through `json_ld`.
3. **Directory** (`organizations`): `GetOrganizations::publicDirectory()` + `GetEventOccurrences::forOrganizations()`
   → `OrganizationsDirectoryBuilder` (next date per organization from the occurrences' statuses); "+ Add organization"
   for signed-in players; indexable. A link "Organizations" in the head of the events page's series directory
   (`events/_series_directory.html.twig`).
4. **Forms**: `OrganizationFormType` on `OrganizationFormData` (fields of README "Forms"; social links as a textarea,
   one per line, transformed to the list; maintainers = the TomSelect player picker of `CompetitionFormType`, max 10;
   logo with `FormPhotoStash` like the event forms; edit adds the "URL" field via `CompetitionUrlField::organizationSlug()`);
   add → `AddOrganization` (`isDraft` from a secondary "Save as draft" submit `saveDraft`), redirect to the organization
   page; edit → `EditOrganization`, redirect back with a flash; the edit page lists the team (names, `#code`) - for
   the team only; rejected: the reason as a danger banner, pending: a "waiting for approval" note.
5. **"Organized by"** (`_organized_by.html.twig`): `organization.byline.organized_by` with `%name%` = the link to
   `organization_detail` - the name escaped before the substitution and the result `|raw`, as `_event_time.html.twig`
   assembles its text - when `organization.isPublic` or the viewer has `ORGANIZATION_EDIT` (then with a "Draft" /
   "Waiting for approval" mark when not public). The header star of the organization page is `_follow_action` with
   `kind: 'organization'` (it falls back to the "Follow" / "Following" texts - no change to that partial).
6. **Events page**: `EventsIndexFactory::occurrence()` and `series()` fold the organization's name and short name into
   `x` (public organizations only); `_series_directory.html.twig` writes "by {name}" under a line whose `line.organization`
   is set.
7. **Event JSON-LD**: `_event_json_ld.html.twig` (event and edition) and `_series_json_ld.html.twig` add `organizer`
   (`{"@type": "Organization", "name", "url"}`) when the organization is publicly visible.
8. **Admin queue**: an "Organizations" section above series (`GetOrganizations::allUnapproved()`), approve/reject forms
   posting to `admin_approve_organization` / `admin_reject_organization`.

**Tests**: `tests/Controller/OrganizationPageTest.php` (guest: header, social links, About, Coming up rows with "18+"
and "Recurring", the Lantern card "Next", the Riverbend Spring Open card, Past line of EDITION_VIRTUAL_PAST, no
EDITION_LANTERN_DRAFT; team (PLAYER_WITH_STRIPE): the draft edition tagged Draft, ⋯ present; follower PLAYER_REGULAR:
"Following" pressed; HARBOR_CLUB_DRAFT: 404 guest / 200 + banner hook for its creator; MAPLE (pending): 200,
`noindex`, no star; unknown slug 404; **canary-style**: with `is_draft` set to false by SQL EDITION_LANTERN_DRAFT is
listed to a guest, with true it is not), `OrganizationsDirectoryTest`, `OrganizationFormsTest` (add with and without
draft, edit, slug taken, social links validation, team list), `OrganizationPagesQueryBudgetTest` (page guest 4 /
player 10 / team 10; directory guest 2 / player 6; 10 more series with editions add none), `OrganizationJsonLdTest`
(parses, `sameAs`, `</script>` in a name stays inside its string, none on a non-public page), `OrganizedByTest` (series,
edition, event pages: link for RIVERBEND items, nothing for HARBOR_CLUB_MEETS to a guest - canary-style: shown once
the organization is published by SQL - shown to its team; the crumb follows the same rule),
`CompetitionApprovalsOrganizationsTest` (MAPLE listed, CEDAR not), extend `EventsIndexFactoryTest` (organization name
searchable, a draft organization's not), `tests/Services/Organizations/OrganizationPageBuilderTest.php` (unit,
synthetic, fixed now); add `organization_detail` + `organizations` to `RobotsTxtTest`'s crawlable pages.

**Acceptance**: README "Organization page", "Directory", the "Organized by" part of the detail pages and the events page
bullets 1-2 hold; budgets pinned; works at 360 px (cards one column, header actions wrap, 44 px targets).

### B. Drafts (`org-ws-b`, DB `speedpuzzling_orgb_test`)

**Goal**: no draft on any public surface - the hunt, the guards, the banner, and the tests that keep it that way.

**Owns**:
- `templates/event_parts/_draft_banner.html.twig`, `assets/styles/_drafts.scss`; the three detail page templates
  (`competition_series_detail.html.twig`, `edition_detail.html.twig`, `event_detail.html.twig`) for any further change.
- Controllers: `EventDetailController`, `EditionDetailController`, `CompetitionSeriesDetailController`,
  `EditionDetailLegacyRedirectController`, `EventRoundResultsController`, `EditionRoundResultsController`,
  `JoinCompetitionController`, `src/Controller/Events/AbstractEventFollowController.php`, `SitemapEventsController`,
  `SitemapStaticController`, `ManageSeriesPageController`, `ManageCompetitionPageController`,
  `ManageCompetitionRegistrationController`, `OfficialResults/ResultsDeskController` (+ the "not public yet" lines of
  their templates `manage_page_sections.html.twig`, `manage_competition_registration.html.twig`,
  `official_results/results_desk.html.twig` - only those lines).
- `src/MessageHandler/JoinCompetitionHandler.php`, `src/MessageHandler/AddPuzzleSolvingTimeHandler.php` (the round
  branch only), `src/Api/V1/CreateSolvingTimeProcessor.php`, `src/Services/CompetitionRegistrationMailer.php`.
- Queries: `GetWjpcEvents`, `GetCompetitionSlugsForSitemap`, `GetTags`, `GetPuzzleSummary`.
- Translations: block `drafts:`.
- Tests: `tests/DraftCanaryTest.php`, `tests/DraftVisibilityCoverageTest.php`, `tests/Controller/DraftBannerTest.php`,
  `tests/Controller/DraftPagesTest.php`, plus the tests of the files above.

**Builds**:
1. **Banner** (`_draft_banner.html.twig`): own draft → "Draft: only you and your team can see this page." (`drafts.banner.own`)
   + a **Publish** form (POST `publish_competition` / `publish_competition_series` / `publish_organization`, session
   CSRF token, `return` = the current path) when the viewer passes the kind's edit voter; edition of a draft series →
   `drafts.banner.series` + **Publish series** (`publish_competition_series`, `COMPETITION_SERIES_EDIT`). Both lines when
   both apply. `role="status"`, the `ev-draft-*` classes, 44 px button.
2. **Guards** (README P5, P7): event page, edition page, series page - a hidden draft (`draft_state.isHidden`) for a
   viewer without the edit voter → `throw new DraftNotVisible()`, **before** the event page's edition redirect;
   `EditionDetailLegacyRedirectController` and the two round results controllers: the same before their redirects
   (round results of a non-public event already 404 in `RoundResultsPageBuilder` - keep).
3. **Join** (P17): `JoinCompetitionController` (GET and POST, both flows) → 404 unless `IsCompetitionPubliclyVisible::check()`;
   `JoinCompetitionHandler` throws `CompetitionNotFound` for a non-public event (every caller).
4. **API v1**: `AddPuzzleSolvingTimeHandler` - a round of a non-public competition → `CompetitionRoundNotFound`;
   `CreateSolvingTimeProcessor` maps it as today (404).
5. **Follow names**: `AbstractEventFollowController::nameOf()` names only a target that is publicly visible or that the
   viewer followed.
6. **Converted readers** (the classification of 2026-10-08): `GetWjpcEvents::allEditions()` (aliases + `SQL_CONDITION`),
   `GetCompetitionSlugsForSitemap::standaloneEventSlugs()` (`SQL_CONDITION`) and `seriesSlugs()` (`IsSeriesPubliclyVisible`),
   `GetTags::forPuzzle()` and `GetPuzzleSummary::forPuzzle()` series branches (`IsSeriesPubliclyVisible`).
7. **Sitemaps**: `GetCompetitionSlugsForSitemap::organizationSlugs()` (publicly visible organizations) →
   `sitemap-events.xml` (`organization_detail`); `organizations` in `SitemapStaticController`.
8. **Organiser hints**: the four manage controllers pass whether the item is a draft; their "not public yet" line says
   "This is a draft - publish it when it is ready." (`drafts.hint.*`) instead of the approval wording for drafts.
9. **Registration e-mails** (P18): nothing is sent while the event is hidden as a draft.
10. **Guard tests**:
    - `DraftVisibilityCoverageTest` (the pattern of `SuspiciousTimeQueryCoverageTest`): every file under `src/` whose
      code (comments stripped) matches `/\b(FROM|JOIN)\s+(competition|competition_series|organization)\b(?![_a-z])/i`
      contains `IsCompetitionPubliclyVisible`, `IsSeriesPubliclyVisible`, `IsOrganizationPubliclyVisible` or `is_draft`, or is
      listed in `NOT_FILTERED` with a reason. Reasons (constants): `VIA_SOLVING_TIMES` ("Reached through
      puzzle_solving_time.competition_id - a draft never gets a linked time: the pickers offer public events only, the
      API refuses a round of a non-public event, Unpublish refuses with linked times"), `PAGE_GATED` ("Called only by a
      page or endpoint that 404s a draft first"), `ORGANISER` ("Organiser tooling behind COMPETITION_EDIT / a voter"),
      `ADMIN`, `OWN` ("The viewer's own rows"), `WRITE`, `URL_ONLY` ("Builds a URL, shows nothing"),
      `NOTIFICATION` ("Rows created only after IsCompetitionPubliclyVisible::check(); Unpublish refuses with results"),
      `MARKS` ("Marketplace event marks exist only on qualifying (public) events"), `TAGS` ("Competition tags come from
      the admin internal API only"). Expected entries (from the classification - a starting point: a file whose code
      names a constant or `is_draft` is decided already and must not be listed; the test reports both ways):
      `GetFastestPlayers`, `GetFastestPairs`, `GetFastestGroups`, `GetPlayerSolvedPuzzles`, `GetPuzzleSolvers`,
      `GetPuzzleResultDetail`, `GetRecentActivity`, `GetPlayerDuplicateCases`, `GetSuspiciousTimeCaseDetail`
      (`VIA_SOLVING_TIMES`); `GetEditionRounds`, `GetEventAttendance`, `GetCompetitionPageSections`, `GetPublishedRoundResults`,
      `GetEventOffers` (`PAGE_GATED`); `GetCompetitionPermissions`, `GetCompetitionRoundsForManagement`,
      `GetRoundResultsOverview`, `GetParticipantsSheetState`, `GetParticipantsSheetVersion`, `GetPageSectionOwner`,
      `SheetChangesPlanner`, `RoundPuzzleOwnership`, `SecretPuzzleAccess` (`ORGANISER`); `GetEventsViewerData` (`OWN`);
      `GetNotifications`, `GetRoundsWithPublishedOfficialResults` (`NOTIFICATION`); `GetStoredFileReferences`,
      `CompetitionSlugGenerator`, `WarmupImgproxyCacheConsoleCommand`, the write handlers that SELECT
      (`AddCompetitionSeriesHandler`, `AddEditionHandler`, `ConvertCompetitionToSeriesHandler`, `DeleteCompetitionSeriesHandler`,
      `UnpublishBlockers`) (`WRITE` / `URL_ONLY`); and the new files of A, C and D once merged (each owner adds its own
      entry when its file has no constant - prefer using the constant). The test also fails on stale entries.
    - `DraftCanaryTest` (the pattern of `BlocklistCanaryTest`): per surface, the test first sets the item's
      `is_draft = false` by SQL and asserts the needle (name or id) **is** on the page (else it is no canary), then
      `is_draft = true` and asserts it is not. Surfaces × items: events page (guest, PLAYER_REGULAR, PLAYER_ADMIN - its
      admin view and its index) × COMPETITION_DRAFT_NIGHT, EDITION_LANTERN_DRAFT, EDITION_QUIET_PINES_1 (via
      SERIES_QUIET_PINES_DRAFT), SERIES_QUIET_PINES_DRAFT (series directory); `?view=calendar`; the archive year of
      COMPETITION_DRAFT_PAST; "Your events" of PLAYER_REGULAR (FOLLOW_REGULAR_QUIET_PINES); the series page of
      SERIES_LANTERN_NIGHTS (guest) × EDITION_LANTERN_DRAFT; the organizations directory (the foundation's skeleton
      lists names) × ORGANIZATION_HARBOR_CLUB_DRAFT; `sitemap-events.xml` × all of them; API v1 `GET /api/v1/competitions` and `/api/v1/competitions/{id}` (OAuth2 client
      credentials, `OAuth2TestHelper`) × COMPETITION_DRAFT_NIGHT; the add-time form's competition picker (`puzzle_add`
      HTML) and `?competition=<id>` pre-selection × COMPETITION_DRAFT_NIGHT and EDITION_LANTERN_DRAFT; the puzzle page of
      `PUZZLE_3000` (its "used at" / tags) × COMPETITION_DRAFT_NIGHT; the marketplace event select × COMPETITION_DRAFT_NIGHT;
      the players page's upcoming count. Plus direct checks: the item pages answer 404 to a guest and to PLAYER_REGULAR,
      200 to the team and admins with `noindex`; follow, join and API time POST on a draft are refused; the approval queue
      and the admin badge never list WILLOW / CEDAR. **Comment at the top: "Add every new event-listing surface here."**
      The surfaces A builds (the organization page's rows and cards, the "Organized by" byline and crumb) are checked
      canary-style in A's own tests while the workstreams run, and added to this data provider at integration (4.1).

**Tests (besides the two guards)**: `DraftBannerTest` (own banner + Publish for the team, series banner on
EDITION_QUIET_PINES_1, no banner for a published page, Publish button posts with a token), `DraftPagesTest` (404/200
matrix incl. the legacy edition redirect and `/en/events/{edition-slug}`), extend `JoinCompetitionControllerTest`
(non-public → 404), the API v1 solving time test (round of a draft → 404), `GetWjpcEventsTest`, sitemap tests
(organizations listed, drafts not), `GetTagsTest` / `GetPuzzleSummaryTest` (draft series branch).

**Acceptance**: both guard tests green with no undecided reader; README "Drafts" and "Visibility" hold; the canary
fails if any one of the converted readers is reverted (check two by hand).

### C. Organiser surfaces and forms (`org-ws-c`, DB `speedpuzzling_orgc_test`)

**Goal**: everything an organiser touches: "You organize" with organizations and drafts, the ⋯ menu (Publish/Unpublish,
organization items, the restructuring links), the Organization select, Save as draft, Who can enter / When it happens
on forms and pages, "Add several dates", "Your events" with followed organizations.

**Owns**:
- `src/Controller/Events/OrganizedEventsController.php`, `templates/events/organized.html.twig`,
  `src/Query/GetOrganizedEvents.php`, `src/Results/OrganizedEvent.php`, `src/Value/OrganizerBadge.php`,
  `templates/events/_manage_items.html.twig`, `src/Controller/Events/EventManageMenuController.php`,
  `templates/events/_organize_button.html.twig`, `assets/styles/_events-organizer.scss`.
- Forms: `src/FormType/CompetitionFormType.php`, `src/FormData/CompetitionFormData.php`, `src/FormType/EditionFormType.php`,
  `src/FormData/EditionFormData.php`, new `src/FormType/AddEditionsFormType.php`, `src/FormData/AddEditionsFormData.php`.
- Controllers: `AddCompetitionController`, `EditCompetitionController`, `EditCompetitionSeriesController`,
  `AddEditionController`, new `src/Controller/AddEditionsController.php` (route `add_editions`, cs `/pridat-edice/{seriesId}`,
  others `/{xx}/add-editions/{seriesId}`).
- `src/Value/EditionDateRule.php` + `src/Value/EditionDateRuleKind.php` (new).
- Templates: `add_competition.html.twig`, `edit_competition.html.twig`, `edit_competition_series.html.twig`,
  `add_edition.html.twig`, new `add_editions.html.twig`, `manage_competition_rounds.html.twig` (the move link only),
  `manage_competition_series.html.twig` (links only), `event_parts/_eligibility.html.twig`, `event_parts/_schedule.html.twig`.
- `src/Services/EventsPage/EventsPageBuilder.php` (`yourEvents()` only).
- `assets/styles/_add-editions.scss`; any JS for the forms (show "When it happens" only while "Recurring" is ticked,
  like the dates) in a new lazy controller `assets/controllers/organizer_form_controller.js`.
- Translations: block `organizer_tools:`; validators block `add_editions:`.

**Builds**:
1. **"You organize"** (P9): `GetOrganizedEvents::byIds($viewer->organizedCompetitionIds(), $viewer->organizedSeriesIds(), $viewer->organizedOrganizationIds())`
   (the viewer statement already includes the series and one-time events of the viewer's organizations);
   organizations first (Draft / Waiting / Rejected badges, "N series · N events"), each followed by its items
   (`organizationOf()`), then the rest as today; "+ Add organization" (`add_organization`) next to "+ Add event" and a
   link to the directory (`organizations`); the header count = `organizedCount()` (foundation). Sorting inside a group as today (Rejected, Draft, Waiting, Live,
   Upcoming, Date not set, Past).
2. **⋯ menu** (`_manage_items.html.twig` + `EventManageMenuController`): README "The ⋯ menu" table - Publish (draft) /
   Unpublish (not a draft): in the ⋯ menu (one item, loaded on demand) Unpublish is offered only when
   `UnpublishBlockers` allows it, otherwise a plain line "Cannot go back to draft: …" with the reasons; in the inline
   layout of "You organize" it is always offered (no statement per item) and a refusal comes back as the flash.
   Organization items (Edit, Add event
   `add_competition?organization=<id>`, Delete when `ORGANIZATION_DELETE`, admin Approve/Reject while pending), series:
   Add several dates, Turn into an organization (no organization yet), editions: Move to another series; the rounds page:
   "Move to another event or edition" per round (`move_competition_round`). Admin approval items never on drafts.
3. **Organization select** on `CompetitionFormType` (`organizationId`, `ChoiceType`, choices `GetOrganizations::choicesForPlayer()`
   or `allChoices()` for admins; draft / pending ones marked in the label; "None" first; `?organization=` pre-selects on
   add). Add: passed in `AddCompetition` / `AddCompetitionSeries`. Edit (event, series): when it changed, dispatch
   `AssignEventToOrganization` after the edit message (the select only offers allowed values; an `OrganizationNotManaged`
   is turned into a form error, 422). An edition's edit form shows "Organized by {series' organization}" read-only.
4. **Who can enter / When it happens**: fields on `CompetitionFormType` (`eligibility`, `schedule` - schedule only when
   recurring / on the series form) and `EditionFormType` (`eligibility`), with the README's help texts; passed to the
   messages. Display: `_eligibility.html.twig` ("Who can enter: …" via `organization.byline.who_can_enter`) and
   `_schedule.html.twig` (`organization.byline.when_it_happens`), each `<span class="ev-detail-by">`.
5. **Save as draft**: a secondary submit `saveDraft` (`SubmitType`, `organizer_tools.form.save_draft`) on the add forms
   (event/series, edition, several dates); `isDraft = $form->get('saveDraft')->isClicked()`; the flash says it is a
   draft (`organizer_tools.flash.saved_as_draft`).
6. **Add several dates** (`add_editions`, P15/P16): a GET form (`how`, `rule`: `EditionDateRuleKind` = `weekly`,
   `nth_weekday`, `last_weekday`; `weekday` 1-7; `nth` 1-4; `starting` date; `count` 1-24; or `dates[]`; `name_pattern`
   default `"{series name} {date}"`; `eligibility`) renders the dates (`EditionDateRule::dates(): list<DateTimeImmutable>`,
   unit-tested) with their names (`{date}` → `EventsPageDates::format($date, 'yMMMMd', $locale)`; a pattern without
   `{date}` gets " {date}" appended), each a checked checkbox (a date that already has an edition of the series:
   unchecked, marked); POST `AddEditionsFormData` (the checked dates + names, `saveDraft`) → `AddEditions` with
   `NewEdition` per date → redirect to `manage_competition_series` with a flash "N editions added". Linked from
   `add_edition.html.twig`, the series ⋯ and `manage_competition_series.html.twig`. Date fields use the site's datepicker
   as the add-edition form does.
7. **"Your events"** (`EventsPageBuilder::yourEvents()`): an occurrence whose `organization` is public and followed counts
   as followed - a one-time event like a followed event, an edition like a followed series (its next one per series);
   one row per competition, Going wins, deduplicated with direct follows.

**Tests**: `tests/Controller/OrganizedEventsTest.php` (PLAYER_WITH_STRIPE: RIVERBEND group with its series and the open,
the draft night with Draft first, header count; PLAYER_WITH_FAVORITES: RIVERBEND + MAPLE (Waiting) + CEDAR (Draft);
PLAYER_REGULAR unchanged), extend `EventManageMenuControllerTest` (Publish/Unpublish per state, organization items,
Delete only for the creator, Move/Turn-into links per kind, admin approve absent on drafts), `CompetitionFormsOrganizationTest`
(add with an organization as PLAYER_WITH_FAVORITES → approved at once; as PLAYER_REGULAR the select has no RIVERBEND;
`?organization=`; edit moves in/out; edition shows the series' organization read-only; Save as draft → `is_draft`,
no admin e-mail), `EligibilityScheduleTest` (forms save them; the byline and row tags show them; edition inherits),
`AddEditionsControllerTest` (preview GET lists the dates, POST creates the checked ones, max 24, existing date unchecked,
draft), `tests/Value/EditionDateRuleTest.php` (weekly, 1st-4th weekday, last weekday incl. 5-week months and year
boundaries, count), `YourEventsOrganizationsTest` (PLAYER_REGULAR: Riverbend items marked Following, Lantern once,
Quiet Pines never), `OrganizedEventsQueryBudgetTest` (measured and pinned; 5 more items add none).

**Acceptance**: README "You organize", "The ⋯ menu", "Forms", "Add several dates" and the "Your events" part of
"Follow" hold; forms keep 422 on errors and redirect on success; works at 360 px.

### D. Restructuring tools and the internal API (`org-ws-d`, DB `speedpuzzling_orgd_test`)

**Goal**: move an edition, move a round, turn a series into an organization (messages, web pages, internal API), the
redirect subscriber, every internal API endpoint of the README, the docs of the internal API.

**Owns**:
- Messages + handlers: `MoveEditionToSeries` (`string $competitionId, string $targetSeriesId, string $actingPlayerId,
  ?string $newSlug = null`; `implements SerializedByLock`, key `CompetitionParticipantsLock::key($competitionId)`),
  `MoveRoundToCompetition` (`string $roundId, string $competitionId` (= the round's current competition, the lock key),
  `string $targetCompetitionId, string $actingPlayerId`; `implements SerializedByLock`), `CreateOrganizationFromSeries`
  (`string $seriesId, UuidInterface $organizationId, string $actingPlayerId, string $name, ?string $shortName,
  ?string $slug, ?OrganizationKind $kind, ?string $countryCode, ?string $region, bool $approve, ?string $newSeriesName = null,
  ?string $newSeriesSlug = null`).
- Exceptions: `NotAnEdition` (409), `RoundNotMovable` (409, `RoundNotMovableReason` enum: `same_competition`,
  `has_entries`, `stopwatch_running`; the category invariant reuses `PuzzleAlreadyInCompetitionRoundCategory` → 409),
  `RoundMovedMeanwhile` (409), `SeriesAlreadyInOrganization` (409).
- `src/Services/Restructuring/EventUrlRedirects.php` (writes rows: `remember(EventUrlPath, target)` - `findByPath()` then
  `pointTo()` or a new row), `src/Query/GetEventUrlRedirect.php` (`target(EventUrlPath): ?array{route, params}` - one
  statement joining every target to its current slugs), `src/EventSubscriber/EventUrlRedirectSubscriber.php`.
- `src/Repository/PuzzleSolvingTimeRepository.php` (+ `findByCompetitionRound(CompetitionRound): list<PuzzleSolvingTime>`).
- Web: the three controllers (taking over the skeletons), `src/FormType/MoveEditionFormType.php`,
  `MoveRoundFormType.php`, `CreateOrganizationFromSeriesFormType.php` (+ form data classes), templates
  `templates/restructure/move_edition.html.twig`, `move_round.html.twig`, `series_to_organization.html.twig`,
  `assets/styles/_restructure.scss`.
- Internal API: new controllers in `src/Controller/InternalApi/` (one per endpoint of the README table - names
  `ListOrganizationsController`, `GetOrganizationController`, `CreateOrganizationController`, `UpdateOrganizationController`,
  `ApproveOrganizationController`, `DeleteOrganizationController`, `AddOrganizationMaintainerController`,
  `RemoveOrganizationMaintainerController`, `PublishOrganizationController`, `UnpublishOrganizationController`,
  `ListSeriesController`, `GetSeriesController`, `CreateSeriesController`, `UpdateSeriesController`,
  `PublishSeriesController`, `UnpublishSeriesController`, `CreateEditionController`, `AssignSeriesOrganizationController`,
  `AssignCompetitionOrganizationController`, `PublishCompetitionController`, `UnpublishCompetitionController`,
  `MoveEditionController`, `MoveRoundController`, `CreateOrganizationFromSeriesController` - all under
  `SpeedPuzzling\Web\Controller\InternalApi`), `OrganizationInput.php`, `SeriesInput.php`; changes to
  `CreateCompetitionController`, `UpdateCompetitionController`, `CompetitionInput`, `ListCompetitionsController`,
  `GetCompetitionController`; `src/Query/GetAdminCompetitions.php` + `src/Results/AdminCompetition*.php` (`organizationId`,
  `eligibility`, `draft`; `status` from `SQL_APPROVED`; the `pending` filter = not approved, not rejected; new `draft`
  filter); new `src/Query/GetAdminOrganizations.php`, `GetAdminSeries.php` + results `AdminOrganization*`, `AdminSeries*`
  (list them in `BlocklistQueryCoverageTest` as admin tooling when they join `player`).
- Docs: `docs/features/internal-api.md` (a section "Organizations, series and drafts" + the moves, field tables in the
  style of "Competitions and events"), `docs/features/internal-api.openapi.yaml`.
- Translations: block `restructure:`.

**Builds**:
1. **Move an edition** (README): `NotAnEdition` for a one-time event; the target ≠ the current series; the actor may
   edit the edition and the target series (`GetCompetitionPermissions` or admin - checked in the controllers/API; the
   handler re-checks the lock-time state); slug taken in the target (`CompetitionSlugGenerator::isTaken($slug, $targetSeriesId, $competitionId)`)
   → `newSlug` (validated, free) or `CompetitionSlugTaken`; `Competition::moveToSeries()`; redirect rows for
   `EventUrlPath::edition(old series slug, old slug)` → the competition and `editionRound(…)` → each round with a slug.
   Web page: a select of the series the actor can edit (`CompetitionPermissions` editable series; admins: all not
   rejected), the slug field shown with a "taken" error; success → the edition's new page.
2. **Move a round** (D7, P22): under the source lock; the round's competition must still be `competitionId`
   (`RoundMovedMeanwhile`); refusals of the README (`RoundNotMovable`, `PuzzleAlreadyInCompetitionRoundCategory` naming
   the target's round); slug: the round's slug, `-2`, `-3`, … until free in the target; `CompetitionRound::moveToCompetition()`;
   every `PuzzleSolvingTime` of the round → `competitionRoundMovedTo($target)`; the round's table layout moves with it
   (rows hang on the round); a redirect row for the old results path (`eventRound` / `editionRound`). The two
   `CompetitionRoundsChanged` events reconcile both competitions after the flush. Web page: a select of the competitions
   the actor can edit (admins: an autocomplete of all not rejected), every refusal as a form error (422).
3. **Turn a series into an organization** (README): `SeriesAlreadyInOrganization` when it has one; the organization
   from the series (logo path shared, `about` = description, `website` = link, `countryCode` = the series' country,
   `region` from the input else the series' location, maintainers = the series' maintainers, creator = the series'
   creator ?? the actor), approved when `$approve` (internal API; web: when the actor is an admin), else pending + the
   admin e-mail; followers moved (`FollowedCompetition::moveToOrganization()`; a player already following the
   organization keeps one row); `assignOrganization()` on the series; new name/slug (validated, free) with redirect rows:
   `series(old)` → the organization, `edition(old, e)` → each edition, `editionRound(old, e, r)` → each round. The
   series' approval is unchanged. Web page: prefilled form, success → the organization's page.
4. **Redirects** (D6, P5): `EventUrlRedirectSubscriber` (priority 8, like `ManufacturerSlugRedirectSubscriber`) on a main
   request's `NotFoundHttpException` that is **not** `DraftNotVisible`, for the five routes of the README; builds the
   `EventUrlPath` from the route params; `GetEventUrlRedirect::target()` → 301 to the generated URL in the request's
   locale + the query string.
5. **Internal API**: every endpoint of the README table, `INTERNAL_API_REVIEWER_PLAYER_ID` as the actor (400 when empty
   for writes that need it), the web forms' validation (`OrganizationFormData`, `CompetitionFormData` with `series:
   true` rules, `EditionFormData`), unknown fields 400, JSON errors and the audit log through the existing subscribers,
   `InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE` on every 201. `organizationId` on competition/series create goes
   into `AddCompetition` / `AddCompetitionSeries` (the policy approves under an approved organization - the reviewer is
   an admin); on `PATCH` it dispatches `AssignEventToOrganization`; `draft` on create → `isDraft`, on `PATCH` →
   Publish/Unpublish (409 with the blockers); answers gain `organizationId`, `eligibility`, `draft`.

**Tests**: `MoveEditionToSeriesHandlerTest` (slug kept / taken / new slug, place rule, redirect rows, visibility follows
the target), `MoveRoundToCompetitionHandlerTest` (puzzles, tables, times move; both competitions reconciled - the times
keep their round; each refusal changes nothing; slug suffix; zone kept), `CreateOrganizationFromSeriesHandlerTest`
(fields copied, followers moved with a duplicate, series attached and renamed, redirect rows), `EventUrlRedirectSubscriberTest`
(the five path kinds, chained moves resolve to the current URL, a live page wins, a draft page wins - `DraftNotVisible`
not redirected), `tests/Controller/RestructurePagesTest.php` (each page: access, select choices, a refusal as a 422
form error, success redirect), `tests/Controller/InternalApi/OrganizationsInternalApiTest.php`, `SeriesInternalApiTest.php`,
`DraftsInternalApiTest.php`, `MovesInternalApiTest.php`, extend `CompetitionsInternalApiTest` (new fields, `draft`
filter, pending excludes approved drafts), `SerializedByLockMessagesTest` (the two moves lock the source event).

**Acceptance**: README "Restructuring tools and old URLs" and "Internal API" hold; internal-api.md and the OpenAPI spec
list every endpoint with fields, answers and errors; the restructure of the motivating case can be done through the API
alone (a test walks it: create the organization from the series, create two series, move editions, move a round into a
new edition, assign).

## 3. File ownership (summary)

| File / area | F | A | B | C | D |
|---|---|---|---|---|---|
| Entities, migration, fixtures, `.claude/fixtures.md` | ✎ | | | | |
| Visibility constants, `GetCompetitionPermissions`, voters, `CompetitionPermissions` | ✎ | | | | |
| `GetEventOccurrences`, `GetEventSeriesDirectory`, `GetEventsViewerData`, `GetCompetitionSeries`, `GetCompetitionEvents`, `GetAdminQueueCounts`, `GetOrganization(s)`, `UnpublishBlockers` | ✎ | | | | |
| `EventRowFactory`, `SeriesPageBuilder`, `RowContext`, `RowTag(Type)`, `EventOccurrence`, `EventsViewerData` | ✎ | | | | |
| `EventsPageBuilder` | ✎ (series lines) | | | ✎ (`yourEvents`) | |
| `EventsIndexFactory`, `CompetitionUrlField`, `events/_series_directory.html.twig` | | ✎ | | | |
| All organization/draft/assign/add-editions messages + handlers, changed add/edit messages + handlers | ✎ | | | | |
| Restructuring messages + handlers, redirect subscriber + lookup | | | | | ✎ |
| Publish/unpublish, delete-organization, admin approve/reject controllers | ✎ | | | | |
| Organization page/directory/add/edit controllers + templates, `CompetitionApprovalsController` + template, JSON-LD partials | skeleton | ✎ | | | |
| Detail controllers (event, edition, series) + their 3 templates | wiring + hooks | | ✎ | | |
| `_draft_banner` · `_organized_by` · `_eligibility` + `_schedule` | stub | `_organized_by` | `_draft_banner` | `_eligibility`, `_schedule` | |
| Join, follow-name, legacy/round-results redirects, sitemaps, `GetWjpcEvents`, `GetTags`, `GetPuzzleSummary`, manage hints, registration mailer, API v1 round check | | | ✎ | | |
| "You organize", ⋯ menu (`_manage_items`, `EventManageMenuController`, `GetOrganizedEvents`, `OrganizedEvent`, `OrganizerBadge`) | minimal org support | | | ✎ | |
| `CompetitionFormType/Data`, `EditionFormType/Data`, add/edit event/series/edition controllers + templates, `add_editions` | data props | | | ✎ | |
| `OrganizationFormData` | ✎ | (form type) | | | (API validation) |
| Restructure controllers + templates, internal API controllers, `GetAdminCompetitions`, `GetAdmin*`, internal-api docs | skeleton (3 web) | | | | ✎ |
| `SerializedByLockMessagesTest` | ✎ | | | | ✎ |
| Coverage allowlist tests (`SuspiciousTime…`, `Blocklist…`, `PrivateProfile…`) | append | append | append | append | append |
| `DraftCanaryTest`, `DraftVisibilityCoverageTest` | | | ✎ | | |
| `messages.en.yml` blocks | `organization`, `drafts_core` (+3 keys in existing blocks) | `organization_page` | `drafts` | `organizer_tools` | `restructure` |
| `validators.en.yml` blocks | `organization_fields` | | | `add_editions` | |
| SCSS | stubs + `app.scss` | `_organization-page` | `_drafts` | `_events-organizer`, `_add-editions` | `_restructure` |

## 4. Integration and review

### 4.1 Order

1. Foundation → commit → the orchestrator pushes and opens the draft PR (Jan's rule: push early, CI runs the suite).
2. A, B, C, D in parallel from the foundation commit.
3. Merge into `feature/organizations` with `git merge --no-ff`, gates after each: **B** (guards and the hunt - the
   most shared readers), **C** (forms, "You organize", ⋯), **A** (organization pages), **D** (restructuring + API -
   builds on the others' routes). Expected conflicts: only the `messages.en.yml` / `validators.en.yml` EOF blocks and the
   shared append-only test allowlists - keep both sides. After each merge: `DraftVisibilityCoverageTest` and
   `DraftCanaryTest` (a merged workstream's new reader must be decided), the budget tests. After A is merged, the
   integrator adds A's surfaces to `DraftCanaryTest` (the organization page's Coming up / cards × EDITION_LANTERN_DRAFT
   and SERIES_QUIET_PINES_DRAFT moved under RIVERBEND by SQL in the test; the "Organized by" byline and crumb of
   SERIES_HARBOR_CLUB_MEETS × ORGANIZATION_HARBOR_CLUB_DRAFT; the events page search index × the same organization's
   name).
4. Translators (4.3), then review (4.2), then docs (4.4), then CI green on all 3 shards.

### 4.2 Review

**Code review agent** checks: every reader of competitions/series/organizations decided (the guard test), the policy
called by every create/move handler (`AddCompetition`, `AddCompetitionSeries`, `AssignEventToOrganization`,
`CreateOrganizationFromSeries`, `ApproveOrganization` cascade), no organization on an edition anywhere (forms, API,
`ConvertCompetitionToSeries`, moves), no 200 on full-page POSTs, CSRF on every new POST, voters on every new route,
`ResetInterface` where a service caches, JSON-LD through `json_ld`, no real names (`AGENTS.md` grep), lock keys of the
moves, no N+1 (budgets), the redirect subscriber only on 404s and never for `DraftNotVisible`.

**Browser review agent** (dev Selenium recipe; guest / PLAYER_REGULAR / PLAYER_WITH_STRIPE / PLAYER_WITH_FAVORITES /
admin; 360, 390 and 1280 px; en, de, ja for long labels):
1. Organization page as guest, follower, team (draft edition tagged), admin; HARBOR_CLUB_DRAFT as its creator (banner,
   Publish → public), as a guest (404).
2. Directory; the events page search "riverbend"; the series directory "by Riverbend Jigsaw Association" and the
   "Organizations" link; "Your events" of PLAYER_REGULAR.
3. Series, edition and event pages: byline (Organized by · Who can enter · When it happens), crumbs, the draft banner
   on EDITION_LANTERN_DRAFT and EDITION_QUIET_PINES_1 for the team.
4. "You organize" for PLAYER_WITH_STRIPE and PLAYER_WITH_FAVORITES; ⋯ on each kind; Publish and an Unpublish refusal
   (an event with participants) - the flash, no blank page.
5. Add event with an organization + Save as draft (PLAYER_WITH_FAVORITES); add series with When it happens; add
   edition with Who can enter; Add several dates: rule preview (last Friday of the month, 6 dates), uncheck one,
   create.
6. Add organization (with social links, one invalid → 422 keeps the input), edit it, the admin queue approves it.
7. Move edition (slug taken → slug field), move round (a refusal shown as a form error), turn a series into an
   organization; the old URLs answer 301 to the new pages.
8. Console clean, focus visible, 44 px targets, the banner announced (`role="status"`), no horizontal scroll at 360 px.

### 4.3 Translations

One translator agent per locale group after the merges: every key of the namespaces `organization`, `drafts_core`,
`organization_page`, `drafts`, `organizer_tools`, `restructure`, `events_page.tag.draft`, `events_page.tag.eligibility`,
`events_organizer.badge.draft`, and the validators blocks `organization_fields`, `add_editions` - into cs, de, es, fr,
ja, written to scratch files; natural wording, `%placeholders%` and `{{ value }}` kept, Czech plurals in three forms,
"Organization" = cs "Organizace", de "Organisation", es "Organización", fr "Organisation", ja "団体"; "Who can enter" /
"When it happens" as short labels. The orchestrator appends them after a parity check:
`run.sh <wt> <db> php bin/console debug:translation <locale> --only-missing --domain=messages | grep -E "organization|drafts|organizer_tools|restructure|events_page.tag|events_organizer.badge"`
prints nothing (same for `validators`).

### 4.4 Docs (integration step)

- `docs/features/events-page/README.md`: Follow (third target), "You organize" (organizations, Draft badge), the ⋯ menu
  (new items), Search (organization names), series directory ("by …"), the "Every kind of event" table → link to the
  organizations README.
- `docs/features/events-page/detail-pages.md`: the byline, the crumbs, the draft banner; "Not in this change: Format
  chips / an 'Organisation' level" → built (link).
- `docs/features/competitions-management/README.md`: lifecycle (drafts, approval under an organization), the
  visibility paragraph (drafts, `SQL_APPROVED`), "Linking solving times to events" (drafts never selectable).
- `docs/features/internal-api.md` + OpenAPI: D.
- `docs/TODO.md`: replace "Format chips / an 'Organisation' level…" by a new section "Organizations and drafts" with:
  co-hosts; notifications for followed organizations; a casual/competitive flag; season rankings (not wanted by the
  motivating organiser - only on demand); outreach to the organiser patterns of the README (patterns, no names); merging
  the duplicate series of other organisers; moving participants with a round; moving a one-time event into a series;
  page sections on organization pages.
- `CLAUDE.md`, the feature list gets:

  "- **Organizations + drafts**: `docs/features/organizations/README.md` (+ `implementation-plan.md`) — an optional level
  above series and one-time events (`organization`, team `organization_maintainer`; an edition never has its own -
  it is its series'). Page `organization_detail` (`/en/organizations/{slug}`, 6 locales: header, About, Coming up =
  `GetEventOccurrences::forOrganization()`, "What we run" cards, Past; **no public team list**; `Organization`
  JSON-LD), directory `organizations`, "Organized by" byline + crumb on series/edition/event pages, follow
  (`followed_competition.organization_id`; "Your events" = the next date of each of its series + its upcoming one-time
  events, deduplicated). Permissions = more legs of `GetCompetitionPermissions` (the team has the creator's rights on
  everything under it; `ORGANIZATION_EDIT` / `ORGANIZATION_DELETE`); approval like series, and **items created under or
  moved into an approved organization by its team are approved at once** (`OrganizationApprovalPolicy`, called by every
  create/move handler; approving an organization approves its pending items). **Drafts** (`is_draft` on events,
  editions, series, organizations): only whoever can edit them sees them (`DraftNotVisible` 404 otherwise, banner +
  Publish), never in a list, picker, API, sitemap, follow, join or registration. `IsCompetitionPubliclyVisible::SQL_CONDITION`
  includes drafts (`SQL_APPROVED` = the approval part only), `IsSeriesPubliclyVisible` / `IsOrganizationPubliclyVisible`
  for series/organization rows - every reader uses them or is listed in `DraftVisibilityCoverageTest`; `DraftCanaryTest`
  proves no public surface shows a draft (**add every new event-listing surface there**). Publishing a pending item
  submits it (admin e-mail then); Unpublish only without participants, official results or linked times. Extras: "Who
  can enter" (`eligibility`), "When it happens" (series `schedule`), "Add several dates" (`add_editions`, `AddEditions`
  ≤ 24, GET preview). Restructuring (messages + web pages + internal API): `MoveEditionToSeries`, `MoveRoundToCompetition`
  (puzzles, tables, linked times; refuses entries/teams and the one-round-per-category invariant; source event lock),
  `CreateOrganizationFromSeries`; old URLs 301 through `event_url_redirect` (`EventUrlRedirectSubscriber`, only on a 404,
  to the target's current URL; a live or draft page wins)"

  and the "Events page" entry gains "organization names in the search; drafts nowhere (admins included)".

### 4.5 Statement budgets (pinned)

| Page | Guest | Signed in | Test |
|---|---|---|---|
| Organization page (`riverbend-jigsaw-association`) | 4 | 10 (player and team) | `OrganizationPagesQueryBudgetTest` |
| Organizations directory | 2 | 6 | same |
| Events page | 3 | 8 (admin 9), archive 1 | `EventsPageQueryBudgetTest` - unchanged |
| Series / edition / event pages | unchanged | unchanged | `DetailPagesQueryBudgetTest` - unchanged |
| "You organize" | - | measured + pinned; + 1 for organizations at most | `OrganizedEventsQueryBudgetTest` |

A measurement above these is a bug to explain in the PR, not a new number.

## 5. Risks and open questions (with a proposed answer)

1. **Fixture churn** - new upcoming public items change counts in many existing tests. *Answer*: the foundation fixes
   them in the same commit and lists them; workstreams never add fixtures.
2. **P2 (approving an organization approves its pending items)** goes one step beyond D2. *Answer*: keep - only the
   team can put items under an organization; flag it in the PR.
3. **P17 (joining needs a public event)** also closes joining pending and rejected events, not only drafts. *Answer*:
   keep - the join page leaked participant names of non-public events; no UI ever offered joining them.
4. **`UnpublishCompetitionSeries` is not under one event lock** - a join racing it can leave a participant on a hidden
   edition. *Answer*: accept, documented in `NOT_LOCKED`; the organiser sees the participant and can publish again.
5. **The Japanese path `/ja/団体`**. *Answer*: keep (other Japanese routes are Japanese); the translator may propose
   another word before the release - a path change after launch would need redirects.
6. **Bootstrap Icons version** may lack `bi-bluesky` / `bi-threads`. *Answer*: the foundation checks the mounted CSS and
   falls back to the link icon; no dependency upgrade in this change.
7. **Draft editions on the series page for its team** can become the team's "Next" card. *Answer*: fine - it is tagged
   Draft in the list and the card shows the draft banner state on its own page; guests never see it.
8. **Event JSON-LD `organizer`** changes structured data of existing pages. *Answer*: only for items under a public
   organization (none on production at launch); `DetailPagesJsonLdTest` stays green.
