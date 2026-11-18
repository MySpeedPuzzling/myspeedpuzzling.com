<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMode;
use SpeedPuzzling\Web\Value\StandingRules;

/**
 * Name, category, rounds and rules of a standing (competition toolkit A6) - the slug stays. Same lock as
 * CreateCompetitionStanding.
 */
readonly final class EditCompetitionStanding implements SerializedByLock
{
    /**
     * @param list<string> $roundIds in the standing's order - ignored for `stages`
     */
    public function __construct(
        public string $standingId,
        // The standing's event, as the caller authorised it - the handler refuses a standing of another event
        public string $competitionId,
        public string $name,
        public RoundCategory $category,
        public array $roundIds,
        public StandingRules $rules,
        // Competition toolkit v2 S5 - null = kept as it is (the wizard's "rounds added later" edits only the rounds)
        public null|StandingMode $mode = null,
        public null|bool $numberedAcrossStages = null,
        // Real events L2a: limited to one division of the event (sum only) - null = everybody, false = kept as it is
        public false|null|string $divisionId = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
