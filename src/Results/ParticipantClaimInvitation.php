<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * What the claim invitation e-mail needs of one participant, read when it is sent (GetParticipantClaimInvitation,
 * participant-linking.md §5.5, §9.1) - the organiser's record only, nothing of any account.
 */
readonly final class ParticipantClaimInvitation
{
    public function __construct(
        public string $participantId,
        public string $competitionId,
        public string $participantName,
        // CountryCode case name (lower case) or null - picks the e-mail's language
        public null|string $participantCountry,
        public null|string $email,
        public null|string $claimToken,
        public bool $removed,
        public bool $linked,
        public bool $refused,
        public string $competitionName,
        // The event's public organization - null when it has none (or it is not public)
        public null|string $organizationName,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|string $location,
        public bool $publiclyVisible,
        public bool $usesOnlineTiming,
    ) {
    }

    /**
     * Still somebody to invite with this address: on the list, not linked, did not say no, a claim link, the event public
     * and without online timing (the sender's re-check - the e-mail was queued a moment ago)
     */
    public function mayStillBeSentTo(string $email): bool
    {
        return $this->removed === false
            && $this->linked === false
            && $this->refused === false
            && $this->claimToken !== null
            && $this->email === $email
            && $this->publiclyVisible
            && $this->usesOnlineTiming === false;
    }
}
