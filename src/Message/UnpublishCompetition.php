<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Back to draft - only while nobody joined it and no result or solving time is linked to it (UnpublishBlockers,
 * docs/features/organizations/README.md "Drafts"). Under the event's participants lock: a join waits for it, so nobody
 * ends up on a hidden page by a race.
 */
readonly final class UnpublishCompetition implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
