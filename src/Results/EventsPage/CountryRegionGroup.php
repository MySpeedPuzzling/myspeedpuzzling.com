<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use SpeedPuzzling\Web\Value\CountryRegion;

readonly final class CountryRegionGroup
{
    /**
     * @param list<CountryCount> $countries by upcoming desc, then name
     */
    public function __construct(
        public CountryRegion $region,
        public array $countries,
    ) {
    }
}
