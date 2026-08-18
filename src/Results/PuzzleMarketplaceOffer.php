<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class PuzzleMarketplaceOffer
{
    public function __construct(
        public float $price,
        public string $currency,
        public PuzzleCondition $condition,
    ) {
    }
}
