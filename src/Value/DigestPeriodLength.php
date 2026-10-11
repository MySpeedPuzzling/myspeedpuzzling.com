<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DigestPeriodLength: string
{
    case Week = 'week';
    case TwoWeeks = 'two_weeks';
    case Month = 'month';
}
