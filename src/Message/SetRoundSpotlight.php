<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionDisplaysLock;

/**
 * Puts an entry of a round in its displays' spotlight - `null` takes it out (competition toolkit v2 L2,
 * displays-standings.md §5.1). `$competitionId` is the round's event as the controller loaded it (the handler checks it):
 * the write takes turns with the event's other display writes under the displays lock - never the participants lock,
 * so the judges' saves never wait for a stream operator.
 */
readonly final class SetRoundSpotlight implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        public null|string $entryRef,
        public null|string $actorPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionDisplaysLock::key($this->competitionId);
    }
}
