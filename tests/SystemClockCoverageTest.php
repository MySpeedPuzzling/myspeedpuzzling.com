<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * One clock (docs/features/time-and-clock/README.md R1): application code reads the time only through the injected
 * Psr\Clock\ClockInterface, templates through now() (ClockTwigExtension) - so a test can freeze it. Every file whose
 * code (comments aside) reads the system clock - `new DateTimeImmutable()`, a relative string like '-3 days' or
 * 'now', time(), date(), strtotime(), microtime(), hrtime(), Clock::get(), a NativeClock; in templates `'now'|date`
 * and `date()` - fails this test, unless that kind of read is listed for that file with the reason (a listed file
 * still fails on any other kind of read; a listed read that is gone fails as stale).
 *
 * The listed exceptions (Jan, 2026-10-22): what the browser or another process judges by its own clock reads real
 * time on purpose - a cookie's expiry, the page's msp-rendered-at, a rate limiter's retry-after (the limiter counts
 * on microtime()), the bot-blocker's issued-at, health probes. Durations are measured, not dated. Parsing a given value
 * is no clock - where the code can say so without a system-clock function it does (`new DateTimeImmutable('@' . $ts)`).
 *
 * The test suite follows the same rule (R4): the app's clock is frozen at TestClock::FROZEN_NOW, so a test takes its
 * moments from TestClock (or the container's clock) or writes them as absolute literals. A test file may read real time
 * only where the code under test, a library or the browser judges it on real time - listed per file and per kind of read,
 * so a new kind of read in a listed file still fails. A relative moment ('-3 days', 'tomorrow') in a test goes straight
 * into ->modify() - also through a helper whose parameter does - so a constant or a variable holding one cannot end up
 * counted from the real now; the few other ones (inputs, expected texts) are listed.
 */
final class SystemClockCoverageTest extends TestCase
{
    private const string COOKIE = 'Cookie expiry - the browser expires it on its own (real) clock.';
    private const string RATE_LIMIT = 'Rate-limiter retry-after - the limiter counts on the system clock (microtime()).';
    private const string DURATION = 'Measures a duration (microtime(true) / hrtime()) - no date is read.';
    private const string PROBE = 'Health probe - answers with the server\'s real time.';
    private const string RENDERED_AT = 'msp-rendered-at is real time (the browser compares it with the server\'s Date header), so is its reader.';
    private const string PATTERN_DATA = 'A guard - the clock reads it must recognise are data.';

    /**
     * PHP files under src/ allowed to read the system clock: the hits allowed (as this test prints them) and the reason
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const array ALLOWED = [
        'Api/V1/PuzzleSearchResponseProvider.php' => [['time('], self::RATE_LIMIT],
        'ConsoleCommands/BackfillPaceHistoryConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/CanonicalizePuzzleCodesConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/DateAchievementsFromHistoryConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/DetectBoxTextMismatchesConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/FitPredictionModelConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/RecalculateCommunityStatsConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/RecalculateInsightsDailyConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/RecalculateKarmaConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/RefreshPuzzleTagsConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/RerankRatingSnapshotsConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/SnapshotPlayerInsightsConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/VerifyProgressionConsoleCommand.php' => [['microtime('], self::DURATION],
        'ConsoleCommands/XpBackfillConsoleCommand.php' => [['microtime('], self::DURATION],
        'Controller/HealthCheckContainerController.php' => [['time('], self::PROBE],
        'Controller/HealthCheckLivenessController.php' => [['time('], self::PROBE],
        'Controller/OnlineRounds/OnlineWatchTokenController.php' => [['time('], self::RATE_LIMIT],
        'Controller/SocialRegisterSignInController.php' => [['time('], self::COOKIE],
        'EventSubscriber/ApiUsageSubscriber.php' => [['hrtime('], self::DURATION],
        'EventSubscriber/BotTrustCookieSubscriber.php' => [['new DateTimeImmutable()'], 'The bot-blocker sidecar validates the cookie\'s issued-at on its own clock, the browser its expiry.'],
        'EventSubscriber/NativeAuthPageSubscriber.php' => [['time('], self::COOKIE],
        'EventSubscriber/ReferralCookieSubscriber.php' => [['new \\DateTimeImmutable(\'+'], self::COOKIE],
        'EventSubscriber/SlidingLoginCookiesSubscriber.php' => [['time('], self::COOKIE],
        'EventSubscriber/StaleLiveComponentPageSubscriber.php' => [['new DateTimeImmutable(\'+'], self::COOKIE],
        'Security/LoginFormAuthenticator.php' => [['time('], self::RATE_LIMIT],
        'Services/AssetLoadFailureClassifier.php' => [['time('], 'Page age against msp-rendered-at, which is real time (the browser compares it with the server\'s Date header).'],
        'Services/BotTrustCookieSigner.php' => [['new DateTimeImmutable()'], 'The bot-blocker sidecar validates the issued-at on its own clock.'],
        'Services/GenerateManufacturerSlug.php' => [['microtime('], 'microtime() as entropy for a slug, not as a time.'],
        'Services/LiveKit/StudioSpectators.php' => [['microtime('], 'A lock-wait deadline in microtime(true) - a duration, no date is read.'],
        'Services/OnlineRounds/OnlineParticipantApi.php' => [['time('], self::RATE_LIMIT],
        'Services/PuzzleIntelligence/ImprovementRatioCalculator.php' => [['strtotime('], 'strtotime() of two stored solve moments in the batch\'s hot loop - parses given values, reads no clock.'],
        'Services/PuzzleIntelligence/Model/PredictionEvaluator.php' => [['microtime('], self::DURATION],
        'Services/PuzzleIntelligence/PredictionModelRecalculator.php' => [['microtime('], self::DURATION],
        'Services/PuzzleIntelligence/PuzzleIntelligenceRecalculator.php' => [['microtime('], self::DURATION],
        'Services/SecretRevealPreview.php' => [['\'now\''], 'The word \'now\' as a token of a hash (a reveal without a moment), no clock read.'],
        'Twig/ClockTwigExtension.php' => [['\'now\''], 'Registers the Twig function now() - backed by the clock.'],
    ];

    /** Templates allowed to read the system clock, with the reason */
    private const array ALLOWED_TEMPLATES = [
        'base.html.twig' => 'msp-rendered-at - the browser compares it with the server\'s Date header, real time on both sides.',
    ];

    /**
     * Files under tests/ allowed to read the system clock: the hits allowed (as this test prints them) and the reason.
     * null = every hit (the file is a guard whose clock reads are data).
     *
     * @var array<string, array{0: list<string>|null, 1: string}>
     */
    private const array ALLOWED_IN_TESTS = [
        'Controller/AssetLoadFailureControllerTest.php' => [['time('], self::RENDERED_AT],
        'Controller/LiveResults/CompetitionNameTagsTest.php' => [['hrtime('], self::DURATION],
        'Controller/RegisterControllerTest.php' => [['time('], 'The REMEMBERME cookie\'s expiry - remember-me mints it with time(), the browser judges it on its own clock.'],
        'Controller/StaleDocumentControllerTest.php' => [['time('], self::RENDERED_AT],
        'DatabaseClockCoverageTest.php' => [null, self::PATTERN_DATA],
        'EventSubscriber/BotTrustCookieSubscriberTest.php' => [['microtime('], 'The bot-blocker cookie\'s issued-at - the sidecar validates it on its own clock.'],
        'EventSubscriber/NativeAuthPageLocaleTest.php' => [['time('], self::COOKIE],
        'OAuth2TestHelper.php' => [['new DateTimeImmutable()', 'new DateTimeImmutable(\'+'], 'league/oauth2-server judges its access tokens on its own clock (lcobucci SystemClock), never the app\'s.'],
        'Panther/Extension/PantherDatabaseManager.php' => [['microtime('], 'microtime() as entropy for a database name, not as a time.'],
        'RelativeTimeParityTest.php' => [['\'now\''], 'The label "now" the relative-time formatter prints - an expected text, no clock read.'],
        'RoundRevealMomentComputedOnlyHereTest.php' => [null, self::PATTERN_DATA],
        'Security/RememberMeTest.php' => [['time('], self::COOKIE],
        'Services/AssetLoadFailureClassifierTest.php' => [['time('], self::RENDERED_AT],
        'Services/Doctrine/MigrationLockTest.php' => [['microtime('], self::DURATION],
        'Services/Insights/InsightsBuilderTest.php' => [['hrtime('], self::DURATION],
        'Services/Insights/InsightsFramesBudgetTest.php' => [['hrtime('], self::DURATION],
        'Services/Insights/InsightsLazyPartsBudgetTest.php' => [['hrtime('], self::DURATION],
        'Services/LiveKit/StudioSpectatorsTest.php' => [['microtime('], self::DURATION],
        'Services/RateLimiting/RedisSlidingWindowLimiterTest.php' => [['microtime(', 'new NativeClock('], 'Forked processes start together at a real moment (time_sleep_until), and the limiter counts on real time.'],
        'Services/Session/PostgresSessionHandlerTest.php' => [['hrtime('], self::DURATION],
        'Services/Storage/ObjectStorageHttpClientFactoryTest.php' => [['microtime('], self::DURATION],
        'SystemClockCoverageTest.php' => [null, self::PATTERN_DATA],
        'TestClockTest.php' => [['time('], 'Checks that the frozen instant lies before the real date of the run (TestClock\'s rule).'],
        'TestDouble/InMemoryStudioInviteSignal.php' => [['time('], 'Stands in for a Redis TTL, which Redis counts on its own clock.'],
        'TestingDatabaseCaching.php' => [['time('], 'The age of the test database cache file - a file system time.'],
    ];

    private const string INPUT = 'A string sent as user or API input on purpose - the code under test refuses it as a date.';
    private const string STOPWATCH_TABLE = 'An inline table of round states - the test hands each moment to setStopwatch(), which applies it with TestClock::now()->modify().';

    /**
     * Relative moments under tests/ that do not go straight into ->modify() (testRelativeMomentsInTestsAreAppliedToTheFrozenClock),
     * with the reason
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const array RELATIVE_LITERALS_ALLOWED = [
        'Controller/DetailPagesJsonLdTest.php' => [["'+2 days'"], 'The expected text of a DateInterval (%R%a days) between two given dates.'],
        'Controller/InternalApi/RoundsInternalApiTest.php' => [["'tomorrow'"], self::INPUT],
        'Controller/OnlineRounds/OnlineRoundSettingsControllerTest.php' => [["'+1 hour'", "'-5 minutes'", "'-90 minutes'", "'-30 minutes'"], self::STOPWATCH_TABLE],
        'Controller/StaleDocumentControllerTest.php' => [["'yesterday'"], self::INPUT],
        'DatePickerScriptTest.php' => [["'today'"], 'flatpickr\'s maxDate option - the browser judges it on the visitor\'s clock.'],
        'MessageHandler/OnlineRoundSetUpHandlersTest.php' => [["'+1 hour'", "'-5 minutes'", "'-90 minutes'", "'-30 minutes'"], self::STOPWATCH_TABLE],
        'OAuth2TestHelper.php' => [["'+1 hour'"], 'league/oauth2-server judges its access tokens on its own clock (lcobucci SystemClock), never the app\'s.'],
        'RelativeTimeParityTest.php' => [["'now'", "'59 seconds ago'", "'1 minute ago'", "'1 hour ago'", "'27 days ago'"], 'Labels the relative-time formatter prints - expected texts, no moment.'],
        'Services/Insights/InsightsCriteriaTest.php' => [["'yesterday'"], self::INPUT],
        'Value/ComparisonCriteriaTest.php' => [["'yesterday'"], self::INPUT],
        'Value/PuzzleImageAnalysisInputTest.php' => [["'yesterday'"], self::INPUT],
        'Value/PuzzlePickerCriteriaTest.php' => [["'-6 months'", "'-2 weeks'", "'-30 days'"], 'What PuzzlePickerSinceUnit::modifier() returns - expected values; the code under test applies them to the app\'s clock.'],
    ];

    private const array PATTERNS = [
        // new DateTime(Immutable)() and every relative string ('now', 'today', '-3 days', 'first day of this month' …)
        '~new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*\)~i',
        '~new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*([\'"])(?:now|today|tomorrow|yesterday|midnight|noon|first\b|last\b|next\b|this\b|[+-])~i',
        // A call: lower case, `(` right after the name (prose in a string - "a date (Y-m-d)" - is no call), not after
        // `->`, `::`, `$` or a word character ($input->date() or a method date() is no clock)
        '~(?<![\w>:$])\\\\?(?:time|date|gmdate|idate|getdate|localtime|mktime|gmmktime|strtotime|date_create|date_create_immutable|microtime|hrtime|gettimeofday)\(~',
        '~Clock::get\s*\(~',
        '~new\s+\\\\?(?:\w+\\\\)*(?:NativeClock|DatePoint)\s*\(~',
        // 'now' anywhere else (a default, `?? 'now'`) - except as the name of a statement's :now parameter
        '~(?<!\[)(?<!setParameter\()\'now\'(?!\s*=>)~i',
    ];

    /**
     * Tests only: a MockClock started at the real now or a relative moment (`new MockClock()` is the real now), and
     * relative moments built by sprintf() (NewsletterSignupControllerTest hid one that way)
     */
    private const array TEST_PATTERNS = [
        '~new\s+\\\\?(?:\w+\\\\)*MockClock\s*\(\s*(?:\)|([\'"])(?:now|today|tomorrow|yesterday|midnight|noon|first\b|last\b|next\b|this\b|[+-]))~i',
        // sprintf('-%d seconds', …) - a relative moment counted from the real now; sprintf('first monday of october %d', $year) names a day
        '~new\s+\\\\?(?:(?:\w+\\\\)*MockClock|DateTime(?:Immutable)?)\s*\(\s*sprintf\s*\(\s*([\'"])(?:now|today|tomorrow|yesterday|[+-])~i',
    ];

    private const array TEMPLATE_PATTERNS = [
        '~[\'"]now[\'"]\s*\|\s*date~i',
        // Twig's date() function without a value, or with a relative one - date('2026-09-21') parses a given value
        '~(?<![\w.|])date\s*\(\s*\)~',
        '~(?<![\w.|])date\s*\(\s*[\'"](?:now|today|tomorrow|yesterday|midnight|noon|first\b|last\b|next\b|this\b|[+-])~i',
    ];

    public function testApplicationCodeReadsTheTimeFromTheClock(): void
    {
        [$offending, $stale] = self::compareWithAllowed((string) realpath(__DIR__ . '/../src'), self::PATTERNS, self::ALLOWED);

        self::assertSame([], $offending, 'These files read the system clock. Inject Psr\Clock\ClockInterface instead (an entity or value object takes the moment from its caller), or list the read here with the reason.');
        self::assertSame([], $stale, 'These listed reads no longer happen - remove them from the list.');
    }

    public function testTestsReadTheTimeFromTheFrozenClock(): void
    {
        [$offending, $stale] = self::compareWithAllowed((string) realpath(__DIR__), [...self::PATTERNS, ...self::TEST_PATTERNS], self::ALLOWED_IN_TESTS);

        self::assertSame([], $offending, 'These tests read the real clock, while the app\'s clock is frozen. Use TestClock::now() (->modify(…)), TestClock::timestamp() / milliseconds(), new MockClock(TestClock::now()) or an absolute literal - or list the read here with the reason.');
        self::assertSame([], $stale, 'These listed reads no longer happen - remove them from the list.');
    }

    public function testTemplatesReadTheTimeFromTheClock(): void
    {
        $offending = [];
        $stale = self::ALLOWED_TEMPLATES;
        $root = (string) realpath(__DIR__ . '/../templates');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            // Twig comments aside
            $code = (string) preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents($file->getPathname()));
            $hits = self::hits(self::TEMPLATE_PATTERNS, $code);

            if ($hits === []) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::ALLOWED_TEMPLATES) === false) {
                $offending[] = $relative . ': ' . implode(', ', $hits);
            }
        }

        sort($offending);

        self::assertSame([], $offending, 'These templates read the system clock. Use now() (the app\'s clock), or list the template here with the reason.');
        self::assertSame([], array_keys($stale), 'These templates no longer read the system clock - remove them from the list.');
    }

    public function testThePatternsCatchTheSystemClockAndNothingElse(): void
    {
        foreach (
            [
            '$now = new DateTimeImmutable();',
            '$now = new \DateTime();',
            "\$at = new \\DateTimeImmutable('+7 days');",
            "\$at = new DateTimeImmutable('first day of this month');",
            "\$at = new \\DateTimeImmutable('+' . self::LIFETIME_DAYS . ' days');",
            "\$at = new DateTimeImmutable('now', \$zone);",
            'return (int) date(\'Y\');',
            '$expires = time() + 60;',
            '$started = microtime(true);',
            '$started = hrtime(true);',
            '$now = Clock::get()->now();',
            '$clock = new NativeClock();',
            "\$at = \$row['at'] ?? 'now';",
            ] as $code
        ) {
            self::assertNotSame([], self::hits(self::PATTERNS, $code), $code);
        }

        foreach (
            [
            '$now = $this->clock->now();',
            "\$at = new DateTimeImmutable('@' . \$timestamp);",
            "\$at = new DateTimeImmutable(\$row['at']);",
            "\$at = new DateTimeImmutable('2026-10-14 12:00:00');",
            "\$from = \$input->date('dateFrom');",
            "'now' => \$this->clock->now()->format('Y-m-d H:i:s'),",
            "\$params['now'] = \$now;",
            'public function time(): string',
            "\$query->setParameter('now', \$now);",
            "\$this->addOption('until', null, InputOption::VALUE_REQUIRED, 'A date (Y-m-d)');",
            ] as $code
        ) {
            self::assertSame([], self::hits(self::PATTERNS, $code), $code);
        }

        foreach (
            [
            'new MockClock()',
            "new MockClock('-1 day')",
            "new \\Symfony\\Component\\Clock\\MockClock('now')",
            "new MockClock(sprintf('-%d seconds', \$secondsAgo))",
            "new DateTimeImmutable(sprintf('-%d days', \$days))",
            'new DateTimeImmutable("-{$days} days")',
            ] as $code
        ) {
            self::assertNotSame([], self::hits([...self::PATTERNS, ...self::TEST_PATTERNS], $code), $code);
        }

        foreach (
            [
            'new MockClock(TestClock::now())',
            "new MockClock('2026-10-14 12:00:00')",
            "new MockClock(TestClock::now()->modify(sprintf('-%d seconds', \$secondsAgo)))",
            "TestClock::now()->modify('-3 days')",
            "new DateTimeImmutable(sprintf('first monday of october %d', \$year))",
            ] as $code
        ) {
            self::assertSame([], self::hits([...self::PATTERNS, ...self::TEST_PATTERNS], $code), $code);
        }

        foreach (["{{ 'now'|date('Y') }}", '{% if date() < limit %}', "{% set d = date('-1 day') %}"] as $code) {
            self::assertNotSame([], self::hits(self::TEMPLATE_PATTERNS, $code), $code);
        }

        foreach (["{{ now()|date('Y') }}", "{{ end|date('Y') }}", "{% if now() < date('2026-09-21T00:00:00+02:00') %}", 'data-ev-group="now"'] as $code) {
            self::assertSame([], self::hits(self::TEMPLATE_PATTERNS, $code), $code);
        }
    }

    public function testRelativeMomentsInTestsAreAppliedToTheFrozenClock(): void
    {
        $root = (string) realpath(__DIR__);
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }

        // Helpers anywhere under tests/ (SpotlightSeeding::seed(registeredAt: …) is called from other files)
        $helpers = [];

        foreach ($files as $path) {
            foreach (self::modifyingHelpers(self::significantTokens((string) file_get_contents($path))) as $name => $definitions) {
                foreach ($definitions as $definition) {
                    $helpers[$name][] = $definition;
                }
            }
        }

        $offending = [];
        $stale = [];

        foreach ($files as $relative => $path) {
            $allowed = self::RELATIVE_LITERALS_ALLOWED[$relative][0] ?? [];
            $hits = self::relativeLiteralHits((string) file_get_contents($path), $helpers);

            foreach (array_diff($allowed, $hits) as $gone) {
                $stale[] = $relative . ': ' . $gone;
            }

            $notAllowed = array_unique(array_diff($hits, $allowed));

            if ($notAllowed !== []) {
                $offending[] = $relative . ': ' . implode(', ', $notAllowed);
            }
        }

        foreach (array_keys(self::RELATIVE_LITERALS_ALLOWED) as $relative) {
            if (array_key_exists($relative, $files) === false) {
                $stale[] = $relative;
            }
        }

        sort($offending);
        sort($stale);

        self::assertSame([], $offending, 'A relative moment (\'-3 days\', \'tomorrow\', …) that is not handed straight to ->modify() - or to a helper passing its parameter straight to ->modify() - may end up counted from the real now (a constant, a variable, new DateTimeImmutable($relative)). Write TestClock::now()->modify(…) where it is defined, or list it here with the reason.');
        self::assertSame([], $stale, 'These listed relative moments no longer occur - remove them from the list.');
    }

    public function testTheRelativeMomentCheckFollowsModifyAndNothingElse(): void
    {
        foreach (
            [
            "private const string AGE = '-3 days';",
            '$at = \'yesterday\';',
            "\$this->seed('-1 day');",
            "TestClock::now()->modify(\$young ? '-1 day' : '-9 days');",
            "\$at = new DateTimeImmutable(\$x)->modify('+1 hour' . \$suffix);",
            "\$when = sprintf('-%d minutes', \$n);",
            'private function at(string $m = \'+1 hour\'): DateTimeImmutable { return new DateTimeImmutable($m); }',
            "private function at(string \$m): DateTimeImmutable { return TestClock::now()->modify(\$m); }\n\$this->at(\$x, '-1 day');",
            ] as $code
        ) {
            self::assertNotSame([], self::relativeLiteralHits('<?php ' . $code, []), $code);
        }

        foreach (
            [
            "TestClock::now()->modify('-3 days');",
            "\$clock->modify('+10 minutes');",
            "TestClock::now()->modify(sprintf('-%d seconds', \$age));",
            "\$params = ['now' => \$now, 'today' => \$today];",
            "\$at = TestClock::now()->modify(\"-{\$days} days\");",
            "private function at(string \$m = '+0 seconds'): DateTimeImmutable { return TestClock::now()->modify(\$m); }\n\$this->at('-1 day'); self::at(m: '+2 hours'); \$this->at(sprintf('+%d days', 3));",
            "\$label = 'Today';",
            "\$at = new DateTimeImmutable('2026-10-14 12:00:00');",
            ] as $code
        ) {
            self::assertSame([], self::relativeLiteralHits('<?php ' . $code, []), $code);
        }
    }

    /**
     * The relative moments of one file that do not go straight into ->modify(), as `'<literal>'`. A literal is fine as
     * the argument of ->modify(…) (also wrapped in sprintf('<literal>', …)), as the argument of a helper whose parameter
     * goes straight into ->modify() (the file's own helper of that name first, else every helper of that name under
     * tests/), as the default of such a parameter, or as an array key.
     *
     * @param array<string, list<array<int|string, true>>> $helpers name => what each definition of it passes to ->modify()
     * @return list<string>
     */
    private static function relativeLiteralHits(string $code, array $helpers): array
    {
        $tokens = self::significantTokens($code);
        $own = self::modifyingHelpers($tokens);
        $hits = [];

        foreach (self::stringLiterals($tokens) as [$start, $end, $content]) {
            if (self::isRelativeMoment($content) === false || self::text($tokens[$end + 1] ?? '') === '=>') {
                continue;
            }

            if (self::isDefaultOfModifiedParameter($tokens, $start, $own) || self::goesIntoModify($tokens, $start, $end, $own + $helpers)) {
                continue;
            }

            $hits[] = "'" . $content . "'";
        }

        return $hits;
    }

    /**
     * '-3 days', '+1 hour', 'today 08:00', 'now', 'tomorrow', 'next monday', 'first day of next month', '-%d days' …
     * - lower case, as code writes them (a label says "Today")
     */
    private static function isRelativeMoment(string $content): bool
    {
        $unit = '(?:secs?|seconds?|mins?|minutes?|hours?|days?|weekdays?|weeks?|fortnights?|months?|years?)';
        $number = '(?:\d+|%[dsu]|\{?\$\w+\}?)';
        $weekday = '(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)';
        $part = "(?:now|today|tomorrow|yesterday|midnight|noon|[+-]\\s*{$number}\\s*{$unit}|{$number}\\s*{$unit}\\s+ago"
            . "|(?:next|last|this|previous)\\s+(?:{$weekday}|{$unit})|(?:first|last)\\s+day\\s+of(?:\\s+(?:next|last|this|previous)\\s+(?:month|year))?)";

        return preg_match("~^{$part}(?:\\s+{$part})*(?:\\s+\\d{1,2}:\\d{2}(?::\\d{2})?)?$~", trim($content)) === 1;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, list<array<int|string, true>>> $helpers
     */
    private static function goesIntoModify(array $tokens, int $start, int $end, array $helpers): bool
    {
        // At most: modify(sprintf('<literal>', …)) / helper(sprintf('<literal>', …))
        for ($depth = 0; $depth < 2; $depth++) {
            if (in_array(self::text($tokens[$end + 1] ?? ''), [',', ')'], true) === false) {
                return false;
            }

            $named = null;
            $before = $start - 1;

            if (self::text($tokens[$before] ?? '') === ':' && self::isName($tokens[$before - 1] ?? null)) {
                $named = '$' . self::text($tokens[$before - 1]);
                $before -= 2;
            }

            if (in_array(self::text($tokens[$before] ?? ''), ['(', ','], true) === false) {
                return false;
            }

            // The call's opening bracket and the argument's position
            $position = 0;
            $nesting = 0;
            $open = $before;

            for (; $open >= 0; $open--) {
                $text = self::text($tokens[$open]);

                if (in_array($text, [')', ']', '}'], true)) {
                    $nesting++;
                } elseif (in_array($text, ['(', '[', '{'], true)) {
                    if ($nesting === 0) {
                        break;
                    }

                    $nesting--;
                } elseif ($text === ',' && $nesting === 0) {
                    $position++;
                }
            }

            if ($open < 1 || self::text($tokens[$open]) !== '(' || self::isName($tokens[$open - 1]) === false) {
                return false;
            }

            $function = strtolower(self::text($tokens[$open - 1]));

            if ($function === 'sprintf' && $position === 0 && $named === null) {
                $start = $open - 1;
                $end = self::closingBracket($tokens, $open);

                continue;
            }

            if ($function === 'modify') {
                return $position === 0 && $named === null && self::isObjectOperator($tokens[$open - 2] ?? null);
            }

            foreach ($helpers[$function] ?? [] as $flows) {
                if (isset($flows[$named ?? $position]) === false) {
                    return false;
                }
            }

            return isset($helpers[$function]);
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, list<array<int|string, true>>> $own
     */
    private static function isDefaultOfModifiedParameter(array $tokens, int $start, array $own): bool
    {
        $variable = $tokens[$start - 2] ?? null;

        if (self::text($tokens[$start - 1] ?? '') !== '=' || is_array($variable) === false || $variable[0] !== T_VARIABLE) {
            return false;
        }

        $parameter = $variable[1];

        // A parameter list: `function name(` before it, at the same bracket depth
        $nesting = 0;

        for ($i = $start - 3; $i >= 2; $i--) {
            $text = self::text($tokens[$i]);

            if ($text === ')') {
                $nesting++;
            } elseif ($text === '(') {
                if ($nesting === 0) {
                    $function = $tokens[$i - 2];
                    $name = strtolower(self::text($tokens[$i - 1]));

                    return is_array($function) && $function[0] === T_FUNCTION
                        && array_filter($own[$name] ?? [], static fn (array $flows): bool => isset($flows[$parameter])) !== [];
                }

                $nesting--;
            } elseif (in_array($text, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Functions passing a parameter straight into ->modify($parameter): name => per definition, the parameter's
     * position and its name
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, list<array<int|string, true>>>
     */
    private static function modifyingHelpers(array $tokens): array
    {
        $helpers = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) === false || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameAt = self::text($tokens[$i + 1] ?? '') === '&' ? $i + 2 : $i + 1;

            if (self::isName($tokens[$nameAt] ?? null) === false || self::text($tokens[$nameAt + 1] ?? '') !== '(') {
                continue;
            }

            $open = $nameAt + 1;
            $close = self::closingBracket($tokens, $open);
            $parameters = [];
            $position = 0;
            $nesting = 0;

            for ($k = $open + 1; $k < $close; $k++) {
                $text = self::text($tokens[$k]);

                if (in_array($text, ['(', '['], true)) {
                    $nesting++;
                } elseif (in_array($text, [')', ']'], true)) {
                    $nesting--;
                } elseif ($text === ',' && $nesting === 0) {
                    $position++;
                } elseif ($nesting === 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE && isset($parameters[$position]) === false) {
                    $parameters[$position] = $tokens[$k][1];
                }
            }

            $body = $close + 1;

            while ($body < $count && in_array(self::text($tokens[$body]), ['{', ';'], true) === false) {
                $body++;
            }

            if ($body >= $count || self::text($tokens[$body]) !== '{') {
                continue;
            }

            $flows = [];
            $bodyEnd = self::closingBracket($tokens, $body);

            for ($k = $body; $k < $bodyEnd; $k++) {
                $argument = $tokens[$k + 3] ?? null;

                if (
                    self::isObjectOperator($tokens[$k])
                    && strtolower(self::text($tokens[$k + 1] ?? '')) === 'modify'
                    && self::text($tokens[$k + 2] ?? '') === '('
                    && is_array($argument) && $argument[0] === T_VARIABLE
                    && self::text($tokens[$k + 4] ?? '') === ')'
                ) {
                    $position = array_search($argument[1], $parameters, true);

                    if ($position !== false) {
                        $flows[$position] = true;
                        $flows[$argument[1]] = true;
                    }
                }
            }

            $helpers[strtolower(self::text($tokens[$nameAt]))][] = $flows;
        }

        return $helpers;
    }

    /**
     * Every string literal: [first token, last token, content] - a double-quoted one with its variables written as
     * they stand ("-{$days} days" stays "-{$days} days")
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function stringLiterals(array $tokens): array
    {
        $literals = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = [$i, $i, substr($token[1], 1, -1)];
            } elseif ($token === '"') {
                $content = '';
                $end = $i + 1;

                while ($end < $count && $tokens[$end] !== '"') {
                    $content .= self::text($tokens[$end]);
                    $end++;
                }

                $literals[] = [$i, $end, $content];
                $i = $end;
            }
        }

        return $literals;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function significantTokens(string $code): array
    {
        return array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => is_string($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true) === false,
        ));
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function closingBracket(array $tokens, int $open): int
    {
        $nesting = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = self::text($token);

            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $nesting++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $nesting--;

                if ($nesting === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function text(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /**
     * @param null|array{0: int, 1: string, 2: int}|string $token
     */
    private static function isName(null|array|string $token): bool
    {
        return is_array($token) && $token[0] === T_STRING;
    }

    /**
     * @param null|array{0: int, 1: string, 2: int}|string $token
     */
    private static function isObjectOperator(null|array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /**
     * Every PHP file under $root against its allowlist: a read that is not listed for its file is offending, a listed
     * read (or file) that no longer occurs is stale. null = every read (the file is a guard whose reads are data).
     *
     * @param array<string> $patterns
     * @param array<string, array{0: list<string>|null, 1: string}> $allowedReads
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function compareWithAllowed(string $root, array $patterns, array $allowedReads): array
    {
        $offending = [];
        $stale = [];
        $seen = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $hits = self::hits($patterns, self::code($file->getPathname()));

            if ($hits === []) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $seen[$relative] = true;
            $allowed = array_key_exists($relative, $allowedReads) ? $allowedReads[$relative][0] : [];

            if ($allowed === null) {
                continue;
            }

            foreach (array_diff($allowed, $hits) as $gone) {
                $stale[] = $relative . ': ' . $gone;
            }

            $notAllowed = array_unique(array_diff($hits, $allowed));

            if ($notAllowed !== []) {
                $offending[] = $relative . ': ' . implode(', ', $notAllowed);
            }
        }

        foreach (array_keys($allowedReads) as $relative) {
            if (array_key_exists($relative, $seen) === false) {
                $stale[] = $relative;
            }
        }

        sort($offending);
        sort($stale);

        return [$offending, $stale];
    }

    /**
     * @param array<string> $patterns
     * @return list<string>
     */
    private static function hits(array $patterns, string $code): array
    {
        // A declared function or method named time() / date() is no call
        $code = (string) preg_replace('~\bfunction\s+&?\w+~i', 'function', $code);
        $hits = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $code, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $hits[] = trim($match);
                }
            }
        }

        return $hits;
    }

    /**
     * The file without comments - a comment mentioning time() reads nothing.
     */
    private static function code(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
