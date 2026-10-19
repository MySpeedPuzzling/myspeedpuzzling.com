<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * The improvement curve at one piece count.
 */
readonly final class ImprovementCurveSize
{
    /**
     * @param list<PacePoint> $points one per day (the day's last pace), oldest first
     * @param list<TypicalPacePoint> $typicalCurve the typical newcomer's curve from the same first pace (500 pieces only), up to the player's count
     * @param list<PaceJump> $biggestJumps biggest first
     */
    public function __construct(
        public int $piecesCount,
        public array $points,
        public int $firstPaceSeconds,
        public int $paceSeconds,
        public int $firstTries,
        /** Today's pace ÷ the first − 1: −0.12 = 12 % faster than at the start */
        public float $changeSinceFirst,
        /** The typical change at this many first tries (500 pieces, up to 300 first tries), else null */
        public null|float $typicalChange,
        public array $typicalCurve,
        public array $biggestJumps,
        /** Steps of more than 3 % faster at once, of all steps */
        public int $jumpsCount,
        public int $stepsCount,
        public null|PacePlateau $plateau,
    ) {
    }
}
