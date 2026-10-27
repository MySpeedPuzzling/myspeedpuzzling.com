<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * The picture-only window (docs/features/insights/image-estimate-gates.md, "The picture-only window"): the estimate's
 * own asymmetric quantiles - its 80 % interval for the 80 % ends, its 50 % interval for the 50 % ends - combined on
 * each side with the player's part, one fitted scale per window, keyed by size group × image kind × k = 0 / 1-4.
 * Only predictions resting on the picture alone change; the shown statistical predictor never does.
 */
final class ImageWindowTest extends TestCase
{
    private const float IMAGE_LOG = 0.10;

    public function testTheEstimatesOwnQuantilesAreTheOffsets(): void
    {
        $estimate = new ImagePriorInput(0.10, -0.15, 0.40, 'm', ImageKind::Flat, '1.2.1', logP25: 0.0, logP75: 0.22);

        self::assertEqualsWithDelta([-0.25, -0.10, 0.12, 0.30], $estimate->quantileOffsets(), 1e-12);
    }

    /**
     * An estimate from before difficulty_prior 1.2.0 has no 50 % interval: its 80 % offsets × z75 ÷ z90, each side its
     * own (the normal shape of the asymmetric interval).
     */
    public function testAnEstimateWithoutItsOwn50Interval(): void
    {
        $estimate = new ImagePriorInput(0.10, -0.15, 0.40, 'm', ImageKind::Flat, '1.1.0');
        $ratio = ImagePriorInput::Z75 / ImagePriorInput::Z90;

        self::assertEqualsWithDelta([-0.25, -0.25 * $ratio, 0.30 * $ratio, 0.30], $estimate->quantileOffsets(), 1e-12);
        self::assertEqualsWithDelta([0.0, 0.0, 0.0, 0.0], new ImagePriorInput(0.1, 0.2, 0.05, 'm', ImageKind::Flat)->quantileOffsets(), 1e-12, 'A broken interval is no negative width');
    }

    /**
     * Each end: centre × exp(± √((z × σ_player)² + s × e²)) with the 50 % window's scale on its ends and the 80 %
     * window's on its own.
     */
    public function testEachEndAddsThePlayersPartAndTheScaledPictureOffset(): void
    {
        $estimate = new ImagePriorInput(0.10, -0.15, 0.40, 'm', ImageKind::Flat, '1.2.1', logP25: 0.0, logP75: 0.22);
        $playerVariance = 0.01;
        $window = TimePredictionCalculator::imageWindow(3000.0, $playerVariance, $estimate, [1.5, 2.0]);
        $z90 = ImagePriorInput::Z90;
        $z75 = ImagePriorInput::Z75;

        self::assertSame([
            TimePredictionCalculator::seconds(3000 * exp(-sqrt($z90 ** 2 * $playerVariance + 2.0 * 0.25 ** 2))),
            TimePredictionCalculator::seconds(3000 * exp(-sqrt($z75 ** 2 * $playerVariance + 1.5 * 0.10 ** 2))),
            TimePredictionCalculator::seconds(3000 * exp(sqrt($z75 ** 2 * $playerVariance + 1.5 * 0.12 ** 2))),
            TimePredictionCalculator::seconds(3000 * exp(sqrt($z90 ** 2 * $playerVariance + 2.0 * 0.30 ** 2))),
        ], $window);

        // Asymmetric like the estimate: the slow side reaches further than the fast one
        self::assertGreaterThan(log(3000 / $window[0]), log($window[3] / 3000));
    }

    /**
     * The 50 % window follows the estimate's own 50 % interval, the 80 % window its 80 % one: the same estimate with
     * another 50 % interval moves only the 50 % ends.
     */
    public function testThe50WindowFollowsTheEstimatesOwn50Interval(): void
    {
        $calculator = new TimePredictionCalculator();
        $inputs = $this->inputs(k: 0, sumLogIndices: null);
        $narrow = $this->estimate();
        $wide = new ImagePriorInput(self::IMAGE_LOG, self::IMAGE_LOG - 0.22, self::IMAGE_LOG + 0.30, 'ridge-siglip2-dinov2', ImageKind::Flat, '1.2.1', logP25: self::IMAGE_LOG - 0.2, logP75: self::IMAGE_LOG + 0.25);

        [, $withNarrow] = $calculator->sideBySide($inputs, $narrow, $this->parameters());
        [, $withWide] = $calculator->sideBySide($inputs, $wide, $this->parameters());
        self::assertNotNull($withNarrow);
        self::assertNotNull($withWide);

        self::assertSame([$withNarrow->range80LowSeconds, $withNarrow->range80HighSeconds], [$withWide->range80LowSeconds, $withWide->range80HighSeconds]);
        self::assertLessThan($withNarrow->rangeLowSeconds, $withWide->rangeLowSeconds);
        self::assertGreaterThan($withNarrow->rangeHighSeconds, $withWide->rangeHighSeconds);
    }

    public function testAWindowIsNeverAPoint(): void
    {
        $point = new ImagePriorInput(0.0, 0.0, 0.0, 'm', ImageKind::Flat, '1.2.1', logP25: 0.0, logP75: 0.0);
        $window = TimePredictionCalculator::imageWindow(3000.0, 0.0, $point, [1.0, 1.0]);

        self::assertSame(TimePredictionCalculator::seconds(3000 * exp(ImagePriorInput::Z90 * PredictionModelParameters::MINIMUM_SIGMA)), $window[3]);
        self::assertLessThan(3000, $window[1]);
        self::assertGreaterThan(3000, $window[2]);
    }

    /**
     * From the most specific fitted key to the least: group × kind × k bucket, group × k bucket, group × kind, group,
     * all - and null without window scales (a fit from before them).
     */
    public function testTheScalesFallBackFromTheMostSpecificKey(): void
    {
        $parameters = $this->parameters();

        self::assertSame([1.1, 1.2], $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Flat, 0));
        self::assertSame([2.1, 2.2], $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Flat, 3), 'k = 1-4: its own');
        self::assertSame([3.1, 3.2], $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Box, 0), 'no box × k0: the group × k0');
        self::assertSame([4.1, 4.2], $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Box, 2), 'no box × k1-4 nor group × k1-4: the group × kind');
        self::assertSame([5.1, 5.2], $parameters->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Assembled, 1), 'the group');
        self::assertSame([6.1, 6.2], $parameters->imageWindowScalesFor(PiecesGroup::Thousand, ImageKind::Flat, 0), 'all');
        self::assertNull(PredictionModelParameters::defaults()->imageWindowScalesFor(PiecesGroup::FiveHundred, ImageKind::Flat, 0));
        self::assertSame(['pieces_500|flat|k0', 'pieces_500|k0', 'pieces_500|flat', 'pieces_500', 'all'], PredictionModelParameters::imageWindowKeys(PiecesGroup::FiveHundred, ImageKind::Flat, 0));
        self::assertSame('up_to_300|box|k1-4', PredictionModelParameters::imageWindowKey(PiecesGroup::UpTo300, ImageKind::Box, 4));
    }

    public function testTheScalesAreStoredAndAnOlderFitLoadsWithoutThem(): void
    {
        $stored = $this->parameters()->toArray();
        $loaded = PredictionModelParameters::fromArray($stored);

        self::assertSame($this->parameters()->imageWindowScales, $loaded->imageWindowScales);

        $firstTry = $stored['first_try'];
        self::assertIsArray($firstTry);
        unset($firstTry['image_window_scales']);
        $stored['first_try'] = $firstTry;

        self::assertSame([], PredictionModelParameters::fromArray($stored)->imageWindowScales);
    }

    /**
     * With window scales, a prediction resting on the picture alone takes them: the image row by the puzzle's index
     * count when predicted (k = 3 → the k1-4 scales although its inputs are those without the solves), the blend at
     * k = 0 the k0 ones - the same window as the image row there.
     */
    public function testThePictureOnlyPredictionTakesTheScalesOfItsIndexCount(): void
    {
        $calculator = new TimePredictionCalculator();
        $parameters = $this->parameters();
        $estimate = $this->estimate();
        $variance = $parameters->firstTryPlayerVariance(0.12, PiecesGroup::FiveHundred);

        [, $imageAtZero, $blendAtZero] = $calculator->sideBySide($this->inputs(k: 0, sumLogIndices: null), $estimate, $parameters);
        self::assertNotNull($imageAtZero);
        self::assertEquals($imageAtZero, $blendAtZero);
        $centre = 3000 * exp(self::IMAGE_LOG);
        self::assertSame(TimePredictionCalculator::imageWindow($centre, $variance, $estimate, [1.1, 1.2]), [$imageAtZero->range80LowSeconds, $imageAtZero->rangeLowSeconds, $imageAtZero->rangeHighSeconds, $imageAtZero->range80HighSeconds]);

        [, $imageAtThree, $blendAtThree] = $calculator->sideBySide($this->inputs(k: 3, sumLogIndices: 0.3), $estimate, $parameters);
        self::assertNotNull($imageAtThree);
        self::assertNotNull($blendAtThree);
        self::assertSame(3, $imageAtThree->kIndices);
        self::assertSame($imageAtZero->predictedSeconds, $imageAtThree->predictedSeconds);
        self::assertSame(TimePredictionCalculator::imageWindow($centre, $variance, $estimate, [2.1, 2.2]), [$imageAtThree->range80LowSeconds, $imageAtThree->rangeLowSeconds, $imageAtThree->rangeHighSeconds, $imageAtThree->range80HighSeconds]);
        self::assertSame(DifficultySource::Shrunk, $blendAtThree->difficultySource);

        // The blend at k = 1-4 rests on the solves too: its window is the statistical model's, scales or not
        [, , $blendWithout] = $calculator->sideBySide($this->inputs(k: 3, sumLogIndices: 0.3), $estimate, $parameters->with(imageWindowScales: []));
        self::assertEquals($blendWithout, $blendAtThree);
    }

    /**
     * The guard: the statistical predictor - the shown one - is the same number and window whatever the image window
     * scales are, at every k.
     */
    public function testTheShownPredictionNeverChanges(): void
    {
        $calculator = new TimePredictionCalculator();
        $without = PredictionModelParameters::defaults()->with(tau: 0.5);

        foreach ([[0, null, null], [2, 0.3, null], [4, -0.2, null], [7, 0.1, 1.1]] as [$k, $sum, $score]) {
            $inputs = $this->inputs(k: $k, sumLogIndices: $sum, score: $score);

            self::assertEquals($calculator->statistical($inputs, $without, allowPriorOnly: true), $calculator->statistical($inputs, $this->parameters(), allowPriorOnly: true), "k = {$k}");
            self::assertEquals($calculator->sideBySide($inputs, null, $without)[0], $calculator->sideBySide($inputs, $this->estimate(), $this->parameters())[0], "k = {$k}");
        }
    }

    /**
     * A stored fit from before the window scales keeps the symmetric form exactly: the estimate's variance × the
     * image variance scale as the puzzle term of the k = 0 bucket's sigma.
     */
    public function testAFitWithoutWindowScalesKeepsTheSymmetricForm(): void
    {
        $calculator = new TimePredictionCalculator();
        $parameters = PredictionModelParameters::defaults()->with(tau: 0.5, imageVarianceScale: 1.8);
        $estimate = $this->estimate();

        [, $image] = $calculator->sideBySide($this->inputs(k: 0, sumLogIndices: null), $estimate, $parameters);
        self::assertNotNull($image);

        $sigma = $parameters->firstTrySigma(0.12, 0, PiecesGroup::FiveHundred, $estimate->variance(), ImageKind::Flat);
        $quantiles = $parameters->quantilesFor(WindowBucket::firstTry(0.12, $parameters->firstTryConsistencyEdges, 0, PiecesGroup::FiveHundred));
        self::assertSame(TimePredictionCalculator::window(3000 * exp(self::IMAGE_LOG), $sigma, $quantiles), [$image->range80LowSeconds, $image->rangeLowSeconds, $image->rangeHighSeconds, $image->range80HighSeconds]);
    }

    private function estimate(): ImagePriorInput
    {
        return new ImagePriorInput(self::IMAGE_LOG, self::IMAGE_LOG - 0.22, self::IMAGE_LOG + 0.30, 'ridge-siglip2-dinov2', ImageKind::Flat, '1.2.1', logP25: self::IMAGE_LOG - 0.09, logP75: self::IMAGE_LOG + 0.13);
    }

    private function parameters(): PredictionModelParameters
    {
        return PredictionModelParameters::defaults()->with(tau: 0.5, imageWindowScales: [
            'pieces_500|flat|k0' => [1.1, 1.2],
            'pieces_500|flat|k1-4' => [2.1, 2.2],
            'pieces_500|k0' => [3.1, 3.2],
            'pieces_500|box' => [4.1, 4.2],
            'pieces_500' => [5.1, 5.2],
            'all' => [6.1, 6.2],
        ]);
    }

    private function inputs(int $k, null|float $sumLogIndices, null|float $score = null): StatisticalPredictionInputs
    {
        return new StatisticalPredictionInputs(
            piecesCount: 500,
            ownBaselineSeconds: 3000,
            ownBaselineDirect: true,
            ownQualifyingCount: 12,
            ownDrift: 0.0,
            ownSpread: 0.12,
            pace500Seconds: 3000,
            drift500: 0.0,
            spread500: 0.12,
            kIndices: $k,
            sumLogIndices: $sumLogIndices,
            difficultyScore: $score,
            prior: new PriorContext(
                puzzleId: '018d0003-0000-0000-0000-000000000005',
                piecesCount: 500,
                manufacturerId: '018d0001-0000-0000-0000-000000000001',
                brandPiecesGroupSumLog: 1.25,
                brandPiecesGroupCount: 5,
            ),
        );
    }
}
