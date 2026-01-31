<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

readonly final class GenerateVouchers
{
    public function __construct(
        public int $count,
        public int $monthsValue,
        public DateTimeImmutable $validUntil,
        public int $codeLength = 16,
        public null|string $internalNote = null,
    ) {
    }
}
