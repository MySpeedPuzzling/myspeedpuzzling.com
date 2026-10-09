<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;

/**
 * One publicly visible organization of the directory (GetOrganizations::publicDirectory()): counts of its publicly
 * visible series and one-time events. The next date comes from the occurrences (GetEventOccurrences::forOrganizations()).
 */
readonly final class OrganizationDirectoryRow
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortName,
        public string $slug,
        public null|string $logo,
        public null|OrganizationKind $kind,
        public null|CountryCode $countryCode,
        public null|string $region,
        public int $seriesCount,
        public int $eventCount,
    ) {
    }
}
