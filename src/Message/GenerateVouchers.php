<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\VoucherType;

readonly final class GenerateVouchers
{
    public function __construct(
        public int $count,
        public DateTimeImmutable $validUntil,
        public VoucherType $voucherType = VoucherType::FreeMonths,
        public null|int $monthsValue = null,
        public null|int $percentageDiscount = null,
        public int $maxUses = 1,
        public int $codeLength = 16,
        public null|string $internalNote = null,
    ) {
    }
}
