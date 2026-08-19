<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bulk read model must be indistinguishable from the single-puzzle one:
 * both delegate to TimePredictionCalculator, this test pins that they also
 * feed it the same inputs for every fixture player × puzzle pair.
 */
final class GetPlayerPredictionsTest extends KernelTestCase
{
    private const array PLAYERS = [
        PlayerFixture::PLAYER_REGULAR,
        PlayerFixture::PLAYER_PRIVATE,
        PlayerFixture::PLAYER_ADMIN,
        PlayerFixture::PLAYER_WITH_FAVORITES,
        PlayerFixture::PLAYER_WITH_STRIPE,
    ];

    private GetPlayerPrediction $single;

    private GetPlayerPredictions $bulk;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(PuzzleIntelligenceRecalculator::class)->recalculate();

        $this->single = $container->get(GetPlayerPrediction::class);
        $this->bulk = $container->get(GetPlayerPredictions::class);
        $this->database = $container->get(Connection::class);
    }

    public function testBulkEqualsSingleForEveryFixturePlayerAndPuzzle(): void
    {
        $puzzleIds = $this->allPuzzleIds();
        $personalPairs = 0;
        $statisticalPairs = 0;
        $absentPairs = 0;

        foreach (self::PLAYERS as $playerId) {
            $bulk = $this->bulk->forPuzzles($playerId, $puzzleIds);
            $personal = $this->bulk->forAllSolvedPuzzles($playerId);

            foreach ($puzzleIds as $puzzleId) {
                $single = $this->single->forPuzzle($playerId, $puzzleId);

                if ($single === null) {
                    self::assertArrayNotHasKey($puzzleId, $bulk, "Bulk must have no prediction for {$playerId} × {$puzzleId}");
                    $absentPairs++;

                    continue;
                }

                self::assertArrayHasKey($puzzleId, $bulk, "Bulk is missing {$playerId} × {$puzzleId}");
                self::assertEquals($single, $bulk[$puzzleId], "Bulk differs from single for {$playerId} × {$puzzleId}");

                if ($single->isPersonalized) {
                    $personalPairs++;
                } else {
                    $statisticalPairs++;
                }
            }

            $personalFromBulk = array_filter($bulk, static fn (TimePredictionResult $prediction): bool => $prediction->isPersonalized);
            ksort($personalFromBulk);
            ksort($personal);

            self::assertEquals($personalFromBulk, $personal, 'forAllSolvedPuzzles() is exactly the personal subset of forPuzzles()');
        }

        // Make sure the sweep exercised real cases, not an empty intersection
        self::assertGreaterThanOrEqual(20, $personalPairs);
        self::assertGreaterThan(0, $absentPairs);
        // Every fixture player has solved every scored puzzle, so a statistical
        // case never occurs naturally here - it is covered by the dedicated test
        self::assertSame(0, $statisticalPairs);
    }

    public function testRegularPlayerOnPuzzle50002IsAPersonalPredictionFromThreeAttempts(): void
    {
        // PLAYER_REGULAR on PUZZLE_500_02: 2200 -> 1900 -> 1700 (chronological), 10 days since the last solve
        $prediction = $this->bulk->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_02])[PuzzleFixture::PUZZLE_500_02];

        self::assertTrue($prediction->isPersonalized);
        self::assertSame(3, $prediction->personalSolveCount);
        self::assertSame(4, $prediction->predictedAttemptNumber);
        self::assertSame(1700, $prediction->lastTimeSeconds);
        self::assertSame(0.0, $prediction->difficultyForPlayer);
        // Improving player: predicted below the best time but never below the 70 % floor (1190)
        self::assertLessThan(1700, $prediction->predictedSeconds);
        self::assertGreaterThanOrEqual(1190, $prediction->predictedSeconds);
        self::assertGreaterThanOrEqual($prediction->rangeLowSeconds, $prediction->predictedSeconds);
        self::assertLessThanOrEqual($prediction->rangeHighSeconds, $prediction->predictedSeconds);

        self::assertEquals($this->single->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02), $prediction);
        self::assertEquals($prediction, $this->bulk->forAllSolvedPuzzles(PlayerFixture::PLAYER_REGULAR)[PuzzleFixture::PUZZLE_500_02]);
    }

    public function testStatisticalPredictionForAPuzzleThePlayerHasNeverSolved(): void
    {
        // Every fixture player has solved every puzzle with a difficulty score, so build the
        // case: drop PLAYER_WITH_STRIPE's only attempt on INTEL_PUZZLE_A *after* the intelligence
        // tables were computed (the test transaction rolls it back). Baseline (500 pcs) x
        // difficulty score of the puzzle is what both read models must now return.
        $this->database->executeStatement(
            'DELETE FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId',
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzleId' => PuzzleIntelligenceFixture::INTEL_PUZZLE_A],
        );

        $baseline = $this->database->fetchOne(
            'SELECT baseline_seconds FROM player_baseline WHERE player_id = :playerId AND pieces_count = 500',
            ['playerId' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        $difficulty = $this->database->fetchOne(
            'SELECT difficulty_score FROM puzzle_difficulty WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleIntelligenceFixture::INTEL_PUZZLE_A],
        );
        assert(is_int($baseline) || is_string($baseline));
        assert(is_float($difficulty) || is_string($difficulty));

        $prediction = $this->bulk->forPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleIntelligenceFixture::INTEL_PUZZLE_A])[PuzzleIntelligenceFixture::INTEL_PUZZLE_A];

        self::assertFalse($prediction->isPersonalized);
        self::assertNull($prediction->personalSolveCount);
        self::assertNull($prediction->lastTimeSeconds);
        self::assertSame((int) round((int) $baseline * (float) $difficulty), $prediction->predictedSeconds);
        self::assertSame((float) $difficulty, $prediction->difficultyForPlayer);
        self::assertGreaterThan(0, $prediction->rangeLowSeconds);
        self::assertGreaterThanOrEqual($prediction->rangeLowSeconds, $prediction->predictedSeconds);
        self::assertLessThanOrEqual($prediction->rangeHighSeconds, $prediction->predictedSeconds);

        self::assertEquals($this->single->forPuzzle(PlayerFixture::PLAYER_WITH_STRIPE, PuzzleIntelligenceFixture::INTEL_PUZZLE_A), $prediction);
        self::assertArrayNotHasKey(PuzzleIntelligenceFixture::INTEL_PUZZLE_A, $this->bulk->forAllSolvedPuzzles(PlayerFixture::PLAYER_WITH_STRIPE), 'Statistical predictions are not part of the solved-puzzles set');
    }

    public function testNothingForPuzzlesWithoutDataOrPlayersWithoutBaseline(): void
    {
        // PUZZLE_9000: never solved by anyone, no difficulty -> absent
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_9000, $this->bulk->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_01]));
        self::assertSame([], $this->bulk->forPuzzles(PlayerFixture::PLAYER_REGULAR, []));
        self::assertSame([], $this->bulk->forPuzzles('00000000-0000-0000-0000-000000000099', [PuzzleFixture::PUZZLE_500_01]));
        self::assertSame([], $this->bulk->forAllSolvedPuzzles('00000000-0000-0000-0000-000000000099'));
    }

    public function testPersonalPredictionsAreMemoisedPerRequestAndResetClearsThem(): void
    {
        $before = $this->bulk->forAllSolvedPuzzles(PlayerFixture::PLAYER_REGULAR);
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_02, $before);

        $this->database->executeStatement(
            'DELETE FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_500_02],
        );

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_02, $this->bulk->forAllSolvedPuzzles(PlayerFixture::PLAYER_REGULAR), 'Memoised within the request');
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_02, $this->bulk->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_02]));

        $this->bulk->reset();

        $after = $this->bulk->forAllSolvedPuzzles(PlayerFixture::PLAYER_REGULAR);
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_500_02, $after, 'reset() drops the memo');
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $after);
        // ... and the puzzle now falls back to the statistical prediction
        self::assertFalse($this->bulk->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_02])[PuzzleFixture::PUZZLE_500_02]->isPersonalized);
    }

    /**
     * @return list<string>
     */
    private function allPuzzleIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn('SELECT id FROM puzzle ORDER BY id');

        return $ids;
    }
}
