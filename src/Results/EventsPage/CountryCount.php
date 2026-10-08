<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\CountryRegion;

/**
 * In-person, public occurrences of one country: upcoming (live + upcoming), date TBA and past.
 */
readonly final class CountryCount
{
    public function __construct(
        public CountryCode $code,
        // localised
        public string $name,
        public int $upcoming,
        public int $tba,
        public int $past,
        public CountryRegion $region,
    ) {
    }
}
