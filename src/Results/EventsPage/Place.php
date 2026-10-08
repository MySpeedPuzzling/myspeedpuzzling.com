<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * `[flag] City, Country` - the city is null when the location already holds the country's name (then the country
 * shows alone), the country name is localised. Online occurrences have neither.
 */
readonly final class Place
{
    public function __construct(
        public null|string $city,
        public null|string $country,
        public null|CountryCode $countryCode,
        public bool $isOnline,
    ) {
    }
}
