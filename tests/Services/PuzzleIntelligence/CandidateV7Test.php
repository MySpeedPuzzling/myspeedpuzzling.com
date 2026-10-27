<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePaceOffsets;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\SizeCurve;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\TasteModel;
use SpeedPuzzling\Web\Value\TasteState;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;

/**
 * The candidate model's v7 parts (never shown - README "Step 8", "Candidate v7"): the difficulty-adjusted pace's
 * offsets as the live and reconstructed paths read them, and the player's own difficulty slope.
 */
final class CandidateV7Test extends TestCase
{
    public function testStoredOffsetsAreReadAsOfAMoment(): void
    {
        $offsets = new CandidatePaceOffsets();
        $offsets->add('a', 500, 100.0, 100.0, -0.05);
        $offsets->add('a', 500, 200.0, 200.0, -0.08);
        $offsets->add('a', 1000, 150.0, 150.0, 0.02);

        self::assertNull($offsets->at('a', 500, 100.0, 100.0), 'a row is valid strictly after its own moment');
        self::assertSame(-0.05, $offsets->at('a', 500, 100.0, 100.0, inclusive: true));
        self::assertSame(-0.05, $offsets->at('a', 500, 199.0, 199.0));
        self::assertSame(-0.08, $offsets->at('a', 500, 1e9, 1e9));
        self::assertNull($offsets->at('b', 500, 1e9, 1e9));
        // The rows of the player's sizes at a moment: 500 and 1000 at 160, only 500 at 120
        self::assertEqualsWithDelta((-0.05 + 0.02) / 2, $offsets->meanAt('a', [500, 1000], 160.0, 160.0), 1e-12);
        self::assertSame(-0.05, $offsets->meanAt('a', [500, 1000], 120.0, 120.0));
        self::assertSame(0.0, $offsets->meanAt('a', [500, 1000], 50.0, 50.0));
    }

    /**
     * Live, the offset rides on the drift of the player's latest pace row (one more element of the row's JSON): the
     * own-pace prediction moves by exactly e^offset - and not at all while the fit has the adjusted pace off.
     */
    public function testTheLiveOffsetMovesThePaceRowItBelongsTo(): void
    {
        $parameters = CandidateModelParameters::defaults()->with(sizeCurve: new SizeCurve());
        $row = ['candidate_pace_rows' => json_encode([[500, 3600, 0.02, 0.12, 20, -0.07]], JSON_THROW_ON_ERROR)];
        $withOffset = StatisticalInputsSql::hydrateCandidatePace($row, self::inputs(), $parameters);
        $legacyRow = ['candidate_pace_rows' => json_encode([[500, 3600, 0.02, 0.12, 20]], JSON_THROW_ON_ERROR)];
        $without = StatisticalInputsSql::hydrateCandidatePace($legacyRow, self::inputs(), $parameters);
        $off = $parameters->with(features: CandidateFeatures::without(['adjusted_pace'])->toArray());

        self::assertSame([[500, 3600, 0.02 - 0.07, 0.12, 20]], $withOffset->candidatePaceRows);
        self::assertSame([[500, 3600, 0.02, 0.12, 20]], $without->candidatePaceRows);
        self::assertSame([[500, 3600, 0.02, 0.12, 20]], StatisticalInputsSql::hydrateCandidatePace($row, self::inputs(), $off)->candidatePaceRows);

        $calculator = new CandidatePredictionCalculator();
        $a = $calculator->firstTry($withOffset, $parameters);
        $b = $calculator->firstTry($without, $parameters);
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertEqualsWithDelta($b->predictedSeconds * exp(-0.07), $a->predictedSeconds, 1.0);
    }

    /**
     * The slope: a player who loses more than predicted on hard puzzles and gains on easy ones gets γ > 0 - their
     * prediction for a hard puzzle goes up, for an easy one down, for their average puzzle not at all; shrunk with few.
     */
    public function testThePlayersDifficultySlopeIsAShrunkRegressionOfTheirResidualsOnTheDifficulty(): void
    {
        $model = new TasteModel('1.0.0', array_fill(0, TasteModel::RANK, array_fill(0, TasteModel::FEATURES, 0.0)), 100.0, 30.0, 10.0);
        $vector = $model->vector(null, 500);
        $state = $model->initialState();
        $difficulties = [];

        $left = [];

        for ($i = 0; $i < 200; $i++) {
            $d = ($i % 9 - 4) * 0.05;
            $difficulties[] = $d;
            // Residual 0.3 × d: the player's slope 0.3 - what the slope reads is it less the taste term known before
            $left[] = 0.3 * $d - $state->term($vector);
            $state = $state->with($vector, 0.3 * $d, false, $d);
        }

        $mean = array_sum($difficulties) / count($difficulties);
        $sxx = 0.0;
        $sxr = 0.0;

        foreach ($difficulties as $n => $d) {
            $sxx += ($d - $mean) ** 2;
            $sxr += ($d - $mean) * $left[$n];
        }

        $gamma = $sxr / ($sxx + TasteModel::SLOPE_LAMBDA);
        self::assertEqualsWithDelta(0.3 * $sxx / ($sxx + TasteModel::SLOPE_LAMBDA), $gamma, 0.01, 'about the planted slope, shrunk');

        self::assertEqualsWithDelta($gamma * (0.2 - $mean), $state->slopeTerm(0.2, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM), 1e-9);
        self::assertGreaterThan(0.0, $state->slopeTerm(0.2, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM));
        self::assertLessThan(0.0, $state->slopeTerm(-0.2, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM));
        self::assertEqualsWithDelta(0.0, $state->slopeTerm($mean, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM), 1e-12);
        self::assertLessThan(0.3 * 0.2, $state->slopeTerm(0.2, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM), 'shrunk');

        // Below the minimum, nothing; a first try without a difficulty adds nothing to the slope
        $few = $model->initialState()->with($vector, 0.1, false, 0.2)->with($vector, -0.1, false, -0.2);
        self::assertSame(0.0, $few->slopeTerm(0.3, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM));
        self::assertSame($state->slope, $state->with($vector, 0.5, false)->slope);

        // Stored with the state (absent before v7: zeros)
        self::assertEquals($state, TasteState::fromJson(json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), TasteModel::RANK));
        self::assertArrayNotHasKey('s', $model->initialState()->toArray());
        self::assertSame([0, 0.0, 0.0, 0.0, 0.0], TasteState::fromJson(json_encode($model->initialState()->toArray(), JSON_THROW_ON_ERROR), TasteModel::RANK)?->slope);
    }

    /**
     * The slope joins the taste term of a first try (switch player_slope) - with the puzzle's posterior log difficulty.
     */
    public function testTheSlopeJoinsTheTasteTermOfAFirstTry(): void
    {
        $model = new TasteModel('1.0.0', array_fill(0, TasteModel::RANK, array_fill(0, TasteModel::FEATURES, 0.0)), 100.0, 30.0, 10.0);
        $vector = $model->vector(null, 500);
        $state = $model->initialState();

        for ($i = 0; $i < 100; $i++) {
            $d = ($i % 5 - 2) * 0.1;
            $state = $state->with($vector, 0.5 * $d, false, $d);
        }

        $parameters = CandidateModelParameters::defaults()->with(taste: $model);
        $picture = new PictureFactors(null, null, ImageKind::Flat);
        // A hard puzzle: three indices of 0.3
        $evidence = new CandidateEvidence(3, 3 / 0.016, 3 * 0.3 / 0.016, 0.9, 0.27, 0.048);
        $inputs = self::inputs()->withCandidate($evidence, 0.0, 200)->withCandidateTaste($state, $picture);
        $calculator = new CandidatePredictionCalculator();

        $with = $calculator->firstTry($inputs, $parameters);
        $without = $calculator->firstTry($inputs, $parameters->with(features: CandidateFeatures::without(['player_slope'])->toArray()));
        self::assertNotNull($with);
        self::assertNotNull($without);
        $slope = $state->slopeTerm((float) $with->logDifficulty, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM);
        self::assertGreaterThan(0.01, $slope);
        self::assertSame(TimePredictionCalculator::seconds($without->predictedSeconds * exp($slope)), $with->predictedSeconds);
        self::assertSame($state->slopeTerm(0.1, TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM), CandidatePredictionCalculator::taste($inputs, $parameters, false, 0.1)[0]);
        self::assertEqualsWithDelta(0.0, CandidatePredictionCalculator::taste($inputs, $parameters, false)[0], 1e-15, 'no difficulty, no slope');
    }

    private static function inputs(): StatisticalPredictionInputs
    {
        return new StatisticalPredictionInputs(
            piecesCount: 500,
            ownBaselineSeconds: 3600,
            ownBaselineDirect: true,
            ownQualifyingCount: 20,
            ownDrift: 0.02,
            ownSpread: 0.12,
            pace500Seconds: 3600,
            drift500: 0.02,
            spread500: 0.12,
            kIndices: 0,
            sumLogIndices: null,
            difficultyScore: null,
            prior: new PriorContext('puzzle', 500),
        )->withCandidate(CandidateEvidence::none(), 0.05, 200);
    }
}
