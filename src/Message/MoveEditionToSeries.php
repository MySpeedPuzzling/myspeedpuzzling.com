<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Moves an edition to another series (docs/features/organizations/README.md "Restructuring tools") with everything it
 * holds - participants, rounds, results, times. The caller checks the actor may edit the edition and the target series;
 * the handler checks what may have changed meanwhile. Its old address and its rounds' results addresses redirect to
 * the new ones (event_url_redirect).
 *
 * Under the edition's participants lock: a registration never lands half way through the move.
 */
readonly final class MoveEditionToSeries implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $targetSeriesId,
        public string $actingPlayerId,
        // A slug for the edition in the target series, when its own is taken there (validated, free) - null keeps it
        public null|string $newSlug = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
