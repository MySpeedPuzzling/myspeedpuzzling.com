<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;

/**
 * How the honesty test refits a window scale on the solves before a block (OutOfPeriodCalibration) - the same way the
 * subject's own production fit does, so the test judges the procedure members actually meet
 * (docs/features/insights/image-estimate-gates.md, "Honesty"):
 * - equalWeight(): every earlier solve counts the same, one scale per window for the whole group - the candidate time
 *   models' window fit (CandidateModelFitter weighs no solve by age) and every record before 2026-10-27;
 * - imageWindow(): the picture-only window's fit (PredictionModelFitter step 6) - weighted 0.5^(age ÷ half-life) at
 *   the block's start, and the first solvers of a puzzle (k = 0) and its later ones (k = 1-4) each with their own
 *   scale from minimumEffective effective (Kish) solves, else the group's.
 */
readonly final class WindowRefit
{
    private function __construct(
        public null|float $halfLifeDays,
        public bool $byFirstSolver,
        public int $minimumEffective,
    ) {
    }

    public static function equalWeight(): self
    {
        return new self(null, false, 0);
    }

    public static function imageWindow(): self
    {
        return new self(PredictionModelFitter::IMAGE_WINDOW_HALF_LIFE_DAYS, true, PredictionModelFitter::IMAGE_WINDOW_MINIMUM_EFFECTIVE);
    }

    public function isEqualWeight(): bool
    {
        return $this->halfLifeDays === null && $this->byFirstSolver === false;
    }

    /**
     * The key a solve's scale is refitted under: '' = the group's
     */
    public function keyOf(null|int $kIndices): string
    {
        if ($this->byFirstSolver === false || $kIndices === null) {
            return '';
        }

        return $kIndices <= 0 ? 'k0' : 'k1-4';
    }

    /**
     * Whether stored calibration numbers (OutOfPeriodCalibration::metrics()) were judged by this refit - an
     * equal-weight record carries no refit keys
     *
     * @param array<mixed> $calibration
     */
    public function judged(array $calibration): bool
    {
        if ($this->isEqualWeight()) {
            return !array_key_exists('refitHalfLifeDays', $calibration) && !array_key_exists('refitByFirstSolver', $calibration);
        }

        $halfLife = $calibration['refitHalfLifeDays'] ?? null;

        return (is_int($halfLife) || is_float($halfLife)) && abs((float) $halfLife - (float) $this->halfLifeDays) < 1e-9
            && ($calibration['refitByFirstSolver'] ?? null) === $this->byFirstSolver
            && ($calibration['refitMinimumEffective'] ?? null) === $this->minimumEffective;
    }

    /**
     * A solve's weight in a refit at $fitAt
     */
    public function weight(float $solvedAt, float $fitAt): float
    {
        return $this->halfLifeDays === null ? 1.0 : 0.5 ** (max(0.0, $fitAt - $solvedAt) / ($this->halfLifeDays * 86400));
    }
}
