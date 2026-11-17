<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The line-up tags to print (GetCompetitionLineupTags) and the line-ups that changed between rounds (the print page's
 * warning).
 */
readonly final class CompetitionLineupTags
{
    /**
     * @param list<CompetitionLineupTag> $tags
     * @param list<CompetitionLineupChange> $changes
     */
    public function __construct(
        public array $tags,
        public array $changes,
    ) {
    }
}
