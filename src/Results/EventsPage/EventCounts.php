<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

/**
 * The numbers on agenda rows (GetEventGoingCounts::forCompetitions(), one statement): "41 going" and "6 looking for a
 * partner" (docs/features/partner-finder.md). Keys are lower-case competition ids, only competitions with a number.
 */
readonly final class EventCounts
{
    /**
     * @param array<string, int> $going competition id => spots taken
     * @param array<string, int> $partnerListings competition id => open partner listings
     */
    public function __construct(
        public array $going = [],
        public array $partnerListings = [],
    ) {
    }
}
