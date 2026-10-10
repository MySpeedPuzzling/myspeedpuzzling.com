<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Digest;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Digest\DigestPacing;

final class DigestPacingTest extends TestCase
{
    public function testTwelveThousandRecipientsSpreadOverTheWholeWindow(): void
    {
        // The Monday cron at 07:01 Prague: the window has 11 h 59 min left
        $plan = (new DigestPacing())->plan(12_000, $this->prague('2026-10-19 07:01'));

        self::assertSame(0, $plan['firstDelayMs']);
        self::assertSame(3595, $plan['spacingMs']);
        // The last one leaves before 19:00
        self::assertLessThanOrEqual((11 * 3600 + 59 * 60) * 1000, 11_999 * $plan['spacingMs']);
    }

    public function testARunBeforeTheWindowWaitsForItsStart(): void
    {
        $plan = (new DigestPacing())->plan(100, $this->prague('2026-10-19 06:00'));

        self::assertSame(3_600_000, $plan['firstDelayMs']);
        self::assertSame(432_000, $plan['spacingMs'], '12 h / 100');
    }

    public function testARunAfterTheWindowSendsAtTheSlowestAllowedPace(): void
    {
        $plan = (new DigestPacing())->plan(500, $this->prague('2026-10-19 20:00'));

        self::assertSame(0, $plan['firstDelayMs']);
        self::assertSame(DigestPacing::MIN_SPACING_MS, $plan['spacingMs']);
    }

    public function testNeverCloserThanTheMinimumSpacing(): void
    {
        $plan = (new DigestPacing())->plan(100_000, $this->prague('2026-10-19 18:00'));

        self::assertSame(DigestPacing::MIN_SPACING_MS, $plan['spacingMs']);
    }

    public function testTheWindowIsPragueTimeOnADaylightSavingDay(): void
    {
        // Sunday 25 October 2026 has 25 hours; 07:00 Prague = 06:00 UTC that day
        $plan = (new DigestPacing())->plan(1, new DateTimeImmutable('2026-10-25 05:00:00', new DateTimeZone('UTC')));

        self::assertSame(3_600_000, $plan['firstDelayMs']);
    }

    private function prague(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('Europe/Prague'));
    }
}
