<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * The automatic reveal is the round's own delay after its start - the one rule PHP and SQL share
 * (SecretPuzzleRevealInvariantTest checks both against the database).
 */
final class RoundPuzzleRevealMomentTest extends TestCase
{
    private const string START = '2026-10-24 00:05:00';

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function delays(): iterable
    {
        yield 'when the round starts' => [0, '2026-10-24 00:05:00'];
        yield 'one minute' => [1, '2026-10-24 00:06:00'];
        yield 'the default' => [RoundPuzzleReveal::DEFAULT_DELAY_MINUTES, '2026-10-24 00:15:00'];
        yield 'custom' => [25, '2026-10-24 00:30:00'];
        yield 'the longest' => [RoundPuzzleReveal::MAX_DELAY_MINUTES, '2026-10-24 04:05:00'];
    }

    #[DataProvider('delays')]
    public function testAutomaticRevealIsTheRoundsDelayAfterItsStart(int $delay, string $expected): void
    {
        $start = new DateTimeImmutable(self::START);

        self::assertEquals(new DateTimeImmutable($expected), RoundPuzzleReveal::Automatic->revealAt($start, $delay, null));
        self::assertEquals(new DateTimeImmutable($expected), RoundPuzzleReveal::automaticRevealAt($start, $delay));
    }

    public function testScheduledAndManualIgnoreTheDelay(): void
    {
        $start = new DateTimeImmutable(self::START);
        $own = new DateTimeImmutable('2026-10-25 18:00:00');

        foreach ([0, 10, 25, RoundPuzzleReveal::MAX_DELAY_MINUTES] as $delay) {
            self::assertSame($own, RoundPuzzleReveal::Scheduled->revealAt($start, $delay, $own));
            self::assertNull(RoundPuzzleReveal::Manual->revealAt($start, $delay, $own));
        }
    }

    public function testSqlReadsTheDelayFromTheRoundRow(): void
    {
        $sql = RoundPuzzleReveal::sqlRevealAt('crp', 'cr');

        self::assertStringContainsString('cr.starts_at + make_interval(mins => cr.reveal_delay_minutes)', $sql);
        self::assertStringNotContainsString(' minutes\'', $sql);
        self::assertStringContainsString($sql, RoundPuzzleReveal::sqlHidden('crp', 'cr'));
    }

    public function testTheDelayIsWholeMinutesFromZeroToMax(): void
    {
        self::assertTrue(RoundPuzzleReveal::isValidDelay(0));
        self::assertTrue(RoundPuzzleReveal::isValidDelay(RoundPuzzleReveal::MAX_DELAY_MINUTES));
        self::assertFalse(RoundPuzzleReveal::isValidDelay(-1));
        self::assertFalse(RoundPuzzleReveal::isValidDelay(RoundPuzzleReveal::MAX_DELAY_MINUTES + 1));

        foreach ([-1, RoundPuzzleReveal::MAX_DELAY_MINUTES + 1] as $invalid) {
            try {
                RoundPuzzleReveal::assertValidDelay($invalid);
                self::fail(sprintf('%d minutes is no reveal delay', $invalid));
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        RoundPuzzleReveal::automaticRevealAt(new DateTimeImmutable(self::START), -5);
    }
}
