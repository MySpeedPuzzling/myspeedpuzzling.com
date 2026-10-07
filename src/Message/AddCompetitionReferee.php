<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * An organiser lets a player enter results of the competition (an edition is a competition) - the live entry only,
 * nothing else (docs/features/competitions-management/live-results.md "Referees"). The caller checked
 * COMPETITION_EDIT; the handler answers with a CompetitionRefereeAddition. Serialised per competition: two organisers
 * adding the same person at once get one row and "already a referee", never a unique-constraint error.
 */
readonly final class AddCompetitionReferee implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $playerId,
        public string $addedByPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return 'competition-referees-' . strtolower($this->competitionId);
    }
}
