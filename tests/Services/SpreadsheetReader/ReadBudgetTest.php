<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\SpreadsheetReader\CpuClock;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx\ReadBudget;

/**
 * The reader's seconds are the process's own CPU time (docs/features/data-import/reader.md "Why 60 s of CPU and 300 s
 * on the wall"): waiting costs nothing of the budget, work does - and the wall clock is the last resort.
 */
final class ReadBudgetTest extends TestCase
{
    public function testTimeThatIsNoCpuTimeIsNotCounted(): void
    {
        $budget = new ReadBudget(1_000_000, 150_000_000);

        $budget->enter();
        // A busy server, a slow disk: 0.3 s go by and the reader computes nothing
        usleep(300_000);

        self::assertGreaterThan(290_000_000, $budget->wallNanoseconds());
        self::assertLessThan(100_000_000, $budget->nanoseconds());
        self::assertFalse($budget->outOfTime());

        // … and work is: 0.2 s of it are over a budget of 0.15 s
        $this->work(200_000_000);

        self::assertGreaterThan(150_000_000, $budget->nanoseconds());
        self::assertTrue($budget->outOfTime());

        $budget->leave();
        $spent = $budget->nanoseconds();
        $this->work(20_000_000);

        // What happens after the reader was left is the caller's
        self::assertSame($spent, $budget->nanoseconds());
    }

    public function testTheWallClockIsTheLastResort(): void
    {
        $budget = new ReadBudget(1_000_000, 60_000_000_000, 100_000_000);

        $budget->enter();
        self::assertFalse($budget->outOfTime());
        usleep(150_000);

        self::assertLessThan(60_000_000_000, $budget->nanoseconds());
        self::assertTrue($budget->outOfTime());
        $budget->leave();
    }

    public function testTheCpuClockCountsWorkAndNotWaiting(): void
    {
        $before = CpuClock::nanoseconds();
        usleep(200_000);
        $waited = CpuClock::nanoseconds() - $before;
        $this->work(100_000_000);
        $worked = CpuClock::nanoseconds() - $before - $waited;

        self::assertLessThan(100_000_000, $waited);
        self::assertGreaterThanOrEqual(100_000_000, $worked);
    }

    /**
     * Burns CPU time until the process has used this much more of it.
     */
    private function work(int $nanoseconds): void
    {
        $until = CpuClock::nanoseconds() + $nanoseconds;
        $sum = 0;

        while (CpuClock::nanoseconds() < $until) {
            for ($i = 0; $i < 10_000; $i++) {
                $sum += $i % 7;
            }
        }

        self::assertGreaterThan(0, $sum);
    }
}
