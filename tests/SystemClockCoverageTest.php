<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * One clock (docs/features/time-and-clock/README.md R1): application code reads the time only through the injected
 * Psr\Clock\ClockInterface, templates through now() (ClockTwigExtension) - so a test can freeze it. Every file whose
 * code (comments aside) reads the system clock - `new DateTimeImmutable()`, a relative string like '-3 days' or
 * 'now', time(), date(), strtotime(), microtime(), hrtime(), Clock::get(), a NativeClock; in templates `'now'|date`
 * and `date()` - fails this test, or is listed here with the reason.
 *
 * The listed exceptions (Jan, 2026-10-22): what the browser or another process judges by its own clock reads real
 * time on purpose - a cookie's expiry, the page's msp-rendered-at, a rate limiter's retry-after (the limiter counts
 * on microtime()), the bot-blocker's issued-at, health probes. Durations are measured, not dated. Parsing a given value
 * is no clock - where the code can say so without a system-clock function it does (`new DateTimeImmutable('@' . $ts)`).
 *
 * The test suite follows the same rule (R4): the app's clock is frozen at TestClock::FROZEN_NOW, so a test takes its
 * moments from TestClock (or the container's clock) or writes them as absolute literals. A test file may read real time
 * only where the code under test, a library or the browser judges it on real time - listed per file and per kind of read,
 * so a new kind of read in a listed file still fails.
 */
final class SystemClockCoverageTest extends TestCase
{
    private const string COOKIE = 'Cookie expiry - the browser expires it on its own (real) clock.';
    private const string RATE_LIMIT = 'Rate-limiter retry-after - the limiter counts on the system clock (microtime()).';
    private const string DURATION = 'Measures a duration (microtime(true) / hrtime()) - no date is read.';
    private const string PROBE = 'Health probe - answers with the server\'s real time.';
    private const string RENDERED_AT = 'msp-rendered-at is real time (the browser compares it with the server\'s Date header), so is its reader.';
    private const string PATTERN_DATA = 'A guard - the clock reads it must recognise are data.';

    /** PHP files under src/ allowed to read the system clock, with the reason */
    private const array ALLOWED = [
        'Api/V1/PuzzleSearchResponseProvider.php' => self::RATE_LIMIT,
        'ConsoleCommands/BackfillPaceHistoryConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/CanonicalizePuzzleCodesConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/DateAchievementsFromHistoryConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/DetectBoxTextMismatchesConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/FitPredictionModelConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/RecalculateCommunityStatsConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/RecalculateInsightsDailyConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/RecalculateKarmaConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/RefreshPuzzleTagsConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/RerankRatingSnapshotsConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/SnapshotPlayerInsightsConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/VerifyProgressionConsoleCommand.php' => self::DURATION,
        'ConsoleCommands/XpBackfillConsoleCommand.php' => self::DURATION,
        'Controller/HealthCheckContainerController.php' => self::PROBE,
        'Controller/HealthCheckLivenessController.php' => self::PROBE,
        'Controller/OnlineRounds/OnlineWatchTokenController.php' => self::RATE_LIMIT,
        'Controller/SocialRegisterSignInController.php' => self::COOKIE,
        'EventSubscriber/ApiUsageSubscriber.php' => self::DURATION,
        'EventSubscriber/BotTrustCookieSubscriber.php' => 'The bot-blocker sidecar validates the cookie\'s issued-at on its own clock, the browser its expiry.',
        'EventSubscriber/NativeAuthPageSubscriber.php' => self::COOKIE,
        'EventSubscriber/ReferralCookieSubscriber.php' => self::COOKIE,
        'EventSubscriber/SlidingLoginCookiesSubscriber.php' => self::COOKIE,
        'EventSubscriber/StaleLiveComponentPageSubscriber.php' => self::COOKIE,
        'Security/LoginFormAuthenticator.php' => self::RATE_LIMIT,
        'Services/AssetLoadFailureClassifier.php' => 'Page age against msp-rendered-at, which is real time (the browser compares it with the server\'s Date header).',
        'Services/BotTrustCookieSigner.php' => 'The bot-blocker sidecar validates the issued-at on its own clock.',
        'Services/GenerateManufacturerSlug.php' => 'microtime() as entropy for a slug, not as a time.',
        'Services/LiveKit/StudioSpectators.php' => 'A lock-wait deadline in microtime(true) - a duration, no date is read.',
        'Services/OnlineRounds/OnlineParticipantApi.php' => self::RATE_LIMIT,
        'Services/PuzzleIntelligence/ImprovementRatioCalculator.php' => 'strtotime() of two stored solve moments in the batch\'s hot loop - parses given values, reads no clock.',
        'Services/PuzzleIntelligence/Model/PredictionEvaluator.php' => self::DURATION,
        'Services/PuzzleIntelligence/PredictionModelRecalculator.php' => self::DURATION,
        'Services/PuzzleIntelligence/PuzzleIntelligenceRecalculator.php' => self::DURATION,
        'Services/SecretRevealPreview.php' => 'The word \'now\' as a token of a hash (a reveal without a moment), no clock read.',
        'Twig/ClockTwigExtension.php' => 'Registers the Twig function now() - backed by the clock.',
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
        $offending = [];
        $stale = self::ALLOWED;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $hits = self::hits(self::PATTERNS, self::code($file->getPathname()));

            if ($hits === []) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::ALLOWED) === false) {
                $offending[] = $relative . ': ' . implode(', ', $hits);
            }
        }

        sort($offending);

        self::assertSame([], $offending, 'These files read the system clock. Inject Psr\Clock\ClockInterface instead (an entity or value object takes the moment from its caller), or list the file here with the reason.');
        self::assertSame([], array_keys($stale), 'These files no longer read the system clock - remove them from the list.');
    }

    public function testTestsReadTheTimeFromTheFrozenClock(): void
    {
        $offending = [];
        $stale = [];
        $seen = [];
        $root = (string) realpath(__DIR__);

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $hits = self::hits([...self::PATTERNS, ...self::TEST_PATTERNS], self::code($file->getPathname()));

            if ($hits === []) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $seen[$relative] = true;
            $allowed = array_key_exists($relative, self::ALLOWED_IN_TESTS) ? self::ALLOWED_IN_TESTS[$relative][0] : [];

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

        foreach (array_keys(self::ALLOWED_IN_TESTS) as $relative) {
            if (array_key_exists($relative, $seen) === false) {
                $stale[] = $relative;
            }
        }

        sort($offending);
        sort($stale);

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
