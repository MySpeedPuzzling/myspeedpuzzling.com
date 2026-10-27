<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PictureDispersionModel;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\PictureLookalikes;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

/**
 * The candidate model's v6 parts (never shown - README "Step 8", "Candidate v6"): the image prior's own variance from
 * the estimate's interval, the k = 0 picture term of the window, and the unboxed window as a variance of its own.
 */
final class CandidateV6Test extends TestCase
{
    public function testAnImageEstimateWeighsByItsOwnInterval(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(
            priorVariances: ['image_estimate' => 0.01, 'brand_pieces' => 0.03, 'none' => 0.04],
            imageIntervalExponent: 0.5,
            imageIntervalReference: 0.01,
        );

        // An estimate four times as uncertain as the reference: twice the variance (exponent 0.5)
        self::assertEqualsWithDelta(0.02, $parameters->imagePriorVariance(0.04), 1e-12);
        self::assertEqualsWithDelta(0.005, $parameters->imagePriorVariance(0.0025), 1e-12);
        self::assertSame(0.01, $parameters->imagePriorVariance(0.0), 'no interval: the type\'s variance');
        self::assertSame(0.01, $parameters->with(features: CandidateFeatures::without(['image_interval'])->toArray())->imagePriorVariance(0.04), 'switched off');
        self::assertSame(0.01, CandidateModelParameters::defaults()->with(priorVariances: ['image_estimate' => 0.01])->imagePriorVariance(0.04), 'not fitted (v5): one variance');

        $narrow = new ImagePriorInput(0.2, 0.2 - 0.05 * ImagePriorInput::Z90, 0.2 + 0.05 * ImagePriorInput::Z90, 'ridge', ImageKind::Flat);
        $wide = new ImagePriorInput(0.2, 0.2 - 0.2 * ImagePriorInput::Z90, 0.2 + 0.2 * ImagePriorInput::Z90, 'ridge', ImageKind::Flat);
        self::assertEqualsWithDelta(0.0025, $narrow->variance(), 1e-12);
        self::assertEqualsWithDelta(0.04, $wide->variance(), 1e-12);

        // The puzzle's indices say 0 (three of noise 0.016): a wide estimate gives way to them sooner
        $calculator = new CandidatePredictionCalculator();
        $evidence = self::evidence([0.0, 0.0, 0.0], 0.016);
        $withNarrow = $calculator->firstTry(self::inputs($evidence, $narrow), $parameters);
        $withWide = $calculator->firstTry(self::inputs($evidence, $wide), $parameters);
        self::assertNotNull($withNarrow);
        self::assertNotNull($withWide);
        self::assertEqualsWithDelta(0.2 / 0.005 / (187.5 + 1 / 0.005), (float) $withNarrow->logDifficulty, 1e-6);
        self::assertEqualsWithDelta(0.2 / 0.02 / (187.5 + 1 / 0.02), (float) $withWide->logDifficulty, 1e-6);

        // Nothing measured yet: the same centre, the wider estimate the wider window
        $atZeroNarrow = $calculator->firstTry(self::inputs(CandidateEvidence::none(), $narrow), $parameters);
        $atZeroWide = $calculator->firstTry(self::inputs(CandidateEvidence::none(), $wide), $parameters);
        self::assertNotNull($atZeroNarrow);
        self::assertNotNull($atZeroWide);
        self::assertSame($atZeroNarrow->predictedSeconds, $atZeroWide->predictedSeconds);
        self::assertGreaterThan($atZeroNarrow->range80HighSeconds, $atZeroWide->range80HighSeconds);
        self::assertLessThan($atZeroNarrow->range80LowSeconds, $atZeroWide->range80LowSeconds);
    }

    public function testThePictureTermMovesOnlyTheWindowOfAPuzzleWithoutIndices(): void
    {
        $model = new PictureDispersionModel([0.1, 0.06, 5.0, 0.3, 0.5], [0.1, 0.02, 0.5, 0.2, 0.5], [0.004, 0.0, 0.0, 0.0, 0.0], 0.0, 0.0, 1.5);
        $parameters = CandidateModelParameters::defaults()->with(pictureDispersion: $model);
        $alike = new PictureLookalikes([0.3, 0.06, 5.0, 0.3, 0.5]);
        $distinct = new PictureLookalikes([0.0, 0.06, 5.0, 0.3, 0.5]);

        // Two standard deviations above the mean look-alike share: 2 × 0.004, × the weight
        self::assertEqualsWithDelta(0.008, $model->relative($alike), 1e-12);
        self::assertEqualsWithDelta(1.5 * 0.008, $model->term($alike), 1e-12);
        self::assertEqualsWithDelta(-1.5 * 0.004, $model->term($distinct), 1e-12, 'distinct pieces narrow the window');
        self::assertSame(0.0, $model->term(null), 'no numbers: no term');

        $calculator = new CandidatePredictionCalculator();
        $picture = static fn (PictureLookalikes $lookalikes): PictureFactors => new PictureFactors(null, null, ImageKind::Flat, $lookalikes);
        $plain = $calculator->firstTry(self::inputs(CandidateEvidence::none(), null), $parameters);
        $withAlike = $calculator->firstTry(self::inputs(CandidateEvidence::none(), null)->withCandidateTaste(null, $picture($alike)), $parameters);
        $withDistinct = $calculator->firstTry(self::inputs(CandidateEvidence::none(), null)->withCandidateTaste(null, $picture($distinct)), $parameters);
        self::assertNotNull($plain);
        self::assertNotNull($withAlike);
        self::assertNotNull($withDistinct);
        self::assertSame($plain->predictedSeconds, $withAlike->predictedSeconds, 'the centre stays');
        self::assertGreaterThan($plain->range80HighSeconds, $withAlike->range80HighSeconds);
        self::assertLessThan($plain->range80HighSeconds, $withDistinct->range80HighSeconds);

        // From the first index on the puzzle's own dispersion speaks; switched off nothing changes
        $measured = self::inputs(self::evidence([0.1], 0.016), null);
        self::assertEquals($calculator->firstTry($measured, $parameters), $calculator->firstTry($measured->withCandidateTaste(null, $picture($alike)), $parameters));
        $off = $parameters->with(features: CandidateFeatures::without(['picture_dispersion'])->toArray());
        self::assertEquals($calculator->firstTry(self::inputs(CandidateEvidence::none(), null), $off), $calculator->firstTry(self::inputs(CandidateEvidence::none(), null)->withCandidateTaste(null, $picture($alike)), $off));

        // Stored with the fit
        $decoded = json_decode(json_encode($parameters->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertEquals($parameters->toArray(), CandidateModelParameters::fromArray($decoded)->toArray());
    }

    public function testThePictureTermIsARidgeRegressionCentredOnTheMedianPuzzle(): void
    {
        mt_srand(7);
        $rows = [];
        $targets = [];

        // The target rises with the look-alike share and falls with the neighbours below 0.06, plus noise
        for ($i = 0; $i < 3000; $i++) {
            $row = [mt_rand(0, 1000) / 2000, mt_rand(40, 90) / 1000, mt_rand(400, 600) / 100, mt_rand(0, 1000) / 1000, mt_rand(0, 2000) / 1000];
            $rows[] = $row;
            $targets[] = 0.02 * $row[0] - 0.01 * $row[3] + (mt_rand(0, 1000) - 500) / 100000;
        }

        $puzzles = array_map(static fn (array $row): PictureLookalikes => new PictureLookalikes($row), array_slice($rows, 0, 501));
        $model = PictureDispersionModel::fit($rows, $targets, $puzzles, 100.0);

        self::assertGreaterThan(0.0, $model->coefficients[0]);
        self::assertLessThan(0.0, $model->coefficients[3]);
        self::assertEqualsWithDelta(0.02 * 0.144, $model->coefficients[0], 0.0006, 'the standardised slope (sd of the share ~0.144)');
        self::assertSame(0.0, $model->weight, 'the window fit sets the weight');

        $relatives = array_map($model->relative(...), $puzzles);
        sort($relatives);
        self::assertEqualsWithDelta(0.0, $relatives[250], 1e-8, 'centred on the median puzzle');
    }

    public function testPictureLookalikesNeedEveryNumber(): void
    {
        self::assertNull(PictureLookalikes::fromColumns('0.1', '0.06', null, '0.3', '0.5'));
        $lookalikes = PictureLookalikes::fromColumns('0.1', 0.06, '199', '0.3', '0.5');
        self::assertNotNull($lookalikes);
        self::assertEqualsWithDelta(log(200.0), $lookalikes->values[2], 1e-12, 'colour regions on a log scale');
        self::assertCount(count(PictureLookalikes::NAMES), $lookalikes->values);

        $picture = PictureFactors::fromColumns(null, null, null, $lookalikes);
        self::assertNotNull($picture, 'the numbers alone are a picture');
        self::assertSame($lookalikes, $picture->lookalikes);
    }

    public function testTheUnboxedWindowAddsAVarianceOfItsOwn(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(unboxedOffset: 0.1, unboxedVariance: 0.02, contextScales: ['unboxed' => 1.0]);
        $boxed = $parameters->firstTrySigma(0.12, 0.004, PiecesGroup::FiveHundred, PaceSource::Own, 0.0, 1.0);
        $unboxed = $parameters->firstTrySigma(0.12, 0.004, PiecesGroup::FiveHundred, PaceSource::Own, 0.0, 1.0, extraVariance: 0.02);
        self::assertEqualsWithDelta(sqrt($boxed ** 2 + 0.02), $unboxed, 1e-12);

        $calculator = new CandidatePredictionCalculator();
        $inputs = self::inputs(self::evidence([0.1, 0.2], 0.016), null);
        $plain = $calculator->firstTry($inputs, $parameters);
        $withUnboxed = $calculator->firstTry($inputs, $parameters, new SolveConditions(unboxed: true));
        self::assertNotNull($plain);
        self::assertNotNull($withUnboxed);
        self::assertGreaterThan($plain->predictedSeconds, $withUnboxed->predictedSeconds);
        self::assertGreaterThan(log($plain->range80HighSeconds / $plain->predictedSeconds), log($withUnboxed->range80HighSeconds / $withUnboxed->predictedSeconds));
    }

    /**
     * @param list<float> $logs
     */
    private static function evidence(array $logs, float $variance): CandidateEvidence
    {
        $evidence = CandidateEvidence::none();

        foreach ($logs as $log) {
            $evidence = $evidence->with($log, $variance, 1 / $variance);
        }

        return $evidence;
    }

    private static function inputs(CandidateEvidence $evidence, null|ImagePriorInput $estimate): StatisticalPredictionInputs
    {
        return new StatisticalPredictionInputs(
            piecesCount: 500,
            ownBaselineSeconds: 3600,
            ownBaselineDirect: true,
            ownQualifyingCount: 20,
            ownDrift: 0.0,
            ownSpread: 0.12,
            pace500Seconds: 3600,
            drift500: 0.0,
            spread500: 0.12,
            kIndices: $evidence->k,
            sumLogIndices: null,
            difficultyScore: null,
            prior: new PriorContext('puzzle', 500, imageEstimate: $estimate),
        )->withCandidate($evidence, null, 200);
    }
}
