<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\BrandPiecesPrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\DifficultyPriors;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\ImageEstimatePrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

/**
 * The image prior (Insights plan §5.4): in front of brand × pieces in the chain, answering only for contexts that carry
 * an estimate - so the statistical (shown) predictor never changes - and fading with the puzzle's own solves.
 */
final class ImageEstimatePriorTest extends TestCase
{
    private const float BRAND_PRIOR = 0.05;
    private const float IMAGE_LOG = 0.30;

    public function testTheImagePriorAnswersOnlyWithAnEstimateAndBrandPiecesTakesTheRest(): void
    {
        $priors = DifficultyPriors::all();
        $context = $this->context(withBrandPrior: true);

        $brandOnly = $priors->priorFor($context);
        self::assertNotNull($brandOnly);
        self::assertSame(BrandPiecesPrior::NAME, $brandOnly->name);
        self::assertEqualsWithDelta(self::BRAND_PRIOR, $brandOnly->logDifficulty, 1e-12);

        $image = $priors->priorFor($context->withImageEstimate($this->estimate()));
        self::assertNotNull($image);
        self::assertSame(ImageEstimatePrior::NAME, $image->name);
        self::assertSame(self::IMAGE_LOG, $image->logDifficulty);
        self::assertSame(DifficultySource::Image, $image->sourceWithoutSolves);
        self::assertEqualsWithDelta($this->estimate()->variance(), $image->variance, 1e-12);

        // A puzzle without a brand prior still has the image one, and nothing without either
        self::assertSame(ImageEstimatePrior::NAME, $priors->priorFor($this->context(withBrandPrior: false)->withImageEstimate($this->estimate()))?->name);
        self::assertNull($priors->priorFor($this->context(withBrandPrior: false)));
    }

    public function testTheEstimatesIntervalIsReadAsPlusMinus128Sigma(): void
    {
        $estimate = new ImagePriorInput(0.1, 0.1 - 1.2815515655446004 * 0.2, 0.1 + 1.2815515655446004 * 0.2, 'ridge-siglip2-dinov2', ImageKind::Flat);

        self::assertEqualsWithDelta(0.04, $estimate->variance(), 1e-12);
        self::assertSame(0.0, new ImagePriorInput(0.1, 0.2, 0.1, 'm', ImageKind::Flat)->variance(), 'A broken interval is no negative variance');
    }

    public function testTheArtworkKindsAreGroupedLikeThePipelineGroupsThem(): void
    {
        self::assertSame(ImageKind::Flat, ImageKind::fromArtworkKind('flat'));
        self::assertSame(ImageKind::Box, ImageKind::fromArtworkKind('box_front'));
        self::assertSame(ImageKind::Box, ImageKind::fromArtworkKind('box_angled'));
        self::assertSame(ImageKind::Assembled, ImageKind::fromArtworkKind('assembled'));
        self::assertSame(ImageKind::Other, ImageKind::fromArtworkKind('other'));
        self::assertSame(ImageKind::Unknown, ImageKind::fromArtworkKind('box_3d'));
        self::assertSame(ImageKind::Unknown, ImageKind::fromArtworkKind(null));
    }

    /**
     * The statistical predictor never sees the estimate: with and without one it predicts the same, shrunk toward the
     * brand prior.
     */
    public function testTheStatisticalPredictionIgnoresTheEstimate(): void
    {
        $calculator = new TimePredictionCalculator();
        $inputs = $this->inputs(k: 2, sumLogIndices: 0.2);

        [$statistical, $image, $blend] = $calculator->sideBySide($inputs, $this->estimate(), $this->parameters());
        [$withoutEstimate, $noImage, $noBlend] = $calculator->sideBySide($inputs, null, $this->parameters());

        self::assertEquals($withoutEstimate, $statistical);
        self::assertEquals($calculator->statistical($inputs, $this->parameters(), allowPriorOnly: true), $statistical);
        self::assertNull($noImage);
        self::assertNull($noBlend);
        self::assertNotNull($statistical);
        self::assertEqualsWithDelta(self::BRAND_PRIOR, $statistical->priorLog, 1e-12);
        self::assertNotNull($image);
        self::assertNotNull($blend);
    }

    /**
     * Image = the picture and the pieces alone, whatever the solves say (k kept on the result); blend = the estimate
     * alone at k = 0, shrunk toward it at 1-4 - fading as solves arrive - and the solves' median from 5 indices on.
     */
    public function testImageRestsOnThePictureAloneAndTheBlendFadesWithTheSolves(): void
    {
        $calculator = new TimePredictionCalculator();
        $parameters = $this->parameters();
        $pace = 3000.0;

        // k = 0: image and blend are the same prediction
        [, $imageAtZero, $blendAtZero] = $calculator->sideBySide($this->inputs(k: 0, sumLogIndices: null), $this->estimate(), $parameters);
        self::assertNotNull($imageAtZero);
        self::assertEquals($imageAtZero, $blendAtZero);
        self::assertSame(DifficultySource::Image, $imageAtZero->difficultySource);
        self::assertSame((int) round($pace * exp(self::IMAGE_LOG)), $imageAtZero->predictedSeconds);
        self::assertSame(self::IMAGE_LOG, $imageAtZero->priorLog);

        // The puzzle's own solves (mean log index 0.0): the image predictor does not move, the blend leaves the picture
        $previousPull = INF;

        foreach ([1, 2, 3, 4] as $k) {
            [$statistical, $image, $blend] = $calculator->sideBySide($this->inputs(k: $k, sumLogIndices: 0.0), $this->estimate(), $parameters);
            self::assertNotNull($statistical);
            self::assertNotNull($image);
            self::assertNotNull($blend);

            self::assertSame($imageAtZero->predictedSeconds, $image->predictedSeconds, "k = {$k}");
            self::assertSame($k, $image->kIndices, 'The index count then stays on the row');
            self::assertSame(DifficultySource::Shrunk, $blend->difficultySource);
            self::assertSame(self::IMAGE_LOG, $blend->priorLog);

            $pull = $blend->logDifficulty;
            self::assertNotNull($pull);
            self::assertEqualsWithDelta(0.5 * self::IMAGE_LOG / ($k + 0.5), $pull, 1e-6, 'tau 0.5: weight tau / (k + tau)');
            self::assertLessThan($previousPull, $pull, 'The prior fades as solves arrive');
            $previousPull = $pull;
        }

        self::assertLessThan(0.05, $previousPull, 'Hardly anything of the picture is left at k = 4');

        // Rated: the median of the solves, whatever the picture says
        [$statistical, , $blend] = $calculator->sideBySide($this->inputs(k: 5, sumLogIndices: 0.0, score: 1.2), $this->estimate(), $parameters);
        self::assertNotNull($statistical);
        self::assertNotNull($blend);
        self::assertSame(DifficultySource::Solves, $blend->difficultySource);
        self::assertSame($statistical->logDifficulty, $blend->logDifficulty);
        self::assertSame($statistical->predictedSeconds, $blend->predictedSeconds);
        self::assertSame([$statistical->rangeLowSeconds, $statistical->rangeHighSeconds], [$blend->rangeLowSeconds, $blend->rangeHighSeconds]);
    }

    /**
     * A prediction resting on the picture alone takes the estimate's own uncertainty as its puzzle term: a sharper
     * estimate, a narrower window.
     */
    public function testTheImageWindowFollowsTheEstimatesInterval(): void
    {
        $calculator = new TimePredictionCalculator();
        $inputs = $this->inputs(k: 0, sumLogIndices: null);

        [, $sharp] = $calculator->sideBySide($inputs, new ImagePriorInput(self::IMAGE_LOG, self::IMAGE_LOG - 0.05, self::IMAGE_LOG + 0.05, 'm', ImageKind::Flat), $this->parameters());
        [, $vague] = $calculator->sideBySide($inputs, new ImagePriorInput(self::IMAGE_LOG, self::IMAGE_LOG - 0.6, self::IMAGE_LOG + 0.6, 'm', ImageKind::Flat), $this->parameters());

        self::assertNotNull($sharp);
        self::assertNotNull($vague);
        self::assertSame($sharp->predictedSeconds, $vague->predictedSeconds);
        self::assertLessThan($vague->rangeHighSeconds - $vague->rangeLowSeconds, $sharp->rangeHighSeconds - $sharp->rangeLowSeconds);
        self::assertLessThan($vague->range80HighSeconds - $vague->range80LowSeconds, $sharp->range80HighSeconds - $sharp->range80LowSeconds);
    }

    /**
     * An estimate at the edge of what anything could push (×148, an interval over the whole log range) and a huge pace:
     * every number stays within the int columns - never an INSERT that would cost the player the time.
     */
    public function testAnExtremeEstimateNeverOverflowsTheSeconds(): void
    {
        $inputs = new StatisticalPredictionInputs(500, 20_000_000, true, 12, 0.0, 0.12, 20_000_000, 0.0, 0.12, 0, null, null, $this->context(withBrandPrior: true));

        [, $image, $blend] = new TimePredictionCalculator()->sideBySide($inputs, new ImagePriorInput(5.0, -5.0, 5.0, 'm', ImageKind::Unknown), $this->parameters());

        foreach ([$image, $blend] as $prediction) {
            self::assertNotNull($prediction);
            self::assertSame(TimePredictionCalculator::MAX_SECONDS, $prediction->predictedSeconds);
            self::assertSame(TimePredictionCalculator::MAX_SECONDS, $prediction->range80HighSeconds);
            self::assertGreaterThanOrEqual(1, $prediction->range80LowSeconds);
        }

        self::assertSame(1, TimePredictionCalculator::seconds(NAN));
        self::assertSame(TimePredictionCalculator::MAX_SECONDS, TimePredictionCalculator::seconds(INF));
        self::assertSame(1, TimePredictionCalculator::seconds(0.2));
    }

    public function testNoPaceNoImagePrediction(): void
    {
        $inputs = new StatisticalPredictionInputs(500, null, false, 0, null, null, null, null, null, 0, null, null, $this->context(withBrandPrior: true));

        self::assertSame([null, null, null], new TimePredictionCalculator()->sideBySide($inputs, $this->estimate(), $this->parameters()));
    }

    private function estimate(): ImagePriorInput
    {
        return new ImagePriorInput(self::IMAGE_LOG, self::IMAGE_LOG - 0.25, self::IMAGE_LOG + 0.3, 'ridge-siglip2-dinov2', ImageKind::Flat);
    }

    private function parameters(): PredictionModelParameters
    {
        return PredictionModelParameters::defaults()->with(tau: 0.5);
    }

    private function context(bool $withBrandPrior): PriorContext
    {
        // BrandPiecesPrior::fromSums(): (sum - own) / (count - ownCount + 20) = 1.25 / 25 = 0.05
        return new PriorContext(
            puzzleId: '018d0003-0000-0000-0000-000000000005',
            piecesCount: 500,
            manufacturerId: $withBrandPrior ? '018d0001-0000-0000-0000-000000000001' : null,
            brandPiecesGroupSumLog: $withBrandPrior ? 1.25 : 0.0,
            brandPiecesGroupCount: $withBrandPrior ? 5 : 0,
        );
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
            prior: $this->context(withBrandPrior: true),
        );
    }
}
