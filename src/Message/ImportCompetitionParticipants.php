<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The console import without a preview - under the same lock as the web import (ApplyParticipantImport) and every other
 * write to the event's participants.
 */
readonly final class ImportCompetitionParticipants implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $filePath,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
