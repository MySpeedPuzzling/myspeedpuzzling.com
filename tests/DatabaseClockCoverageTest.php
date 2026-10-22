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
 * fails this test. There is no exception today; should one ever be needed, add an allowlist `path => reason` like
 * SystemClockCoverageTest's.
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

    public function testSqlReadsTheTimeFromTheClock(): void
    {
        $offending = [];
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $hits = self::hits(self::code($file->getPathname()));

            if ($hits !== []) {
                $offending[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . implode(', ', $hits);
            }
        }

        sort($offending);

        self::assertSame([], $offending, 'These files read the database\'s clock. Bind :now from the injected ClockInterface instead.');
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
