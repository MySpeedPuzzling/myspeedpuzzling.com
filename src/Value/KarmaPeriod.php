<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * The periods of the public karma list (docs/features/karma.md).
 */
enum KarmaPeriod: string
{
    case AllTime = 'all';
    case LastTwelveMonths = '12m';

    public function since(DateTimeImmutable $now): null|DateTimeImmutable
    {
        return match ($this) {
            self::AllTime => null,
            self::LastTwelveMonths => $now->modify('-12 months'),
        };
    }
}
