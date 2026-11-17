<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Publishes a standing's public page (competition toolkit A7, A-round-formats.md §3.4) - under the event's lock like every
 * other standing write (A6), so it never interleaves with an edit of the same standing.
 */
readonly final class PublishCompetitionStanding implements SerializedByLock
{
    public function __construct(
        public string $standingId,
        // The standing's event, as the caller authorised it - the handler refuses a standing of another event
        public string $competitionId,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
