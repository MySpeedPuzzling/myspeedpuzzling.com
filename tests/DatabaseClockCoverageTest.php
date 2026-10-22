<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * SQL never asks the database for the time (docs/features/time-and-clock/README.md R2): "now" comes from the app's
 * clock as a bound parameter (`CAST(:now AS timestamp)` with `'now' => $this->clock->now()->format('Y-m-d H:i:s')`),
 * so a test's frozen clock and the data it compares agree. Every file whose code (comments aside) uses NOW(),
 * CURRENT_DATE / CURRENT_TIME / CURRENT_TIMESTAMP, LOCALTIME / LOCALTIMESTAMP, clock_timestamp(),
 * statement_timestamp(), transaction_timestamp(), timeofday(), a one-argument age() or a 'now' / 'today' literal cast
 * fails this test. Application code has no exception.
 *
 * The test suite follows the same rule (R4): Postgres keeps the real time while the app's clock is frozen at
 * TestClock::FROZEN_NOW, so test SQL uses TestClock::SQL_NOW / SQL_TODAY (or binds TestClock::sqlParameter()).
 */
final class DatabaseClockCoverageTest extends TestCase
{
    private const array PATTERNS = [
        // Without arguments, not after `->`, `::`, `$` or a word character: the PHP clock's ->now() is not SQL
        '~(?<![\w>:$\\\\])(?:now|clock_timestamp|statement_timestamp|transaction_timestamp|timeofday)\(\s*\)~i',
        '~(?<![\w>:$\\\\])(?:current_date|current_time|current_timestamp|localtime|localtimestamp)(?!\w)~i',
        '~\'(?:now|today|tomorrow|yesterday)\'\s*::~i',
        // age(x) = age(current_date, x); the two-argument form compares two given values
        '~(?<![\w>:$\\\\])age\(\s*[^,()]*\)~i',
    ];

    /**
     * Files under tests/ whose SQL-clock words are data, not SQL run against the database, with the reason
     */
    private const array ALLOWED_IN_TESTS = [
        'DatabaseClockCoverageTest.php' => 'This guard - the patterns and their self-test are data.',
        'RoundRevealMomentComputedOnlyHereTest.php' => 'A guard of its own - SQL snippets it must recognise are data.',
        'SystemClockCoverageTest.php' => 'A guard of its own - the patterns and their self-test are data.',
    ];

    public function testSqlReadsTheTimeFromTheClock(): void
    {
        $offending = [];

        foreach (self::filesWithHits(__DIR__ . '/../src') as $relative => $hits) {
            $offending[] = $relative . ': ' . implode(', ', $hits);
        }

        self::assertSame([], $offending, 'These files read the database\'s clock. Bind :now from the injected ClockInterface instead.');
    }

    public function testTestSqlReadsTheTimeFromTheFrozenClock(): void
    {
        $offending = [];
        $stale = self::ALLOWED_IN_TESTS;

        foreach (self::filesWithHits(__DIR__) as $relative => $hits) {
            unset($stale[$relative]);

            if (array_key_exists($relative, self::ALLOWED_IN_TESTS) === false) {
                $offending[] = $relative . ': ' . implode(', ', $hits);
            }
        }

        self::assertSame([], $offending, 'These tests read the database\'s clock, which is not frozen. Use TestClock::SQL_NOW / TestClock::SQL_TODAY (or bind TestClock::sqlParameter()) instead.');
        self::assertSame([], array_keys($stale), 'These files no longer read the database\'s clock - remove them from the list.');
    }

    public function testThePatternsCatchTheDatabaseClockAndNothingElse(): void
    {
        self::assertNotSame([], self::hits("SELECT 1 WHERE a < NOW()"));
        self::assertNotSame([], self::hits("SET read_at = now()"));
        self::assertNotSame([], self::hits("WHERE day = CURRENT_DATE"));
        self::assertNotSame([], self::hits("WHERE at > current_timestamp - INTERVAL '1 day'"));
        self::assertNotSame([], self::hits("WHERE at > 'now'::timestamp"));
        self::assertNotSame([], self::hits("WHERE age(p.created_at) > INTERVAL '1 year'"));
        self::assertNotSame([], self::hits("SELECT clock_timestamp()"));

        self::assertSame([], self::hits('$now = $this->clock->now();'));
        self::assertSame([], self::hits('public function now(): DateTimeImmutable'));
        self::assertSame([], self::hits("'An age (\"6 months\") counted back from now (the clock)'"));
        self::assertSame([], self::hits("'now' => \$this->clock->now()->format('Y-m-d H:i:s')"));
        self::assertSame([], self::hits('throw new InvalidLocalTime();'));
        self::assertSame([], self::hits('WHERE age(a, b) > INTERVAL \'1 year\''));
        self::assertSame([], self::hits('WHERE at < CAST(:now AS timestamp)'));
    }

    /**
     * @return array<string, list<string>> path relative to $directory => hits, sorted by path
     */
    private static function filesWithHits(string $directory): array
    {
        $files = [];
        $root = (string) realpath($directory);

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $hits = self::hits(self::code($file->getPathname()));

            if ($hits !== []) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $hits;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function hits(string $code): array
    {
        // A declared function or method named now() is no SQL
        $code = (string) preg_replace('~\bfunction\s+&?\w+~i', 'function', $code);
        $hits = [];

        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $code, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $hits[] = trim($match);
                }
            }
        }

        return $hits;
    }

    /**
     * The file without comments - the SQL lives in strings, a comment mentioning NOW() reads nothing.
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
