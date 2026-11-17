<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioSignals;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioStateBuilder;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioWatch;

/**
 * The studio signal's timing contract (analytics-studio.md R23, R25): the page asks for the state SIGNAL_SETTLE_MS after
 * a signal - never before the server's state cache (AnalyticsStudioStateBuilder::TTL_SECONDS) can have dropped what it
 * computed before the signalled commit, + 300 ms; the watch marker outlives the slowest fallback poll twice over.
 */
final class AnalyticsStudioSignalTimingTest extends TestCase
{
    private const string MODULE = __DIR__ . '/../assets/analytics-studio/refresh.js';

    public function testTheSignalSettlesAfterTheStateCacheExpired(): void
    {
        self::assertGreaterThanOrEqual(AnalyticsStudioStateBuilder::TTL_SECONDS * 1000 + 300, self::constant('SIGNAL_SETTLE_MS'));
    }

    public function testTheMarkerOutlivesTwoFallbackPolls(): void
    {
        // The live fallback poll while the stream is live: 30 s + 20 %
        self::assertSame(30_000, self::constant('STREAMED_LIVE_POLL_MS'));
        self::assertGreaterThan(2 * intdiv(self::constant('STREAMED_LIVE_POLL_MS') * 6, 5000), AnalyticsStudioWatch::WATCH_TTL_SECONDS);
    }

    public function testThePageKnowsTheSignalsType(): void
    {
        $source = (string) file_get_contents(self::MODULE);

        self::assertStringContainsString("export const SIGNAL_TYPE = '" . AnalyticsStudioSignals::TYPE . "';", $source);
    }

    private static function constant(string $name): int
    {
        $source = (string) file_get_contents(self::MODULE);

        if (preg_match('/export const ' . $name . ' = ([\d_]+(?: \* [\d_]+)*);/', $source, $match) !== 1) {
            self::fail($name . ' not found in refresh.js');
        }

        return (int) array_product(array_map(static fn (string $factor): int => (int) str_replace('_', '', $factor), explode(' * ', $match[1])));
    }
}
