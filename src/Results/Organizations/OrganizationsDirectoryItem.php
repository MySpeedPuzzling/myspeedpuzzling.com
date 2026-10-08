<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Organizations;

use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Results\EventsPage\SeriesNext;
use SpeedPuzzling\Web\Value\OrganizationKind;

/**
 * One organization of the directory: logo, name, kind, flag + region, its public series and one-time events, the next
 * date of any of them (else the last one, else none).
 */
readonly final class OrganizationsDirectoryItem
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortName,
        public string $url,
        public null|string $logo,
        public null|OrganizationKind $kind,
        // `[flag] Region, Country` - null without either
        public null|Place $place,
        public int $seriesCount,
        public int $eventCount,
        public SeriesNext $next,
    ) {
    }
}
