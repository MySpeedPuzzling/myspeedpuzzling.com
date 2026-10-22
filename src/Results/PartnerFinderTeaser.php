<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What a guest sees of an event's partner finder (D5, GetPartnerListings::teaserForEvent()): counts per kind and per
 * country and a few faces - never a name, a note or a link.
 */
readonly final class PartnerFinderTeaser
{
    /**
     * @param array<string, int> $countries country code => listings, most first
     * @param list<array{playerId: string, countryCode: null|string, avatar: null|string, masked: bool}> $faces newest first
     */
    public function __construct(
        public PartnerFinderOffer $offer,
        public int $total,
        public int $pairs,
        public int $teams,
        public int $groups,
        // Listings closed with "Found my partner" at the event
        public int $foundCount,
        public array $countries,
        public array $faces,
    ) {
    }
}
