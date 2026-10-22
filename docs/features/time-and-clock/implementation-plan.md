# Time and the clock - implementation plan

Design and decisions: `README.md`. Progress and findings: `STATUS.md` (every phase reads it first and appends to it).

## How every phase works

- **Where:** branch `time-and-clock`, worktree `/Users/janmikes/www/speedpuzzling.cz/.claude/worktrees/time-and-clock`
  (`W` below). Never edit the shared checkout `/Users/janmikes/www/speedpuzzling.cz` (other sessions work there), never
  push to `main`, never run migrations against the dev DB. Generate migrations with the command if one is needed (none
  is expected).
- **Running PHP:** the dev stack's containers mount the shared checkout, not `W`. Run the base image on the compose
  network with `W` at `/app` and the shared checkout's `vendor`, `node_modules`, `public/build` read-only, and a test DB
  of its own (the name must end in `_test`, the bootstrap drops and rebuilds it):

  ```bash
  M=/Users/janmikes/www/speedpuzzling.cz; W=$M/.claude/worktrees/time-and-clock
  docker run --rm --network speedpuzzlingcz_default -v $W:/app -v $M/vendor:/app/vendor:ro \
    -v $M/node_modules:/app/node_modules:ro -v $M/public/build:/app/public/build:ro -w /app \
    -e APP_ENV=test -e "DATABASE_URL=postgresql://postgres:postgres@postgres:5432/speedpuzzling_clock_test?serverVersion=16&charset=utf8" \
    --entrypoint php ghcr.io/myspeedpuzzling/web-base-php85:main vendor/bin/phpunit <file or --testsuite "Project Test Suite">
  ```
  Write the command out each time (zsh does not word-split a variable). ParaTest the same way with
  `vendor/bin/paratest --testsuite "Project Test Suite"` (it leaves `speedpuzzling_clock_test_N` DBs behind - fine).
  PHPStan: run `APP_ENV=dev php bin/console cache:warmup` in the same kind of container first, then
  `composer run phpstan` (entrypoint `composer`). CS: `composer run cs-fix` then `composer run cs`.
  Git always on the host (`git -C $W …`), never inside the container.
- **Gates before a phase is done:** phpstan, cs, the full `Project Test Suite` (paratest), `doctrine:schema:validate`
  (dev env), `cache:warmup`. A failing test is either drift (an assertion pinned old behaviour - fix the test and say
  so) or a regression (fix the code). Report both lists.
- **Commits:** one or more commits on `time-and-clock` per phase, explicit paths (`git -C $W commit -m … -- <paths>`),
  message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Push the branch
  (`git -C $W push -u origin time-and-clock`) after each phase so the work is safe. No PR until Phase 5.
- **Stop and ask** (write the question into `STATUS.md` → "Open questions for Jan" and end the phase) when: stored data
  would have to change (D4), a behaviour visible to players would change, or a decision in `README.md` turns out wrong.
- **Code style:** read `CLAUDE.md`; match the surrounding code, comments explain why in the repo's tone, single-action
  controllers, messages for state changes, `ClockInterface` injected (never `Clock::get()` statics in `src/`).
- **End of a phase:** update `STATUS.md` (done, findings, numbers, what the next phase must know), commit it, push.

## Phase 0 - verify the decisions (investigation, small experiments, no production code)

Goal: turn D1-D5 in `README.md` into facts; produce the lists the later phases work from.

1. **Date constants (D1):** list every hard-coded date in `src/` and `tests/DataFixtures` (grep `'20[0-9]{2}-`,
   `DateTimeImmutable('20`) with what it switches. Confirm `2026-10-14 12:00:00 UTC` or propose another instant.
2. **Framework clock consumers (D2):** `debug:container --show-arguments` (test env) for every service receiving
   `clock`; read how Security (login link `SignatureHasher`, remember-me, `security.access_token`), CSRF, sessions
   (`PostgresSessionHandler`), RateLimiter, Messenger (delays, retries, `DelayedEmailQueue`), league/oauth2-server,
   lcobucci/jwt, Stripe webhook signature checks, `SnsMessageVerifier`, `UriSigner`, the photo-stash presigner get
   their time. Experiment: in `W`, freeze `clock` in `config/services_test.php` (MockClock at the D1 instant) and run
   the suite once; classify the failures (expected now-relative test data vs. framework mismatch). Revert the
   experiment afterwards (it lands properly in Phase 3). Check Panther separately (`--group panther` on 2-3 tests) for
   cookie expiry.
3. **UTC (D3/D4):** confirm PHP + DB session zone in dev, CI (`.github/workflows/test.yml` services) and prod (read-only
   `SHOW TimeZone` on lily is already known: `Etc/UTC`). Find how to pin both without a per-request statement.
   Audit every place a non-UTC `DateTime`/`DateTimeImmutable` is created (`new DateTimeZone(`, `setTimezone(`,
   `view_timezone`/`model_timezone` in form types, `DateTimeImmutable::createFromFormat` with offsets, API input) and
   trace whether it can reach a `datetime_immutable`/`date_immutable` column un-normalised. For any such path, query
   **prod read-only** (lily, see memory `reference_production_access`) whether rows look shifted (e.g. round
   `starts_at` vs its event's local schedule). Do not change data.
4. **SQL clock in `src/` (R2):** the full list with the replacement for each, including all callers of
   `StatisticalInputsSql::NOT_SECRET`, `DEFAULT NOW()` in the current schema (`pg_attrdef` on the test DB).
5. **PHP clock bypasses in `src/` (R1):** classify the ~138 hits: (a) reads "now" → must use the clock, (b) parses a
   given value (fine), (c) measures a duration (`hrtime`/`microtime` - fine), (d) library/edge (reason). Write the
   classified list into `STATUS.md` (file:line, class, note).
6. **Tests:** sample 10 test files with SQL `NOW()` and 10 with `new DateTimeImmutable(...)`; describe the mechanical
   rewrite (helpers needed in `tests/`, e.g. `TestClock::now()`, `TestClock::sql()` for a `:now` parameter) and
   estimate batches for Phase 4.
7. **libfaketime (D5):** can it run in `ghcr.io/myspeedpuzzling/web-base-php85:main` (arm64 locally, amd64 CI)?
   Try `apt-get install faketime` in a throwaway container; does PHP + `pdo_pgsql` honour it?

Deliverable: `STATUS.md` → "Phase 0 findings" with each decision confirmed/changed, the classified lists, open
questions. Commit + push. **Then stop** - the orchestrator reads it before Phase 1.

## Phase 1 - pin UTC; UTC on write (production code)

1. PHP `date.timezone=UTC` in the Dockerfile's ini (and wherever CI runs PHP outside the image - the PHPCS job does not
   need it). DB session zone pinned per Phase 0's choice.
2. If Phase 0 confirmed D4 (and Jan agreed where data is affected): the UTC-normalising DBAL type(s), registered for
   `datetime_immutable` (+ `date_immutable` if relevant) in `config/packages/doctrine.php`; unit test (a Prague-zone
   object round-trips as the same instant; read values come back in UTC).
3. Guard test `TimezoneConfigurationTest`: PHP default zone is UTC; `SHOW TimeZone` on the app's connection is UTC.
4. Gates, commit, push, `STATUS.md`.

## Phase 2 - one clock in `src/` (production code, behaviour-neutral)

1. Every SQL clock in `src/` → bound `:now` from the injected `ClockInterface` (format like the neighbouring queries -
   check whether they pass a `DateTimeImmutable` with `Types::DATETIME_IMMUTABLE` or a `'Y-m-d H:i:s'` string, and keep
   what the file already does). `StatisticalInputsSql::NOT_SECRET` and all its callers.
2. Every class-(a) PHP bypass → `ClockInterface` (constructor injection; for value objects/entities, pass the time in
   from the caller - entities never read a clock).
3. Guard tests: `DatabaseClockCoverageTest` (R2 over `src/`) and `SystemClockCoverageTest` (R1 over `src/`), each
   with an allowlist `path => reason` like `SuspiciousTimeQueryCoverageTest`. Make them scan `src/` only for now; Phase
   4 extends them to `tests/`.
4. Gates (the real clock still runs in tests, so the suite must stay green as is), commit, push, `STATUS.md`.

## Phase 3 - the frozen clock in tests (infrastructure)

1. `tests/TestClock.php`: `FROZEN_NOW` (D1 instant, UTC), `now(): DateTimeImmutable`, and whatever helpers Phase 0
   proposed (e.g. a SQL-literal/parameter helper).
2. `config/services_test.php`: `clock` → `MockClock(TestClock::now())` (or the narrower override Phase 0 chose), a new
   instance per kernel so a test moving its clock cannot leak into the next one. Remove the tests' ad-hoc
   `set(ClockInterface::class, new MockClock(...))` only where they now duplicate the frozen instant - keep the ones
   that set a different moment on purpose.
3. Fixtures: every `DataFixtures` class derives dates from the injected clock (now frozen) or absolute literals; no
   `new DateTimeImmutable()`/relative strings/`time()`. `tests/bootstrap.php`: the DB cache hash includes
   `TestClock::FROZEN_NOW` (a moved instant rebuilds the DB).
4. Revert the `+2 years` patch in `XpChainRecomputerTest` (`8447c607d`) to a week derived from the frozen instant or a
   fixed week - with fixtures frozen, a fixed week is stable.
5. Run the suite: failures now are tests whose own data is still on the real clock - that is Phase 4's list. Record
   the failing files in `STATUS.md` grouped by directory. Commit + push the infrastructure even if the suite is red
   at this point (the branch is not merged before Phase 5).

## Phase 4 - tests on the frozen clock (mechanical, in batches)

Batches by directory, one batch per agent run, in this order (re-measure in Phase 0/3):
`tests/MessageHandler`, `tests/Query`, `tests/Controller`, `tests/Services`, everything else (root-level `*Test.php`,
`*Seeding.php`, `Component`, `Api`, `ConsoleCommands`, `Panther`).

Per batch: replace `new DateTimeImmutable()` and relative strings with `TestClock::now()->modify(…)` (or the container
clock where a test moves time), SQL `NOW()`/`CURRENT_*` with a bound frozen value, `time()` with
`TestClock::now()->getTimestamp()`. Keep each test's meaning: "3 days ago" stays "3 days before now". Never weaken an
assertion to get green - if a test only passed by luck of the real date, fix the data and say so in `STATUS.md`.
Gates for the batch's files + the full suite at the end of each batch. Commit per batch, push, `STATUS.md`.

Last batch: extend `DatabaseClockCoverageTest` and `SystemClockCoverageTest` to `tests/` (allowlist with reasons, e.g.
a test of `NativeClock` itself).

## Phase 5 - proof, docs, PR

1. Full gates. Then the suite under libfaketime at real date +400 days and -30 days (D5) - must pass unchanged; if
   libfaketime is not workable, say so and why.
2. Optional (Jan's call): weekly CI job running the suite under a shifted date.
3. `CLAUDE.md`: replace the "Use `ClockInterface`" bullet with R1-R4 in two or three lines + link to `README.md`; the
   test section mentions `TestClock`. `docs/TODO.md`: follow-ups found on the way.
4. Open the PR from `time-and-clock` (body: what, why with the 2026-10-22 incident, the rules, the guards, the
   faketime proof, drift vs regression list, anything for Jan). Do not merge.
