<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * A new advancement step of an event (competition toolkit A8, A-round-formats.md §3.5), checked
 * against every other step of the event (AdvancementStepGraph). Takes turns with round deletes and moves
 * (CompetitionParticipantsLock - they prune steps), so a round that is going away is never saved into a step.
 */
readonly final class CreateCompetitionAdvancementStep implements SerializedByLock
{
    public function __construct(
        public UuidInterface $stepId,
        // The step's event, as the caller authorised it
        public string $competitionId,
        public null|string $name,
        public AdvancementStepSettings $settings,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
