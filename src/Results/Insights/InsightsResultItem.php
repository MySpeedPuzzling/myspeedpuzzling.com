<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\InsightsImageTrait;
use SpeedPuzzling\Web\Value\InsightsSituation;

/**
 * A result as the lists of the page show it (outliers, surprises, drill-downs): the result, its yardsticks and the
 * reasons it may be off ("why" chips). Puzzle name and image come from GetInsightsPuzzles by puzzleId.
 */
readonly final class InsightsResultItem
{
    /**
     * @param null|float $ratio your time ÷ the puzzle's median solo time
     * @param null|float $residual ratio ÷ your usual then ÷ the community's learning factor (1.0 = your usual)
     * @param list<InsightsSituation> $situations
     * @param list<InsightsImageTrait> $imageTraits
     */
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public int $seconds,
        public DateTimeImmutable $solvedAt,
        public int $attempt,
        public bool $firstAttempt,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|int $puzzleMedianSeconds,
        public null|float $ratio,
        public null|float $residual,
        public null|int $expectedSeconds,
        public null|int $daysSincePrevious,
        public array $situations,
        public array $imageTraits,
    ) {
    }
}
