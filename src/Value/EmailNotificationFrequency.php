<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum EmailNotificationFrequency: string
{
    case SixHours = '6_hours';
    case TwelveHours = '12_hours';
    case TwentyFourHours = '24_hours';
    case FortyEightHours = '48_hours';
    case OneWeek = '1_week';

    public function toHours(): int
    {
        return match ($this) {
            self::SixHours => 6,
            self::TwelveHours => 12,
            self::TwentyFourHours => 24,
            self::FortyEightHours => 48,
            self::OneWeek => 168,
        };
    }
}
