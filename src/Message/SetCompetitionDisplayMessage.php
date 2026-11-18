<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionDisplaysLock;

/**
 * The live control's message of an event's displays (competition toolkit v2 L1, displays-standings.md §5.1): a text
 * sets it, null (or a text empty after cleaning) clears it. Takes turns with the event's other display writes under the
 * displays lock - never the participants lock, so the judges' saves never wait for a stream operator.
 */
readonly final class SetCompetitionDisplayMessage implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public null|string $text,
        public null|string $actorPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionDisplaysLock::key($this->competitionId);
    }
}
