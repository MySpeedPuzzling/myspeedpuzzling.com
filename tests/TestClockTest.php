<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;

/**
 * The frozen clock of the suite (docs/features/time-and-clock/README.md, R4)
 */
final class TestClockTest extends KernelTestCase
{
    public function testTheContainerClockStandsAtTheFrozenInstant(): void
    {
        self::bootKernel();

        $now = self::getContainer()->get(ClockInterface::class)->now();

        self::assertEquals(TestClock::now(), $now);
        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    public function testATestMovingTheClockDoesNotMoveItForTheNextKernel(): void
    {
        self::bootKernel();
        $clock = self::getContainer()->get(ClockInterface::class);
        $clock->sleep(3 * 24 * 3600); // a MockClock moves on at once
        self::assertEquals(TestClock::now()->modify('+3 days'), $clock->now());

        self::ensureKernelShutdown();
        self::bootKernel();

        self::assertEquals(TestClock::now(), self::getContainer()->get(ClockInterface::class)->now());
    }

    public function testTheSqlLiteralsNameTheFrozenInstant(): void
    {
        self::assertSame("TIMESTAMP '" . TestClock::now()->format('Y-m-d H:i:s') . "'", TestClock::SQL_NOW);
        self::assertSame("DATE '" . TestClock::now()->format('Y-m-d') . "'", TestClock::SQL_TODAY);
        self::assertSame(TestClock::now()->format('Y-m-d H:i:s'), TestClock::sqlParameter());
        self::assertSame(TestClock::timestamp() * 1000, TestClock::milliseconds());
    }

    public function testTheFrozenInstantLiesInThePastOfTheRun(): void
    {
        // Symfony mints sign-in links with time() and the app checks them on the clock: the instant must never be ahead
        // of the real date (docs/features/time-and-clock/STATUS.md, D1)
        self::assertLessThan(time(), TestClock::timestamp());
    }
}
