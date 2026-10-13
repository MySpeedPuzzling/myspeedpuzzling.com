<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;

/**
 * The reviewer confirms a participant's online result (docs/features/online-competitions/README.md §5.9): the finish
 * moment and where it came from on the online entry, and the official result - in one transaction, under the event's
 * participants lock like every other official result.
 */
readonly final class ConfirmOnlineResult implements SerializedByLock
{
    public function __construct(
        // The competition the caller authorised - a round of another one is refused
        public string $competitionId,
        public string $roundId,
        public string $participantId,
        // The finish moment in epoch ms - null when unfinished (then the pieces)
        public null|int $momentMs,
        public OnlineConfirmedSource $source,
        public null|int $pieces,
        public string $actingPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
