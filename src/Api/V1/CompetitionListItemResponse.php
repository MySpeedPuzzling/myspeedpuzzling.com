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
        public null|string $country_code,
        public bool $is_online,
        public null|string $date_from,
        public null|string $date_to,
        public null|string $status,
        public null|string $link,
        public null|string $registration_link,
        public null|string $results_link,
    ) {
    }
}
