# Time and the clock: one clock, UTC everywhere, frozen in tests

Status: plan of record 2026-10-22 (Jan: "time is now, carefully prepare plan"). Progress: `STATUS.md`. Phases with the
prompt for each: `implementation-plan.md`.

## Why

On 2026-10-22 CI went red on `XpChainRecomputerTest::testWeeklyBoostStopsAfterFiveSolvesAndResetsNextWeek` without a
code change. The test pins a fixed week (Aug 24-30, 2026); a fixture solve (`EventDetailFixture::TIME_SPRINT_1`) is
dated ~59 days before *the real now* and on that day drifted into the week, taking one of its five boosted slots. It
passed locally because the local test DB had been built a day earlier. The test was patched (`8447c607d`, week moved to
`+2 years`) - a patch, not a fix: every test that compares now-relative fixtures with fixed dates is a time bomb, and
a result depends on the day the cached test DB was built.

Three sources of "now" exist today, and they can disagree:

1. PHP's `ClockInterface` (Symfony `clock` service) - most code uses it.
2. PHP's system clock read directly (`new DateTimeImmutable()`, `'-3 days'`, `time()`, `date()`) - ~138 places in `src/`.
3. PostgreSQL's clock (`NOW()`, `CURRENT_DATE`, …) - ~9 places in `src/`, ~400 lines in `tests/`.

## Goal (the rules once this ships)

- **R1 - one clock.** Application code reads the time only through `Psr\Clock\ClockInterface`. Never the system clock,
  never the database's clock. Exceptions (durations measured with `hrtime()`/`microtime()`, libraries we do not own)
  are listed with a reason in a guard test.
- **R2 - SQL gets "now" from PHP.** No `NOW()`, `CURRENT_DATE`, `CURRENT_TIME`, `CURRENT_TIMESTAMP`, `LOCALTIME`,
  `LOCALTIMESTAMP`, `clock_timestamp()`, `statement_timestamp()`, `transaction_timestamp()`, one-argument `age(x)`,
  `'now'::…`/`'today'::…` literals in our SQL. It binds `:now` (and derived values like `:today`, `:weekStart`)
  computed from the clock in PHP. The codebase already does this in ~290 places; this closes the rest.
- **R3 - UTC in the database, a zone only on output.** Every timestamp is stored as UTC: `datetime_immutable` columns
  (no zone) hold UTC wall time, `datetimetz` columns are absolute anyway. PHP's default zone and the DB session's
  `TimeZone` are pinned to UTC (today they are UTC only by default - verified on prod 2026-10-22: PHP `UTC`, Postgres
  `Etc/UTC`). A player's or an event's zone is applied when formatting, never when storing.
- **R4 - tests run at a frozen instant.** In the test env the clock is a `MockClock` frozen at
  `TestClock::FROZEN_NOW`. Fixtures and tests derive every date from it (`TestClock::now()->modify('-3 days')`) or use
  absolute literals. A test may move its own clock (`MockClock::modify()`/`sleep()`); nothing reads the real date.
  The cached test DB never goes stale by date, and the suite gives the same result on any day.
- **R5 - guarded.** Guard tests (like the repo's other `*CoverageTest`s) fail on a new violation of R1/R2/R4, each
  exception listed with its reason; one test pins R3 (PHP + DB session zone).

## Decisions (proposed - Phase 0 verifies, Jan's call where marked)

- **D1 - frozen instant:** `2026-10-14 12:00:00 UTC` (a Wednesday: mid-week, mid-month, midday, so no week/month/day
  boundary in any zone ±12 h; no DST switch in Europe/Prague (Oct 25) or the US (Nov 1) within ±10 days; after every
  date constant the fixtures already pin, e.g. `XpSettingsFixture::FULL_FORMULA_FROM` 2026-08-01). Phase 0 checks the
  date constants in `src/` (`NEXT_SEASON_STARTS_ON`, `PAGE_LAST_REBUILT_AT`, `FORMULA_CHANGES`, feature switches,
  `KARMA…`) and in fixtures, and moves the instant if one of them sits badly.
- **D2 - what gets frozen:** the `clock` service (global, Symfony's `Clock`) is replaced in `config/services_test.php`,
  so the app and every framework service that reads `clock` agree. Phase 0 lists the framework consumers (Messenger,
  Security access tokens, Validator, Scheduler, web value resolvers, rate limiter, login links / remember-me /
  signed URLs, sessions, league/oauth2-server, lcobucci/jwt) and finds what reads the real time anyway - those are the
  risk: a token or cookie minted at the frozen instant and checked against the real time (or the other way round).
  Panther's browser keeps real time: cookie expiry is computed from the frozen clock - Phase 0 checks remember-me and
  session cookies survive (expiry far enough after the *real* date, or Panther gets its own handling).
- **D3 - pinning UTC:** PHP `date.timezone=UTC` in the image's ini (Dockerfile) + CI; DB session `SET TIME ZONE 'UTC'`
  on connect (DBAL middleware or DSN `options`), whichever costs no statement per request in worker mode. Guard test
  asserts both.
- **D4 - naive columns get UTC on write (Jan's call if Phase 0 finds stored non-UTC data).** Doctrine's
  `datetime_immutable` type formats the object in *its own* zone, so a `DateTimeImmutable` in Europe/Prague persisted
  into a naive column stores Prague wall time. Phase 0 audits every path that builds a non-UTC `DateTime` (event/round
  times "no offset = the country's zone", `event_time()`, the player's time zone, form date fields with a `view_timezone`,
  API input with offsets) and whether it reaches an entity. Proposed fix: override the `datetime_immutable` (and `date`?)
  DBAL type with one that converts to UTC before formatting - a one-place guarantee. If Phase 0 finds rows already
  stored in non-UTC wall time, the data fix is a separate, reviewed step (Jan decides).
- **D5 - proof:** besides the guard tests, run the whole suite once under libfaketime with the real date shifted
  (+400 days and -30 days); it must pass unchanged. Phase 0 checks libfaketime in the base image (local arm64, CI
  amd64); optional weekly CI job.

## Inventory (2026-10-22, regenerate with the commands below)

SQL clock in `src/` (real ones, PHP `->now()` excluded):

```
src/ConsoleCommands/ExpireMutesCommand.php      messaging_muted_until < NOW()
src/MessageHandler/MarkMessagesAsReadHandler.php  SET read_at = NOW()
src/MessageHandler/PrepareDigestEmailForPlayerHandler.php  sent_at > NOW() - INTERVAL …
src/Query/GetAchievementHolders.php             ) > NOW()
src/Query/GetXpLeaderboard.php                  ) > NOW()
src/Services/PuzzleIntelligence/StatisticalInputsSql.php  NOT_SECRET: hide_until <= NOW() (a const, used in many queries)
src/Services/PuzzleIntelligence/PaceHistoryReadiness.php  built_at = NOW()
```

```bash
grep -rnE -i "(^|[^>:a-zA-Z_])NOW\(\)|CURRENT_DATE|CURRENT_TIME|LOCALTIME|clock_timestamp\(|statement_timestamp\(|transaction_timestamp\(|\bage\([^,()]*\)|'(now|today|tomorrow|yesterday)'::" src
grep -rnE "new \\\\?DateTime(Immutable)?\(\)|new \\\\?DateTime(Immutable)?\('(now|today|tomorrow|yesterday|[+-][0-9]|first|last|next|midnight|noon)[^']*'|\btime\(\)|\bdate\('|\bmicrotime\(|\bhrtime\(|\bstrtotime\(" src   # ~138
grep -rlE "(^|[^>:a-zA-Z_])NOW\(\)|CURRENT_DATE|CURRENT_TIMESTAMP" tests | wc -l                        # 205 files, ~403 lines
grep -rnE "new \\\\?DateTime(Immutable)?\(\)|new \\\\?DateTime(Immutable)?\('(now|today|[+-][0-9]|…)" tests | wc -l   # ~709 lines, 250 files
grep -rlE "clock->now\(\)|daysAgo|modify\('[+-]" tests/DataFixtures | wc -l                               # 45 of 53 fixtures
```

`StatisticalInputsSql::NOT_SECRET` is a string constant spliced into many queries; it becomes a fragment that needs
`:now` bound by every caller (or a method taking the parameter name) - Phase 2 handles every caller.

Schema: 178 `DATETIME_IMMUTABLE`, 23 `DATETIMETZ_IMMUTABLE`, 7 `DATE_IMMUTABLE` columns. No `DEFAULT NOW()` in
migrations' current schema (verify in Phase 0).

## Out of scope

- Browser-side time (JS `Date.now()`, relative labels) - the browser's clock is the visitor's.
- Redis TTLs, Messenger delays and HTTP cache headers measured by other processes - they live on real time by nature;
  Phase 0 only checks that tests do not depend on them disagreeing with the frozen clock.
- How a player's zone is chosen for output (`docs/features/player-timezone.md`) - unchanged.
