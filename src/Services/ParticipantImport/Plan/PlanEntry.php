<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

/**
 * A round entry (competition_participant_round) while an import is planned. Teams are referenced as
 * "t:<team id>" (existing) or "n:<round id>:<team name key>" (created by the import) - never by a generated id.
 */
final class PlanEntry
{
    public bool $removed = false;

    public function __construct(
        /** null = created by the import */
        public readonly null|string $id,
        public readonly string $personKey,
        public readonly string $roundId,
        public null|string $team,
        public readonly null|string $originalTeam = null,
        /** The row that put the person into the round (new entries) */
        public readonly null|int $row = null,
    ) {
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }
}
