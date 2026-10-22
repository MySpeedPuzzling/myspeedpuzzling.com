# Time and the clock - status

Living record. Every phase appends: what it did, what it found, what the next phase must know.

| Phase | State | Commit(s) |
|---|---|---|
| 0 - verify decisions | done 2026-10-22, answered | this commit |
| 1 - pin UTC, UTC on write | done 2026-10-22 | this commit |
| 2 - one clock in `src/` | done 2026-10-22 | c00bf8343, e16908c18, 3a15ef3b0 + this commit |
| 3 - frozen clock in tests | done 2026-10-22 - **branch red until Phase 4 ends** (157 tests) | fe580bfa0 |
| 4 - tests on the frozen clock | batches 1-2 done 2026-10-22 (157 → 103 → 92 failing); batches 3-9 open | batch 1: f2483f7d3; batch 2: this commit |
| 5 - proof, docs, PR | not started | |

## Jan's answers (2026-10-22) - binding for every later phase

1. **Cookies / real clock:** no second clock service. A cookie's expiry (and anything else the browser or another
   process judges by its own clock: `msp-rendered-at` + its readers, rate-limiter retry-after, health checks) is real
   time by nature, so those few places read real time on purpose - each listed with that reason in
   `SystemClockCoverageTest`. No special test session. (Jan asked why tests need cookies at all: signing in is a session
   cookie - KernelBrowser carries it between requests, Panther is a real browser.)
2. **API v1 `finishedAt` with an offset:** convert to UTC keeping the same instant (`2026-10-20T00:30:00+02:00` →
   `2026-10-19 22:30:00`). The UTC-on-write `datetime_immutable` type does exactly that; no API-specific day logic.
   `date_immutable` columns are NOT converted (Phase 0: a day would move).
3. **Proof runs:** left to us - libfaketime at real date +400 days and +5 years, once in Phase 5; no "-30 days" run,
   no weekly CI job (the guard tests catch new violations).
4. **DB session zone:** no application code for it - no DBAL middleware, no `PGTZ`. What matters is that the app stores
   UTC and never reads the database's clock. Stated assumption (README): the PostgreSQL server's default zone is UTC
   (dev, CI, prod verified 2026-10-22); SQL that casts between `timestamp` and `timestamptz` relies on it.
   PHP's zone is already pinned by the base image's ini (`date.timezone=UTC`) - a test may assert it, no code change.
5. **(2026-10-22, after Phase 1 was paused on it) Keep both:** the UTC-on-write `datetime_immutable` type as the
   production safety net for any future path (Doctrine cookbook pattern) **and** the call-site fix in the two API v1
   processors. A test-only Doctrine listener asserting that every persisted value is UTC was rejected.

## Open questions for Jan (answered above)

1. **A second, real clock for what the browser (or another process) judges by its own time.** The experiments show a
   frozen clock breaks cookies: a cookie whose expiry comes from the frozen clock is already expired in the browser
   (BrowserKit's cookie jar and Chrome both use the real time). With the instant 8 days old one KernelBrowser test
   fails (the Live Component reload-guard cookie, 10 s lifetime); with the instant a year old every signed-in Panther
   test fails (the session cookie re-sent by `SlidingLoginCookiesSubscriber` with "frozen + 30 days" is dropped -
   logged out on the next page). Proposal: a `clock.real` service (`NativeClock`, never frozen) used only for (a) cookie
   expiries, (b) page-render timestamps the browser sends back (`msp-rendered-at` and its readers
   `AssetLoadFailureClassifier` / `StaleDocumentController`), (c) rate-limiter retry-after (the limiter itself runs on
   `microtime()`), (d) health checks - each listed with its reason in `SystemClockCoverageTest`. In production both
   clocks are the same clock. The alternative (keep the frozen instant always < 30 days old by bumping it monthly) only
   moves the time bomb. OK?
2. **API v1 `finishedAt` with an offset** (`POST/PUT /api/v1/me/solving-times`) is the one path found that stores a
   non-UTC wall time in a naive column: `new DateTimeImmutable("2026-10-20T00:30:00+02:00")` is persisted as
   `2026-10-20 00:30:00`. Production has **0** rows with `created_via = 'api'`, so no data is affected. A UTC-converting
   DBAL type would store `2026-10-19 22:30:00` - the previous day, while `finished_at` means "the day it was solved"
   (the forms store midnight). Proposal: the API reads `finishedAt` as a calendar day (its `Y-m-d` as written,
   midnight UTC) - a behaviour change for API clients that send a time of day (none so far). OK, or keep the time?
3. **D5 shifts:** drop the "-30 days" run (a real date *before* the frozen instant is a situation CI never meets, and
   it breaks by design where Symfony mints with `time()` and the app checks with the clock - the sign-in code's
   `expiresAt`). Run +400 days and +5 years instead? Weekly CI job under a shifted date: yes/no (already your call in
   the plan).
4. **DB session zone pin (D3):** DBAL driver middleware running `SET TIME ZONE 'UTC'` on connect (code-owned; one
   statement per physical connection - in worker mode per worker and per reconnect after the 300 s idle TTL, never per
   request) - or the `PGTZ=UTC` environment variable (zero statements, libpq sends it in the startup packet - verified
   with pdo_pgsql - but it has to be set in three places outside the code: app Dockerfile/base image, compose, CI).
   I recommend the middleware unless you prefer zero statements.

## Phase 0 findings (2026-10-22)

Everything below is measured on branch `time-and-clock` at `dd4c402a4`. Baseline (real clock, own DB
`speedpuzzling_clock_test`): **11505 tests green** (5 skipped), 5 min with ParaTest.

### D1 - frozen instant: confirmed `2026-10-14 12:00:00 UTC` (with one new constraint)

New constraint found: **the instant must lie before the real date of every run.** Symfony mints several things with
`time()` that the app then checks with its clock (sign-in link expiry → `VerifySignInCodeHandler`), and cookies minted
from the clock are judged by the browser's real time. Both are fine while the instant is in the past and break when the
real date is before it. A far-past instant is fine too once Q1's real clock handles cookies. Hence: never move
`FROZEN_NOW` into the future; a later bump is a deliberate change (the DB cache hash includes it - Phase 3).

Date constants in `src/` that switch behaviour, against 2026-10-14:

| Constant | Value | At the instant |
|---|---|---|
| `DigestPeriod::FIRST_PERIOD_START` | 2026-10-12 | active (first period 12-18 Oct, nothing due before 19 Oct - digest tests set their own clock) |
| `MspRatingTimeline::FORMULA_CHANGES` | 2026-03-31, **2026-10-18** | the 18 Oct change is "not yet" at the instant (production is past it). No test failed on it; rating-history tests pin their own clocks (2026-06-21). Moving the instant after it means ≥ 19 Oct, i.e. within 6 days of the 25 Oct DST switch - not worth it |
| `MissYouRun::FIRST_DAY` | **2026-10-27** | runs not allowed yet - same as the real today; tests pin their own clock |
| `MspRatingTimeline::NEXT_SEASON_STARTS_ON` | 2027-01-01 | announced (future either way) |
| `GetPuzzleIdsForSitemap::PAGE_LAST_REBUILT_AT` | 2026-10-08 | past |
| guide `PUBLISHED` dates | 2026-07-11, 2026-09-30 | past |
| `MyActivityController::EARLIEST` / `'2000-01-01'` floors | 2020-01-01 / 2000-01-01 | past |
| `AddEditionsFormType` `2026-01-05` | a Monday used for weekday names | not a switch |
| `ModeratorGuide::VERSION` | '2026-10' | not a date compare |

No karma/feature switch is a date (`KARMA_INFINITE_PLAYER_IDS` is ids; XP's switch is the `xp_settings` row).
Fixture constants: `XpSettingsFixture::FULL_FORMULA_FROM` 2026-08-01, `LAUNCH_READY_AT` 2026-08-02,
`XpLaunchRevealSeenFixture::SEEN_AT` 2026-01-01, `DismissedHintFixture` 2025-01-15, `OAuth2ClientRequestFixture`
2025-01-01, `PuzzleFixture` `hideImageUntil` 2099-12-31 - all consistent with the instant. **45 of 53 fixtures already
take their dates from the injected clock** (`$this->clock->now()->modify('-3 days')`); none reads the system clock
directly - freezing the `clock` service freezes the fixtures as they are.

### D2 - what gets frozen: confirmed (replace the `clock` service), changed for cookies (Q1)

- The `clock` service is `Symfony\Component\Clock\Clock` without an inner clock, which delegates to the static
  `Clock::get()` (a `NativeClock`). `Psr\Clock\ClockInterface` and `Symfony\Component\Clock\ClockInterface` alias it.
  **537 services receive `clock`**; outside `SpeedPuzzling\…` only `argument_resolver.datetime`,
  `console.argument_resolver.datetime`, `messenger.middleware.handle_message` (+ `command_bus.…`),
  `messenger.transport.in_memory.factory`, `messenger.transport.doctrine.pg_notify_on_idle_listener`, `uri_signer`,
  `security.access_token_handler.oidc(.generator)` (unused) and the test locator.
- Replacing the service (experiment: `$services->set('clock', MockClock::class)->args(['2026-10-14 12:00:00', 'UTC'])`)
  works, including in the 12 test files that `set(ClockInterface::class, new MockClock(...))` themselves - every test
  that sets its own moment passed (their own moment wins; the failures in `EventsListUiTest` / `SeriesPageUiTest` are
  tests of those files that do not set one). A per-kernel instance is preferable to `Clock::set()` (a static that would leak between
  tests of one ParaTest worker).
- What reads the real time anyway (vendor): Security login links (`LoginLinkHandler`, `SignatureHasher`: `time()`),
  remember-me (`SignatureRememberMeHandler`, `PersistentRememberMeHandler`: `time()`), `HttpFoundation\Cookie`
  (`time()` for Max-Age/expired), BrowserKit's cookie jar (`time()`), RateLimiter policies (`microtime()`),
  doctrine-messenger `Connection` (`new DateTimeImmutable('UTC')` for `available_at`), `DelayStamp::delayUntil()` /
  `RedeliveryStamp` (`Clock::get()` - the app only uses `new DelayStamp(ms)`, durations), league/oauth2-server (own
  `new DateTimeImmutable()` + lcobucci `SystemClock`, consistent with itself), firebase/php-jwt (static
  `JWT::$timestamp` - the app's verifiers `LiveKitWebhookVerifier` and `MicrosoftIdTokenVerifier` already set it from
  the clock), Stripe `Webhook::constructEvent` (`time()` tolerance, inside the library), `PostgresSessionHandler`
  (uses the clock - consistent, and tests use `session.storage.factory.mock_file` anyway). Each of these is
  self-consistent; the risk is only where a value minted on one clock is checked on the other:
  - **cookies** with an expiry from the clock, read by a browser/jar on real time: `SlidingLoginCookiesSubscriber:167`,
    `BotTrustCookieSubscriber:81`, `StaleLiveComponentPageSubscriber:145` → Q1;
  - `VerifySignInCodeHandler:53` compares `LoginLinkRequest::$expiresAt` (Symfony's `time()` + 1800 s) with the clock,
    and `SingleUseLoginLinkHandler:132` garbage-collects by it - fine while the instant is in the past (D1);
  - `AssetLoadFailureClassifier:116` compares the page's `msp-rendered-at` (Twig `'now'`, real) with the clock → Q1.
- **Experiment A** (frozen at 2026-10-14, real date 2026-10-22): **160 failing tests in 77 files** (131 failures, 29
  errors): Controller 41 files / 86 tests, MessageHandler 20 / 43, Security 3 / 11, Query 7 / 9, Services 5 / 9,
  EventSubscriber 1 / 2. Full list: `phase0-frozen-clock-failures.txt`. Classification:
  - **framework mismatch: 1 file** - `EventSubscriber/StaleLiveComponentPageSubscriberTest` (2): the reload-guard
    cookie expires at "frozen + 10 s", the jar drops it at once → Q1.
  - **everything else is now-relative test data on the real clock**, in these shapes: SQL `NOW()` in a test's setup
    (Postgres keeps the real date: e.g. `deleted_at = NOW()`, `hide_until = NOW() + 30 days`); `new DateTimeImmutable('-1
    hour')` / `time()` / `microtime()` in a test (asserting `>= microtime(true)*1000`, expired tokens built 1 h before
    the *real* now, prune/GC tests seeding "old" rows from the real date); test doubles minting tokens with `time()`
    (`MicrosoftIdTokenFactory`, `AppleIdTokenFactory` → "id_token verification failed", 11 Security tests) and test
    helpers decoding the app's JWTs with php-jwt's real clock (`LiveVideoTesting`, `ApiVideoTokenControllerTest`,
    `LiveUpdatesSubscriptionTest` → `ExpiredException`); fixtures 8 days "older" than the test's real-clock
    expectations (free trial 7-day account age, "joined yesterday", "Recently completed", online rounds whose fixture
    start is frozen while the test starts the round at the real now → `round_not_started`).
  - **No regression in `src/`** showed up: no production code path failed because of the frozen clock itself.
- **Experiment A+400** (same frozen clock, the container's real date shifted +400 days with libfaketime): 41 more
  failures, 1 fewer. All but one are the same kind of test data, now further apart (prune/retention tests, "this month"
  calendars, insights windows). The one framework mismatch: `Security/RememberMeTest::testBothLoginCookiesSlide…` -
  the session cookie re-sent with "frozen + 30 days" is expired in the jar → Q1.
- **Panther** (own FrankenPHP test server on the worktree + own Selenium chromium, 4 files, 18 tests): with the instant
  8 days old `SmokeTest`, `AddTimeRequiredFieldsTest`, `PuzzleLibrary/SolvedListTest` pass. With the instant a year old
  (2025-10-15, fresh fixture DB) `SmokeTest` passes and **every signed-in test fails** (pages without the signed-in
  parts: `#solved-count` missing, `.ts-wrapper` never appears) - the session cookie mechanism above. Q1 fixes it.

### D3 - pinning UTC: changed (PHP is pinned already)

- **PHP:** the base image `ghcr.io/myspeedpuzzling/web-base-php85:main` ships `/usr/local/etc/php/conf.d/99-php-overrides.ini`
  with `date.timezone = "UTC"` (it is pinned, not UTC "by default"). The app `Dockerfile` builds `FROM` it, CI's test
  jobs run in it (`container:`), compose's `web`/`web-test` use it. Dev `web` container: `UTC`. The disabled
  `panther-tests` CI job uses `shivammathur/setup-php` (whose default ini also sets UTC). Phase 1 needs no ini change -
  only the guard test (and a comment pointing at the base image).
- **DB session:** dev `SHOW TimeZone` = `Etc/UTC` (source: `postgresql.conf`, written by initdb), prod `Etc/UTC`
  (re-checked read-only today), CI uses `postgres:16` (same initdb default). Nothing sets it per connection. DBAL's
  PDO pgsql driver builds the DSN itself and passes no `options`, so the DSN cannot carry `-c TimeZone=UTC`.
  Verified: `PGTZ=UTC` and `PGOPTIONS='-c TimeZone=…'` both work with pdo_pgsql (startup packet, no statement). Choice
  → Q4.
- Why it matters once R2 is done: `::date` / `date_trunc` on `datetimetz` columns, `timestamptz` ↔ `timestamp`
  comparisons (today `hide_until <= NOW()` compares a naive column with a `timestamptz` through the session zone), and
  `datetimetz` values read as strings (`new DateTimeImmutable($row['…'])` keeps the session's offset).
- Schema: 178 `DATETIME_IMMUTABLE`, 23 `DATETIMETZ_IMMUTABLE`, 7 `DATE_IMMUTABLE` columns. **No `DEFAULT now()`, no
  trigger, no function, no partial index using the clock** in the current schema (`pg_attrdef`, `pg_trigger`,
  `pg_proc`, `pg_index.indpred` on the test DB).

### D4 - UTC on write: confirmed for `datetime_immutable`, changed for `date_immutable`

- DBAL `DateTimeImmutableType::convertToDatabaseValue()` is `$value->format('Y-m-d H:i:s')` - the object's own zone,
  so the risk is real; it also applies to typed query parameters (ORM infers `datetime_immutable` for a
  `DateTimeImmutable` parameter). Untyped parameters formatted by hand (`->format('Y-m-d H:i:s')`, the common style
  in `src/Query`) are not covered by a type override - they stay safe only because the clock is UTC.
- **`date_immutable` must not be converted**: it is a calendar day; converting a Prague-midnight day to UTC moves it to
  the previous day. Override `datetime_immutable` only.
- Audit of every non-UTC `DateTime` in `src/` (60 sites of `new DateTimeZone(`/`setTimezone(`/`createFromFormat(`
  without UTC, plus parsing of request strings):
  - converted back to UTC before use: `RoundTimezone::toInstant()/parseLocal()` (round starts, registration windows,
    form + internal API), `InternalApiInput::dateTime()`, `ScheduledRoundStart`, `RegistrationAvailability::eventEndsAt()`,
    `DuplicateDateShift`, `SendPlannedResultReviewEmailsHandler:176`, `LiveKitWebhookController:154`, `Insights\FormBuilder`.
  - only formatted for output / compared, never stored: `ZonedDateTimeFormatter`, `EventsPageDates`,
    `RoundResultsExporter`, `OfficialEntryTimePrefill`, `AddEditionsController:205`, `DuplicateAssessor`,
    `InsightsRowsPreparer`, `DigestPacing`, `CompetitionRoundFormType:85` (offset label).
  - Europe/Prague digest periods: queries bind `startUtc()`/`endUtc()`; `MissYouRun`, `SendContentDigestConsoleCommand`
    build Prague days only for keys/comparisons.
  - `createFromFormat('U', …)`, `'@…'`, `createFromFormat` without a zone, SES/SNS ISO `Z` strings: UTC already.
  - **The one path to a naive column: API v1 `finishedAt`** (`CreateSolvingTimeProcessor:73`,
    `UpdateSolvingTimeProcessor:62`, free-form string, `new DateTimeImmutable($data->finishedAt)`) → Q2.
- **Production evidence (read-only, lily, 2026-10-22):** `puzzle_solving_time` by `created_via`: form 27 040 (0 with a
  non-midnight `finished_at`), stopwatch 1 676 (0), NULL/legacy 530 288 (266 non-midnight - old imports), **api 0**.
  `finished_at > tracked_at` for 58 form rows: a player whose local date was already the next day (Asia, or Europe
  just after midnight) - the field is a local calendar day, as designed, not a shifted instant. No stored data needs a
  fix; no D4 data step.

### R2 - SQL clock in `src/`: 7 sites + 1 shared fragment with 11 callers

Convention to follow (what `GetPuzzlePickerSuggestions`, `PredictionModelRecalculator` do): `CAST(:now AS timestamp)` /
`:now::timestamp` with `'now' => $this->clock->now()->format('Y-m-d H:i:s')`. A side effect: the comparison becomes
independent of the session zone.

| Site | Today | Replacement |
|---|---|---|
| `ConsoleCommands/ExpireMutesCommand.php:30` | `messaging_muted_until < NOW()` | inject clock, bind `:now` (the command runs SQL itself - a message + handler would be the CLAUDE.md way; Phase 2 may keep the move minimal and note it in `docs/TODO.md`) |
| `MessageHandler/MarkMessagesAsReadHandler.php:54` | `SET read_at = NOW()` | inject clock, `SET read_at = CAST(:now AS timestamp)` (NOW() = transaction start; one value per statement either way) |
| `MessageHandler/PrepareDigestEmailForPlayerHandler.php:131` | `sent_at > NOW() - INTERVAL '…'` | clock is injected already: bind `:since` = `now()->modify('-6 hours' …)` computed in PHP |
| `Query/GetAchievementHolders.php:38` | `LISTED_ELIGIBILITY` const `… > NOW()` (used at :221) | inject clock, `> CAST(:now AS timestamp)`, bind in every statement using the fragment |
| `Query/GetXpLeaderboard.php:47` | `ACTIVE_MEMBERSHIP` const `… > NOW()` (used at :119) | same |
| `Services/PuzzleIntelligence/PaceHistoryReadiness.php:46` | `built_at = NOW()` | inject clock, bind `:now` |
| `Services/PuzzleIntelligence/StatisticalInputsSql.php:58` | `NOT_SECRET` const, `hide_until <= NOW()` ×2 | `<= CAST(:now AS timestamp)`; every statement containing it binds `now` (below) |

`StatisticalInputsSql::NOT_SECRET` reaches SQL through (each must bind `'now'` = the clock's now - for
`PredictionReconstructor` too: "secret" is judged at the real now, never as-of, or a reconstruction would leak):

- directly: `Query/GetPersonalEstimates.php:164` and `:206`; `Query/GetTimesAwaitingImagePredictions.php:37`;
  `Query/GetImageEstimateEvaluationRows.php:39`; `PuzzleIntelligence/PredictionReconstructor.php:389`;
  `PuzzleIntelligence/PredictionModelRecalculator.php:100`; `PuzzleIntelligence/Model/PredictionEvaluator.php:49`;
- via `StatisticalInputsSql::JOINS` (line 50): `Query/GetPlayerPrediction.php:256`, `Query/GetPersonalEstimates.php:205`,
  `Query/GetPlayerPredictions.php:266`;
- via `imageEstimateJoins()` (line 87): `GetPlayerPrediction.php:256`, `GetPersonalEstimates.php:205`,
  `PredictionReconstructor.php:390`, `PredictionModelRecalculator.php:170`;
- via `centreSql()` (line 236, `CASE WHEN {$notSecret}`): `Query/GetPuzzlePickerSuggestions.php:162` - binds `now`
  already (`:64`), so it needs nothing new; `centreSql()` returns its params, check they do not clash.
- Clock not injected yet in: `GetTimesAwaitingImagePredictions`, `GetImageEstimateEvaluationRows`,
  `PredictionReconstructor`, `PredictionEvaluator`, `GetXpLeaderboard`, `GetAchievementHolders`,
  `MarkMessagesAsReadHandler`, `ExpireMutesCommand`, `PaceHistoryReadiness` (`PredictionModelRecalculator` gets `$now`
  passed in).

The grep in `README.md` also matches `InvalidLocalTime` (case-insensitive `LOCALTIME`), `performance.now()` comments and
the PHP `now()` helpers - the guard test must match SQL tokens only (word boundary, not after `->`/`::`/`function `).

### R1 - PHP clock bypasses in `src/`: 138 grep hits + 14 the grep misses

Classes: **(a)** reads "now" → must use the clock; **(b)** parses/formats a given value (fine); **(c)** measures a
duration (fine); **(d)** deliberately real time / not a clock (allowlist with reason); **(r)** = (d) via the real
clock of Q1.

**(a) - 79 hits in 56 files, + 6 outside the grep:**

- Entities (an entity never reads a clock - take the moment from the caller): `Entity/PuzzleDifficulty.php:97`;
  `PlayerRatingSnapshot.php:51`, `GlobalImprovementRatio.php:36`, `PlayerElo.php:37`, `PlayerImprovementRatio.php:37`
  (constructor defaults - no `src/` code constructs them, the batch writes by SQL; drop the default);
  `SellSwapListItem.php:96`; `Conversation.php:84,90`; `ConversationReport.php:65,73`; `PersonalAccessToken.php:59`;
  `PlayerInsightsProfile.php:119`; `ChatMessage.php:76`; `OAuth2/OAuth2ClientRequest.php:110,115,121,136` (incl.
  `'+7 days'`); `Player.php:434` (fair use accepted) and `:652` (mute expired check).
- Handlers / services: `MessageHandler/LendPuzzleToPlayerHandler.php:81,110`; `BorrowPuzzleFromPlayerHandler.php:83,110`;
  `PassLentPuzzleHandler.php:79,108`; `ReturnLentPuzzleHandler.php:61`; `AddPuzzleToSellSwapListHandler.php:77`;
  `MarkPuzzleAsSoldOrSwappedHandler.php:79`; `AddPuzzleToWishListHandler.php:55`; `AddPuzzleToCollectionHandler.php:74`;
  `MovePuzzleToCollectionHandler.php:84`; `CreateCollectionHandler.php:46`; `DismissHintHandler.php:41`;
  `RegisterUserToPlayHandler.php:34`; `ReportConversationHandler.php:52`; `ReportFeatureRequestCommentHandler.php:44`;
  `MuteUserHandler.php:36`; `UnmuteUserHandler.php:41`; `WarnUserHandler.php:46`; `BanFromMarketplaceHandler.php:48`;
  `LiftMarketplaceBanHandler.php:41`; `AdminRemoveListingHandler.php:50`; `CreatePersonalAccessTokenHandler.php:32`;
  `RequestOAuth2ClientAccessHandler.php:35`; `SendMessageHandler.php:59`; `StartConversationHandler.php:90,152`;
  `Services/SystemMessageSender.php:36`; `Repository/PlayerRepository.php:64` (new player's registration moment).
- Controllers: `PuzzleLibraryController.php:85,98,113,131,145,173`; `PlayerStatisticsController.php:114,170,188,212,222`;
  `ExportPuzzlerDataDownloadController.php:61` (file name date); `OAuth2/ClaimOAuth2CredentialsController.php:62`;
  `Messaging/SendMessageController.php:67`; `Stopwatch/ApiStartController.php:50`, `ApiResumeController.php:60`,
  `ApiPauseController.php:62` (`serverTime` - must match the stored lap moments, which come from the clock);
  `Test/TestLoginController.php:51,57` (test-only route, still the app's clock).
- Forms / components / results: `FormData/PuzzleAddFormData.php:142`, `EditPuzzleSolvingTimeFormData.php:83` (default
  day); `Component/MostActiveSoloPlayers.php:65,70`, `GlobalStatistics.php:54,59`, `MostSolvedPuzzles.php:121,126`
  (`date('m')`/`date('Y')`); `Results/StopwatchDetail.php:30,50`; `FormType/CompetitionRoundFormType.php:86` (offset
  label "now" when the round has no date).
- **Missed by the grep:** `Services/RelativeTimeFormatter.php:86` (`$to = null` → `'now'`: the Twig `|ago` filter, 54
  template uses - every "3 days ago" label reads the real time); `Query/GetPuzzleHistory.php:229,506` (`?? 'now'`
  fallback for a NULL column); `Value/OccurrenceDates`-style callers are fine.
- **Templates (Twig reads the real time, no clock):** `_footer.html.twig:160` (`'now'|date('Y')`),
  `stopwatch.html.twig:15` (server-now for the stopwatch JS), `components/RecentActivity.html.twig:8` (server-now for
  relative labels), `_event_date_range.html.twig:11` (current year), `insights/frames/_rating_strips.html.twig:15`
  (`date().timestamp`), `admin/duplicate_results.html.twig:283`, `components/ActivityCalendar.html.twig:5`
  (can go forward), `base.html.twig:492` (`date() < date('2026-09-21…')` - an expired banner condition, dead code:
  remove). Phase 2: a Twig function `now()` backed by the clock, and a guard over `templates/` for `'now'|date` /
  `date()`.

**(b) - 13:** `Controller/InternalApi/CompetitionInput.php:91,95`, `DuplicateCompetitionController.php:68,69`,
`CreateEditionController.php:66,67` (grep false positives: `$input->date(...)`); `Services/EventsPage/EventsPageDates.php:25`
(comment); `MessageHandler/ClaimVoucherHandler.php:348` (`setTimestamp` of Stripe's value), `:358` (`date()` of Stripe's
value); `Services/RelativeTimeFormatter.php:82`; `PuzzleIntelligence/ImprovementRatioCalculator.php:80` (`strtotime` of
two stored values); `PuzzleIntelligence/Model/PredictionModelFitter.php:79,80` (`date()` of window bounds). Plus
`DetectSuspiciousTimesHandler.php:338` (`gmdate` of a stored value).

**(c) - 37 (durations, `microtime(true)` / `hrtime`):** console commands `SnapshotPlayerInsights` 57/70,
`VerifyProgression` 35/46/58, `DetectBoxTextMismatches` 35/47, `RefreshPuzzleTags` 41/52, `RerankRatingSnapshots`
69/87, `RecalculateKarma` 49/59, `DateAchievementsFromHistory` 76/141, `RecalculateCommunityStats` 31/42,
`FitPredictionModel` 32/46, `CanonicalizePuzzleCodes` 68/182, `XpBackfill` 66/120, `BackfillPaceHistory` 42/49,
`RecalculateInsightsDaily` 31/42; `EventSubscriber/ApiUsageSubscriber.php:70,157` (hrtime);
`PuzzleIntelligence/PuzzleIntelligenceRecalculator.php:82,84`; `PredictionModelRecalculator.php:74,78`;
`Model/PredictionEvaluator.php:39,125`; `LiveKit/StudioSpectators.php:111,115` (lock-wait deadline).

**(d)/(r) - 9 + 5:** `Services/GenerateManufacturerSlug.php:31` (`microtime()` as entropy, (d));
`HealthCheckContainerController.php:21`, `HealthCheckLivenessController.php:37` ((d), probes);
rate-limiter retry-after against the limiter's own `microtime()`: `Security/LoginFormAuthenticator.php:146`,
`OnlineRounds/OnlineWatchTokenController.php:123`, `Api/V1/PuzzleSearchResponseProvider.php:187`,
`OnlineRounds/OnlineParticipantApi.php:169` ((r)); cookie expiry: `SocialRegisterSignInController.php:90`,
`EventSubscriber/NativeAuthPageSubscriber.php:113`, `ReferralCookieSubscriber.php:91` (missed by the grep) ((r)); and
three that use the clock today but should move to (r) per Q1: `SlidingLoginCookiesSubscriber.php:167`,
`BotTrustCookieSubscriber.php:81`, `StaleLiveComponentPageSubscriber.php:145`; plus `base.html.twig:288`
(`msp-rendered-at`, compared by the stale-document script with the server's HTTP `Date` header - must stay real,
a frozen value would make Panther pages "stale" and reload) with its server-side reader
`AssetLoadFailureClassifier.php:116` ((r)).

Count check: 79 (a) + 13 (b, 12 in the grep + `gmdate`) + 37 (c) + 9 (d/r in the grep) = the 138 grep hits (+1
`gmdate` from the extended grep).

### Tests - the rewrite recipe and Phase 4 batches

Scope (grep of `tests/`): **415 files** - 205 with SQL `NOW()`/`CURRENT_*`, 253 with `new DateTimeImmutable()` /
relative strings / `time()` / `date('…')`. Per directory (files, sql/php): `MessageHandler` 90 (32/70), `Query` 71
(56/22), `Controller` 141 (78/77), `Services` 47 (22/34), `Component` 8, `Security` 9, `Entity` 12, `EventSubscriber` 4,
`ConsoleCommands` 2, `Panther` 1, `TestDouble` 3, `Value` 2, root-level helpers/tests ~16. Of these, the 77 files of
`phase0-frozen-clock-failures.txt` fail outright once the clock is frozen; the rest are self-consistent today and are
rewritten for R4 (nothing reads the real date).

Helpers for Phase 3 (`tests/TestClock.php`):

- `TestClock::FROZEN_NOW = '2026-10-14 12:00:00'` (UTC), `TestClock::now(): DateTimeImmutable` (UTC zone);
- `TestClock::SQL_NOW = "TIMESTAMP '2026-10-14 12:00:00'"` and `TestClock::SQL_TODAY = "DATE '2026-10-14'"` - a literal
  spliced into SQL is the most mechanical replacement (no new parameter, works inside `executeStatement` strings with
  existing params): `'… = NOW() …'` → `'… = ' . TestClock::SQL_NOW . ' …'`, heredocs via `{$now}` with
  `$now = TestClock::SQL_NOW`;
- `TestClock::timestamp(): int` for `time()`, `TestClock::milliseconds(): int` for `microtime(true) * 1000`.

Replacement table: `new DateTimeImmutable()` → `TestClock::now()`; `new DateTimeImmutable('-3 days')` →
`TestClock::now()->modify('-3 days')`; `'today 12:00'` → `TestClock::now()->setTime(12, 0)`; `NOW() - INTERVAL '10 days'`
→ `SQL_NOW . " - INTERVAL '10 days'"`; `CURRENT_DATE` → `SQL_TODAY`; `time()` → `TestClock::timestamp()`; a test that moves
time uses the container's `MockClock` (`self::getContainer()->get('clock')->modify(...)`) - never `sleep()`. Token doubles
(`MicrosoftIdTokenFactory`, `AppleIdTokenFactory`) take `iat`/`exp` from `TestClock`; tests decoding the app's JWTs set
`JWT::$timestamp = TestClock::timestamp()` (as the app's verifiers do) or go through a helper in `LiveVideoTesting`.
Assertions like `assertGreaterThanOrEqual(microtime(true) * 1000, $at)` become exact equalities against the frozen
moment - stronger, not weaker. Tests that already pin their own `MockClock` keep it.

Batches (≤ ~50 files, one agent run each), in order: 1 `MessageHandler` A-L, 2 `MessageHandler` M-Z, 3 `Query`,
4 `Controller` (`Admin`, `Api`, `Compete`, `OnlineRounds`, `OfficialResults`), 5 `Controller` A-M top level,
6 `Controller` N-Z top level + other subdirs, 7 `Services`, 8 the rest (`Security`, `Entity`, `Component`,
`EventSubscriber`, `ConsoleCommands`, `Value`, `TestDouble`, root, `Panther`) + the guard tests over `tests/`.
Batch 4 holds most of the experiment's errors (online rounds: fixture start vs real-now start).

### D5 - libfaketime: confirmed, works on both architectures

- `apt-get install faketime` in the base image (Debian 12 bookworm) gives `/usr/lib/<arch>-linux-gnu/faketime/libfaketime.so.1`.
  arm64 (local) and amd64 (`--platform linux/amd64`, emulated): `LD_PRELOAD=… FAKETIME=+400d` shifts PHP's `time()`,
  `date()`, `microtime()`, `new DateTimeImmutable()`; `hrtime()` stays monotonic with `FAKETIME_DONT_FAKE_MONOTONIC=1`.
  `FAKETIME='@2027-11-25 10:00:00'` starts the clock there and lets it run.
- PostgreSQL is another container and is **not** shifted (`SELECT NOW()` stays real) - correct for the proof: once R2
  holds in `src/` and `tests/`, nothing asks Postgres for the time.
- The whole suite ran under ParaTest with it (`msp-faketime:local`, a two-line Dockerfile `FROM` the base image +
  `apt-get install faketime`): 4 min, results consistent (A+400 above). For CI: install it in the job (`apt-get` in the
  job container) or ship it in the base image (another repo).
- Shift choice → Q3.

### Notes for the next phases

- `tests/.database.cache` is not keyed by database name: switching `DATABASE_URL` to another DB reuses a stale "up to
  date" mark → delete the file when changing DB names. Its validity has no time component
  (`TestingDatabaseCaching::isCacheUpToDate()` compares `time() + CACHE_VALID_FOR_SECONDS < filemtime`, never true).
- Phase 3: include `TestClock::FROZEN_NOW` in the bootstrap hash (fixtures are built from the clock).
- The experiment needed nothing but one `set('clock', MockClock::class)->args([...])` - strings as args, the container
  cannot dump a `DateTimeImmutable` argument.
- Scratch databases left on the dev Postgres (all ending in `_test`, safe to drop): `speedpuzzling_clock_test(_N)`,
  `speedpuzzling_clockexp_test(_N)`, `speedpuzzling_clockold_test(_template)`. Local image `msp-faketime:local`.

## Phase 1 (2026-10-22) - UTC on write

What changed:

- `src/Doctrine/UtcDateTimeImmutableDoctrineType.php` overrides DBAL's `datetime_immutable` (registered in
  `config/packages/doctrine.php` under the same name): it converts to UTC before formatting and reads back explicitly as
  UTC. A value that is already UTC is stored byte-for-byte the same as before. The SQL declaration is unchanged, so there
  is no schema change. `date_immutable` and `datetimetz_immutable` are untouched, and no entity uses the mutable `datetime`.
- `src/Api/V1/CreateSolvingTimeProcessor.php` / `UpdateSolvingTimeProcessor.php` convert `finishedAt` to UTC right after
  parsing. **The type alone was not enough** (verified by removing the processor change: the test fails). The handler
  compares days in memory before anything is stored (`PuzzleSolvingTime::isSameEntryAs()`, the day check in `modify()`),
  and the response echoed the input's `+02:00`. With the input kept local, a retry with the same Idempotency-Key at
  00:30+02:00 would not count as the same entry.
- Nothing else in `src/` hands a non-UTC object to a `datetime_immutable` column (the Phase 0 audit). Since the API path
  is fixed at the call site, the type changes no current behaviour.
- No DB session zone code and no Dockerfile change (Jan's answers 1 and 4).

Tests: `tests/Doctrine/UtcDateTimeImmutableDoctrineTypeTest.php` (a Prague object stored as UTC wall time with the same
instant, an offset input, UTC unchanged, the input object not mutated, null, fractions read as UTC, non-dates refused);
`tests/TimezoneConfigurationTest.php` (PHP default zone UTC, the registered type is ours); one
`testFinishedAtWithAnOffsetIsStoredAsTheSameInstantInUtc` each in `CreateSolvingTimeEndpointTest` (response, stored row,
the retry answered as the same result) and `UpdateSolvingTimeEndpointTest` (response, stored row). Reads (`/me/results`,
player results) format the stored UTC value with `format('c')`, so they show `+00:00`.

Gates: PHPStan clean (4 findings in the new test fixed: assertions that PHPStan already knew to be true), PHPCS clean.
`doctrine:schema:validate` (dev env) is in sync, and `doctrine:schema:update --dump-sql` reports nothing to update. Both
were run against a scratch DB built from the committed migrations (`speedpuzzling_clockschema_test`, safe to drop).
`cache:warmup` (dev) is OK. In this one-off container it needed `-d memory_limit=-1`: at the default 512 MB it ran out of
memory in the container-lint warmer. That is environmental (the dev web container sets its own limit) and was not
caused by this change. Full suite (ParaTest, `speedpuzzling_clock_test`): **11516 tests green** (5 skipped, 27 PHPUnit notices - none from the
changed files, checked with `--display-phpunit-notices`; Phase 0's baseline was 11505 + the 11 new tests), 4 min 22 s. Drift: none. Regressions: none.

For Phase 2:

- Hand-formatted SQL parameters (`->format('Y-m-d H:i:s')`, the common style in `src/Query`) never pass through the
  type. They are UTC only because the clock is UTC. Phase 2's `:now` bindings keep that style.
- `LapsArrayDoctrineType` also formats its laps in the object's own zone (JSON, not a timestamp column). The laps come
  from the clock (UTC), so this is not a problem today. If it ever matters, give it the same UTC conversion.

## Phase 2 (2026-10-22) - one clock in `src/` and templates

What changed (behaviour-neutral: in production the clock is the real time):

- **SQL (R2), 7 sites + the shared fragment.** `NOW()` → `CAST(:now AS timestamp)` with
  `'now' => $this->clock->now()->format('Y-m-d H:i:s')` in `ExpireMutesCommand`, `MarkMessagesAsReadHandler`
  (`read_at`), `PrepareDigestEmailForPlayerHandler` (`:since` computed in PHP), `GetAchievementHolders` (3 statements),
  `GetXpLeaderboard` (AP board only), `PaceHistoryReadiness`. `StatisticalInputsSql::NOT_SECRET` now expects `:now`;
  every statement using it (directly, via `JOINS`, `imageEstimateJoins()`, `centreSql()`) binds it:
  `GetPersonalEstimates` (2), `GetTimesAwaitingImagePredictions` + `GetPlayersWithPendingPredictions` (its
  `condition()`, bound only when used), `GetImageEstimateEvaluationRows`, `GetPlayerPrediction`, `GetPlayerPredictions`,
  `PredictionModelRecalculator` (2, `$now` passed into the private methods), `PredictionEvaluator`,
  `PredictionReconstructor`; the Picker bound `now` already. **Reconstructor/evaluator judge secrecy at the real
  present** (the clock's now), never as of the solve or `$fitBefore` - commented at both call sites.
- **PHP (R1).** Every class-(a) read of Phase 0 goes through the injected clock (102 src files changed in total across
  the three commits). Entities take the moment from the caller (`accept($now)`, `ignore($now)`, `resolve(…, $now)`,
  `dismiss(…, $now)`, `revoke($now)`, OAuth2 `approve/reject/resetCredentials(…, $now)`, `markAsReserved($now, …)`,
  `markAsRead($now)`, `acceptFairUsePolicy($now)`, `isMessagingMuted($now)`; `computedAt` is a required argument of
  `PuzzleDifficulty`, `PlayerInsightsProfile`, `PlayerElo`, `PlayerRatingSnapshot`, `GlobalImprovementRatio`,
  `PlayerImprovementRatio` - reordered before the optional ones, nothing in `src/` constructs them positionally).
  `PuzzleAddFormData` / `EditPuzzleSolvingTimeFormData` take the default day in their constructor; `StopwatchDetail`
  gets `$now` from `GetStopwatch`; `GetPuzzleHistory::decisionEntry()` is no longer static. The `|ago` filter
  (`RelativeTimeFormatter`) counts to the clock's now.
- **Twig.** New `now()` function (`ClockTwigExtension`). Converted: `_footer` (year), `stopwatch` (server-now),
  `components/RecentActivity` (server-now for relative labels), `_event_date_range` (current year),
  `insights/frames/_rating_strips`, `admin/duplicate_results`, `components/ActivityCalendar`, and the expired WJPC
  live strip in `base.html.twig` (kept, `now() < date(…)` - Phase 0 suggested removing it; left for Jan, see TODO
  below).
- **Real time on purpose (Jan's answer 1), each with a short comment:** cookie expiries - `SlidingLoginCookiesSubscriber`
  (the session cookie; its refresh throttle stays on the clock), `BotTrustCookieSubscriber` + `BotTrustCookieSigner`
  (the sidecar judges the issued-at; the signer lost its clock argument), `StaleLiveComponentPageSubscriber` - these
  three used the clock before and moved to real time; `SocialRegisterSignInController`, `NativeAuthPageSubscriber`,
  `ReferralCookieSubscriber` were real already. `msp-rendered-at` in `base.html.twig` and its reader
  `AssetLoadFailureClassifier` (lost its clock argument). Rate-limiter retry-after (4), health probes (2).
- **(b) parsing** rewritten so no system-clock function is involved: `ClaimVoucherHandler` (`'@' . $stripeTs`),
  `DetectSuspiciousTimesHandler` (`gmdate` → `'@'`), `PredictionModelFitter` (`date()` → `'@'`); `RelativeTimeFormatter`
  ints via `'@'`. `ImprovementRatioCalculator`'s `strtotime()` of stored values stays (hot loop) and is allowlisted.

Guard tests:

- `tests/DatabaseClockCoverageTest.php` (R2, `src/`): `NOW()`/`clock_timestamp()`/… without arguments,
  `CURRENT_DATE|TIME|TIMESTAMP`, `LOCALTIME(STAMP)`, `'now'::…` casts, one-argument `age()`; comments stripped, PHP
  `->now()` / `function now()` excluded. **No allowlist - no exception exists** (an empty allowlist only made PHPStan
  complain; add one when the first exception appears). Plus a pattern self-test.
- `tests/SystemClockCoverageTest.php` (R1, `src/` + `templates/`): `new DateTime(Immutable)()`, relative strings
  (`'now'`, `'today'`, `'+…'`, `'first day of…'` …), the call of `time() date() gmdate() strtotime() mktime()
  microtime() hrtime() date_create()` … (lower case, `(` right after the name, not after `->`/`::`/`$`, declarations
  ignored), `Clock::get(`, `new NativeClock/DatePoint`, a bare `'now'` (except as a `:now` parameter name). Templates:
  `'now'|date`, `date()`, `date('<relative>')`. Allowlist `path => reason`, **36 PHP files**: 18 durations
  (`microtime(true)`/`hrtime()` in 13 console commands, `ApiUsageSubscriber`, `PredictionEvaluator`,
  `PredictionModelRecalculator`, `PuzzleIntelligenceRecalculator`, `StudioSpectators` lock deadline), 5 cookie expiries,
  4 rate-limiter retry-afters, 2 bot-blocker (subscriber + signer), 2 health probes, `AssetLoadFailureClassifier`,
  `GenerateManufacturerSlug` (entropy), `ImprovementRatioCalculator` (parses stored values), `SecretRevealPreview`
  (`'now'` as a hash token), `ClockTwigExtension` (registers `now`). **1 template**: `base.html.twig`
  (`msp-rendered-at`). File-level, like the repo's other coverage tests - an allowlisted file can hide a new read;
  Phase 4 may tighten it.

Gates: PHPStan clean; PHPCS clean (cs-fix fixed whitespace in the new test only); full suite (ParaTest,
`speedpuzzling_clock_test`) **11521 tests green** (5 skipped, 27 PHPUnit notices as in Phase 1; 11516 + 5 new guard
tests), 3 min 52 s; `doctrine:schema:validate` (dev, scratch DB from the committed migrations) in sync - the entity
parameter reorder changes no mapping; `cache:warmup` (dev) OK (`-d memory_limit=-1` in the one-off container, as in
Phase 1).

Drift (tests changed because they pinned the old wiring, none weakened): the 3 test helpers splicing `NOT_SECRET`
(`FitsThePredictionModel`, `ImagePredictionsSideBySideTest`, `PredictionModelRecalculatorTest`) now bind `:now` from
the container clock - they caused all 35 errors of the first run; tests constructing services by hand get the new
arguments (`PaceHistoryReadiness`, `PredictionModelRecalculator` in 2 batch tests, `BotTrustCookieSigner/Subscriber`
without a clock, `AssetLoadFailureClassifier` without a clock - its page-age assertion is
`assertEqualsWithDelta(90, …, 1)` now: both sides are real time); entity constructors/methods called by tests and
fixtures pass a moment (fixtures their `$this->clock`, tests `new DateTimeImmutable()` - Phase 4 turns those into
`TestClock`). Regressions: none.

For Phase 3:

- Everything the frozen clock must reach is on `ClockInterface` / `now()`; what stays real is exactly the allowlist.
  With the clock frozen, cookies now expire on real time (the Phase 0 jar/Panther failures should be gone), and
  `AssetLoadFailureControllerTest` (sends `time()`-based `rendered`) keeps working.
- `DateTimeImmutable::createFromFormat()` without `!`/`|` fills missing fields from the system clock; all current uses
  in `src/` either give the full format or `setTime()` right after - the guard does not look for it.
- Tests still read the real clock in many places (Phase 4), including the ones Phase 2 touched.
- `tests/.database.cache` is not keyed by DB name (Phase 0 note) - the parallel helpers of this phase hit it with a
  fresh DB name; delete it when switching DB names.
- TODO for Jan / Phase 5: the expired WJPC live strip in `base.html.twig` (date 2026-09-21) is dead code - remove it
  together with `live_event_strip` and `.competition-panel-info` when convenient (not done here: not a clock question).

## Phase 3 (2026-10-22) - the frozen clock in tests

**The branch is red from here until Phase 4 ends** (157 failing tests, all test data on the real clock). Do not merge.

What changed:

- `tests/TestClock.php`: `FROZEN_NOW` (`2026-10-14 12:00:00`, UTC), `now()`, `SQL_NOW` (`TIMESTAMP '…'`, built from
  `FROZEN_NOW`), `SQL_TODAY` (`DATE '2026-10-14'`), `sqlParameter()` (for a bound `:now`, `'Y-m-d H:i:s'` like the app),
  `timestamp()` (for `time()`), `milliseconds()` (for `microtime(true) * 1000`). `tests/TestClockTest.php` pins it: the
  container clock is at the instant in UTC, a test moving it (`sleep()`) does not move the next kernel's clock, the SQL
  literals name the instant, and the instant lies before the real date of the run (D1's constraint).
- `config/services_test.php`: `$services->set('clock', MockClock::class)->args([TestClock::FROZEN_NOW, 'UTC'])` - a
  service of each container, never `Clock::set()`. `Psr\Clock\ClockInterface` / Symfony's `ClockInterface` alias it, so
  the app, the framework consumers (Phase 0's list) and the fixtures all see the instant. A test moving it in a
  `WebTestCase` loses the move on the browser's kernel reboot - the 12 files setting their own
  `set(ClockInterface::class, new MockClock(…))` keep doing that (all of them set a different moment on purpose; none
  duplicated the frozen instant, so none was removed).
- Fixtures: nothing to change. All 53 take dates from the injected clock (45) or absolute literals (8:
  `DismissedHintFixture`, `OAuth2ClientRequestFixture`, `XpSettingsFixture`, `XpLaunchRevealSeenFixture`, and four
  without dates); none reads the system clock (grep for `new DateTime…()`, relative strings, `time()`, `date(`,
  `strtotime`, `microtime`, `Clock::get`, `NativeClock`: 0 hits). Verified on the rebuilt DB: latest
  `puzzle_solving_time.tracked_at` 2026-10-13 12:00, latest `player.registered_at` 2026-10-14 12:00. The bootstrap's
  pace-history backfill and incremental recalculation run on the same frozen clock.
- `tests/bootstrap.php`: the DB cache hash ends with `-now-<FROZEN_NOW>` - a moved instant rebuilds the fixtures.
- `XpChainRecomputerTest::testWeeklyBoostStopsAfterFiveSolvesAndResetsNextWeek`: back to the fixed week
  `fullFormulaFrom() + 28 days` (24-30 Aug 2026; `8447c607d`'s `+2 years` reverted). `EventDetailFixture::TIME_SPRINT_1`
  is now always 16 Aug. Passes.

Gates: PHPStan clean (the test reads the clock through Symfony's `ClockInterface` - PHPStan sees the dev container's
`Clock`, so no `instanceof MockClock`), PHPCS clean. Full suite (ParaTest, `speedpuzzling_clock_test`): **11525 tests,
128 failures + 29 errors = 157 tests in 75 files**, 4 min 16 s. Full list with the first message line of each:
`phase3-frozen-clock-failures.txt`.

Failing files by directory (files / tests):

| Directory | Files | Tests |
|---|---|---|
| `Controller` (top level) | 20 | 44 |
| `Controller/OnlineRounds` | 7 | 21 |
| `Controller/Api` | 4 | 5 |
| `Controller/Compete` | 4 | 6 |
| `Controller/DuplicateResults`, `OfficialResults`, `ReviewResults`, `Seating`, `SuspiciousTimes` | 5 | 9 |
| `MessageHandler` (+ `ParticipantsSheet` 1/1) | 20 | 43 |
| `Query` | 7 | 9 |
| `Security` | 3 | 11 |
| `Services` (top level 2/4, `SeriesEditions` 1/1, `SocialLogin` 1/3, `Storage` 1/1) | 5 | 9 |
| **Total** | **75** | **157** |

Classification - **every failure is test data on the real clock (Phase 4); no framework mismatch, no bug in `src/`:**

- Against Phase 0's experiment (160 / 77): the same files minus 3 that Phase 2 fixed -
  `EventSubscriber/StaleLiveComponentPageSubscriberTest` (the framework mismatch: the reload-guard cookie now expires on
  real time), `Controller/AssetLoadFailureControllerTest` (`msp-rendered-at` on real time),
  `MessageHandler/PrepareDigestEmailForPlayerHandlerTest` (its `NOW()` became a bound `:since`) - plus 1 new:
  `MessageHandler/MuteUserHandlerTest::testMutingSetsCorrectFieldsOnPlayer` - Phase 2 made it pass
  `new DateTimeImmutable()` (real) to `isMessagingMuted($now)`; the mute ends at frozen + 7 days = 21 Oct, before the
  real date. Test data.
- Shapes (as Phase 0 described): SQL `NOW()` in a test's setup (hidden/embargoed puzzles, `deleted_at`, expiries);
  `new DateTimeImmutable('-…')` / `time()` / `microtime()` in tests (expired codes/links built before the *real* now,
  `>= microtime(true)*1000` assertions in the Compete API tests, prune/GC seeds); token doubles minting with `time()`
  (`MicrosoftIdTokenFactory`, `AppleIdTokenFactory` → 11 Security + 3 `SocialProfileFetcherMicrosoftTest`); helpers
  decoding the app's JWTs on php-jwt's real clock (`LiveVideoTesting`, `LiveUpdatesSubscriptionTest` →
  `ExpiredException`); fixtures 8 days "older" than real-clock expectations (free trial 7-day age, "joined yesterday",
  online rounds started at the real now while their fixture start is frozen → `round_not_started`).
- 13 failing files have no clock read of their own - it is in a shared helper (`LiveVideoTesting`,
  `FreeTrialConditions`, `SpotlightSeeding`, the token doubles, …). Phase 4 batch 1 does the helpers first.
- The sign-in link/code failures (`SignInLinkTest` 2, `SignInCodeTest` 1) are the tests' own `'-20 seconds'` /
  `'-2 minutes'` / `'-1 minute'` timestamps against the frozen clock - Symfony's link `expires` (minted and checked with
  `time()`) is self-consistent.

Panther (own FrankenPHP test server on the worktree + own Selenium chromium, frozen clock, instant 8 days old):
**22 tests in 5 files green** - `SmokeTest` (2), `AddTimeRequiredFieldsTest` (2), `PuzzleLibrary/SolvedListTest` (10),
`WishListTest` (4), `LendBorrowListTest` (4); the signed-in ones stay signed in (the session cookie expires on real time
since Phase 2). Recipe: `docker run -d --name clock-panther-web --network speedpuzzlingcz_default` the base image with
`W` at `/app` (+ vendor/node_modules/public/build read-only, `.docker/on-startup.sh` at
`/docker-entrypoint.d/on-startup.sh`, `--tmpfs /app/var/cache`, `APP_ENV=test`, `SKIP_DATABASE_MIGRATIONS=true`, the
clock test `DATABASE_URL`) + `seleniarm/standalone-chromium` as `clock-panther-chrome`; run phpunit with
`PANTHER_SELENIUM_HOST=http://clock-panther-chrome:4444` and `PANTHER_EXTERNAL_BASE_URI=http://clock-panther-web:8080`
(`--colors=never` to grep the summary). Containers removed afterwards. Three `speedpuzzling_panther_*` databases exist on
the dev Postgres (origin unknown - possibly another session's; left alone).

### Phase 4 - batches re-planned from the real list

Scope = 447 test files: the 434 that still read the real clock somewhere (grep: SQL `NOW()`/`CURRENT_DATE|TIMESTAMP`,
`new DateTime(Immutable)()`, relative strings, `time()`, `microtime(`, `new MockClock()` / `new MockClock('±…')`) + the 13
failing files whose read is in a helper. Every batch fixes its failing tests **and** rewrites the rest of its files for R4
(Phase 0's recipe: `TestClock::now()->modify(…)`, `TestClock::SQL_NOW` / `SQL_TODAY`, `TestClock::timestamp()`,
`new MockClock()` → `new MockClock(TestClock::now())`, container clock where a test moves time). Batch-local gate:
the batch's files with phpunit; full suite at the end of each batch - the failure count must only go down.

| # | Files | Failing (files / tests) | Contents |
|---|---|---|---|
| 1 | 56 | 3 / 11 | **shared helpers first** (root-level non-`*Test.php`: `*Seeding`, `FreeTrialConditions`, `LiveVideoTesting`, `OnlineCvTesting`, `OAuth2TestHelper`, `PatTestHelper`, `TestingLogin`, `TestingViewer`, `ChangesPuzzleRecords`, …), `TestDouble` (`MicrosoftIdTokenFactory`, `AppleIdTokenFactory`, `InMemoryStudioInviteSignal`), `Security`, `Entity`, `Component`, `ConsoleCommands`, `EventSubscriber`, `Value`, `Results` |
| 2 | 46 | 7 / 21 | `MessageHandler` A-O + `MessageHandler/OfficialResults` + `MessageHandler/ParticipantsSheet` |
| 3 | 46 | 13 / 22 | `MessageHandler` P-Z |
| 4 | 46 | 5 / 7 | `Query/GetA*`-`Query/GetP*` |
| 5 | 48 | 2 / 2 | `Query` rest (`GetR*`-`GetX*`, non-`Get` files) + `Services/Insights` + `Services/PuzzleIntelligence` |
| 6 | 51 | 6 / 10 | `Services` rest + `Controller` top level A-B |
| 7 | 47 | 20 / 47 | `Controller/Compete`, `OnlineRounds`, `OfficialResults`, `LiveResults`, `ParticipantsSheet`, `Seating` + `Controller` top level C-E (most errors: online-round start vs frozen fixture start, JWTs) |
| 8 | 54 | 13 / 27 | `Controller/Admin`, `Api`, `InternalApi`, `Marketplace`, `Messaging`, `SellSwap`, `ReviewResults`, `DuplicateResults`, `SuspiciousTimes` + `Controller` top level F-O |
| 9 | 53 | 6 / 10 | `Controller` top level P-Z + root-level `*Test.php` + `Panther` + **extend `DatabaseClockCoverageTest` / `SystemClockCoverageTest` to `tests/`** (allowlist with reasons: `TestClockTest`'s "instant in the past" check, `TestingDatabaseCaching`'s file-age `time()`, `PantherDatabaseManager`'s `microtime()` entropy, the cookie/real-time tests) |

Batch 1 must run first (helpers used everywhere); 2-8 are independent of each other after it (separate worktrees
possible, but they share `tests/.database.cache` per worktree - one DB name per worktree); 9 last (the guards need
everything converted).

For Phase 4:

- `TestClock::SQL_NOW` is a `timestamp` literal: comparing it with a `datetimetz` column goes through the session zone
  (UTC - README's assumption), like the app's `CAST(:now AS timestamp)`.
- A test that needs the clock to move inside one request flow: `self::getContainer()->get(ClockInterface::class)`
  (Symfony's interface - `sleep()`/`modify()` work on the `MockClock`), before the request; a `KernelBrowser` reboot
  between requests resets it to the instant (`$browser->disableReboot()` if a test needs it to stay moved).
- `new MockClock()` without an argument is the *real* now - every one of them becomes `new MockClock(TestClock::now())`
  (or a fixed literal).

## Phase 4 batch 1 (2026-10-22) - shared helpers, token doubles, Security, Entity, Component, small directories

Converted (55 files): root helpers `ComparisonSeeding`, `DifficultyRankingSeeding`, `FreeTrialConditions`,
`ChangesPuzzleRecords`, `InsightsPaceSeeding`, `LeaderboardSeeding`, `LiveVideoTesting`, `OnlineReviewSeeding`,
`PatTestHelper`, `ProfileInsightsSeeding`, `SpotlightSeeding`, `TestingLogin`, `TestingViewer`; `TestDouble/MicrosoftIdTokenFactory`;
every clock read in `Security` (9), `Entity` (11), `Component` (8), `ConsoleCommands` (2), `EventSubscriber` (4), `Value` (2),
`Results` (1); plus `Services/SocialLogin/MicrosoftIdTokenVerifierTest` (batch 6's directory, but it pairs the factory with its
own `new MockClock()` - converting the factory alone turned it red). Mechanical: `new DateTimeImmutable()` →
`TestClock::now()`, relative strings → `TestClock::now()->modify(…)`, SQL `NOW()` → `TestClock::SQL_NOW` (concatenated into
quoted strings; heredocs get `$now = TestClock::SQL_NOW;` at the top of the method and `{$now}` in the SQL), `time()` →
`TestClock::timestamp()`. `LiveVideoTesting::liveKitClaims()` decodes with `JWT::$timestamp = TestClock::timestamp()` (restored
in `finally`), like the app's verifiers. `OnlineReviewSeeding` starts/stops the round at `TestClock::now()->modify("-N seconds")`.
No assertion was weakened; no test needed its data fixed for passing by luck.

**Real time on purpose (left as is, each with a comment; batch 9 lists them in `SystemClockCoverageTest`'s allowlist):**

- `tests/OAuth2TestHelper.php` - token expiry and `iat`/`nbf`: league/oauth2-server judges its access tokens on its own clock
  (lcobucci `SystemClock`, `new DateTimeImmutable()`), not the app's. Converting it turned 25 API v1 files red (401) - reverted.
- `tests/TestDouble/AppleIdTokenFactory.php` - `iat`/`exp`: the Apple provider library and
  `AppleServerNotificationVerifier` decode with php-jwt's `time()` (the app sets no `JWT::$timestamp` there - unlike
  `MicrosoftIdTokenVerifier`/`LiveKitWebhookVerifier`; harmless, a candidate for `docs/TODO.md` in Phase 5).
- `tests/TestDouble/InMemoryStudioInviteSignal.php` - stands in for a Redis TTL, which Redis counts on its own clock.
- Cookie expiries the browser judges (Jan's answer 1): `Security/RememberMeTest` (4 × `time()`),
  `EventSubscriber/NativeAuthPageLocaleTest` (locale cookie), `EventSubscriber/BotTrustCookieSubscriberTest` (`microtime()`,
  the sidecar's clock).
- `tests/TestingDatabaseCaching.php` (file age) and `tests/TestClock.php` itself - untouched.
- `Entity/PuzzleSolvingTimeSeriesPickTest` matches the grep only through its own `$this->time()` helper (no clock).

Gates: PHPStan clean, PHPCS clean (cs-fix removed the unused `DateTimeImmutable` imports). Full suite (ParaTest,
`speedpuzzling_clock_test`): **11525 tests, 99 failures + 4 errors = 103 tests** (Phase 3: 157). Every remaining failure was
already on Phase 3's list (compared per test); nothing green turned red. Fixed by this batch: all of `Security` (3 files / 11),
`SocialProfileFetcherMicrosoftTest` (3), and through the helpers `AnnouncementModalTest`, `StartFreeTrialControllerTest`,
`StartFreeTrialHandlerTest`, `ApiVideoTokenControllerTest`, `ConfirmOnlineResultHandlerTest` and 6 of the 7 `OnlineRounds` files.

Remaining (files / tests): `Controller` top level 18 / 39, `Controller/Api` 4 / 5, `Controller/Compete` 3 / 3,
`Controller/DuplicateResults` 1 / 5, `OfficialResults` 1 / 1, `OnlineRounds` 1 / 1, `ReviewResults` 1 / 1, `Seating` 1 / 1,
`SuspiciousTimes` 1 / 1, `MessageHandler` 18 / 31, `Query` 7 / 9, `Services` 4 / 6.

For batches 2-9:

- No new helper in `TestClock`; the heredoc pattern above (`$now = TestClock::SQL_NOW;` + `{$now}`) is the one to copy.
- A test minting an OAuth2 access token keeps real time (see above); PAT tokens (`PatTestHelper`) are on the frozen clock.
- Any test that builds a Microsoft id_token and checks it with its own verifier needs `new MockClock(TestClock::now())`.
- Watch double-quoted multi-line SQL: a `NOW()` on a continuation line must be spliced with `" . TestClock::SQL_NOW . "`,
  not single quotes (a naive "first quote on the line" rewrite gets it wrong when the line contains `'high'` etc.).

## Phase 4 batch 2 (2026-10-22) - `MessageHandler` A-O, `OfficialResults`, `ParticipantsSheet`

Converted (47 files - every file of the batch with a clock read; the rest of A-O had none): 42 top-level `MessageHandler`
A-O tests, `OfficialResults/` (4), `ParticipantsSheet/ApplyParticipantSheetChangesHandlerTest`. Mechanical, by a
token-based rewrite (PHP `token_get_all`, so a `NOW()` is spliced with the quote character of the string it sits in -
single, double or multi-line double-quoted; no heredoc in this batch): `new DateTimeImmutable()` → `TestClock::now()`,
relative strings → `TestClock::now()->modify(…)`, SQL `NOW()` → `TestClock::SQL_NOW`, `time()` →
`TestClock::timestamp()`, `new MockClock()` → `new MockClock(TestClock::now())`. By hand: relative strings passed through
a variable or constant - `EmailVerificationTokenSigner::LIFETIME` (`AuthAuditEventWiringTest`; the signer checks expiry
with the clock), `MarkAbandonedOnlineMediaHandlerTest::media($age)`, `OnlineRoundSetUpHandlersTest::setStopwatch()`.
Left as is (parse a given value, no clock): `CompetitionRegistrationTest`'s `new DateTimeImmutable($today)` (`$today` comes
from the container clock), `EditMarkedTimeRecheckTest` / `NotifyWhenPuzzleSolvedTest` / `ApplyParticipantSheet…` parsing
stored columns, absolute literals. No real-time exception in this batch.

Fixed (11 tests, all test data on the real clock, none passed by luck, no assertion weakened):
`ConfirmAccountDeletionHandlerTest` (expired token built 1 min before the real now = still valid at the instant),
`EditCompetitionRoundRevealDelayTest` (2, round start from the real now), `MarkAbandonedOnlineMediaHandlerTest` (5, media
`createdAt` real), `MuteUserHandlerTest` (mute checked at the real now), `NotifyWhenPuzzleSolvedTest` (`notified_at`
bracketed by real-now ±1 s), `ApplyParticipantSheetChangesHandlerTest` (`registered_at` ≥ real-now − 1 min).

Gates: PHPStan clean, PHPCS clean (cs-fix removed 17 unused `DateTimeImmutable` imports). Batch files alone: 469 tests
green. Full suite (ParaTest, `speedpuzzling_clock_test`): **11525 tests, 88 failures + 4 errors = 92 tests** (batch 1: 103).
Every remaining failure was already on Phase 3's list (compared per test); nothing green turned red.

Remaining (files / tests): `Controller` top level 18 / 39, `Controller/Api` 4 / 5, `Controller/Compete` 3 / 3,
`DuplicateResults` 1 / 5, `OfficialResults` 1 / 1, `OnlineRounds` 1 / 1, `ReviewResults` 1 / 1, `Seating` 1 / 1,
`SuspiciousTimes` 1 / 1, `MessageHandler` (all P-Z, batch 3) 12 / 20, `Query` 7 / 9, `Services` 4 / 6.

For batches 3-9:

- The token-based rewrite is the safe way to do the SQL splices mechanically (a regex on lines cannot know the quote
  character of a multi-line string). It flags `NOW()` inside heredocs/interpolated strings for hand work
  (`$now = TestClock::SQL_NOW;` + `{$now}`), and lists every remaining `new DateTimeImmutable(<non-literal>)` to check
  by hand - a relative string can hide behind a variable or a class constant.
- Import placement: inserting `use SpeedPuzzling\Web\Tests\TestClock;` right after the namespace passes PHPCS as is.

## Log

- 2026-10-22: plan written (README.md, implementation-plan.md). Branch `time-and-clock` from `8447c607d`.
- 2026-10-22: Phase 0 - baseline green; frozen-clock experiment (160 failures / 77 files, 1 framework mismatch),
  +400 days under libfaketime (41 more, 1 framework mismatch), Panther with an 8-day-old and a year-old instant; UTC
  audit incl. read-only prod check; SQL and PHP clock lists; test recipe. Experiment reverted. Four questions for Jan.
- 2026-10-22: Phase 1 - UTC-on-write `datetime_immutable` type + API v1 `finishedAt` converted at the call site;
  type and configuration tests; Jan's answer 5 (keep both, no test-only listener).
- 2026-10-22: Phase 2 - SQL :now from the clock (7 sites + NOT_SECRET in 11 statements), class-(a) PHP + Twig on the
  clock (`now()`), browser-judged times on real time, `DatabaseClockCoverageTest` + `SystemClockCoverageTest`; suite
  green (11521).
- 2026-10-22: Phase 3 - `clock` frozen at `TestClock::FROZEN_NOW` in the test container, `TestClock` helpers + test, DB
  cache keyed by the instant, fixtures already clock-derived, XP weekly-boost test back on a fixed week; suite red by
  design (157 tests / 75 files, all test data on the real clock), Panther green (22); Phase 4 re-planned in 9 batches.
- 2026-10-22: Phase 4 batch 1 - shared helpers, Microsoft token double, Security/Entity/Component/ConsoleCommands/
  EventSubscriber/Value/Results on the frozen clock (55 files); OAuth2/Apple/Redis-TTL doubles and cookie assertions stay on
  real time with reasons; suite 157 → 103 failing, nothing new red.
- 2026-10-22: Phase 4 batch 2 - `MessageHandler` A-O + `OfficialResults` + `ParticipantsSheet` on the frozen clock (47 files);
  suite 103 → 92 failing, nothing new red.
