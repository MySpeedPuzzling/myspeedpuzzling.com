<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;

/**
 * Name, category, rounds and rules of a standing (competition toolkit A6) - the slug stays. Same lock as
 * CreateCompetitionStanding.
 */
readonly final class EditCompetitionStanding implements SerializedByLock
{
    /**
     * @param list<string> $roundIds in the standing's order
     */
    public function __construct(
        public string $standingId,
        // The standing's event, as the caller authorised it - the handler refuses a standing of another event
        public string $competitionId,
        public string $name,
        public RoundCategory $category,
        public array $roundIds,
        public StandingRules $rules,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
