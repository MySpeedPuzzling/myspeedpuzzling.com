<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum VoucherType: string
{
    case FreeMonths = 'free_months';
    case PercentageDiscount = 'percentage_discount';
}
