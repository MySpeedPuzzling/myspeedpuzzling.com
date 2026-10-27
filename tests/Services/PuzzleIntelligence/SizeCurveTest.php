<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\NotIdentifiable;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\SizeCurveFitter;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\SizeCurve;

/**
 * The candidate model's size curve (SizeCurve, review "Size curve" 2026-10-27) pinned to hand-computed numbers.
 */
final class SizeCurveTest extends TestCase
{
    public function testTheCurveInterpolatesInLogPiecesAndSlowPlayersGetASteeperSmallEnd(): void
    {
        $curve = new SizeCurve();

        self::assertSame(0.0, $curve->curve(500));
        self::assertEqualsWithDelta(-2.162, $curve->curve(100), 1e-12);
        // Between 300 (-0.675) and 500 (0) at 400: linear in ln pieces
        $t = (log(400) - log(300)) / (log(500) - log(300));
        self::assertEqualsWithDelta((1 - $t) * -0.675, $curve->curve(400), 1e-12);
        self::assertEqualsWithDelta(-3.089, $curve->curve(20), 1e-12, 'clamped at the first knot');

        // A 120-minute 500 pace: slower than the 70-minute reference, so relatively quicker at 100 pieces
        self::assertEqualsWithDelta(-2.162 + 0.093 * (log(7200) - SizeCurve::REFERENCE_LOG_PACE_500) * log(100 / 500), $curve->curve(100, log(7200)), 1e-12);
        self::assertSame($curve->curve(1000), $curve->curve(1000, log(7200)), 'no speed term above 500');
    }

    public function testANeverSolvedSizeComesFromTheOtherSizesThroughTheCurve(): void
    {
        $curve = new SizeCurve();
        $pace = $curve->pace(100, [[500, 3600, 0.02, 0.12, 20]], null);
        self::assertNotNull($pace);

        $rowVariance = 1.57 * 0.12 ** 2 / 20 + 1e-4;
        self::assertEqualsWithDelta(log(3600) + 0.02 + $curve->curve(100, log(3600)), $pace[0], 1e-12);
        self::assertEqualsWithDelta($rowVariance + 0.005 * log(100 / 500) ** 2, $pace[1], 1e-12);
        self::assertSame(PaceSource::Pooled, $pace[2]);
        self::assertSame(0.12, $pace[3]);
    }

    public function testOwnFirstTriesAtTheSizeUpdateThePooledPace(): void
    {
        $curve = new SizeCurve();
        $pooled = $curve->pace(200, [[500, 3600, 0.0, 0.12, 20], [300, 2000, 0.01, 0.1, 8]], null);
        $withFew = $curve->pace(200, [[500, 3600, 0.0, 0.12, 20], [300, 2000, 0.01, 0.1, 8]], [2, 2 * 7.1]);
        self::assertNotNull($pooled);
        self::assertNotNull($withFew);

        $own = 0.03 / 2;
        $weight = $pooled[1] / ($pooled[1] + $own);
        self::assertEqualsWithDelta($pooled[0] + $weight * (7.1 - $pooled[0]), $withFew[0], 1e-12);
        self::assertEqualsWithDelta($pooled[1] * $own / ($pooled[1] + $own), $withFew[1], 1e-12);
        self::assertSame(PaceSource::PooledFew, $withFew[2]);
        self::assertSame(0.1, $withFew[3], 'the spread of the closest size');
    }

    public function testAnOwnDirectPaceAloneIsTheOwnPaceAndWithOtherSizesItBorrows(): void
    {
        $curve = new SizeCurve();
        $alone = $curve->pace(1000, [[1000, 7000, 0.01, 0.1, 7]], null);
        self::assertNotNull($alone);
        self::assertEqualsWithDelta(log(7000) + 0.01, $alone[0], 1e-12);
        self::assertEqualsWithDelta(1.57 * 0.01 / 7 + 1e-4, $alone[1], 1e-12);
        self::assertSame(PaceSource::Own, $alone[2]);

        $borrowing = $curve->pace(1000, [[1000, 7000, 0.01, 0.1, 7], [500, 2800, 0.0, 0.12, 60]], null);
        self::assertNotNull($borrowing);
        self::assertSame(PaceSource::PooledOwn, $borrowing[2]);
        self::assertGreaterThan($alone[0], $borrowing[0], 'the 500 pace × the curve is slower than this own 1000 pace - pulled toward it');
        self::assertLessThan($alone[1], $borrowing[1]);

        self::assertNull($curve->pace(1000, [], null), 'no evidence, no pace');
    }

    public function testTheHatBasisSumsToOneAndTheFitRoundTrips(): void
    {
        $basis = SizeCurveFitter::basis(400, SizeCurve::DEFAULT_KNOTS);
        self::assertEqualsWithDelta(1.0, array_sum($basis), 1e-12);
        self::assertCount(2, array_filter($basis, static fn (float $value): bool => $value > 0.0));

        $curve = new SizeCurve(beta: 0.1, tau2: 0.004, summary: ['size_curve_pairs' => 5]);
        $decoded = json_decode(json_encode($curve->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertEquals($curve, SizeCurve::fromArray($decoded));
        self::assertSame(2, SizeCurve::distanceBand(1000));
        self::assertSame(4, SizeCurve::distanceBand(2000));
        self::assertSame(0, SizeCurve::distanceBand(500));
        self::assertSame(5, SizeCurve::distanceBand(48));
    }

    public function testTooFewPairsAreNotIdentifiable(): void
    {
        $this->expectException(NotIdentifiable::class);
        new SizeCurveFitter()->fit([[1000, 8.9, [[500, 4200, 0.0, 0.1, 20]], null, 1]], 1);
    }

    /**
     * Every pair between 1000 and 500 pieces: the speed column (below 500 only) is all zeros and nothing else holds it -
     * a singular system fails instead of a silent zero coefficient.
     */
    public function testASingularSystemIsNotIdentifiable(): void
    {
        $firstTries = [];

        for ($n = 0; $n < 2500; $n++) {
            $firstTries[] = [1000, 9.0 + ($n % 7) / 100, [[500, 4200, 0.0, 0.1, 20]], null, $n % SizeCurveFitter::FOLDS];
        }

        $this->expectException(NotIdentifiable::class);
        new SizeCurveFitter()->fit($firstTries, count($firstTries));
    }

    /**
     * A player's first tries are all in one fold - the grid's held-out players are not in its curve's regression.
     */
    public function testTheHeldOutFoldIsByPlayer(): void
    {
        $player = '018d0001-0000-0000-0000-000000000001';

        self::assertSame(SizeCurveFitter::foldOf($player), SizeCurveFitter::foldOf($player));
        self::assertContains(SizeCurveFitter::foldOf($player), range(0, SizeCurveFitter::FOLDS - 1));
    }
}
