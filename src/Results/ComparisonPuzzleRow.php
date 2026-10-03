<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * One compared puzzle (ComparisonBuilder): the subjects' cells, a ranking among them and who has not solved it. Name,
 * image and brand come from GetComparisonPuzzles for the shown page.
 */
readonly final class ComparisonPuzzleRow
{
    /**
     * @param array<string, ComparisonCell> $cells keyed by ref string, line-up order, solvers only
     * @param list<ComparisonCell> $ranked fastest first; equal times in line-up order
     * @param list<ComparisonSubjectRef> $notSolvedBy line-up order
     */
    public function __construct(
        public string $puzzleId,
        public int $piecesCount,
        // Only when the aggregate selected it (members: difficulty filter / sort, charts), else null
        public null|int $difficultyTier,
        // Only when the aggregate selected names (sort by name, charts), else null
        public null|string $puzzleName,
        public array $cells,
        public array $ranked,
        public array $notSolvedBy,
        public int $fastestSeconds,
        // The unique fastest of ≥ 2 solvers - null for a tie or a single solver
        public null|ComparisonSubjectRef $winner,
        // Latest day among the compared cells ("recent" sort)
        public DateTimeImmutable $latestDay,
        // Highlighted A minus B in seconds (negative = A ahead); null unless both solved it
        public null|int $lead,
    ) {
    }

    public function solvedBy(): int
    {
        return count($this->cells);
    }

    public function cell(ComparisonSubjectRef $subject): null|ComparisonCell
    {
        return $this->cells[$subject->toString()] ?? null;
    }
}
