<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AttemptFlags;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PuzzleWeightedBrandPrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\TasteModel;
use SpeedPuzzling\Web\Value\TasteState;

/**
 * The candidate prediction model (v4, never shown) - its formulas pinned to hand-computed numbers, and the guard that
 * nothing of it reaches the shown model (v3).
 */
final class CandidateModelTest extends TestCase
{
    public function testWithEqualWeightsThePosteriorIsTheShownShrunkMean(): void
    {
        // Three indices of noise 0.016 each (weight 62.5), a prior of variance 0.008: tau = 0.016 / 0.008 = 2
        $evidence = self::evidence([0.1, 0.3, -0.05], 0.016);
        [$mean, $variance] = $evidence->posterior(0.2, 0.008);

        self::assertEqualsWithDelta((0.35 + 2 * 0.2) / (3 + 2), $mean, 1e-12);
        self::assertEqualsWithDelta(0.15, $mean, 1e-12);
        self::assertEqualsWithDelta(1 / 312.5, $variance, 1e-12);
    }

    public function testWithoutSolvesThePosteriorIsThePrior(): void
    {
        self::assertSame([0.12, 0.01], array_map(static fn (float $value): float => round($value, 12), CandidateEvidence::none()->posterior(0.12, 0.01)));
    }

    public function testDispersionIsTheShrunkExcessOverTheIndicesOwnNoise(): void
    {
        $evidence = self::evidence([0.1, 0.3, -0.05], 0.016);

        // Plain variance 0.0308333, mean noise 0.016: excess 0.0148333, × (k - 1) ÷ (k - 1 + shrink)
        self::assertEqualsWithDelta(0.0148333333, $evidence->dispersion(0.0), 1e-9);
        self::assertEqualsWithDelta(0.0148333333 * 2 / 12, $evidence->dispersion(10.0), 1e-9);
        self::assertSame(0.0, self::evidence([0.1], 0.016)->dispersion(10.0), 'one index has no dispersion');
        self::assertSame(0.0, self::evidence([0.1, 0.11], 0.016)->dispersion(10.0), 'agreeing more than their noise - never negative');
    }

    public function testIndexVarianceAddsTheTermsOfItsFlags(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(indexNoise: [
            'spread2' => 0.4, 'inv_q' => 0.1, 'constant' => 0.002, 'indirect' => 0.02,
            'unflagged' => 0.003, 'after_group' => 0.0, 'commented' => 0.004,
        ]);

        self::assertEqualsWithDelta(0.4 * 0.0144 + 0.01 + 0.002 + 0.003 + 0.004, $parameters->indexVariance(0.12, 10, AttemptFlags::UNFLAGGED | AttemptFlags::COMMENTED, false), 1e-12);
        self::assertEqualsWithDelta(0.4 * 0.0144 + 0.002 + 0.02, $parameters->indexVariance(0.12, 0, 0, true), 1e-12);
        // The competition flag is no noise term
        self::assertEqualsWithDelta(0.4 * 0.0144 + 0.01 + 0.002, $parameters->indexVariance(0.12, 10, AttemptFlags::COMPETITION, false), 1e-12);
    }

    public function testAnIndexIsDriftAdjustedClippedAndWeighedByItsNoise(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(indexNoise: ['spread2' => 0.4, 'inv_q' => 0.1, 'constant' => 0.002, 'indirect' => 0.02, 'unflagged' => 0.003], indexClip: 1.0);

        [$log, $variance, $weight] = CandidateEngine::term(0.3, 0.05, 0.12, 10, AttemptFlags::UNFLAGGED, $parameters);
        self::assertEqualsWithDelta(0.25, $log, 1e-12);
        self::assertEqualsWithDelta(0.4 * 0.0144 + 0.01 + 0.002 + 0.003, $variance, 1e-12);
        self::assertEqualsWithDelta(1 / $variance, $weight, 1e-12);

        self::assertSame(1.0, CandidateEngine::term(1.6, 0.0, 0.12, 10, 0, $parameters)[0], 'clipped');

        // Switched off: the raw index, and the flags weigh nothing
        $off = $parameters->with(features: CandidateFeatures::without(['drift_index', 'hygiene'])->toArray());
        [$rawLog, $plainVariance] = CandidateEngine::term(0.3, 0.05, 0.12, 10, AttemptFlags::UNFLAGGED, $off);
        self::assertEqualsWithDelta(0.3, $rawLog, 1e-12);
        self::assertEqualsWithDelta(0.4 * 0.0144 + 0.01 + 0.002, $plainVariance, 1e-12);
    }

    public function testThePuzzleWeightedBrandPriorLeavesThePuzzleOut(): void
    {
        self::assertEqualsWithDelta((0.5 - 0.2) / (5 + 3), PuzzleWeightedBrandPrior::fromSums(0.5, 6, 0.2, 3.0), 1e-12);
        self::assertEqualsWithDelta(0.5 / (6 + 3), PuzzleWeightedBrandPrior::fromSums(0.5, 6, null, 3.0), 1e-12);
        self::assertNull(PuzzleWeightedBrandPrior::fromSums(0.4, 2, 0.1, 3.0), 'one other puzzle is too few');
    }

    public function testAFirstTryIsPaceTimesDriftTimesThePosteriorTimesItsOffsets(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(
            priorVariances: ['image_estimate' => 0.008, 'brand_pieces' => 0.03, 'none' => 0.04],
            centreOffsets: ['pieces_500|own|k1' => -0.01, 'experience|lt30' => 0.02],
            unboxedOffset: 0.1,
        );
        $inputs = self::inputs(self::evidence([0.1, 0.3, -0.05], 0.016), new ImagePriorInput(0.1, -0.2, 0.4, 'ridge', ImageKind::Flat));
        $calculator = new CandidatePredictionCalculator();

        $prediction = $calculator->firstTry($inputs, $parameters);
        self::assertNotNull($prediction);
        // Posterior (62.5 × 0.35 + 0.1 ÷ 0.008) ÷ (187.5 + 125) = 0.11
        self::assertEqualsWithDelta(0.11, (float) $prediction->logDifficulty, 1e-6);
        self::assertSame(TimePredictionCalculator::seconds(3600 * exp(0.02 + 0.11 - 0.01)), $prediction->predictedSeconds);
        self::assertSame(DifficultySource::Shrunk, $prediction->difficultySource);
        self::assertSame(3, $prediction->kIndices);
        self::assertSame(0.1, $prediction->priorLog);
        self::assertLessThan($prediction->predictedSeconds, $prediction->rangeLowSeconds);
        self::assertGreaterThan($prediction->predictedSeconds, $prediction->rangeHighSeconds);

        $unboxed = $calculator->firstTry($inputs, $parameters, new SolveConditions(unboxed: true));
        self::assertNotNull($unboxed);
        self::assertSame(TimePredictionCalculator::seconds(3600 * exp(0.02 + 0.11 - 0.01 + 0.1)), $unboxed->predictedSeconds);

        $newPlayer = $calculator->firstTry($inputs->withCandidate($inputs->candidateEvidence, null, 12), $parameters);
        self::assertNotNull($newPlayer);
        self::assertSame(TimePredictionCalculator::seconds(3600 * exp(0.02 + 0.11 - 0.01 + 0.02)), $newPlayer->predictedSeconds);
    }

    public function testWithoutAnEstimateTheBrandPriorAndWithoutEitherTheAveragePuzzle(): void
    {
        $calculator = new CandidatePredictionCalculator();
        $parameters = CandidateModelParameters::defaults();
        $brand = self::inputs(CandidateEvidence::none(), null, brandPrior: 0.07);

        self::assertSame(['brand_pieces', 0.07], $calculator->prior($brand, true, true));
        self::assertSame(['none', null], $calculator->prior(self::inputs(CandidateEvidence::none(), null), true, true));

        $prediction = $calculator->firstTry($brand, $parameters);
        self::assertNotNull($prediction);
        self::assertSame(0, $prediction->kIndices);
        self::assertEqualsWithDelta(0.07, (float) $prediction->logDifficulty, 1e-9, 'k = 0: the prior itself');
    }

    public function testARepeatWindowKnowsTheGapSinceTheLastSolve(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(
            repeatGapOffsets: ['gt12m' => 0.05],
            repeatGapTerms: ['gt12m' => 0.01],
            quantiles: ['w|ggt12m' => [-1.3, -0.7, 0.7, 1.3]],
        );
        $shown = new TimePredictionResult(1000, 900, 1100, 0.0, true, 1, 2, 1000);
        $calculator = new CandidatePredictionCalculator();

        $old = $calculator->repeatWindow($shown, 0.1, PiecesGroup::FiveHundred, 400, $parameters);
        self::assertSame(1051, $old->predictedSeconds);
        self::assertStringEndsWith('|ggt12m', $old->windowBucket ?? '');
        self::assertTrue($old->isPersonalized);

        $sigma = sqrt($parameters->repeatSpreadWeight * 0.01 + $parameters->repeatConstant + 0.01);
        self::assertSame(TimePredictionCalculator::seconds(1000 * exp(0.05) * exp(-0.7 * $sigma)), $old->rangeLowSeconds);

        $recent = $calculator->repeatWindow($shown, 0.1, PiecesGroup::FiveHundred, 10, $parameters);
        self::assertSame(1000, $recent->predictedSeconds);
    }

    public function testTheFitRoundTripsThroughItsJson(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(
            centreOffsets: ['pieces_500|own|k1' => -0.01],
            quantiles: ['v' => [-1.2, -0.6, 0.6, 1.2]],
            features: CandidateFeatures::without(['hygiene'])->toArray(),
            thinSizes: [PiecesGroup::Thousand->value => ['alpha' => 1.4, 'max_own_solves' => 50]],
        );
        $decoded = json_decode(json_encode($parameters->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $restored = CandidateModelParameters::fromArray($decoded);

        self::assertEquals($parameters->toArray(), $restored->toArray());
        self::assertFalse($restored->features()->on('hygiene'));
        self::assertSame(['alpha' => 1.4, 'max_own_solves' => 50], $restored->paceParameters->thinSizeRule(PiecesGroup::Thousand));
    }

    /**
     * The guard: the shown model (v3) never reads the candidate's inputs - the same prediction with or without them, and
     * the shown readers' SQL carries none of the candidate's columns.
     */
    public function testTheShownModelIgnoresEveryCandidateInput(): void
    {
        $calculator = new TimePredictionCalculator();
        $parameters = PredictionModelParameters::defaults();
        $plain = self::inputs(null, null);
        $withCandidate = $plain->withCandidate(self::evidence([0.4, 0.5], 0.01), 0.3, 500)
            ->withCandidateTaste(TasteState::initial(30.0, 100.0, TasteModel::RANK)->with([1.0, 0.2, -0.1, 0.3, 0.0], 0.2, true), new PictureFactors(array_fill(0, 16, 0.5), '1.0.0', ImageKind::Box));

        self::assertEquals($calculator->statistical($plain, $parameters, allowPriorOnly: true), $calculator->statistical($withCandidate, $parameters, allowPriorOnly: true));
        self::assertEquals($calculator->sideBySide($plain, null, $parameters), $calculator->sideBySide($withCandidate, null, $parameters));

        foreach ([StatisticalInputsSql::COLUMNS, StatisticalInputsSql::JOINS, StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS, StatisticalInputsSql::imageEstimateJoins(false), StatisticalInputsSql::lateralPaceJoins(), StatisticalInputsSql::ownPaceCte(), StatisticalInputsSql::centreSql($parameters)[0], StatisticalInputsSql::centreSql($parameters)[1], StatisticalInputsSql::centreSql($parameters)[2]] as $sql) {
            // ("candidate" alone is the Picker's word for a listed puzzle)
            self::assertStringNotContainsString('candidate_', $sql);
            self::assertStringNotContainsString('candidate_puzzle_evidence', $sql);
            // The taste factor's inputs ride only on the candidate's own statement
            self::assertStringNotContainsString('picture_factors', $sql);
            // v6: nor the look-alike numbers of the k = 0 window
            self::assertStringNotContainsString('pieceLookalikeShare', $sql);
            self::assertStringNotContainsString('piece_scale', $sql);
        }

        self::assertStringContainsString('pieceLookalikeShare', StatisticalInputsSql::CANDIDATE_TASTE_COLUMNS, 'only on the candidate\'s own statement');

        self::assertArrayNotHasKey('index_noise', $parameters->toArray(), 'the shown fit stores none of the candidate');
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

    private static function inputs(null|CandidateEvidence $evidence, null|ImagePriorInput $estimate, null|float $brandPrior = null): StatisticalPredictionInputs
    {
        $inputs = new StatisticalPredictionInputs(
            piecesCount: 500,
            ownBaselineSeconds: 3600,
            ownBaselineDirect: true,
            ownQualifyingCount: 20,
            ownDrift: 0.02,
            ownSpread: 0.12,
            pace500Seconds: 3600,
            drift500: 0.02,
            spread500: 0.12,
            kIndices: $evidence !== null ? $evidence->k : 3,
            sumLogIndices: 0.35,
            difficultyScore: null,
            prior: new PriorContext('puzzle', 500, imageEstimate: $estimate),
        );

        return $evidence !== null ? $inputs->withCandidate($evidence, $brandPrior) : $inputs;
    }
}
