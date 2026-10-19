<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * One result behind a skill-map row (the drill-down): what the list shows without another query - the puzzle's name
 * and picture come from the drill-down's own hydrate (GetInsightsPuzzles::byIds()). residual = your time ÷ your usual
 * then (0.9 = 10 % faster than your usual).
 */
readonly final class InsightsDrillDownResult
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public int $seconds,
        public DateTimeImmutable $solvedAt,
        public int $piecesCount,
        public null|string $manufacturerName,
        public float $residual,
    ) {
    }
}
