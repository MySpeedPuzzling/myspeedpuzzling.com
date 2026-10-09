<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class EventSeriesRow
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $slug,
        public bool $isOnline = false,
        public null|string $location = null,
        public null|CountryCode $countryCode = null,
        public bool $isPublic = true,
        // docs/features/organizations/README.md - the organization, "Who can enter", "When it happens", draft
        public null|OrganizationRef $organization = null,
        public null|string $eligibility = null,
        public null|string $schedule = null,
        public bool $isDraft = false,
    ) {
    }
}
