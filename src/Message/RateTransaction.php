<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RateTransaction
{
    public function __construct(
        public string $soldSwappedItemId,
        public string $reviewerId,
        public int $stars,
        public null|string $reviewText = null,
    ) {
    }
}
