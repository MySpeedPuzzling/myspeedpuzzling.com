# High-frequency series - implementation plan

The build contract for [high-frequency-series.md](high-frequency-series.md) (the design of record). One foundation
agent builds the data model, the matching rule, the resolver and reconciler with every trigger, the handlers, the
conversion tool with its internal API endpoint, the test helper and the guard; then four agents work in parallel
worktrees - **WS-A** picker + form, **WS-B** API v1 + internal API answers, **WS-C** events pages, **WS-D** readers +
puzzle page "Used at" - each owning a fixed set of files. **Every class, route, message, key namespace and file name
below is binding**; a workstream that needs a change to a file it does not own writes the exact change into its final
report instead of editing the file.

Binding rules: `CLAUDE.md` of the worktree and the delivery's `AGENTS.md` (Messenger for every write, repositories never
flush, plain `readonly` repositories, single-action controllers, `ClockInterface`, `Uuid::uuid7()`, `ResetInterface`
for per-request caches, `json_ld` in scripts, no 200 to a full-page POST, `'exception' => $e`, translations in
`messages.en.yml` only). **The repository is public: made-up names only.** The fixtures contain an older series whose
constant names and texts must not be copied into new code, tests or docs (`CompetitionSeriesFixture`'s first series,
its editions and rounds) - new tests use `SeriesEditionScenario` (§1.9). Grep every diff with the `AGENTS.md` pattern
before each commit.

## 0. Shared contract

### 0.1 Ground rules

- Integration branch `feature/high-frequency-series`, worktree `.claude/worktrees/agent-a32bcc01933c3a760`. The
  foundation commits there (test DB `speedpuzzling_hfsf_test`). Workstreams branch from the foundation commit:
  `git worktree add .claude/worktrees/hfs-ws-<x> -b hfs-ws-<x> feature/high-frequency-series`, `<x>` = `a` … `d`, test
  DB `speedpuzzling_hfs<x>_test`. Run PHP only through `run.sh <worktree> <db> [dev|test] …`.
- Gates before every commit: cache warmup (dev) + phpstan, phpcbf + phpcs, test-env `doctrine:schema:validate` and
  `cache:warmup`, the targeted tests of what you touched. CI runs the full suite - never locally.
- **Only the foundation generates a migration** (`doctrine:migrations:diff` against a scratch DB built from the
  committed migrations) and changes entity mapping or entity methods. Ask the orchestrator if something is missing.
- **No fixture rows** (P1). Every test builds its series through `tests/SeriesEditionScenario.php` (§1.9) or messages.
- **Translations**: only `translations/messages.en.yml`, each workstream in its own top-level block appended at the
  end (§0.6). Never edit the other 5 locales.
- **Shared append-only test files** (add your own entries in alphabetical position, never reorder or edit others'):
  `tests/DraftVisibilityCoverageTest.php` (`NOT_FILTERED`), `tests/DraftCanaryTest.php` (`provideSurfaces()` - except
  the picker entries, owned by WS-A), `tests/SeriesPickQueryCoverageTest.php` (owner WS-D after the foundation; others
  add an entry for a new reader or delete their own `TODO_WS_<x>` entry), `tests/ClientErrorLogLevelTest.php`,
  `tests/Services/MessengerMiddleware/SerializedByLockMessagesTest.php`, the `SuspiciousTime…` / `Blocklist…` /
  `PrivateProfile…QueryCoverageTest`s. Merge conflicts there: keep both sides.
- CSS: the foundation pre-creates `assets/styles/_series-picker.scss` (WS-A) and `assets/styles/_series-page.scss`
  (WS-C) with their `@import` in `assets/styles/app.scss`; nobody edits `app.scss` afterwards. New classes: picker
  `sp-`, events family `ev-`. New Stimulus controllers start with `/* stimulusFetch: 'lazy' */`. Do not run encore.
- Statement budgets are measured with `QueryCountAssertions` and pinned with `assertSame` (the second request counts,
  as in `DetailPagesQueryBudgetTest`). A higher number is a bug to explain, never a new pin.

### 0.2 Names (binding)

| Name | Kind | Owner |
|---|---|---|
| `PuzzleSolvingTime::$competitionSeries`, `::$seriesEditionMatch`, `::seriesEditionResolved()` | entity | F |
| `Value\SeriesEditionMatchKind` (`Puzzle = 'puzzle'`, `Date = 'date'`) | enum | F |
| `Value\CompetitionPick` + `Value\CompetitionPickKind` (`Event`, `Series`, `Edition`) | value | F |
| `Query\SeriesEditionMatch` (SQL fragments, §0.4) | SQL | F |
| `Query\SeriesEditionDays` (per-series day aggregates, §0.4) | SQL | F |
| `Results\SeriesEditionResolution` | result | F |
| `Services\SeriesEditions\SeriesEditionResolver`, `SeriesEditionReconciler` | services | F |
| `Events\SeriesEditionsChanged`, `Events\DeduplicatedDomainEvent` | events | F |
| `MessageHandler\ReconcileSeriesEditionsOnSeriesEditionsChanged` | handler | F |
| `Exceptions\CompetitionAlreadyInSeries`, `Exceptions\CompetitionNotConvertible`, `Value\SeriesConversionBlocker` | 409s | F |
| `Controller\InternalApi\ConvertCompetitionToSeriesController` | internal API | F |
| `tests/SeriesEditionScenario.php`, `tests/SeriesPickQueryCoverageTest.php`, `tests/ConvertCompetitionForeignKeyCoverageTest.php` | tests | F |
| `Query\GetSeriesEditionChoices`, `Results\SeriesEditionChoice` | read model | A |
| `Controller\CompetitionPicker\CompetitionPickerEditionsController`, `…\SeriesEditionPreviewController` | controllers | A |
| `assets/controllers/series_edition_preview_controller.js` | Stimulus | A |
| `Api\V1\SeriesListResponse`, `SeriesListResponseProvider`, `SeriesListItemResponse`, `Query\GetApiSeriesList` | API | B |
| `assets/controllers/series_filter_controller.js` | Stimulus | C |
| `Results\PuzzleUsedAtLine`, `tests/Controller/PuzzleDetailQueryBudgetTest.php`, `tests/SeriesPickCanaryTest.php` | result + tests | D |

### 0.3 Data shapes

```php
enum SeriesEditionMatchKind: string { case Puzzle = 'puzzle'; case Date = 'date'; }

enum CompetitionPickKind { case Event; case Series; case Edition; }

final readonly class CompetitionPick
{
    public function __construct(public CompetitionPickKind $kind, public string $id) {}   // id lower-cased uuid
    public static function event(string $id): self;
    public static function series(string $id): self;
    public static function edition(string $id): self;
    // '<uuid>' → Event (P2: may be an edition - the acceptance check decides), 'series:<uuid>' → Series,
    // 'edition:<uuid>' → Edition; null/''/malformed → null
    public static function tryFrom(null|string $fieldValue): null|self;
    // edit prefill: series pick → Series, competition of a series → Edition, other competition → Event, none → null
    public static function ofTime(null|string $competitionId, null|string $seriesPickId, bool $competitionIsEdition): null|self;
    public function fieldValue(): string;           // '<uuid>' | 'series:<uuid>' | 'edition:<uuid>'
    public function competitionId(): null|string;   // Event and Edition
    public function seriesId(): null|string;        // Series only
    public function equals(self $other): bool;
}

final readonly class SeriesEditionResolution   // src/Results/
{
    public function __construct(public null|string $competitionId, public null|SeriesEditionMatchKind $kind) {}
    public static function notIdentified(): self;
    public function isIdentified(): bool;
}

interface DeduplicatedDomainEvent { public function deduplicationKey(): string; }

final readonly class SeriesEditionsChanged implements DeduplicatedDomainEvent { public function __construct(public UuidInterface $seriesId) {} }
// CompetitionRoundsChanged gains `implements DeduplicatedDomainEvent` (key = competition id)
```

Messages (all new parameters last, named, defaulted):

```php
AddPuzzleSolvingTime(…, null|string $seriesId = null)
EditPuzzleSolvingTime(…, null|string $seriesId = null)          // fromFormData(): CompetitionPick::tryFrom($formData->competition)
                                                                 //   Event/Edition → competitionId, Series → seriesId
ConvertCompetitionToSeries(string $competitionId, UuidInterface $seriesId, bool $keepAsEdition = true, bool $dropParticipants = false)
    implements SerializedByLock   // lockKey() = CompetitionParticipantsLock::key($competitionId)
```

Services:

```php
SeriesEditionResolver::resolve(PuzzleSolvingTime $time): SeriesEditionResolution
    // reads $time->competitionSeries, puzzle, puzzlingType, finishedAt ?? trackedAt; not identified when no series
SeriesEditionResolver::preview(string $seriesId, null|string $puzzleId, DateTimeImmutable $solveDay, PuzzlingType $category): SeriesEditionResolution
SeriesEditionReconciler::reconcile(null|UuidInterface $seriesId = null): array{linked: int, moved: int, released: int, roundsLinked: int, roundsUnlinked: int}
SeriesEditionReconciler::reconcileCompetition(UuidInterface $competitionId): void
    // CompetitionRoundsChanged: an edition → reconcile(its series); otherwise RoundResultsReconciler::reconcile($competitionId)
RoundResultsReconciler::reconcileSeries(UuidInterface $seriesId): array{linked: int, unlinked: int}
```

`linked` = series-level → edition, `moved` = edition or kind changed, `released` = edition → series-level.

### 0.4 SQL contracts

**`SeriesEditionMatch`** (`src/Query/SeriesEditionMatch.php`) - the **one** rule (H2), static methods returning SQL;
arguments are SQL expressions already cast (`CAST(:seriesId AS UUID)` or a column). Parameter `:seriesMatchNow`
(`SeriesEditionMatch::NOW_PARAMETER = 'seriesMatchNow'`, `Y-m-d H:i:s` from `ClockInterface`).

```php
public static function sqlCandidates(string $seriesScope): string   // two CTEs, to follow WITH; $seriesScope on alias c
public static function sqlAnswer(string $series, string $puzzle, string $category, string $day): string  // one row: competition_id, kind
public static function sqlLinkHolds(string $competition, string $kind, string $series, string $puzzle, string $category, string $day): string // boolean
```

```sql
-- sqlCandidates($seriesScope)
series_match_edition AS (
    SELECT c.id AS competition_id, c.series_id,
        LEAST(CAST(COALESCE(c.date_from, c.date_to) AS DATE), rd.first_day) AS span_from,      -- LEAST/GREATEST skip NULLs
        GREATEST(CAST(COALESCE(c.date_to, c.date_from) AS DATE), rd.last_day) AS span_to,
        rd.categories                                                                          -- NULL = no rounds
    FROM competition c
    INNER JOIN competition_series cs ON cs.id = c.series_id
    LEFT JOIN LATERAL (
        SELECT MIN(CAST((cr.starts_at AT TIME ZONE 'UTC') AT TIME ZONE COALESCE(cr.timezone, 'Europe/Prague') AS DATE)) AS first_day,
               MAX(CAST((cr.starts_at AT TIME ZONE 'UTC') AT TIME ZONE COALESCE(cr.timezone, 'Europe/Prague') AS DATE)) AS last_day,
               array_agg(DISTINCT cr.category) AS categories
        FROM competition_round cr
        WHERE cr.competition_id = c.id
    ) rd ON true
    WHERE {$seriesScope} AND {IsCompetitionPubliclyVisible::SQL_CONDITION}
),
series_match_round_puzzle AS (                                        -- revealed round puzzles only (P7)
    SELECT e.competition_id, e.series_id, cr.id AS round_id, cr.category, crp.puzzle_id
    FROM series_match_edition e
    INNER JOIN competition_round cr ON cr.competition_id = e.competition_id
    INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id
    INNER JOIN puzzle p ON p.id = crp.puzzle_id
    WHERE NOT {RoundPuzzleReveal::sqlHidden('crp', 'cr', ':seriesMatchNow')}
        AND (p.hide_until IS NULL OR p.hide_until <= CAST(:seriesMatchNow AS TIMESTAMP))
)
```

```sql
-- sqlAnswer($series, $puzzle, $category, $day)
SELECT COALESCE(by_puzzle.competition_id, by_date.competition_id) AS competition_id,
       CASE WHEN by_puzzle.competition_id IS NOT NULL THEN 'puzzle'
            WHEN by_date.competition_id IS NOT NULL THEN 'date' END AS kind
FROM (
    SELECT CASE WHEN COUNT(*) FILTER (WHERE d.distance = d.best) = 1
                THEN CAST(MIN(CAST(d.competition_id AS TEXT)) FILTER (WHERE d.distance = d.best) AS UUID) END AS competition_id,
           COUNT(*) AS candidates                                        -- a tie: candidates > 0, competition NULL (P5)
    FROM (
        SELECT rp.competition_id,
               CASE WHEN {$day} BETWEEN e.span_from AND e.span_to THEN 0
                    ELSE LEAST(ABS({$day} - e.span_from), ABS({$day} - e.span_to)) END AS distance,
               MIN(CASE WHEN {$day} BETWEEN e.span_from AND e.span_to THEN 0
                    ELSE LEAST(ABS({$day} - e.span_from), ABS({$day} - e.span_to)) END) OVER () AS best
        FROM series_match_round_puzzle rp
        INNER JOIN series_match_edition e ON e.competition_id = rp.competition_id
        WHERE rp.series_id = {$series} AND rp.puzzle_id = {$puzzle} AND rp.category = {$category}
    ) d
) by_puzzle
LEFT JOIN LATERAL (
    SELECT CASE WHEN COUNT(*) = 1 THEN CAST(MIN(CAST(e.competition_id AS TEXT)) AS UUID) END AS competition_id
    FROM series_match_edition e
    WHERE by_puzzle.candidates = 0
        AND e.series_id = {$series}
        AND {$day} BETWEEN e.span_from - 1 AND e.span_to + 1             -- NULL span never matches by date
        AND (e.categories IS NULL OR {$category} = ANY(e.categories))
) by_date ON true
```

```sql
-- sqlLinkHolds($competition, $kind, $series, $puzzle, $category, $day)
CASE {$kind}
    WHEN 'puzzle' THEN EXISTS (SELECT 1 FROM series_match_round_puzzle rp WHERE rp.competition_id = {$competition}
        AND rp.series_id = {$series} AND rp.puzzle_id = {$puzzle} AND rp.category = {$category})
    WHEN 'date' THEN EXISTS (SELECT 1 FROM series_match_edition e WHERE e.competition_id = {$competition}
        AND e.series_id = {$series} AND {$day} BETWEEN e.span_from - 1 AND e.span_to + 1
        AND (e.categories IS NULL OR {$category} = ANY(e.categories)))
    ELSE false
END
```

Uses: the resolver runs `WITH {sqlCandidates('c.series_id = CAST(:seriesId AS UUID)')} SELECT a.competition_id, a.kind
FROM ({sqlAnswer('CAST(:seriesId AS UUID)', 'CAST(:puzzleId AS UUID)', 'CAST(:category AS VARCHAR)', 'CAST(:day AS DATE)')}) a`
(one statement; `:puzzleId` NULL in a preview without a puzzle). The reconciler (§1.4) runs the same fragments over
`puzzle_solving_time`. Nothing else may restate the rule.

**`SeriesEditionDays`** (`src/Query/SeriesEditionDays.php`) - per-series aggregates for the picker (WS-A) and the API
series list (WS-B), the days the picker uses today (`COALESCE(date_from, date_to, first round start)`):

```php
public static function sqlJoin(string $alias = 'sed', string $todayParameter = ':today'): string
// LEFT JOIN LATERAL (SELECT COUNT(*) AS edition_count, bool_or(live) AS has_live, MAX(day) FILTER (WHERE past) AS last_past_day,
//   MIN(day) FILTER (WHERE upcoming) AS next_day FROM competition c WHERE c.series_id = cs.id AND {IsCompetitionPubliclyVisible::SQL_CONDITION} …) sed ON true
// - inner alias c, outer series alias cs (the visibility condition's cs is the outer row, the same series)
```

### 0.5 Routes and endpoints

| Route | Path | Owner |
|---|---|---|
| `competition_picker_editions` | `GET /{_locale}/competition-picker/editions` (`?q=`), JSON, `IS_AUTHENTICATED_REMEMBERED`, `Cache-Control: private, no-store` | A |
| `competition_picker_series_preview` | `GET /{_locale}/competition-picker/series-preview` (`?series=&edition=&puzzle=&date=&people=&q=&part=list`), HTML fragment, same guard and header | A |
| `puzzle_add` / `finish_stopwatch` / `edit_time` | unchanged; `puzzle_add` reads `?series=` too | A |
| API `GET /api/v1/series` | resource `SeriesListResponse` (`shortName: 'SeriesList'`, `uriTemplate: '/v1/series'`), `IS_AUTHENTICATED_FULLY` | B |
| API `POST /api/v1/me/solving-times`, `PUT …/{timeId}` | new fields `competition_id`, `series_id` | B |
| `POST /internal-api/competitions/{competitionId}/convert-to-series` | `requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT]` | F |

The helper routes follow `first_try_check` / `my_co_puzzlers` (one `/{_locale}/…` path, all 6 locales, P15).

### 0.6 Translation namespaces (appended at the end of `messages.en.yml`)

| Block | Owner | Holds |
|---|---|---|
| `series_picker:` | A | series card (`next`, `last`, `no_dates`, `online`), preview (`matched`, `not_identified`, `no_editions`, `change`, `let_us_match`, `search_placeholder`, `no_match`), the new hint under the picker |
| `series_pages:` | C | `more_sessions`, filter bar (`all`, `solo`, `pairs`, `teams`, `search_placeholder`, `jump_to`, `no_match`, `show_all`), month headings, `round_results.unofficial_label`, `add_my_time` |
| `series_readers:` | D | `used_at.more` ("and %count% more"), `series_delete_note` |

The foundation and WS-B add no keys (the API and the internal API speak English). Existing keys are reused where they
fit: `competition.round.category.{solo,duo,team}`, `forms.competition_not_selectable`, `forms.competition_live_badge`,
`events.results_link`.

## 1. Foundation (sequential, integration worktree, DB `speedpuzzling_hfsf_test`)

Read first: the design doc, `src/Entity/PuzzleSolvingTime.php`, `Competition.php`, `CompetitionSeries.php`,
`CompetitionRound.php`, `CompetitionRoundPuzzle.php`, `src/Services/DomainEventsSubscriber.php`,
`src/Services/RoundResults/*`, the add/edit/undo/keep-copy/delete/convert handlers, `RoundPuzzleReveal`,
`IsCompetitionPubliclyVisible`.

### 1.1 Entities

- **`PuzzleSolvingTime`**: constructor appends `#[ManyToOne] #[JoinColumn(nullable: true, onDelete: 'SET NULL')] public
  null|CompetitionSeries $competitionSeries = null` (named argument, after `createdVia`). Property
  `#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)] #[Column(type: Types::STRING, nullable: true, enumType: SeriesEditionMatchKind::class)]
  public null|SeriesEditionMatchKind $seriesEditionMatch = null`. Methods:
  - `seriesEditionResolved(null|Competition $edition, null|SeriesEditionMatchKind $match): void` - `LogicException`
    when `competitionSeries` is null, when `$edition?->series` is not it, when exactly one of the two is null; sets
    `competition` and `seriesEditionMatch`; no domain event.
  - `modify(…, null|CompetitionSeries $competitionSeries = null)` - sets `competitionSeries`, clears
    `seriesEditionMatch` (an explicit `competition` with a series is a `LogicException`).
  - `restore(…, null|CompetitionSeries $competitionSeries = null)` and `takeOverFrom()` (P10: only when
    `competition === null && competitionSeries === null`, take `competition`, `competitionSeries`, `seriesEditionMatch`
    together).
- **`Competition`** and **`CompetitionSeries`** `implements EntityWithEvents` (`use HasEvents`). `Competition` records
  `SeriesEditionsChanged($series->id)` - only for an edition - in: the constructor; `moveToSeries()` (old and new);
  `edit()` when `dateFrom` / `dateTo` change by day; `publish()` / `unpublish()` when the flag changes; `reject()`; new
  `recordRemoval(): void` (called by delete handlers before `remove()` - `DomainEventsSubscriber` collects on
  `postRemove`). `CompetitionSeries` records `SeriesEditionsChanged($this->id)` in `approve()`, `reject()`,
  `publish()`, `unpublish()` (when the state changes).
- **`CompetitionRound`**: records `CompetitionRoundsChanged` in the constructor, in `edit()` when the category, the
  start or the zone change (today: category only), and in new `recordRemoval(): void`.
- **`CompetitionRoundPuzzle`**: `changeReveal()` and `revealNow()` record `CompetitionRoundsChanged` (constructor and
  `recordRemoval()` already do).

### 1.2 Migration (generated - check the diff contains exactly this)

`ALTER TABLE puzzle_solving_time ADD competition_series_id UUID DEFAULT NULL, ADD series_edition_match VARCHAR(255)
DEFAULT NULL`, the FK `… REFERENCES competition_series (id) ON DELETE SET NULL NOT DEFERRABLE`, and the index Doctrine
names for it. Nothing else (diff against a scratch DB `hfsf_migcheck_test` built from the committed migrations; never
`--connection=default`). Metadata-only on PostgreSQL 16.

### 1.3 Values

`SeriesEditionMatchKind`, `CompetitionPick`, `CompetitionPickKind`, `SeriesConversionBlocker` (`Rounds`,
`OfficialResults`, `Referees`, `PageSections`, `MarketplaceMarks`, `Participants` - string values `rounds`,
`official_results`, `referees`, `page_sections`, `marketplace_marks`, `participants`). `RemovedResultSnapshot` gains
`competitionSeriesId` and `seriesEditionMatch` (keys `competition_series_id`, `series_edition_match`; missing in old
snapshots → null).

### 1.4 SQL, resolver, reconciler

- `SeriesEditionMatch` and `SeriesEditionDays` exactly as §0.4.
- `SeriesEditionResolver` (§0.3) - one statement per call; `resolve()` returns `notIdentified()` without a series.
- `SeriesEditionReconciler::reconcile()` - the bulk UPDATE (comment: "a genuine bulk operation - set-based like
  `RoundResultsReconciler`, only after flush"):

```sql
WITH {sqlCandidates($scope)},
pick AS (
    SELECT pst.id, pst.competition_series_id AS series_id, pst.puzzle_id, CAST(pst.puzzling_type AS VARCHAR) AS category,
           CAST(COALESCE(pst.finished_at, pst.tracked_at) AS DATE) AS solve_day,
           pst.competition_id AS current_id, pst.series_edition_match AS current_kind
    FROM puzzle_solving_time pst
    WHERE pst.competition_series_id IS NOT NULL {AND pst.competition_series_id = :seriesId}
),
decided AS (
    SELECT pick.id, pick.current_id,
        CASE
            WHEN pick.current_kind = 'puzzle' AND {holds} THEN pick.current_id
            WHEN pick.current_kind = 'date' AND {holds} AND a.kind IS DISTINCT FROM 'puzzle' THEN pick.current_id
            ELSE a.competition_id                                    -- series-level stays NULL when the rule finds nothing
        END AS new_id,
        CASE …same branches… THEN pick.current_kind … ELSE a.kind END AS new_kind
    FROM pick
    LEFT JOIN LATERAL ({sqlAnswer('pick.series_id', 'pick.puzzle_id', 'pick.category', 'pick.solve_day')}) a ON true
)
UPDATE puzzle_solving_time AS target
SET competition_id = decided.new_id,
    series_edition_match = decided.new_kind,
    competition_round_id = CASE WHEN target.competition_id IS DISTINCT FROM decided.new_id THEN NULL ELSE target.competition_round_id END
FROM decided
WHERE target.id = decided.id
    AND (target.competition_id IS DISTINCT FROM decided.new_id OR target.series_edition_match IS DISTINCT FROM decided.new_kind)
RETURNING decided.current_id, decided.new_id
```

  (`{holds}` = `sqlLinkHolds('pick.current_id', 'pick.current_kind', 'pick.series_id', 'pick.puzzle_id',
  'pick.category', 'pick.solve_day')`; `$scope` = `c.series_id = CAST(:seriesId AS UUID)` or `c.series_id IS NOT NULL`.)
  Then `RoundResultsReconciler::reconcileSeries($seriesId)` (scoped) or `reconcile()` (global). Counts from `RETURNING`.
- `RoundResultsReconciler::reconcileSeries()`: link scope `pst.competition_id IN (SELECT id FROM competition WHERE
  series_id = :seriesId)`; unlink scope that, `OR pst.competition_series_id = :seriesId`, `OR pst.competition_round_id
  IN (rounds of the series' editions)`.
- Measure once (report the numbers, not pinned): a scenario with 200 editions (one round + puzzle each) and 5,000
  series picks - `reconcile($seriesId)` and `reconcile()` wall time.

### 1.5 Events, triggers, routing

- `DomainEventsSubscriber::dispatchEvents()`: an event implementing `DeduplicatedDomainEvent` is dispatched once per
  `class + deduplicationKey()` per flush (P4).
- `config/packages/messenger.php`: `'SpeedPuzzling\Web\Events\SeriesEditionsChanged' => 'sync'` next to
  `CompetitionRoundsChanged`.
- `ReconcileSeriesEditionsOnSeriesEditionsChanged` → `SeriesEditionReconciler::reconcile($event->seriesId)`.
- `ReconcileRoundResultsOnCompetitionRoundsChanged` → `SeriesEditionReconciler::reconcileCompetition($competitionId)`.
- `ReconcileRoundResultsHandler` → `SeriesEditionReconciler::reconcile()`; returns all five counts;
  `ReconcileRoundResultsConsoleCommand` prints "Round results reconciled: %d linked, %d unlinked. Series picks: %d
  linked, %d moved, %d back to series level." `ReconcileRoundResultsOnPuzzleMergeApproved` → `reconcile()` (P28).

### 1.6 Messages and handlers

- **`AddPuzzleSolvingTimeHandler`**: precedence `roundId` → explicit (as today); else `competitionId` → explicit (as
  today); else `seriesId` → `CompetitionSeriesRepository::get()`; not found or `isPubliclyVisible() === false` → no
  link + `warning` "Solving time saved without series: …" with `'exception'` when one was thrown. Twin net
  (`GetRecentIdenticalSolvingTime::savedBy(…, seriesId:)`) before the first-try check, as today. Construct with
  `competitionSeries:`, then `seriesEditionResolved()` from `SeriesEditionResolver::resolve()` (edition entity via
  `CompetitionRepository::get()`), then the round resolver as today. Nothing series-related runs without a series.
- **`EditPuzzleSolvingTimeHandler`**: same precedence. A series pick needs a publicly visible series **or** the time's
  current series (its pick, or its linked edition's series) - include-current; otherwise no link + warning. `modify(…,
  competitionSeries:)` then resolve + `seriesEditionResolved()` (always fresh), then the round resolver.
- **`GetRecentIdenticalSolvingTime::savedBy()`** gains `null|string $seriesId = null`: with a series
  `pst.competition_series_id = :seriesId` (the edition is derived - not compared), without one
  `pst.competition_series_id IS NULL AND pst.competition_id IS NOT DISTINCT FROM :competitionId` (+ the round as today).
- **`UndoAutoRemovalHandler`**: snapshot series (if it still exists) → `restore(…, competitionSeries:)` without the
  snapshot's competition, then resolve (P9); an explicit snapshot as today.
- **`KeepDuplicateCopyHandler`**: unchanged code; the entity's `takeOverFrom()` follows P10.
- **`DeleteCompetitionHandler`**: the competition UPDATE also sets `series_edition_match = NULL`; `$competition->recordRemoval()`
  before `delete()`.
- **`DeleteCompetitionSeriesHandler`**: first `UPDATE puzzle_solving_time SET competition_series_id = NULL,
  series_edition_match = NULL WHERE competition_series_id = :id` (comment: the FK would leave the match kind behind).
- **`DeleteCompetitionRoundHandler`**: `$round->recordRemoval()` before `delete()`.
- **`PuzzleSolvingTimeRepository::findByCompetitionRound()`**: adds `time.competitionSeries IS NULL` (P29; comment).
- **`ConvertCompetitionToSeriesHandler`**: `CompetitionAlreadyInSeries` (409) instead of the `LogicException`.
  `keepAsEdition: true` - today's code unchanged. `keepAsEdition: false`:
  1. blockers in one statement (rounds, referees, page sections, `sell_swap_list_item_event` rows, participants not
     deleted) → `CompetitionNotConvertible(list<SeriesConversionBlocker>)` unless only `Participants` and
     `dropParticipants`;
  2. the series exactly as today (fields, maintainers, follows moved), persist, the existing flush;
  3. one commented bulk `UPDATE puzzle_solving_time SET competition_series_id = :series, competition_id = NULL,
     competition_round_id = NULL, series_edition_match = NULL WHERE competition_id = :competition` (P12);
  4. with `dropParticipants`: delete the participants and their participant-sheet receipts (SQL, as `DeleteCompetitionHandler`);
  5. repoint every `event_url_redirect` row targeting the competition to the series
     (`EventUrlRedirectRepository::findPointingAtCompetition()` + `pointTo()`), then
     `EventUrlRedirects::remember(EventUrlPath::event($oldSlug), $series)`;
  6. `CompetitionRepository::delete($competition)`. No event (P12).
- **`config/packages/framework.php`**: `CompetitionAlreadyInSeries`, `CompetitionNotConvertible` at `info`;
  `ClientErrorLogLevelTest` lists them.

### 1.7 Read model touches for the workstreams

- `GetPlayerSolvedPuzzles::byTimeId()` + `SolvedPuzzleDetail` gain `seriesPickId` (`pst.competition_series_id`) and
  `competitionIsEdition` (`competition.series_id IS NOT NULL`) - WS-A's prefill. The rest of the file is WS-D's.

### 1.8 Internal API

`ConvertCompetitionToSeriesController` (`POST /internal-api/competitions/{competitionId}/convert-to-series`):
`CompetitionRepository::get()` (404), `InternalApiInput::fromRequest($request, ['keepAsEdition', 'dropParticipants'])`
→ `bool()` (default `true` / `false`), dispatch with `Uuid::uuid7()` as the series id, `201` with
`GetAdminSeries::detail($seriesId)->toArray()`, `CREATED_ID_ATTRIBUTE` = the series id. No reviewer player. Docs: a
"Converting an event into a series" part in `docs/features/internal-api.md` (Organizations, series and drafts) and the
path in `internal-api.openapi.yaml` (201 `SeriesDetail`, 400, 404, 409 `Conflict`).

### 1.9 Test helper `tests/SeriesEditionScenario.php`

`readonly final class SeriesEditionScenario` (container-built like `FirstTryScenario`), everything through messages
except where a message does not exist (then entities via the entity manager, flushed, never raw SQL writes to
`puzzle_solving_time`). Made-up names only ("Lantern Weekly Jam", "Jam No. 153", "Copper Lighthouse"):

```php
public function series(string $name = 'Lantern Weekly Jam', bool $online = true, bool $public = true, bool $draft = false): string
public function edition(string $seriesId, string $name, null|string $day /* Y-m-d */, bool $draft = false): string
public function round(string $editionId, RoundCategory $category, string $startsAtLocal /* Y-m-d H:i */, string $timezone = 'Europe/Berlin', array $puzzleIds = [], bool $secret = false): string
public function puzzle(string $name = 'Copper Lighthouse', int $pieces = 500): string        // approved, no photo (AddApprovedPuzzle)
public function addTime(string $userId, string $puzzleId, string $day, null|string $seriesId = null, null|string $competitionId = null, array $groupPlayers = [], string $time = '01:05:00'): string
public function reconcile(): void
public function link(string $timeId): array{competition_id: ?string, competition_series_id: ?string, series_edition_match: ?string, competition_round_id: ?string}
```

### 1.10 Guard `tests/SeriesPickQueryCoverageTest.php`

Scans `src/` (comments stripped, like `DraftVisibilityCoverageTest`): a file containing `puzzle_solving_time` and the
word `competition_id` must contain `competition_series_id` or be listed in `NOT_SERIES_AWARE` with a reason constant;
stale entries fail. Reason constants: `ROUND_LEVEL`, `PER_COMPETITION` ("an automatic link counts for its edition; a
series-level time belongs to no edition"), `PARTICIPANTS`, `NOT_A_TIMES_EVENT`, `RELEASES_ON_DELETE`, `TODO_WS_B`,
`TODO_WS_D`. Initial list (the foundation builds it from the test's own output - every hit must be decided):

| File | Reason |
|---|---|
| `src/MessageHandler/DeleteCompetitionHandler.php` | `RELEASES_ON_DELETE` |
| `src/Query/CountCompetitionResults.php` | `PER_COMPETITION` |
| `src/Query/GetAdminCompetitions.php` | `TODO_WS_B` |
| `src/Query/GetCompetitionParticipants.php` | `PARTICIPANTS` |
| `src/Query/GetCompetitionPuzzles.php` | `PER_COMPETITION` |
| `src/Query/GetCompetitionSlugsForSitemap.php` | `ROUND_LEVEL` |
| `src/Query/GetDuplicateCandidates.php` | `TODO_WS_D` |
| `src/Query/GetFastestGroups.php`, `GetFastestPairs.php`, `GetFastestPlayers.php` | `TODO_WS_D` |
| `src/Query/GetNotifications.php` | `NOT_A_TIMES_EVENT` |
| `src/Query/GetParticipantsSheetState.php` | `ROUND_LEVEL` |
| `src/Query/GetPlayerDuplicateCases.php` | `TODO_WS_D` |
| `src/Query/GetPlayersDirectory.php`, `GetSuggestedPlayers.php` | `PARTICIPANTS` |
| `src/Query/GetPublishedRoundResults.php` | `ROUND_LEVEL` |
| `src/Query/GetPuzzleResultDetail.php`, `GetPuzzleSolvers.php`, `GetRecentActivity.php`, `GetSuspiciousTimeCaseDetail.php` | `TODO_WS_D` |
| `src/Query/OccurrenceRounds.php` | `ROUND_LEVEL` |
| `src/Query/SearchPuzzle.php`, `GetStoredFileReferences.php` (if hit) | `NOT_A_TIMES_EVENT` |
| `src/Services/Drafts/UnpublishBlockers.php` | `TODO_WS_D` |
| `src/Services/ParticipantImport/SiteSnapshotReader.php` | `ROUND_LEVEL` |

`GetPlayerSolvedPuzzles.php` passes once §1.7 names the column - WS-D's checklist still covers its other methods (the
guard is per file; `SeriesPickCanaryTest` is per surface). At integration no `TODO_WS_*` entry may remain.

### 1.11 Foundation tests

- `tests/Value/CompetitionPickTest.php` (every kind, bare uuid, malformed, `ofTime()`, `fieldValue()` round trip).
- `tests/Entity/PuzzleSolvingTimeSeriesPickTest.php` (`seriesEditionResolved()` guards, `modify()` clears the kind,
  `takeOverFrom()` P10, `restore()` with a series).
- `tests/Services/SeriesEditions/SeriesEditionResolverTest.php` - H12 2, 3, 4 (hidden puzzle ignored by rule 1, date
  fallback; image-only hidden too), 5, 6, 7, 8, 9 (draft, pending, rejected edition; edition of a draft series), plus:
  rule-1 tie → not identified; nearest of two puzzle candidates; undated never by date; category acceptance; ±1 day;
  a 23:30 Toronto round dated by its local day; an edition dated by `date_to` only (P6); an edition without rounds
  matched by date for every category (H13).
- `tests/Services/SeriesEditions/SeriesEditionReconcilerTest.php` - the stickiness table row by row; H12 4 (attach,
  reveal now, reveal by time + global reconcile), 6 (first edition), 8 (later attach), 9 (publish, series approval),
  10 (dates change, move, delete; explicit untouched); round link cleared and relinked; counts.
- `tests/MessageHandler/SeriesPickTriggersTest.php` - each row of the design doc's trigger table ends reconciled
  (`AddEdition`, `AddEditions` of 3 → one reconcile, `MoveEditionToSeries`, `EditCompetition` dates,
  `Publish`/`Unpublish` edition and series, `RejectCompetitionSeries`, `DeleteCompetition` (automatic → series-level,
  explicit loses the event), `DeleteCompetitionSeries` (picks dropped, match kind nulled), round create / start edit /
  delete, round puzzle attach / remove / reveal now, puzzle merge, `ReconcileRoundResults` counts).
- `tests/MessageHandler/AddPuzzleSolvingTimeSeriesPickTest.php` (precedence, not-public series → no link + warning,
  matched + round, series-level, H12 1 explicit unchanged, twin net with a series) and
  `EditPuzzleSolvingTimeSeriesPickTest.php` (H12 12: re-resolve on puzzle/date/group change, explicit stays,
  include-current non-public series, explicit ↔ series switch).
- `tests/Services/DomainEventsSubscriberDeduplicationTest.php`.
- Extend `RoundResultsReconcilerTest` (`reconcileSeries()`), `tests/MessageHandler/AutoRemovalTest.php` (the snapshot
  carries the series pick; Undo re-resolves it - P9), the keep-a-copy cases of `DuplicateCaseActionsTest` /
  `DuplicateSetActionsTest` (P10), `ConvertCompetitionToSeriesHandlerTest` (H12 11: `keepAsEdition` true
  unchanged; false: times series-level, competition gone, follows moved, redirects repointed + old path → series
  (`GET /en/events/<slug>` 301 to `competition_series_detail`), every refusal, `dropParticipants`),
  `MoveRoundToCompetitionHandlerTest` (P29), `SerializedByLockMessagesTest` (the lock key), `ClientErrorLogLevelTest`.
- `tests/Controller/InternalApi/ConvertCompetitionToSeriesInternalApiTest.php` (201 answer, 404, 409 reasons, 400
  unknown field, audit `createdId`).
- `tests/ConvertCompetitionForeignKeyCoverageTest.php` (P13: every FK to `competition` read from the schema, each with
  its handling: moved / refused / deleted / repointed / cascades).
- `EventsPageQueryBudgetTest`, `DetailPagesQueryBudgetTest`, `DraftCanaryTest`, `DraftVisibilityCoverageTest` green
  unchanged.

Foundation done = gates green, existing tests green without count changes, one commit on
`feature/high-frequency-series` ("High-frequency series: foundation - series picks, the matching rule, reconcile
triggers, conversion tool").

### Foundation deviations (filled by the foundation - binding for A-D)

Everything of §0 and §1 exists under the names and signatures above, except what this list says.

**Data model and entities**

- Migration `migrations/Version20261009090901.php` (generated against the scratch DB `speedpuzzling_hfsfmig_test`, then
  dropped): exactly `series_edition_match VARCHAR(255)`, `competition_series_id UUID`, `FK_FE83A93CF9987DFE … ON DELETE
  SET NULL NOT DEFERRABLE`, `IDX_FE83A93CF9987DFE` - as separate statements. The two `ADD` are metadata-only; the FK
  validation and the (non-concurrent) index build scan `puzzle_solving_time` once (seconds on production).
- `PuzzleSolvingTime::$competitionSeries` is `#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]` - read it, never write it.
  The **constructor**, `modify()` and `restore()` throw `LogicException` when an explicit `competition` and a
  `competitionSeries` are given together: a series pick is constructed series-level, then `seriesEditionResolved()`.
  `restore()` gained `null|CompetitionSeries $competitionSeries = null` as its last (named) parameter.
  `competitionRoundMovedTo()` throws `LogicException` for a series pick (P29 - only explicit times move with a round).
- What records an event (stricter than "when the flag changes", exact for the candidates): `Competition::publish()`,
  `unpublish()`, `reject()` record `SeriesEditionsChanged` only when the **edition's** public visibility
  (`isPubliclyVisible()`) actually changes; `CompetitionSeries::approve()`, `reject()`, `publish()`, `unpublish()` when
  `isPubliclyVisible()` changes; `Competition::edit()` when the **day** of `dateFrom` or `dateTo` changes;
  `CompetitionRound::edit()` when the category, the start instant or the zone string changes;
  `CompetitionRoundPuzzle::changeReveal()` / `revealNow()` always. A one-time event records nothing. The
  `CompetitionRound` constructor now records `CompetitionRoundsChanged` - every new round runs its competition's
  reconcile (2 UPDATEs for a one-time event, the series reconcile for an edition).
- `SolvedPuzzleDetail` gained `seriesPickId` (`null|string`) and `competitionIsEdition` (`bool`) as defaulted last
  constructor parameters (`fromDatabaseRow()` reads optional keys `series_pick_id`, `competition_is_edition`).

**Values**

- `CompetitionPick`: `kind` and `id` are public readonly but not constructor-promoted - the constructor lower-cases the
  id and throws `InvalidArgumentException` for a non-uuid. `tryFrom()` trims the value. Same public API otherwise.
- `RemovedResultSnapshot` gained `competitionSeriesId` and `seriesEditionMatch` as defaulted last constructor
  parameters.

**SQL and services**

- `SeriesEditionMatch` also has `DATE_FORMAT = 'Y-m-d H:i:s'` (the format of `:seriesMatchNow`). Small differences to
  §0.4: `categories` = `array_agg(DISTINCT CAST(cr.category AS VARCHAR))`; `series_match_round_puzzle.category` is cast
  to VARCHAR; rule 1 counts `COUNT(DISTINCT d.competition_id)` at the best distance (a competition with the puzzle in
  two rounds of the category is one candidate) and compares `IS NOT DISTINCT FROM`.
- `SeriesEditionReconciler::reconcile()`: an `evaluated` CTE computes `holds` once, `decided` applies the stickiness;
  `RETURNING` casts the ids to VARCHAR. `reconcileCompetition()` reads `competition.series_id` with one SELECT. Both
  are idempotent; the global `reconcile()` runs `RoundResultsReconciler::reconcile()` (every competition) after the
  picks, a scoped one `reconcileSeries()`.
- `SeriesEditionResolver::preview()`: a non-uuid series answers not identified without a statement; a non-uuid puzzle
  counts as none (only the date can match).
- `SeriesEditionDays::sqlJoin()`: `has_live` is `COALESCE`d to `false`; `edition_count` counts undated editions too;
  `last_past_day` = the first day of the latest edition whose last day is before today, `next_day` = the first day of
  the soonest edition starting after today (an edition live today is in neither); `{$todayParameter}` is used as
  `CAST(… AS DATE)` (pass `'Y-m-d'`).
- `RoundResultsReconciler`: both public methods share a private `run()`; `reconcile()` behaves as before.
- `GetRecentIdenticalSolvingTime::savedBy(…, null|string $seriesId = null)`: one static SQL (both `:seriesId` and
  `:competitionId` always bound).
- `EventUrlRedirectRepository::findPointingAtCompetition(Competition $competition): list<EventUrlRedirect>` is new.

**Handlers**

- Add/edit warnings when a series is unknown or not public (edit: and not the current one): message `Solving time saved
  without series: the submitted series does not exist or is not publicly visible`, context `timeId`, `seriesId`,
  `userId` (+ `puzzleId` on add), `exception` only when `CompetitionSeriesNotFound` was thrown.
- `EditPuzzleSolvingTime::fromFormData()` parses the field with `CompetitionPick::tryFrom()`: a malformed value saves
  without a link (before: the raw value went to the handler and was logged as an unknown competition).
- **Not changed by the foundation, owned by the workstreams** - until they land, a series value is not understood
  there: `PuzzleAddController` still passes `$formData->competition` raw as `competitionId` (WS-A maps it with
  `CompetitionPick`); `UpdateSolvingTimeProcessor` keeps a link by passing the stored competition id - for a series pick
  it must pass `seriesId: $time->competitionSeries?->id` (WS-B), or the pick becomes an explicit edition.
- `ConvertCompetitionToSeriesHandler`: the blockers are checked before anything is created (a refusal changes nothing,
  no series). Removed participants (`deleted_at` set) never block; with `keepAsEdition: false` every participant row of
  the event is deleted (active ones only reachable with `dropParticipants`) together with their
  `competition_participant_round` rows and the event's `participant_sheet_change_receipt` rows. Blocker order = the
  enum's case order; `CompetitionNotConvertible::$blockers` is public; its message lists the values and, when only
  participants block, names `"dropParticipants": true`.
- Internal API `…/convert-to-series`: `keepAsEdition` / `dropParticipants` must be booleans (400 otherwise); an empty
  body is the defaults.

**Assets and tests**

- `assets/styles/_series-page.scss` **already existed** (the series page's sections) and is imported already - nothing
  was created; WS-C extends that file. Only `_series-picker.scss` is new, imported right after `copuzzler-picker`.
- `SeriesEditionScenario` extras: `ADMIN_PLAYER_ID`; `roundPuzzleId(string $roundId, string $puzzleId): string`;
  `dispatch(object $message): void` (dispatches and clears the entity manager - every helper clears it, so a handler
  never works on an entity a reconcile changed in SQL; clear it yourself after your own SQL); `link()` is
  `@phpstan-impure`. Series get a unique slug `hfs-<12 hex>` (offline: "Harbor Town", `cz`), editions `edition-<12
  hex>`; `round(secret: true)` makes its puzzles a **manual** reveal through the entity (no message makes a past round's
  puzzle secret - reveal them with `RevealRoundPuzzleNow` + `roundPuzzleId()`); `puzzle()` uses the brand "Lantern
  Puzzle Works"; `addTime()` finishes at midnight of `$day`. Two `addTime()` calls with equal data within 10 s are one
  save (the twin net) - vary the player or the time.
- `SeriesPickQueryCoverageTest`: `GetPlayersDirectory.php` and `GetStoredFileReferences.php` are no hits (not listed).
  `TODO_WS_B`: `GetAdminCompetitions.php`. `TODO_WS_D`: `GetDuplicateCandidates.php`, `GetFastestGroups.php`,
  `GetFastestPairs.php`, `GetFastestPlayers.php`, `GetPlayerDuplicateCases.php`, `GetPuzzleResultDetail.php`,
  `GetPuzzleSolvers.php`, `GetRecentActivity.php`, `GetSuspiciousTimeCaseDetail.php`, `Services/Drafts/UnpublishBlockers.php`.
- Appended to the shared lists: `DraftVisibilityCoverageTest` (`RoundResultsReconciler`, `SeriesEditionReconciler` -
  WRITE), `SuspiciousTimeQueryCoverageTest` (`ConvertCompetitionToSeriesHandler`, `SeriesEditionReconciler` - WRITE),
  `SerializedByLockMessagesTest` (`NOT_LOCKED`: `SeriesEditionsChanged`; the conversion's lock key asserted),
  `ClientErrorLogLevelTest` (both 409s).
- Extra foundation tests: `tests/Query/SeriesEditionDaysTest.php`, `tests/Services/DomainEventsSubscriberDeduplicationTest.php`.

**Reconcile measurement** (§1.4, local Docker PostgreSQL 16, one series with 200 dated editions of one solo round and
one puzzle each, 5,000 series picks - 4 of 5 on an edition's puzzle 0-2 days after it, 1 of 5 on another puzzle - plus
the fixtures): the first `reconcile($seriesId)` of 5,000 series-level picks **553 ms** (4,667 linked, 4,000 rounds
linked); every further `reconcile($seriesId)` with nothing to change **~122 ms**; the global `reconcile()` (every series
and every round) **~121 ms**; after one edition's dates moved **~122 ms**. Below the ~1 s of risk 1 - no scoping needed
now.

## 2. Workstreams (parallel, after the foundation commit)

Nothing outside a workstream's "Owns" list is edited (shared append-only files excepted, §0.1). Every foundation file
is read-only.

### A. Picker and form (`hfs-ws-a`, DB `speedpuzzling_hfsa_test`) - H5

**Owns**: `src/Query/GetSelectableCompetitions.php`, `src/Results/SelectableCompetition.php`,
`src/Services/CompetitionChoicesBuilder.php`, `src/Value/CompetitionChoices.php`, new `src/Query/GetSeriesEditionChoices.php`,
`src/Results/SeriesEditionChoice.php`, `src/FormType/PuzzleAddFormType.php`, `EditPuzzleSolvingTimeFormType.php`,
`src/FormData/PuzzleAddFormData.php`, `EditPuzzleSolvingTimeFormData.php` (if needed), `src/Controller/PuzzleAddController.php`,
`EditTimeController.php`, new `src/Controller/CompetitionPicker/*`, `templates/_solving_time_form.html.twig` (the
competition section), new `templates/competition_picker/_series_preview.html.twig` + `_edition_list.html.twig`,
`assets/controllers/competition_picker_controller.js`, new `series_edition_preview_controller.js`,
`assets/styles/_series-picker.scss`, the picker entries of `DraftCanaryTest`, block `series_picker:`.

**Builds**:
1. **`GetSelectableCompetitions::all(null|CompetitionPick $alwaysInclude = null)`** - one statement, `UNION ALL` of:
   publicly visible one-time events (today's branch, `kind = 'event'`); publicly visible series (`kind = 'series'`,
   `SeriesEditionDays::sqlJoin()` for live / last past / next / edition count, organization name + short name of a
   publicly visible organization for keywords); the include-current row (a one-time event, a series or an edition -
   `kind = 'edition'`, carrying its series for the optgroup). Order per design doc ("The default list"); the current
   edition sorts right after its series. `SelectableCompetition` gains `kind`, `nextDay`, `lastDay`, `editionCount`,
   `organizationName`, `organizationShortName`.
2. **`CompetitionChoicesBuilder::build(null|CompetitionPick $current)`**: options `value` = `fieldValue()`; series card
   (logo, name, Online/place, `series_picker.next` / `last` / `no_dates`, live badge; escaped); edition card = today's
   edition card ("series · edition · date") under optgroup = series id; `CompetitionChoices::accepts(CompetitionPick)`
   for events and series in memory; editions through `GetSeriesEditionChoices::isSelectableEdition(string $id)` (one
   statement, only for an edition value) or equality with the current pick. A bare uuid that is not an offered event
   but a selectable edition is accepted (P2).
3. **`GetSeriesEditionChoices`**: `search(string $query, int $limit = 20): list<SeriesEditionChoice>` (S1 - every
   folded word in edition name / series name / series shortcut via `immutable_unaccent(…) ILIKE`, publicly visible,
   nearest to today first, undated last); `closest(string $seriesId, DateTimeImmutable $day, null|string $query, int
   $limit = 10)` (the short list - day distance to the span, undated newest first; search over edition names + revealed
   round puzzle names, `RoundPuzzleReveal::sqlHidden()` + `hide_until`); `isSelectableEdition(string $id): bool`. Each
   choice: id, name, series id/name/shortcut/logo, day span, categories, revealed puzzle names.
4. **Forms**: field option `current_competition_pick` (replaces `current_competition_id`); `POST_SUBMIT`:
   `CompetitionPick::tryFrom()` + `accepts()` → else `forms.competition_not_selectable`. Remove the rendered
   `forms.competition_hint_series` (P31); a new hint `series_picker.hint`.
5. **Controllers**: `PuzzleAddController` maps the pick (`competitionId: $pick?->competitionId(), seriesId:
   $pick?->seriesId()`); deep links `?competition=<event|edition>` (edition → `edition:`), `?series=<id>`
   (`IsSeriesPubliclyVisible::check()`); `OfficialEntryTimePrefill` gets `$pick->competitionId()`. `EditTimeController`
   prefills `CompetitionPick::ofTime($solved->competitionId, $solved->seriesPickId, $solved->competitionIsEdition)` and
   passes it as `current_competition_pick`.
6. **`CompetitionPickerEditionsController`** → `{"options": [{value, text, keywords, optgroup}], "optgroups": [{value,
   label, logo?}]}`, `q` shorter than 2 → empty lists.
7. **`SeriesEditionPreviewController`**: series must be publicly visible (else the fragment with the neutral line);
   `date` parsed `d.m.Y` (empty = today) + `MistypedYearNormalizer`; `people` → `PuzzlingType::fromPuzzlersCount()`;
   `puzzle` only when a uuid (used only by rule 1, never echoed - no visibility statement); `SeriesEditionResolver::preview()`
   + `GetSeriesEditionChoices::closest()`; renders matched / not identified / no editions / explicit edition (`edition=`),
   `part=list` → only the list. Each list item carries its TomSelect option as JSON in a data attribute.
8. **JS**: `competition_picker_controller.js` - in `autocomplete:pre-connect` add `load` (fetch
   `competition_picker_editions`, `callback(json.options, json.optgroups)`), `shouldLoad: q => q.trim().length >= 2`,
   `loadThrottle: 300`; remove fetched edition options (except the selected one) when the search is cleared or the
   dropdown closes. `series_edition_preview_controller.js` (lazy, on the competition card): listens to the TomSelect
   change and to `change` events of the form (puzzle, date, `group_players[]`), debounced 250 ms, aborts stale
   fetches; fills the preview; list buttons `addOption()` + `setValue('edition:…')`; "Let MySpeedPuzzling match it" →
   `setValue('series:…')`; the list search refetches `part=list`. Texts from the fragment / data attributes.

**Tests**: rewrite `tests/Query/GetSelectableCompetitionsTest.php` (no edition in the default list; series row with
next/last/none; order incl. a live series and a series without dated editions; include-current event / series /
non-public edition; drafts never) and `tests/Services/CompetitionChoicesBuilderTest.php` (cards escaped, keywords with
the organization, `accepts()` matrix incl. P2); new `tests/Query/GetSeriesEditionChoicesTest.php` (S1 words, order,
drafts out; closest order; revealed puzzle names only - a secret round puzzle's name neither listed nor searchable);
`tests/Controller/CompetitionPicker/CompetitionPickerEditionsControllerTest.php` (auth, < 2 chars, JSON shape, header,
budget overhead + 1) and `SeriesEditionPreviewControllerTest.php` (H12 2 matched line; 5 + 7 + 8 open list, nothing
preselected; 6 neutral line; 4 no leak - the hidden puzzle's name absent, no puzzle match before the reveal; category
from `people`; explicit edition line; budget overhead + ≤ 3); extend `PuzzleAddControllerTest` (H12 1 one-time
unchanged; `series:` → series pick saved and matched; series without editions → series-level (H13); `edition:` and a
bare edition uuid → explicit; edition without rounds explicit (H13); malformed / draft → 422 generic error; `?series=`,
`?competition=<edition>`; official entry still pre-fills; **the add page's statement count equals the foundation
commit's** - pinned in new `tests/Controller/PuzzleAddPageQueryBudgetTest.php`), `EditTimeControllerTest` (H12 12
prefill kinds, include-current non-public series and edition, re-resolve after a puzzle change), the stopwatch finish
route once; `DraftCanaryTest` picker entries rewritten: the series option (draft series), S1 endpoint (draft edition),
preview list (draft edition), `?series=` pre-selection (draft series).

**Acceptance**: design doc "The form" holds; at 360 px the card, preview line and list fit (44 px targets); without a
series value nothing is fetched.

### B. API v1 and internal API answers (`hfs-ws-b`, DB `speedpuzzling_hfsb_test`) - H6, H7 (API parts)

**Owns**: `src/Api/V1/CreateSolvingTimeInput.php`, `CreateSolvingTimeProcessor.php`, `UpdateSolvingTimeInput.php`,
`UpdateSolvingTimeProcessor.php`, `SolvingTimeResponse.php`, new `SeriesListResponse.php`, `SeriesListResponseProvider.php`,
`SeriesListItemResponse.php`, new `src/Query/GetApiSeriesList.php`, `src/Query/GetAdminCompetitions.php`,
`src/Query/GetAdminSeries.php`, `src/Results/AdminCompetition.php`, `AdminSeries.php` (+ detail results if needed),
`docs/features/api/README.md`, `docs/features/internal-api.md` + `internal-api.openapi.yaml` (after the foundation's
part), tests below.

**Builds**:
1. Inputs gain `?string $competitionId = null`, `?string $seriesId = null` (snake_case by the name converter).
   Create processor, before dispatch: `round_id` as today; `competition_id` → `CompetitionRepository::get()` +
   `IsCompetitionPubliclyVisible::check()` (404 `CompetitionNotFound`); `series_id` → `CompetitionSeriesRepository::get()`
   + `isPubliclyVisible()` (404 `CompetitionSeriesNotFound`); combinations disagreeing → `ValidationException` (422,
   `propertyPath` `competition_id` / `series_id`, like `PuzzleSearchResponseProvider::violation()`). Dispatch
   `competitionId` only when no round, `seriesId` only when neither.
2. Update processor: both null → pass the current link (`competitionId` for an explicit link,
   `seriesId: $time->competitionSeries?->id` for a series pick); given → same checks, the current competition / series
   accepted even when not public.
3. `SolvingTimeResponse` gains `?string $competitionId`, `?string $seriesId` (after `roundId`); create, update and the
   replay build them from the stored entity after the bus returns (`competition?->id`, `competitionSeries?->id ??
   competition?->series?->id`, `competitionRound?->id`) - P18.
4. `GET /api/v1/series`: `GetApiSeriesList::all()` - one statement, publicly visible series + `SeriesEditionDays` +
   publicly visible organization's name, ordered by name; items `id, name, shortcut, slug, logo, isOnline, location,
   countryCode, link, organizationName, editionsCount, nextDate, lastDate`.
5. OpenAPI: the POST/PUT `OpenApiOperation` descriptions explain the three fields, precedence, 404/422; the series list
   operation tagged with the competition endpoints.
6. Internal API: `GetAdminCompetitions::COMPETITION_COLUMNS` + `seriesPickResultsCount` (`competition_id = c.id AND
   competition_series_id IS NOT NULL`); `GetAdminSeries` + `resultsCount` (`pst.competition_id IN (editions) OR
   pst.competition_series_id = cs.id`, each time once) and `resultsWithoutEditionCount` (`competition_series_id = cs.id
   AND competition_id IS NULL`). Docs and spec updated; delete the two `TODO_WS_B` guard entries.

**Tests**: extend `CreateSolvingTimeEndpointTest` (H12 13: `series_id` → matched `competition_id` + `round_id`;
series-level; `competition_id` one-time and edition; every precedence pair agreeing and disagreeing (422 with the
property path); unknown / malformed / draft / pending → 404; replay carries the new fields), `UpdateSolvingTimeEndpointTest`
(omitted keeps an explicit link and a series pick; `competition_id` / `series_id` change it; include-current non-public;
`round_id` read from the stored row), new `tests/Controller/Api/V1/SeriesListEndpointTest.php` (public only, drafts
and pending out, fields, budget), OpenAPI assertions (`OpenApiAssertions`: the new properties exist),
`SeriesInternalApiTest` and `CompetitionsInternalApiTest` (the new counts); a `DraftCanaryTest` entry for the API series
list.

**Acceptance**: `docs/features/api/README.md` documents the fields, precedence, errors, the series list and an example;
no BC break (every existing API test green unchanged).

### C. Events pages (`hfs-ws-c`, DB `speedpuzzling_hfsc_test`) - H8 except the puzzle page

**Owns**: `src/Services/EventsPage/EventsPageBuilder.php`, `EventsIndexFactory.php`, `src/Results/EventsPage/*`
(`AgendaRow`, `SessionChip`, …), `templates/event_parts/_row.html.twig`, `_archive_line.html.twig`,
`_series_json_ld.html.twig`, `_event_header_facts.html.twig`, `assets/events_index.js`, `assets/events_calendar.js`,
`assets/controllers/events_page_controller.js`, `src/Query/GetEventOccurrences.php`, `src/Query/OccurrenceRounds.php`,
`src/Value/OccurrenceRound.php`, `src/Results/EventOccurrence.php`, `src/Services/EventDetail/SeriesPageBuilder.php`,
`src/Results/EventDetail/*`, `src/Controller/CompetitionSeriesDetailController.php`, `templates/competition_series_detail.html.twig`,
`templates/series/*`, `assets/controllers/series_archive_controller.js`, new `series_filter_controller.js`,
`assets/styles/_series-page.scss`, `src/Services/RoundResults/RoundResultsPageBuilder.php`, `src/Results/RoundResultsPage.php`,
`templates/round_results.html.twig` + `templates/round_results/*`, block `series_pages:`.

**Builds**:
1. **Roll-up cap**: `EventsPageBuilder::MAX_SESSION_CHIPS = 6`; `AgendaRow` gains `moreSessionsCount`; `_row.html.twig`
   renders 6 chips + "+N more" (`series_pages.more_sessions`) linking the series page; `indexIds` keep every session.
2. **Rounds JSON**: `OccurrenceRounds::SQL_JOIN_WITH_RESULTS` adds per round `category` and `puzzles` (names of
   revealed round puzzles: `NOT RoundPuzzleReveal::sqlHidden('crp', 'cr_j', ':now')` and `hide_until` passed);
   `GetEventOccurrences::fetch()` passes `now`; `OccurrenceRound` gains `category`, `puzzleNames`. One statement still.
3. **Index** (P25): compact edition entries, puzzle names in the edition's search text, the browser matcher looks the
   series fields up through `sid` (one helper in `events_index.js`, used by search, calendar and archive); a session of
   a multi-session edition keeps `u` (with its `#round-`). New `tests/Services/EventsPage/EventsIndexWeightTest.php`
   builds 200 editions of one series in memory (no DB), asserts **≤ 40,000 B raw added** and records raw + gzip in its
   failure message; numbers go into the design doc's As built.
4. **Archive**: verify one line per series and year with 200 editions (server `archiveYears()` and the client
   `archiveLines()`).
5. **Series page**: `SeriesPage` past years carry month sections (`<details>`, newest month of the newest year open);
   rows/lines carry `data-categories`, `data-search` (`SearchText::fold()` of edition name + session label + revealed
   puzzle names), `data-month`; `series/_filters.html.twig` (hidden until JS) + `series_filter_controller.js` (chips of
   the categories present, search with `foldSearchText()`, month select that scrolls to and opens the month, "No date
   matches - show all"); lines show category pills + puzzle names; `subEvent` ≤ 50 (P26); "Add my time"
   (`puzzle_add?series=`, P27) when signed in, the series is publicly visible and an edition has started.
6. **Edition page**: the facts line shows a one-round edition's start time in the event's zone (`event_time()`, P30);
   verify category pill, Registration ↗, Official results ↗.
7. **Round results**: `series_pages.round_results.unofficial_label` + "Official results ↗" next to it whenever the
   times list shows (no published official results); `officialResultsLink` gets `utm_source` for the competition's
   link too.

**Tests**: extend `EventsPageBuilderTest` (cap, "+N more" URL, ids kept), `EventsIndexFactoryTest` (compact entries;
puzzle names; a hidden round puzzle's name absent - H12 4), `EventsIndexScriptTest` (search through `sid`, URL rebuild,
labels equal in 6 locales), `EventsIndexWeightTest`, archive roll-up with 200 editions, `SeriesPageBuilderTest`
(months, categories, search data, `subEvent` cap), `CompetitionSeriesDetailControllerTest` (a 200-edition series renders
filters and folded months; **zero editions** "No editions yet." (H13); "Add my time" rules), `DetailPagesQueryBudgetTest`
unchanged + 200 editions add no statement, `EventsPageQueryBudgetTest` unchanged, `DetailPagesJsonLdTest` (cap),
`RoundResultsControllerTest` (label + link: round link, else the edition's; `OfficialRoundResultsPageTest` unchanged),
the edition facts time, an **organization
without series or events** keeps "Nothing planned yet." (H13, extend `OrganizationPageTest`), the events page series
directory lists a series without editions with "No dates yet" (H13).

**Acceptance**: design doc "Events pages" holds; the series page at 360 px (filter bar wraps, 44 px chips); the
index weight recorded.

### D. Readers and puzzle page "Used at" (`hfs-ws-d`, DB `speedpuzzling_hfsd_test`) - H7, H8 puzzle page

**Owns**: the files of the checklist below, `templates/_competition_badge.html.twig`, `_leaderboard_time_badges.html.twig`,
`templates/review_results/_set.html.twig`, `templates/admin/suspicious_times/_case_card.html.twig`,
`templates/_competition_series_delete_modal.html.twig`, `src/Query/GetPuzzleSummary.php`, `src/Results/PuzzleSummary.php`,
new `src/Results/PuzzleUsedAtLine.php`, `templates/puzzle/_summary.html.twig`, `templates/puzzle_detail.html.twig` (the
Details block), `src/Controller/PuzzleDetailController.php` (if needed), `tests/SeriesPickQueryCoverageTest.php`, block
`series_readers:`.

**Checklist** (from the inventory; every line → a change or a stated "no change"):

| File · method | Today | Change |
|---|---|---|
| `GetPlayerSolvedPuzzles` solo/duo/team/`soloByPlayerIdAndPuzzleId` | badge join `cs ON cs.id = competition.series_id` | `cs ON cs.id = COALESCE(competition.series_id, pst.competition_series_id)` |
| `GetPuzzleSolvers` solo/duo/team | same | same change |
| `GetFastestPlayers` / `Pairs` / `Groups` `perPiecesCount` | same | same change |
| `GetRecentActivity` `forPlayer` / `latest` / `ofPlayerFavorites` | same | same change |
| `GetPuzzleResultDetail::byTimeId` | same | same change |
| `GetPlayerDuplicateCases::copies` | `competition.name` | `COALESCE(competition.name, series name)` via the COALESCE join |
| `GetSuspiciousTimeCaseDetail::cards` | `comp.name`, round name | the series' name for a series-level time |
| `GetDuplicateCandidates::timeColumns` + `DuplicateCandidateTime` + `DuplicateCandidate::differences()` | competition + round | event identity (P23) - `competition_series_id` selected |
| `UnpublishBlockers` `forSeries` | editions only | `OR pst.competition_series_id = :id`; `sqlColumns`/`forCompetition` unchanged (P20) |
| `GetOrganizedEvents` series rows | sum of editions' blockers | + the series' picks |
| `GetExportableSolvingTimes` + `ExportableSolvingTime` + `PuzzlerDataExporter` | no event | P22 columns at the end |
| `_competition_badge` / `_leaderboard_time_badges` | `competitionName` only | series-level: series label → `competition_series_detail`; wrapper condition adds `competitionSeriesName` |
| `_competition_series_delete_modal` | - | `series_readers.series_delete_note` (P21) |
| `CountCompetitionResults`, `GetCompetitionPuzzles::solvedPuzzleOverviews`, `OccurrenceRounds`, `GetCompetitionSlugsForSitemap`, round-level readers | per competition / round | no change (reasons in the guard) |
| `FirstTryAssessor`, `GetFirstTryTimes`, `Services/SuspiciousTimes/*` | no competition | no change - one test each with a series-level time |
| API v1/v0 result lists (`PlayerResultResponse`, V0 results) | no event fields | no change |

DTOs keep their fields (`competitionSeries*` now also filled for series-level times). Burn down every `TODO_WS_D` entry.

**"Used at"** (P24): `GetPuzzleSummary`'s `used_at` JSON becomes round lines (round id, category, start + zone,
competition name/slug, series name/slug) of publicly visible competitions with the round puzzle revealed
(`NOT RoundPuzzleReveal::sqlHidden()`, `hide_until`), newest first, **at most 10** (`LIMIT 10` + a total count in the
same statement), then tag lines not covered by a round line (today's union); `PuzzleSummary::$usedAt` →
`list<PuzzleUsedAtLine>` + `$usedAtMore`; the guest summary renders lines; the header Details collapse renders them
for signed-in viewers (toggle shown when there are lines); links `edition_detail` / `event_detail` + `#round-<id>`.
Measure the puzzle page **on the foundation commit first** and pin it in new `PuzzleDetailQueryBudgetTest` (guest,
signed in) - equal after.

**Tests**: new `tests/SeriesPickCanaryTest.php` (a seeded series-level time and an automatic time: profile results,
puzzle leaderboard, ladder, recent activity, result modal, duplicate review, suspicious case card show the right badge /
name and link); extend `GetDuplicateCandidatesTest` / `DuplicateClassifierTest` (P23 matrix), new
`tests/Services/Drafts/UnpublishBlockersSeriesPickTest.php`,
`PuzzlerDataExporterTest` (columns appended, values for explicit / automatic / series-level), `GetPuzzleSummaryTest`
(lines, cap + more, tag lines, a secret round left out (H12 4), drafts out, one-time), `PuzzleDetailControllerTest`
(guest summary, signed-in Details), `PuzzleDetailQueryBudgetTest`, `FirstTryAssessorTest` + a suspicious-scan test
with a series-level time (H12 14), `GetOrganizedEventsTest` (series blockers).

**Acceptance**: no `TODO_WS_D` left; the canary green; budgets equal.

## 3. File ownership (summary)

| File / area | F | A | B | C | D |
|---|---|---|---|---|---|
| Entities, migration, `.claude/fixtures.md` (unchanged), `SeriesEditionScenario` | ✎ | | | | |
| `SeriesEditionMatch`, `SeriesEditionDays`, resolver, reconciler, `RoundResultsReconciler`, events, `DomainEventsSubscriber`, `messenger.php` | ✎ | | | | |
| Add/edit/undo/delete/convert handlers, messages, `GetRecentIdenticalSolvingTime`, `RemovedResultSnapshot`, `PuzzleSolvingTimeRepository` | ✎ | | | | |
| `CompetitionPick`, `SolvedPuzzleDetail` + `GetPlayerSolvedPuzzles::byTimeId` | ✎ | | | | |
| `GetPlayerSolvedPuzzles` (other methods) | | | | | ✎ |
| Internal API convert controller; `internal-api.md` + openapi | ✎ (convert) | | ✎ (answers, after F) | | |
| Picker read models, form types/data, add/edit controllers, picker endpoints, `_solving_time_form`, picker JS | | ✎ | | | |
| `src/Api/V1/*SolvingTime*`, series list, `GetAdminCompetitions`, `GetAdminSeries`, API README | | | ✎ | | |
| Events page builder/index/JS, `GetEventOccurrences`, `OccurrenceRounds`, series page, edition facts, round results page | | | | ✎ | |
| Badge readers, duplicates, blockers, exports, badge templates, delete modal, `GetPuzzleSummary`, puzzle page Details/summary | | | | | ✎ |
| `SeriesPickQueryCoverageTest` | create | append | own entries | append | ✎ |
| `DraftCanaryTest` | | picker entries | append | append | append |
| `DraftVisibilityCoverageTest`, `ClientErrorLogLevelTest`, `SerializedByLockMessagesTest`, other coverage tests | append | append | append | append | append |
| SCSS | stubs + `app.scss` | `_series-picker` | | `_series-page` | |
| `messages.en.yml` | - | `series_picker:` | - | `series_pages:` | `series_readers:` |

## 4. Budgets and risks

### 4.1 Statement budgets (pinned)

| Page / endpoint | Before | After | Test |
|---|---|---|---|
| Add time GET (signed in) | measured on F | equal | `PuzzleAddPageQueryBudgetTest` (A) |
| Add time POST | - | + ≤ 3 only for a series pick (series, match, edition); + 1 for an `edition:` value | A (asserted in the form tests) |
| `competition_picker_editions` | - | overhead + 1 | A |
| `competition_picker_series_preview` | - | overhead + ≤ 3 | A |
| Events page / archive | pinned today | unchanged | `EventsPageQueryBudgetTest` |
| Series page | guest 3, signed in 9 (no editions 2 / 8) | unchanged; 200 editions add none | `DetailPagesQueryBudgetTest` |
| Edition / event page | guest 12, signed in 19 | unchanged | `DetailPagesQueryBudgetTest` |
| Puzzle page | not pinned | measured on F, equal | `PuzzleDetailQueryBudgetTest` (D) |
| API POST/PUT with `series_id` | - | + match + edition + response series | B |
| `GET /api/v1/series` | - | 1 + authentication | B |

### 4.2 Risks (with the proposed answer)

1. **Reconcile cost on a very large series** - one UPDATE over ~5,000 picks × ~200 candidates per trigger, several
   triggers during the production backfill. *Answer*: measured by the foundation (§1.4); dedup per flush (P4); if a
   series' reconcile exceeds ~1 s, scope it to picks whose solve day is within the changed edition's span ±7 days
   (TODO, not now).
2. **Events in entities that never had them** (`Competition`, `CompetitionSeries`): every edition change now runs a sync
   reconcile inside its transaction - a reconcile failure fails the organiser's save. *Answer*: the reconcile is plain
   SQL, covered by tests; same model as `CompetitionRoundsChanged` today.
3. **Index trimming changes the browser code** of the events page. *Answer*: one lookup helper, `EventsIndexScriptTest`
   parity, the browser review checks search, calendar, archive and country views.
4. **TomSelect remote options mixed with local ones** - fetched editions could stay in the default list. *Answer*: the
   pre-connect hook removes them on clear/close (A tests it in the browser review).
5. **Blue-green**: the old container keeps writing times without the new columns and offering edition ids - accepted
   by P2; an old container ignores series picks (a series-level time shows no badge there for a few minutes).
6. **The conversion is destructive**. *Answer*: snapshot first, the FK coverage test, refusals, the internal API only.
7. **Round link after a date match on a still-secret round puzzle** (P8) - equal to an explicit pick today.
8. **"Used at" for signed-in players** adds a line list to the Details collapse. *Answer*: collapsed by default;
   checked at 375 px.

## 5. Integration

### 5.1 Order

1. Foundation → commit → the orchestrator pushes and opens the draft PR.
2. A, B, C, D in parallel from the foundation commit.
3. Merge into `feature/high-frequency-series` (`git merge --no-ff`), gates after each: **B** (smallest, API), **A**
   (form), **C** (events pages), **D** (readers - last, so its guard sees everybody's new readers). Expected conflicts:
   the `messages.en.yml` EOF blocks and the append-only test lists - keep both sides. After each merge:
   `SeriesPickQueryCoverageTest`, `DraftVisibilityCoverageTest`, `DraftCanaryTest`, the budget tests, the AGENTS.md
   real-name grep on the whole diff.
4. Translators, review, docs, CI green on all 3 shards.

### 5.2 Orchestrator checks

- No `TODO_WS_` in `tests/SeriesPickQueryCoverageTest.php`; no edition option baked into `/en/puzzle-add` (HTML of a
  series with editions contains the series option only); the index weight recorded; budgets equal.
- **Code review**: every series-pick write keeps the invariants; nothing restates the rule outside `SeriesEditionMatch`;
  no hidden puzzle name or match leaks (preview, short list, S1, index, series page, "Used at"); drafts nowhere; no 200
  to a full-page POST; `ResetInterface` where a service caches; CSRF not needed (GET endpoints change nothing).
- **Browser review** (dev Selenium; guest / `PLAYER_REGULAR` / admin; 360, 390, 1280 px; en, de, ja): add time with a
  series - matched line, S2 list, change → edition → back to automatic, S1 typing "No. 15"; stopwatch finish; edit a
  series pick and an explicit edition; events page roll-up "+N more", search by a puzzle name, calendar; series page
  with 200 editions (seeded locally by SQL into a scratch DB, never the dev DB) - filters, month jump, folded months;
  a round results page label; a puzzle page "Used at" as guest and signed in.

### 5.3 Translations

One translator agent per locale group for the blocks `series_picker`, `series_pages`, `series_readers` into cs, de,
es, fr, ja (natural wording, `%placeholders%` kept, Czech plural forms, "series" = cs "série", de "Serie", es "serie",
fr "série", ja "シリーズ"; never the word "jam" in a generic text). The same step deletes `forms.competition_hint_series`
from all 6 locales (P31). Parity: `run.sh <wt> <db> php bin/console debug:translation <locale> --only-missing
--domain=messages | grep -E "series_picker|series_pages|series_readers"` prints nothing.

### 5.4 Docs (integration step)

- Link the design doc from `docs/features/events-page/README.md`, `detail-pages.md`,
  `docs/features/competitions-management/README.md` ("Linking solving times to events" - the picker paragraph is
  rewritten: one-time events and series, editions by typing, `CompetitionPick`, preview, deep links),
  `round-results.md` (rounds of automatic links; the unofficial label), `docs/features/internal-api.md`,
  `docs/features/api/README.md` (B already), `docs/features/duplicate-results.md` (P23) and the design doc's
  "As built".
- `docs/TODO.md`: section "High-frequency series" with the design doc's "Later" items.
- `CLAUDE.md` feature list, one line:

  "- **High-frequency series**: `docs/features/events-page/high-frequency-series.md` (+ `-plan.md`) — a series is
  **one entry** in the add-time picker and MySpeedPuzzling finds the edition: `puzzle_solving_time.competition_series_id`
  = a *series pick* (explicit links keep it NULL - every old row), `series_edition_match` = `puzzle`/`date`, series-level
  (no edition) is a normal permanent state. **One rule** `SeriesEditionMatch` (revealed round puzzle of the category →
  nearest edition; else exactly one edition within ±1 day accepting the category; else series-level), resolved in the
  add/edit handlers (`SeriesEditionResolver`) and reconciled set-based (`SeriesEditionReconciler`, sticky: only
  automatic/series-level rows, a date link yields to a puzzle match) on `SeriesEditionsChanged` /
  `CompetitionRoundsChanged` (recorded by the entities, deduplicated per flush) and in the 15-min
  `reconcile-round-results` cron. Field values `<uuid>` / `series:<uuid>` / `edition:<uuid>` (`CompetitionPick`);
  editions only by typing (`competition_picker_editions`) or the preview's short list
  (`competition_picker_series_preview`, never preselected). API v1 `competition_id` / `series_id` (+ `GET
  /api/v1/series`), responses from the stored row. Readers join `cs ON cs.id = COALESCE(c.series_id,
  pst.competition_series_id)` - `SeriesPickQueryCoverageTest` + `SeriesPickCanaryTest`. Events pages: ≤ 6 roll-up
  chips, compact index entries, series page filters/months, puzzle page "Used at" lines. `ConvertCompetitionToSeries`
  `keepAsEdition: false` (internal API `…/convert-to-series`) turns an umbrella event into the series"
