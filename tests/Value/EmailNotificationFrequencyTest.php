<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;

final class EmailNotificationFrequencyTest extends TestCase
{
    public function testToHours(): void
    {
        self::assertSame(6, EmailNotificationFrequency::SixHours->toHours());
        self::assertSame(12, EmailNotificationFrequency::TwelveHours->toHours());
        self::assertSame(24, EmailNotificationFrequency::TwentyFourHours->toHours());
        self::assertSame(48, EmailNotificationFrequency::FortyEightHours->toHours());
        self::assertSame(168, EmailNotificationFrequency::OneWeek->toHours());
    }
}
