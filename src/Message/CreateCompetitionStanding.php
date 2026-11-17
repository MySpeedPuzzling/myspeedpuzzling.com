<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;

/**
 * A new `sum` standing of an event (competition toolkit A6, A-round-formats.md §3.2-3.3) over some of its rounds of one
 * category, unpublished. Takes turns with round deletes and moves (CompetitionParticipantsLock - they prune standings),
 * so a round that is going away is never saved into a standing, and the cap is counted exactly.
 */
readonly final class CreateCompetitionStanding implements SerializedByLock
{
    /**
     * @param list<string> $roundIds in the standing's order
     */
    public function __construct(
        public UuidInterface $standingId,
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
