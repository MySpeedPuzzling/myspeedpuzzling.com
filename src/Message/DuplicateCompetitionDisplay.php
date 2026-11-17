<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionDisplaysLock;

/**
 * A copy of a display in the same event with a new link (D-displays-overlays.md §3.3). $competitionId is the source
 * display's event (the lock of the cap) - a display of another event is not found.
 */
readonly final class DuplicateCompetitionDisplay implements SerializedByLock
{
    public function __construct(
        public string $sourceDisplayId,
        public UuidInterface $newDisplayId,
        public string $competitionId,
        public string $name,
        public null|string $actorPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionDisplaysLock::key($this->competitionId);
    }
}
