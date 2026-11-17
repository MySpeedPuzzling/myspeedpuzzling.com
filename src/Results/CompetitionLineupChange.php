<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A pair/team whose people play in another line-up in a later round (GetCompetitionLineupTags): its printed tag keeps
 * showing only the rounds of the exact line-up it was printed for (CompetitionLineupId) - the print page says so, so
 * the organiser prints the new line-up's tag (R-review-and-plan.md §2.11).
 */
readonly final class CompetitionLineupChange
{
    public function __construct(
        public string $lineupLabel,
        public string $laterRoundName,
        public string $laterLineupLabel,
    ) {
    }
}
