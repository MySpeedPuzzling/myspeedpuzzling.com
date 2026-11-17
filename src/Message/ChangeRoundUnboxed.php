<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The round's "Unboxed" switch (docs/features/competitions-management/world-series-rules.md §7): no box picture of its
 * puzzles on the event's pages, screens and phones until its results are first published. Takes turns with every other
 * change of the event's rounds (CompetitionParticipantsLock, like EditCompetitionRound); the handler also locks the round
 * and its secret puzzles for their site-wide hide (SecretPuzzleHides).
 */
readonly final class ChangeRoundUnboxed implements SerializedByLock
{
    public function __construct(
        // The round's event, as the caller authorised it - the handler refuses a round of another event
        public string $competitionId,
        public string $roundId,
        public bool $unboxed,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
