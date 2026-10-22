<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerPaceHistoryCalculator;
use Symfony\Component\Clock\MockClock;

final class PlayerPaceHistoryCalculatorTest extends TestCase
{
    /**
     * 0-second times give a zero baseline: no step (no log of zero), never a failed batch.
     */
    public function testAZeroBaselineHasNoStep(): void
    {
        $solves = [];

        foreach ([0, 0, 0, 0, 0, 0, 1200, 1200, 1200, 1200, 1200, 1200, 1200] as $index => $seconds) {
            $solves[] = ['id' => 'time-' . $index, 'seconds' => $seconds, 'solve_date' => sprintf('2026-01-%02d 00:00:00', $index + 1), 'tracked_at' => sprintf('2026-01-%02d 12:00:00', $index + 1)];
        }

        $calculator = new PlayerPaceHistoryCalculator(new PlayerBaselineCalculator($this->createStub(Connection::class), new MockClock(TestClock::now())));
        $rows = $calculator->rows($solves, PlayerPaceHistoryCalculator::checksums($solves), 5);

        self::assertSame(0, $rows[0]['baseline_seconds']);
        self::assertSame(1200, $rows[array_key_last($rows)]['baseline_seconds']);

        foreach ($rows as $row) {
            if ($row['baseline_seconds'] === 0) {
                self::assertNull($row['step']);
            }
        }

        $zeroToTime = array_values(array_filter($rows, static fn (array $row): bool => $row['baseline_seconds'] === 1200))[0];
        self::assertNull($zeroToTime['step'], 'The step from a zero baseline');
    }
}
