<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One country in the Country Cup (docs/features/players-page/README.md).
 */
readonly final class CountryCupRow
{
    public function __construct(
        public CountryCode $country,
        // Null = not ranked: fewer active puzzlers than the per-puzzler minimum, or no pieces in the period
        public null|int $position,
        // Pieces per active puzzler or total pieces; null below the per-puzzler minimum
        public null|int $value,
        public int $active,
        // Bar length against the leader, 0-100
        public float $barPercent,
        public bool $isViewerCountry,
        public bool $isScopeCountry,
    ) {
    }
}
