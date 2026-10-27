<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\TierScores;
use SpeedPuzzling\Web\Value\DifficultyTier;

/**
 * The reported tier numbers (TierScores): the label-noise ceiling of the exact tier, the tier range's coverage and the
 * ranked probability skill score - pure.
 */
final class TierScoresTest extends TestCase
{
    public function testTheTierBoundariesAreDifficultyTiers(): void
    {
        foreach (TierScores::boundaries() as $i => $boundary) {
            self::assertSame($i + 1, DifficultyTier::fromScore(exp($boundary) - 1e-6)->value);
            self::assertSame($i + 2, DifficultyTier::fromScore(exp($boundary) + 1e-6)->value);
        }
    }

    public function testTheNormalCdf(): void
    {
        self::assertEqualsWithDelta(0.5, TierScores::normalCdf(0.0), 1e-7);
        self::assertEqualsWithDelta(0.9, TierScores::normalCdf(1.2815515655446004), 1e-6);
        self::assertEqualsWithDelta(0.025, TierScores::normalCdf(-1.959963984540054), 1e-6);
    }

    /**
     * A label in the middle of its tier from many indices: a perfect estimator gets it; near a boundary from few
     * indices only about half the time.
     */
    public function testTheLabelNoiseCeiling(): void
    {
        self::assertGreaterThan(0.99, TierScores::sameTierProbability(0.0, sqrt(TierScores::LABEL_NOISE_VARIANCE / 1000)));
        self::assertEqualsWithDelta(0.5, TierScores::sameTierProbability(log(1.10) + 1e-9, sqrt(TierScores::LABEL_NOISE_VARIANCE / 10)), 0.05);

        $scores = new TierScores();
        $scores->add(0.0, 0.0, 1000, null, null);
        $scores->add(0.0, log(1.10) + 1e-9, 10, null, null);
        $metrics = $scores->metrics();

        self::assertNotNull($metrics['tierExactCeiling']);
        self::assertEqualsWithDelta(0.75, $metrics['tierExactCeiling'], 0.03);
        // One of the two right (Average vs Challenging): 0.5 ± √(0.25 ÷ 2)
        self::assertEqualsWithDelta(round(sqrt(0.25 / 2), 4), $metrics['tierExactSe'], 1e-9);
        self::assertSame(0, $metrics['tierRangePuzzles'], 'No interval, no range');
        self::assertNull($metrics['rpss']);
    }

    public function testTheTierRangeAndTheRankedProbabilitySkill(): void
    {
        $sharp = new TierScores();
        $vague = new TierScores();

        // Six puzzles, one per tier, each estimated right on; the sharp estimate has a narrow interval, the vague one
        // an interval so wide its tier probabilities are about the group's mix
        foreach ([log(0.6), log(0.82), 0.0, log(1.17), log(1.35), log(1.7)] as $truth) {
            $sharp->add($truth, $truth, 30, -0.02, 0.02);
            $vague->add($truth, $truth, 30, -3.0, 3.0);
        }

        $sharpMetrics = $sharp->metrics();
        $vagueMetrics = $vague->metrics();

        self::assertSame(1.0, $sharpMetrics['tierRangeCoverage']);
        self::assertSame(1.0, $sharpMetrics['tierRangeMeanTiers']);
        self::assertNotNull($sharpMetrics['rpss']);
        self::assertGreaterThan(0.9, $sharpMetrics['rpss']);

        self::assertSame(1.0, $vagueMetrics['tierRangeCoverage']);
        self::assertSame(6.0, $vagueMetrics['tierRangeMeanTiers'], 'Every tier');
        self::assertNotNull($vagueMetrics['rpss']);
        self::assertLessThan(0.3, $vagueMetrics['rpss']);

        // An interval missing the measured tier
        $off = new TierScores();
        $off->add(log(0.6), log(1.7), 30, -0.05, 0.05);
        self::assertSame(0.0, $off->metrics()['tierRangeCoverage']);
    }
}
