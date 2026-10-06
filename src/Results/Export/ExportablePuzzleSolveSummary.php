<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;

/**
 * The exporting player's own results on one puzzle: solo plus every pair/team they are a registered member of.
 */
readonly final class ExportablePuzzleSolveSummary
{
    public function __construct(
        public int $solvedCount,
        public DateTimeImmutable $firstSolvedAt,
        public DateTimeImmutable $lastSolvedAt,
        public null|int $bestSoloSeconds,
    ) {
    }
}
