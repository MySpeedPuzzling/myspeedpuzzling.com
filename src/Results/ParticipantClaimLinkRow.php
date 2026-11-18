<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One person the organiser hands a personal claim link (GetParticipantClaimLinks, docs/features/competitions-management/
 * participant-linking.md §7.4): on the list, not linked to an account. The names as the organiser recorded them;
 * `token` null = no link made yet (ParticipantClaimLinkList makes the missing ones).
 */
readonly final class ParticipantClaimLinkRow
{
    public function __construct(
        public string $participantId,
        public string $name,
        // CountryCode case name (lower case) or null
        public null|string $country,
        public null|string $externalId,
        public bool $waitlisted,
        public null|string $token,
    ) {
    }
}
