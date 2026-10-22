<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PartnerKind;

/**
 * What the event page's partner card knows about a signed-in viewer (GetPartnerCardData, one statement):
 * docs/features/partner-finder.md "Part B".
 */
readonly final class PartnerCardData
{
    /**
     * @param list<PartnerCardFace> $faces up to GetPartnerCardData::FACES, newest listing first
     * @param list<PartnerKind> $ownLookingFor the viewer's open listing: what it looks for ([] without one)
     */
    public function __construct(
        // Every open listing of the event - an aggregate, like "N going"
        public int $listingCount,
        public array $faces,
        public null|string $ownListingId = null,
        public array $ownLookingFor = [],
        public null|int $ownGroupSize = null,
        public null|int $ownNeeded = null,
        // Requests the viewer's open listing received (from players the viewer has not hidden)
        public int $ownRequestsCount = 0,
        // In a pair/team entry of the event (PartnerFinderEligibility::sqlPlayerEntered()) - no card at all
        public bool $viewerEntered = false,
        // HintType::PartnerFinderCard - the card is closed for good, the button stays
        public bool $cardDismissed = false,
    ) {
    }

    public function isListed(): bool
    {
        return $this->ownListingId !== null;
    }

    public function isOwnGroup(): bool
    {
        return $this->ownGroupSize !== null && $this->ownGroupSize > 1;
    }
}
