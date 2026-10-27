<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\TasteFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\TasteRows;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Value\TasteModel;
use SpeedPuzzling\Web\Value\TasteState;

/**
 * The candidate model's taste factor (v5, never shown): the closed-form player state, the puzzle's features, the fit
 * (ALS) recovering a planted taste with strictly as-of terms, and the first try it moves.
 */
final class TasteFactorTest extends TestCase
{
    public function testTheStateIsTheClosedFormRidgeSolutionOfTheEarlierFirstTries(): void
    {
        $vectors = [[1.0, 0.5, -0.2, 0.1, 0.0], [1.0, -0.3, 0.4, 0.0, 0.2], [1.0, 0.1, 0.1, -0.5, 0.3]];
        $residuals = [0.08, -0.05, 0.12];
        $state = TasteState::initial(30.0, 100.0, 4);

        foreach ($vectors as $i => $vector) {
            $state = $state->with($vector, $residuals[$i], false);
        }

        // A = diag(30, 100, 100, 100, 100) + Σ v vᵀ, g = Σ v r - solved directly
        $matrix = [];
        $rhs = array_fill(0, 5, 0.0);

        for ($i = 0; $i < 5; $i++) {
            for ($j = 0; $j < 5; $j++) {
                $matrix[$i][$j] = $i === $j ? ($i === 0 ? 30.0 : 100.0) : 0.0;

                foreach ($vectors as $vector) {
                    $matrix[$i][$j] += $vector[$i] * $vector[$j];
                }
            }

            foreach ($vectors as $k => $vector) {
                $rhs[$i] += $vector[$i] * $residuals[$k];
            }
        }

        $expected = TasteFitter::solve($matrix, $rhs);

        foreach ($expected as $i => $coefficient) {
            self::assertEqualsWithDelta($coefficient, $state->coefficients[$i], 1e-12);
        }

        self::assertSame(3, $state->firstTries);
        // The intercept moves with the mean residual, shrunk by 30: Σ r ÷ (3 + 30) for a near-orthogonal rest
        self::assertEqualsWithDelta(0.15 / 33, $state->coefficients[0], 0.002);

        $restored = TasteState::fromJson(json_encode($state->toArray(), JSON_THROW_ON_ERROR), 4);
        self::assertNotNull($restored);
        self::assertEquals($state->coefficients, $restored->coefficients);
        self::assertNull(TasteState::fromJson(json_encode($state->toArray(), JSON_THROW_ON_ERROR), 3), 'another rank is not read');
    }

    public function testNothingKnownIsNoTermWithThePriorVariance(): void
    {
        $state = TasteState::initial(30.0, 100.0, 4);
        $vector = [1.0, 0.5, -0.2, 0.1, 0.3];

        self::assertSame(0.0, $state->term($vector));
        self::assertEqualsWithDelta(1 / 30 + (0.25 + 0.04 + 0.01 + 0.09) / 100, $state->variance($vector), 1e-12);
        self::assertSame(0.0, $state->competitionOffset(10.0));

        // More first tries: the variance only shrinks
        $after = $state->with($vector, 0.1, false)->with($vector, 0.05, false);
        self::assertLessThan($state->variance($vector), $after->variance($vector));
    }

    public function testTheEventOffsetIsTheShrunkResidualLeftByTheTerm(): void
    {
        $vector = [1.0, 0.0, 0.0, 0.0, 0.0];
        $state = TasteState::initial(30.0, 100.0, 4)->with($vector, -0.1, true);

        // The first event's term before it was 0: its whole residual counts
        self::assertEqualsWithDelta(-0.1 / (1 + 10), $state->competitionOffset(10.0), 1e-12);

        $term = $state->term($vector);
        $state = $state->with($vector, -0.1, true);
        self::assertEqualsWithDelta((-0.1 + (-0.1 - $term)) / (2 + 10), $state->competitionOffset(10.0), 1e-12);
        self::assertSame(2, $state->competitionCount);
    }

    public function testThePuzzleFeaturesAreItsFactorsKindAndSize(): void
    {
        $factors = array_map(static fn (int $i): float => (float) $i / 10, range(1, 16));
        $features = TasteModel::features(new PictureFactors($factors, '1.0.0', ImageKind::Box), 1000, '1.0.0');

        self::assertCount(TasteModel::FEATURES, $features);
        self::assertSame($factors, array_slice($features, 0, 16));
        self::assertSame([1.0, 0.0, 0.0, 1.0, 0.0], array_slice($features, 16));

        $otherBasis = TasteModel::features(new PictureFactors($factors, '2.0.0', ImageKind::Assembled), 300, '1.0.0');
        self::assertSame(array_fill(0, 16, 0.0), array_slice($otherBasis, 0, 16), 'factors of another basis than W were fitted on are not read');
        self::assertSame([0.0, 1.0, 1.0, 0.0, 0.0], array_slice($otherBasis, 16));

        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0], array_slice(TasteModel::features(null, 500, '1.0.0'), 16), 'no picture, 500 pieces: nothing but the intercept');
        self::assertSame([0.0, 0.0, 0.0, 0.0, 1.0], array_slice(TasteModel::features(null, 2000, '1.0.0'), 16));

        self::assertNull(PictureFactors::fromColumns(null, null, null));
        self::assertNull(PictureFactors::fromColumns('[1, 2]', '1.0.0', null), 'not 16 factors and no kind: nothing');
        self::assertSame(ImageKind::Box, PictureFactors::fromColumns(null, null, 'box_front')?->kind);
        self::assertSame($factors, PictureFactors::fromColumns(json_encode($factors, JSON_THROW_ON_ERROR), '1.0.0', 'flat')?->factors);
    }

    public function testTheFitFindsAPlantedTasteAndEveryTermIsAsOfItsFirstTry(): void
    {
        [$rows, $truth] = self::plantedRows(80, 45);
        $states = [];
        $fit = new TasteFitter()->fit($rows, 'test', false, static function (int $player, float $solved, float $tracked, TasteState $state) use (&$states): void {
            $states[$player][] = [$solved, $state->firstTries];
        });

        self::assertNotNull($fit->model);
        self::assertNotNull($fit->terms);
        self::assertContains($fit->model->lambdaU, TasteFitter::LAMBDA_U_GRID);

        // As of each first try: a player's first one has no term (nothing earlier), later ones are learnt
        $before = 0.0;
        $after = 0.0;
        $late = 0;

        for ($r = 0; $r < $rows->count(); $r++) {
            $position = (int) round(fmod((float) $rows->solved[$r], 1e6) / 1000);

            if ($position === 0) {
                self::assertSame(0.0, (float) $fit->terms[$r], 'nothing before a player\'s first first try');
            }

            if ($position >= 25) {
                $before += abs((float) $rows->residual[$r]);
                $after += abs((float) $rows->residual[$r] - (float) $fit->terms[$r]);
                $late++;
            }
        }

        self::assertLessThan(0.8 * $before / $late, $after / $late, 'the taste explains the planted part');

        // A state is written after every first try, in time order: 1, 2, … per player
        foreach ($states as $player => $written) {
            self::assertSame(range(1, count($written)), array_column($written, 1));
            self::assertCount(45, $written);
        }

        // The served model with each player's final state: the gap between a picture high on the first factor and a
        // plain one follows the planted taste
        $model = $fit->model;
        $final = [];

        for ($r = 0; $r < $rows->count(); $r++) {
            $player = (int) $rows->player[$r];
            $final[$player] = ($final[$player] ?? $model->initialState())->with($model->vector($rows->picture($r), 500), (float) $rows->residual[$r], false);
        }

        $high = $model->vector(new PictureFactors(array_replace(array_fill(0, 16, 0.0), [0 => 1.5]), 'test', ImageKind::Flat), 500);
        $plain = $model->vector(new PictureFactors(array_fill(0, 16, 0.0), 'test', ImageKind::Flat), 500);
        $gaps = [];

        foreach ($final as $player => $state) {
            $gaps[$player] = $state->term($high) - $state->term($plain);
        }

        self::assertGreaterThan(0.9, self::correlation($gaps, $truth));
    }

    public function testTooFewFirstTriesFitNoTaste(): void
    {
        [$rows] = self::plantedRows(10, 20);
        $fit = new TasteFitter()->fit($rows, 'test', false);

        self::assertNull($fit->model);
        self::assertSame('too few first tries', $fit->summary['taste_skipped'] ?? null);
    }

    public function testAFirstTryCarriesThePlayersTermAndItsVariance(): void
    {
        $model = new TasteModel('1.0.0', array_fill(0, TasteModel::RANK, array_replace(array_fill(0, TasteModel::FEATURES, 0.0), [0 => 1.0])), 100.0, 30.0, 10.0);
        $parameters = CandidateModelParameters::defaults()->with(taste: $model, tasteVarianceWeight: 0.02);
        $picture = new PictureFactors(array_replace(array_fill(0, 16, 0.0), [0 => 0.5]), '1.0.0', ImageKind::Flat);
        $state = $model->initialState()->with($model->vector($picture, 500), 0.2, true)->with($model->vector($picture, 500), 0.15, false);
        $inputs = self::inputs()->withCandidateTaste($state, $picture);
        $calculator = new CandidatePredictionCalculator();

        $plain = $calculator->firstTry(self::inputs(), CandidateModelParameters::defaults());
        $with = $calculator->firstTry($inputs, $parameters);
        self::assertNotNull($plain);
        self::assertNotNull($with);

        $vector = $model->vector($picture, 500);
        self::assertSame([$state->term($vector), $state->variance($vector)], CandidatePredictionCalculator::taste($inputs, $parameters, false));
        self::assertSame(TimePredictionCalculator::seconds($plain->predictedSeconds * exp($state->term($vector))), $with->predictedSeconds);

        // At an event the player's event offset joins it
        [$atEvent] = CandidatePredictionCalculator::taste($inputs, $parameters, true);
        self::assertEqualsWithDelta($state->term($vector) + $state->competitionOffset(10.0), $atEvent, 1e-12);

        // Switched off, or no state known: no term
        $off = $parameters->with(features: CandidateFeatures::without(['taste'])->toArray());
        self::assertSame([0.0, 0.0], CandidatePredictionCalculator::taste($inputs, $off, true));
        self::assertSame(0.0, CandidatePredictionCalculator::taste(self::inputs(), $parameters, false)[0]);
        self::assertSame([0.0, 0.0], CandidatePredictionCalculator::taste($inputs, CandidateModelParameters::defaults(), true), 'no taste model fitted');

        $decoded = json_decode(json_encode($parameters->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertEquals($parameters->toArray(), CandidateModelParameters::fromArray($decoded)->toArray());
        self::assertNotEquals($plain->predictedSeconds, $calculator->firstTry($inputs, $parameters, new SolveConditions(competition: true))?->predictedSeconds);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private static function correlation(array $a, array $b): float
    {
        $keys = array_keys($a);
        $meanA = array_sum($a) / count($a);
        $meanB = array_sum(array_map(static fn (int $key): float => $b[$key], $keys)) / count($keys);
        [$covariance, $varianceA, $varianceB] = [0.0, 0.0, 0.0];

        foreach ($keys as $key) {
            $covariance += ($a[$key] - $meanA) * ($b[$key] - $meanB);
            $varianceA += ($a[$key] - $meanA) ** 2;
            $varianceB += ($b[$key] - $meanB) ** 2;
        }

        return $covariance / sqrt($varianceA * $varianceB);
    }

    /**
     * Players with a planted taste: residual = b + u · (2 × the first picture factor, 1 for box pictures) + noise; each
     * row's moment encodes its place in the player's order (solved = day × 1e6 + place × 1000).
     *
     * @return array{0: TasteRows, 1: array<int, float>}
     */
    private static function plantedRows(int $players, int $perPlayer): array
    {
        mt_srand(42);
        $rows = new TasteRows($players * $perPlayer);
        $truth = [];
        $puzzle = 0;

        for ($player = 0; $player < $players; $player++) {
            $truth[$player] = mt_rand(-100, 100) / 1000;
            $intercept = mt_rand(-30, 30) / 1000;

            for ($place = 0; $place < $perPlayer; $place++) {
                $factor = mt_rand(-150, 150) / 100;
                $box = mt_rand(0, 3) === 0;
                $rows->pictures[$puzzle] = new PictureFactors(array_replace(array_fill(0, 16, 0.0), [0 => $factor, 1 => mt_rand(-100, 100) / 100]), 'test', $box ? ImageKind::Box : ImageKind::Flat);
                $noise = (mt_rand(0, 1000) - 500) / 10000;
                $residual = $intercept + $truth[$player] * (2 * $factor + ($box ? 1.0 : 0.0)) + $noise;
                $rows->add($player, $puzzle, 500, ($place + 1) * 1e6 + $place * 1000 + $player, ($place + 1) * 1e6 + $place * 1000 + $player, $residual, false);
                $puzzle++;
            }
        }

        return [$rows, $truth];
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
