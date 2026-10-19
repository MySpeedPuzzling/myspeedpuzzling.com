<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * A solve that moved the pace more than 3 % faster at once ("your biggest jumps").
 */
readonly final class PaceJump
{
    public function __construct(
        public DateTimeImmutable $date,
        public string $timeId,
        /** null = the puzzle is kept secret now */
        public null|InsightsPuzzleRef $puzzle,
        public int $solveSeconds,
        public int $paceBeforeSeconds,
        public int $paceAfterSeconds,
        /** exp(step) − 1: −0.05 = the pace got 5 % faster */
        public float $change,
    ) {
    }
}
