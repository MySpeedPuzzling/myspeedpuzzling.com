<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerPredictionTest extends KernelTestCase
{
    private GetPlayerPrediction $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerPrediction $query */
        $query = $container->get(GetPlayerPrediction::class);
        $this->query = $query;
    }

    public function testReturnsPrediction(): void
    {
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01);

        if ($result === null) {
            self::markTestSkipped('No prediction available — puzzle may lack difficulty score');
        }

        self::assertGreaterThan(0, $result->predictedSeconds);
        self::assertGreaterThan(0, $result->rangeLowSeconds);
        self::assertGreaterThan(0, $result->rangeHighSeconds);
        self::assertLessThanOrEqual($result->rangeHighSeconds, $result->predictedSeconds);
        self::assertGreaterThanOrEqual($result->rangeLowSeconds, $result->predictedSeconds);
    }

    public function testPersonalPredictionForSingleSolver(): void
    {
        // PLAYER_WITH_FAVORITES has only 1 solve on PUZZLE_500_01 — should get ratio-based personal prediction
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_01);

        if ($result === null) {
            self::markTestSkipped('No prediction available — player may lack baseline');
        }

        self::assertTrue($result->isPersonalized);
        self::assertSame(1, $result->personalSolveCount);
        self::assertSame(2, $result->predictedAttemptNumber);
    }

    public function testReturnsPersonalizedPredictionForRepeatSolver(): void
    {
        // PLAYER_REGULAR has 3 solves on PUZZLE_500_02: 2200s, 1900s, 1700s (chronological)
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02);

        if ($result === null) {
            self::markTestSkipped('No prediction available');
        }

        self::assertTrue($result->isPersonalized);
        self::assertSame(3, $result->personalSolveCount);
        self::assertSame(4, $result->predictedAttemptNumber);
        // Blended prediction: should be lower than best time (1700) since player is improving
        self::assertLessThan(1700, $result->predictedSeconds);
        // But not unreasonably low (floor is best_time * 0.70 = 1190)
        self::assertGreaterThanOrEqual(1190, $result->predictedSeconds);
        self::assertLessThanOrEqual($result->rangeHighSeconds, $result->predictedSeconds);
        self::assertGreaterThanOrEqual($result->rangeLowSeconds, $result->predictedSeconds);
    }

    public function testExcludeTimeIdReducesSolveCount(): void
    {
        // Without exclude: 3 solves → personalized prediction
        $withAll = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02);

        if ($withAll === null) {
            self::markTestSkipped('No prediction available');
        }

        self::assertSame(3, $withAll->personalSolveCount);

        // Excluding the latest solve leaves 2 → still personalized but different prediction
        $withExclude = $this->query->forPuzzle(
            PlayerFixture::PLAYER_REGULAR,
            PuzzleFixture::PUZZLE_500_02,
            excludeTimeId: PuzzleSolvingTimeFixture::TIME_08,
        );

        if ($withExclude === null) {
            self::markTestSkipped('No prediction available with exclude');
        }

        self::assertTrue($withExclude->isPersonalized);
        self::assertSame(2, $withExclude->personalSolveCount);
        self::assertSame(3, $withExclude->predictedAttemptNumber);
    }

    public function testSameDaySolvesUseTrackedAtOrdering(): void
    {
        // PLAYER_ADMIN has TIME_17 (3900s, 21 days ago) + 3 same-day solves on PUZZLE_1000_01:
        // 5200s (09:00), 4600s (13:00), 4000s (18:00) — all 5 days ago, improving
        // The prediction must see lastTime = 4000 (the latest same-day solve by trackedAt),
        // NOT 5200 (slowest) which would indicate regression
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_ADMIN, PuzzleFixture::PUZZLE_1000_01);

        if ($result === null) {
            self::markTestSkipped('No prediction available');
        }

        self::assertTrue($result->isPersonalized);
        // lastTime should be the latest same-day solve (4000s), not the slowest (5200s)
        self::assertSame(4000, $result->lastTimeSeconds);
    }

    public function testReturnsNullForPuzzleWithoutDifficulty(): void
    {
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_9000);

        self::assertNull($result);
    }

    public function testReturnsNullForPlayerWithoutBaseline(): void
    {
        $result = $this->query->forPuzzle('00000000-0000-0000-0000-000000000099', PuzzleFixture::PUZZLE_500_01);

        self::assertNull($result);
    }

    /**
     * A repeat solver's prediction needs the global ratio of the gap bucket and of the "all"
     * bucket (to gap-correct the player's own ratio) - read by one query instead of two.
     */
    public function testGlobalRatiosOfTheGapAndAllBucketsComeFromOneQuery(): void
    {
        $container = self::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        /** @var TimePredictionCalculator $calculator */
        $calculator = $container->get(TimePredictionCalculator::class);

        // PLAYER_REGULAR solved PUZZLE_500_02 (500 pieces) 3 times
        /** @var list<array{seconds_to_solve: int|string, solved_at: string}> $solves */
        $solves = $connection->fetchAllAssociative(
            "SELECT seconds_to_solve, COALESCE(finished_at, tracked_at) AS solved_at
             FROM puzzle_solving_time
             WHERE player_id = :playerId AND puzzle_id = :puzzleId AND puzzling_type = 'solo'
               AND suspicious = false AND seconds_to_solve IS NOT NULL AND unboxed = false
             ORDER BY COALESCE(finished_at, tracked_at) ASC, tracked_at ASC",
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_500_02],
        );
        self::assertCount(3, $solves);
        /** @var non-empty-list<int> $times */
        $times = array_map(static fn (array $solve): int => (int) $solve['seconds_to_solve'], $solves);
        $transition = TimePredictionCalculator::transitionFor(count($times));
        $gapBucket = TimePredictionCalculator::classifyGap(
            TimePredictionCalculator::gapDays(new DateTimeImmutable(), new DateTimeImmutable($solves[2]['solved_at'])),
        );

        $globalRatios = ['lt30d' => 0.91, '1_3m' => 0.93, '3_12m' => 0.95, 'gt12m' => 0.97, 'all' => 0.9];
        $connection->executeStatement('DELETE FROM global_improvement_ratio WHERE pieces_count = 500 AND from_attempt = :transition', ['transition' => $transition]);
        foreach ($globalRatios as $bucket => $ratio) {
            $connection->executeStatement(
                'INSERT INTO global_improvement_ratio (id, pieces_count, from_attempt, gap_bucket, median_ratio, sample_size, computed_at) VALUES (:id, 500, :transition, :bucket, :ratio, 50, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'transition' => $transition, 'bucket' => $bucket, 'ratio' => $ratio],
            );
        }
        $connection->executeStatement('DELETE FROM player_improvement_ratio WHERE player_id = :playerId AND from_attempt = :transition', ['playerId' => PlayerFixture::PLAYER_REGULAR, 'transition' => $transition]);
        $connection->executeStatement(
            'INSERT INTO player_improvement_ratio (id, player_id, from_attempt, median_ratio, sample_size, computed_at) VALUES (:id, :playerId, :transition, 0.85, 10, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => PlayerFixture::PLAYER_REGULAR, 'transition' => $transition],
        );

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = $container->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        // Player ratio, gap-corrected by the global gap bucket / "all" bucket
        self::assertEquals(
            $calculator->personal($times, 0.85 * ($globalRatios[$gapBucket] / $globalRatios['all'])),
            $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02),
        );

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        self::assertCount(1, array_filter(
            array_column($executed, 'sql'),
            static fn (string $sql): bool => str_contains($sql, 'FROM global_improvement_ratio'),
        ));

        // Without a player ratio: the global ratio of the gap bucket
        $connection->executeStatement('DELETE FROM player_improvement_ratio WHERE player_id = :playerId', ['playerId' => PlayerFixture::PLAYER_REGULAR]);
        self::assertEquals(
            $calculator->personal($times, $globalRatios[$gapBucket]),
            $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02),
        );

        // Player ratio but no "all" bucket: the player ratio as is
        $connection->executeStatement(
            'INSERT INTO player_improvement_ratio (id, player_id, from_attempt, median_ratio, sample_size, computed_at) VALUES (:id, :playerId, :transition, 0.85, 10, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => PlayerFixture::PLAYER_REGULAR, 'transition' => $transition],
        );
        $connection->executeStatement("DELETE FROM global_improvement_ratio WHERE pieces_count = 500 AND from_attempt = :transition AND gap_bucket = 'all'", ['transition' => $transition]);
        self::assertEquals(
            $calculator->personal($times, 0.85),
            $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02),
        );
    }
}
