<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * What a personal claim link's page needs (GetParticipantClaimPage, docs/features/competitions-management/
 * participant-linking.md §6.5): the organiser's record of the person and their event - never a profile's identity.
 * `linkedPlayerId` = the account the row is linked to (compared with the viewer's, never shown); `viewerOtherName` = the
 * name the viewer is on the event's list as now (another row), `viewerOtherPaid` = that row is their own paid
 * registration on an event managing registration (the switch lets go of it).
 */
readonly final class ParticipantClaimPage
{
    public function __construct(
        public string $participantId,
        public string $participantName,
        public null|string $participantCountry,
        public bool $removed,
        public null|string $linkedPlayerId,
        public string $competitionId,
        public string $competitionName,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|string $location,
        public bool $publiclyVisible,
        public null|string $viewerOtherName,
        public bool $viewerOtherPaid,
    ) {
    }

    public function isLinkedTo(null|string $playerId): bool
    {
        return $playerId !== null && $this->linkedPlayerId !== null && strtolower($this->linkedPlayerId) === strtolower($playerId);
    }
}
