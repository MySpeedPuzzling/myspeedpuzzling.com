<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

/**
 * The Wilson score interval of a share - honest also near 0 / 1 and for small counts, where share ± z × SE is not.
 */
final class Wilson
{
    /**
     * z of a two-sided 90 % interval: a share lies within a band with 95 % confidence when its 90 % interval lies
     * inside it (two one-sided tests, the usual equivalence test)
     */
    public const float Z90 = 1.6448536269514722;

    /**
     * @return null|array{0: float, 1: float} null without solves
     */
    public static function bounds(int $hits, int $count, float $z = self::Z90): null|array
    {
        if ($count <= 0) {
            return null;
        }

        $share = $hits / $count;
        $z2 = $z * $z;
        $denominator = 1.0 + $z2 / $count;
        $centre = ($share + $z2 / (2 * $count)) / $denominator;
        $half = $z * sqrt($share * (1 - $share) / $count + $z2 / (4 * $count * $count)) / $denominator;

        return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
    }
}
