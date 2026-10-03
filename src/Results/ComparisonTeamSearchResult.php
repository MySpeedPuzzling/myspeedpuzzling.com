<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * A pair/team offered by the add sheet of the Pairs / Teams line-up (SearchComparisonTeams). Members are masked the
 * way this viewer may see them.
 */
readonly final class ComparisonTeamSearchResult
{
    /**
     * @param list<PuzzlingTeamMemberView> $members in the team's member order
     */
    public function __construct(
        public string $teamId,
        public null|string $name,
        public int $size,
        public array $members,
        // Valid times (not suspicious, with a time) - always at least one
        public int $timesCount,
        public DateTimeImmutable $lastSolvedAt,
        // "You're in it"
        public bool $includesViewer,
    ) {
    }

    public function ref(): ComparisonSubjectRef
    {
        return ComparisonSubjectRef::team($this->teamId);
    }

    public function kind(): ComparisonKind
    {
        return ComparisonKind::forTeamSize($this->size);
    }
}
