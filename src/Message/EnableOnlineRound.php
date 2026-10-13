<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Turns online timing on for a round (docs/features/online-competitions/README.md) and gives every participant of the
 * competition without a personal link one. Serialised with every other participant write of the event - it writes the
 * participants' tokens.
 */
readonly final class EnableOnlineRound implements SerializedByLock
{
    public function __construct(
        // The competition the caller authorised (OnlineRoundsVoter) - a round of another one is refused
        public string $competitionId,
        public string $roundId,
        public string $actingPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
