<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class SeriesListItemResponse
{
    public function __construct(
        // The id to send as series_id when adding or editing a solving time
        public string $id,
        public string $name,
        public null|string $shortcut,
        public null|string $slug,
        public null|string $logo,
        public bool $isOnline,
        public null|string $location,
        public null|string $countryCode,
        public null|string $link,
        public null|string $organizationName,
        public int $editionsCount,
        // Days (`Y-m-d`): the first day of the soonest edition starting after today, of the latest edition that is over
        public null|string $nextDate,
        public null|string $lastDate,
    ) {
    }
}
