<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\VoucherType;

readonly final class ClaimVoucherResult
{
    public function __construct(
        public bool $success,
        public VoucherType $voucherType,
        public bool $redirectToMembership,
        public null|int $percentageDiscount = null,
    ) {
    }
}
