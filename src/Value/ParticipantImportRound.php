<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\RoundDivision;

/**
 * A round of the event an uploaded list is mapped against (and the participants sheet plans against).
 */
readonly final class ParticipantImportRound
{
    /**
     * @param list<RoundDivision> $divisions the divisions the round uses (shared-puzzles-and-divisions.md §3), in the
     *                                       event's order - none = a round without divisions
     */
    public function __construct(
        public string $id,
        public string $name,
        public RoundCategory $category,
        // The organiser's expected team size (CompetitionRound::$teamSize) - team rounds only, null = not set
        public null|int $teamSize = null,
        public array $divisions = [],
    ) {
    }

    public function usesDivisions(): bool
    {
        return $this->divisions !== [];
    }

    public function division(string $divisionId): null|RoundDivision
    {
        foreach ($this->divisions as $division) {
            if ($division->id === $divisionId) {
                return $division;
            }
        }

        return null;
    }

    public function hasTeams(): bool
    {
        return $this->category !== RoundCategory::Solo;
    }
}
