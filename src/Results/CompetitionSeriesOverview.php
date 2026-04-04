<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class CompetitionSeriesOverview
{
    public null|string $link;

    public function __construct(
        public string $id,
        public string $name,
        public null|string $slug,
        public null|string $logo,
        public null|string $description,
        null|string $link,
        public bool $isOnline,
        public null|string $location,
        public null|CountryCode $locationCountryCode,
        public null|string $addedByPlayerId,
    ) {
        $this->link = $link !== null
            ? $link . (str_contains($link, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
            : null;
    }
}
