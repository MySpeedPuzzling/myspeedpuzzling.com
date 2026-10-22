<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "<name> says you teamed up" to a player named in "Found my partner" who still has an open listing at the event
 * (docs/features/partner-finder.md "Phase 2 as built") - sent async, after the close is saved
 */
readonly final class SendPartnerTeamedUpEmail
{
    public function __construct(
        // The closed (or counted down) listing that named the player
        public string $listingId,
        public string $partnerPlayerId,
    ) {
    }
}
