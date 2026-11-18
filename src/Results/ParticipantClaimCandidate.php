<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * A participant the viewer may say "this is me" about (docs/features/competitions-management/participant-linking.md
 * §6.1, §6.4) - the organiser's record only (their spelling and country), never a player's identity: the event page's
 * "Is this you?" card (GetEventAttendance) and the competitor page's "This is me" (GetCompetitorPage).
 */
readonly final class ParticipantClaimCandidate
{
    public function __construct(
        public string $participantId,
        public string $name,
        public null|CountryCode $country = null,
        // The card was asked for by a scanned name tag (`?participant=`, §6.4) - not found by the viewer's name
        public bool $fromScan = false,
    ) {
    }
}
