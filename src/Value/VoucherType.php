<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum VoucherType: string
{
    case FreeMonths = 'free_months';
    case PercentageDiscount = 'percentage_discount';
    case Lifetime = 'lifetime';

    /**
     * Single-use vouchers are consumed by `voucher.used_at`; multi-use ones count their `voucher_claim` rows.
     */
    public function isSingleUse(): bool
    {
        return $this !== self::PercentageDiscount;
    }
}
