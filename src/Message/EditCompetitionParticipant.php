<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class EditCompetitionParticipant
{
    /**
     * @param array<string> $roundIds
     */
    public function __construct(
        public string $participantId,
        public string $name,
        public null|string $country,
        public null|string $externalId,
        public null|string $playerId,
        public array $roundIds = [],
        // The private note exists only on events that manage registration - any other edit keeps it as it is
        public bool $changeOrganizerNote = false,
        public null|string $organizerNote = null,
    ) {
    }
}
