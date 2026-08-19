<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class CompetitionListItemResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortcut,
        public null|string $slug,
        public null|string $logo,
        public null|string $location,
        public null|string $countryCode,
        public bool $isOnline,
        public null|string $dateFrom,
        public null|string $dateTo,
        public null|string $status,
        public null|string $link,
        public null|string $registrationLink,
        public null|string $resultsLink,
    ) {
    }
}
