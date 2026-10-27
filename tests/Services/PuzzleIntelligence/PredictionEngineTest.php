<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PriorLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimeline;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionInputSolve;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use SpeedPuzzling\Web\Value\WindowBucket;
use Symfony\Component\Clock\MockClock;

/**
 * The in-memory replay of the model (PredictionEngine) and its value pieces: every attempt sees only what was known
 * before it.
 */
final class PredictionEngineTest extends TestCase
{
    private const float DAY = 86400.0;

    /**
     * Three solvers of one puzzle; the second solved it before having any pace (their first pace comes from a row
     * formed later). Each attempt's difficulty knows only the indices known strictly before it.
     */
    public function testTheDifficultyOfAnAttemptKnowsOnlyTheIndicesBeforeIt(): void
    {
        $timeline = $this->timeline();
        $timeline->add('a', 500, 1 * self::DAY, 1 * self::DAY, 2000);
        $timeline->add('b', 500, 30 * self::DAY, 30 * self::DAY, 3000);
        $timeline->add('c', 500, 1 * self::DAY, 1 * self::DAY, 2500);

        $set = FirstAttemptSet::build([
            self::row('t1', 'a', 'puzzle', 2200, 10 * self::DAY),
            self::row('t2', 'b', 'puzzle', 3300, 20 * self::DAY), // before b's first row (day 30): known from day 30
            self::row('t3', 'c', 'puzzle', 2500, 25 * self::DAY),
            self::row('t4', 'a', 'other', 2000, 40 * self::DAY),
        ], $timeline);

        $engine = new PredictionEngine($set, $timeline, new TimePredictionCalculator());
        $engine->computeAsOfDifficulty();

        // The set keeps the pace order (player, pieces, moment): look the attempts up by their id
        $at = static fn (string $id): int => (int) array_search($id, $set->ids(), true);

        self::assertSame(0, $engine->k[$at('t1')], 'Nobody before the first solver');
        self::assertSame(1, $engine->k[$at('t2')], 'a known');
        self::assertSame(1, $engine->k[$at('t3')], "b's index is not known yet on day 25");
        self::assertEqualsWithDelta(log(2200 / 2000), $engine->sumLog[$at('t3')], 0.000001);
        self::assertSame(0, $engine->k[$at('t4')]);
        self::assertSame(2, $set->position[$at('t4')], "a's second first attempt at 500");
    }

    public function testDriftAfterAnAttemptIgnoresEverythingAfterIt(): void
    {
        $build = function (bool $withLaterSolve): PredictionEngine {
            $timeline = $this->timeline();
            $rows = [];

            // Rows of the pace history after the 5th..9th attempt
            for ($i = 1; $i <= 9; $i++) {
                $rows[] = self::row("t{$i}", 'a', "puzzle-{$i}", $i <= 5 ? 2000 : 1600, $i * self::DAY);

                if ($i >= 5) {
                    $timeline->add('a', 500, $i * self::DAY, $i * self::DAY, 2000);
                }
            }

            if ($withLaterSolve) {
                $rows[] = self::row('t10', 'a', 'puzzle-10', 9000, 10 * self::DAY);
                $timeline->add('a', 500, 10 * self::DAY, 10 * self::DAY, 2100);
            }

            $set = FirstAttemptSet::build($rows, $timeline);
            $engine = new PredictionEngine($set, $timeline, new TimePredictionCalculator());
            $engine->computeAsOfDifficulty();
            $engine->computeDrift(new PriorLookup([], [], []), 1.0);

            return $engine;
        };

        $without = $build(false);
        $with = $build(true);

        self::assertNull($without->driftAfter[3], 'No pace row before the 5th attempt');
        self::assertSame(0.0, $without->driftAfter[4]);
        self::assertSame(TimePredictionCalculator::drift(array_fill(0, 4, log(1600 / 2000))), $without->driftAfter[8]);

        for ($i = 0; $i < 9; $i++) {
            self::assertSame($without->driftAfter[$i], $with->driftAfter[$i]);
            self::assertSame($without->spreadAfter[$i], $with->spreadAfter[$i]);
        }
    }

    /**
     * A 0-second time is no measurement: after the pair's 5th attempt it must not drag the drift to -INF (a prediction
     * of 1 second for the next 10 first tries) - it leaves the drift as it was, like the pace history and the
     * difficulty skip it.
     */
    public function testAZeroSecondAttemptLeavesTheDriftAsItWas(): void
    {
        $build = function (bool $withZero): PredictionEngine {
            $timeline = $this->timeline();
            $rows = [];

            for ($i = 1; $i <= 8; $i++) {
                $seconds = $withZero && $i === 6 ? 0 : 1900;
                $rows[] = self::row("t{$i}", 'a', "puzzle-{$i}", $seconds, $i * self::DAY);

                if ($i >= 5) {
                    $timeline->add('a', 500, $i * self::DAY, $i * self::DAY, 2000);
                }
            }

            $set = FirstAttemptSet::build($rows, $timeline);
            $engine = new PredictionEngine($set, $timeline, new TimePredictionCalculator());
            $engine->computeAsOfDifficulty();
            $engine->computeDrift(new PriorLookup([], [], []), 1.0);

            return $engine;
        };

        $with = $build(true);
        $without = $build(false);
        $at = static fn (PredictionEngine $engine, string $id): int => (int) array_search($id, $engine->set->ids(), true);

        foreach (['t5', 't6', 't7', 't8'] as $id) {
            $drift = $with->driftAfter[$at($with, $id)];
            self::assertNotNull($drift);
            self::assertTrue(is_finite($drift), "Drift after {$id} is finite");
            self::assertTrue(is_finite((float) $with->spreadAfter[$at($with, $id)]));
        }

        // The zero attempt counts nothing: after it the drift is the one before it
        self::assertSame($with->driftAfter[$at($with, 't5')], $with->driftAfter[$at($with, 't6')]);
        // Residuals of the attempts with a pace row before them (day 5 on): t6..t8, without the zero one t7 and t8
        self::assertSame(TimePredictionCalculator::drift(array_fill(0, 2, log(1900 / 2000))), $with->driftAfter[$at($with, 't8')]);
        self::assertSame(TimePredictionCalculator::drift(array_fill(0, 3, log(1900 / 2000))), $without->driftAfter[$at($without, 't8')]);

        // The next first try is predicted from a sane pace (2000 s × the drift), not 1 second
        $next = $with->calculator()->statistical(new StatisticalPredictionInputs(
            piecesCount: 500,
            ownBaselineSeconds: 2000,
            ownBaselineDirect: true,
            ownQualifyingCount: 8,
            ownDrift: $with->driftAfter[$at($with, 't8')],
            ownSpread: $with->spreadAfter[$at($with, 't8')],
            pace500Seconds: 2000,
            drift500: $with->driftAfter[$at($with, 't8')],
            spread500: $with->spreadAfter[$at($with, 't8')],
            kIndices: 10,
            sumLogIndices: 0.0,
            difficultyScore: 1.0,
            prior: new PriorContext('puzzle-9', 500),
        ));
        self::assertNotNull($next);
        self::assertGreaterThan(1900, $next->predictedSeconds);
        self::assertLessThan(2000, $next->predictedSeconds);
    }

    public function testOneWildTimeMovesTheDriftByAtMostItsClip(): void
    {
        // A typo (10× the pace) next to three normal attempts: clipped at +0.5 instead of ln(10) = 2.3
        self::assertSame(round(0.5 / 9, 4), TimePredictionCalculator::drift([0.0, 0.0, 0.0, log(10.0)]));
        self::assertSame(round(-0.5 / 6, 4), TimePredictionCalculator::drift([log(0.01)]));
        self::assertSame(round(0.2 / 6, 4), TimePredictionCalculator::drift([0.2]));
    }

    public function testThePriorLeavesThePuzzlesOwnIndicesOut(): void
    {
        $timeline = $this->timeline();
        $rows = [];

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $index => $player) {
            $timeline->add($player, 500, 1 * self::DAY, 1 * self::DAY, 2000);
            $rows[] = self::row("x{$index}", $player, 'own', 2400, 10 * self::DAY);
            $rows[] = self::row("y{$index}", $player, 'sibling', 2000, 11 * self::DAY);
        }

        $set = FirstAttemptSet::build($rows, $timeline);
        $priors = PriorLookup::fit($set, ['own' => 'brand', 'sibling' => 'brand'], cutBefore: 11 * self::DAY);

        // Fitted before day 11: only "own" has indices - its prior would be itself, so it has none ...
        self::assertNull((new TimePredictionCalculator())->prior($priors->contextFor('own', 500)));
        // ... and its sibling's prior is "own"'s mean log index, shrunk with 20 pseudo-indices
        self::assertEqualsWithDelta(7 * log(1.2) / 27, (new TimePredictionCalculator())->prior($priors->contextFor('sibling', 500))?->logDifficulty, 0.000001);
    }

    public function testParametersSurviveTheirJsonAndFallBackPartByPart(): void
    {
        $parameters = PredictionModelParameters::defaults()->with(
            tau: 0.5,
            thinSizes: [PiecesGroup::Thousand->value => ['alpha' => 1.44, 'max_own_solves' => PredictionModelFitter::ALWAYS]],
            quantiles: ['f' => [-1.0, -0.5, 0.5, 1.0], 'f|k20' => [-1.2, -0.6, 0.6, 1.2]],
        );

        $decoded = json_decode(json_encode($parameters->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $restored = PredictionModelParameters::fromArray($decoded);

        self::assertEquals($parameters, $restored);
        self::assertEquals(PredictionModelParameters::defaults(), PredictionModelParameters::fromArray([]));

        // A bucket without its own quantiles falls back to the less specific ones
        self::assertSame([-1.2, -0.6, 0.6, 1.2], $parameters->quantilesFor(WindowBucket::firstTry(0.1, [0.1, 0.2, 0.3], 25, PiecesGroup::FiveHundred)));
        self::assertSame([-1.0, -0.5, 0.5, 1.0], $parameters->quantilesFor(WindowBucket::firstTry(0.1, [0.1, 0.2, 0.3], 3, PiecesGroup::FiveHundred)));
    }

    public function testBucketsAndGroups(): void
    {
        self::assertSame(PiecesGroup::UpTo300, PiecesGroup::fromPiecesCount(300));
        self::assertSame(PiecesGroup::FiveHundred, PiecesGroup::fromPiecesCount(500));
        self::assertSame(PiecesGroup::Odd, PiecesGroup::fromPiecesCount(750));
        self::assertSame(PiecesGroup::Thousand, PiecesGroup::fromPiecesCount(1000));
        self::assertSame(PiecesGroup::From1500, PiecesGroup::fromPiecesCount(1500));

        self::assertSame('f|c0|k0|pieces_500', WindowBucket::firstTry(0.05, [0.1, 0.2, 0.3], 0, PiecesGroup::FiveHundred)->key());
        self::assertSame('f|c3|k20|up_to_300', WindowBucket::firstTry(0.5, [0.1, 0.2, 0.3], 40, PiecesGroup::UpTo300)->key());
        self::assertSame('r|c2|pieces_1000', WindowBucket::repeat(0.25, [0.1, 0.2, 0.3], PiecesGroup::Thousand)->key());
    }

    public function testNonNegativeLeastSquaresFixesANegativeShareAtZero(): void
    {
        // y = 2·x1 exactly; x2 would get a negative weight from the noise-free rows
        $rows = [[1.0, 1.0], [2.0, 1.0], [3.0, 2.0], [4.0, 3.0]];
        $targets = [2.0, 4.0, 6.0, 8.0];

        $coefficients = PredictionModelFitter::nonNegativeLeastSquares($rows, $targets);

        self::assertEqualsWithDelta(2.0, $coefficients[0], 0.000001);
        self::assertEqualsWithDelta(0.0, $coefficients[1], 0.000001);
        self::assertGreaterThanOrEqual(0.0, $coefficients[1]);
    }

    public function testAPriorOnlyPredictionIsNotShown(): void
    {
        $priorOnly = SolvingTimePrediction::predicted(new TimePredictionResult(2000, 1800, 2200, 1.0, kIndices: 0), TimePredictionSource::Live);
        self::assertFalse($priorOnly->forDisplay()->isPredictable());

        $shrunk = SolvingTimePrediction::predicted(new TimePredictionResult(2000, 1800, 2200, 1.0, kIndices: 2), TimePredictionSource::Live);
        self::assertSame($shrunk, $shrunk->forDisplay());

        $personal = SolvingTimePrediction::predicted(new TimePredictionResult(2000, 1800, 2200, 0.0, isPersonalized: true), TimePredictionSource::Live);
        self::assertSame($personal, $personal->forDisplay());
    }

    public function testRepeatResidualsBeforeASolveLeaveOutSolvesOfTheSameMoment(): void
    {
        $solve = static fn (string $id, float $moment, null|int $stored, bool $qualifying = true): PredictionInputSolve => new PredictionInputSolve($id, 'puzzle', 500, 1000, false, $qualifying, $moment, $moment, $stored);

        $before = RepeatResiduals::beforeEverySolve([
            $solve('a', 1.0, 1000),
            $solve('b', 2.0, 2000),
            $solve('c', 2.0, 500), // the same moment as b
            $solve('d', 3.0, 800, qualifying: false),
            $solve('e', 4.0, null),
        ]);

        self::assertSame([], $before['a']);
        self::assertSame([0.0], $before['b']);
        self::assertSame([0.0], $before['c'], 'b is not before c');
        self::assertEqualsWithDelta([0.0, log(0.5), log(2.0)], $before['d'], 0.000001);
        self::assertEqualsWithDelta([0.0, log(0.5), log(2.0)], $before['e'], 0.000001, 'A suspicious or unboxed solve is no input');
    }

    /**
     * @return array{id: string, player_id: string, puzzle_id: string, pieces_count: int, seconds_to_solve: int, solved_ts: float, tracked_ts: float}
     */
    private static function row(string $id, string $player, string $puzzle, int $seconds, float $moment, int $pieces = 500): array
    {
        return ['id' => $id, 'player_id' => $player, 'puzzle_id' => $puzzle, 'pieces_count' => $pieces, 'seconds_to_solve' => $seconds, 'solved_ts' => $moment, 'tracked_ts' => $moment];
    }

    private function timeline(): PaceTimeline
    {
        return new PaceTimeline(new PlayerBaselineCalculator($this->createStub(Connection::class), new MockClock(TestClock::now())), 1.3);
    }
}
