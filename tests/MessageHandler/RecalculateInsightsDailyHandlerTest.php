<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateInsightsDaily;
use SpeedPuzzling\Web\Services\Insights\InsightsGeneration;
use SpeedPuzzling\Web\Services\Insights\InsightsProfileRecalculator;
use SpeedPuzzling\Web\Tests\InsightsLadderSeeding;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The daily insights batch: profile cards for every puzzler with enough results, percentiles against everybody,
 * coordination constants, a new generation - and a second run over the same data writes no row.
 */
final class RecalculateInsightsDailyHandlerTest extends KernelTestCase
{
    use InsightsLadderSeeding;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;

        $now = TestClock::now()->modify('today 12:00:00');
        $this->seedLadder($this->connection, $now);

        // Solves were inserted as SQL: give the ladder puzzles their median solo time, as PuzzleStatisticsCalculator would
        $this->connection->executeStatement("
            INSERT INTO puzzle_statistics (puzzle_id, solved_times_solo_count, median_time_solo)
            SELECT puzzle_id, COUNT(*), percentile_cont(0.5) WITHIN GROUP (ORDER BY seconds_to_solve)::int
            FROM puzzle_solving_time
            WHERE puzzle_id::text LIKE '019b0001-%'
            GROUP BY puzzle_id
        ");
    }

    public function testBuildsTheProfileCardsAndMovesTheGenerationOn(): void
    {
        /** @var InsightsGeneration $generation */
        $generation = self::getContainer()->get(InsightsGeneration::class);
        $generationBefore = $generation->current();

        $result = $this->dispatch();

        self::assertSame($generationBefore + 1, $result['generation']);
        self::assertSame($result['generation'], $generation->current());
        self::assertSame(6, $this->countRows('SELECT COUNT(*) FROM group_coordination_constant'));

        // Every ladder player has results on puzzles with a median, private ones too (the card is self-only)
        self::assertSame(24, $this->countRows("SELECT COUNT(*) FROM player_insights_profile WHERE player_id::text LIKE '019b0000-%'"));

        /** @var array<string, float|string> $speed */
        $speed = $this->connection->fetchAllKeyValue("SELECT player_id, speed_percentile FROM player_insights_profile WHERE player_id::text LIKE '019b0000-%' AND speed_percentile IS NOT NULL");
        self::assertCount(24, $speed, 'Everybody solved 55 puzzles in the last 600 days, enough of them in the last year');

        // Lower numbers solve faster (1500 + 60 × player number seconds)
        self::assertGreaterThan((float) $speed[self::ladderPlayer(24)], (float) $speed[self::ladderPlayer(1)]);
        self::assertGreaterThan((float) $speed[self::ladderPlayer(12)], (float) $speed[self::ladderPlayer(2)]);

        // Players 1-10 repeated puzzles 1-5: learning scored for them only
        self::assertSame(10, $this->countRows("SELECT COUNT(*) FROM player_insights_profile WHERE player_id::text LIKE '019b0000-%' AND learning_score IS NOT NULL"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM player_insights_profile WHERE player_id::text LIKE '019b0000-%' AND (big_puzzles_score IS NOT NULL OR events_score IS NOT NULL)"));

        // Same data, same rows: nothing written
        $second = $this->dispatch();
        self::assertSame(0, $second['profiles_written']);
        self::assertSame(0, $second['profiles_deleted']);
        self::assertSame(0, $second['coordination_written']);
        self::assertSame($result['generation'], $second['generation'], 'Nothing changed: cached pages stay valid');
    }

    public function testPercentilesShareTiesAndFollowTheAxisDirection(): void
    {
        /** @var InsightsProfileRecalculator $recalculator */
        $recalculator = self::getContainer()->get(InsightsProfileRecalculator::class);

        $scores = [];

        foreach (['a' => 0.8, 'b' => 0.9, 'c' => 0.9, 'd' => 1.1] as $player => $value) {
            $scores[$player] = array_fill_keys(
                array_map(static fn (InsightsProfileAxis $axis): string => $axis->value, InsightsProfileAxis::cases()),
                ['score' => null, 'sample_size' => 0],
            );
            $scores[$player][InsightsProfileAxis::Speed->value] = ['score' => $value, 'sample_size' => 10];
            $scores[$player][InsightsProfileAxis::Learning->value] = ['score' => $value, 'sample_size' => 10];
        }

        $percentiles = $recalculator->percentiles($scores);

        // Speed: lower is better - a beats all 3 others; b and c beat d and share their tie
        self::assertSame(['a' => 100.0, 'b' => 50.0, 'c' => 50.0, 'd' => 0.0], $percentiles[InsightsProfileAxis::Speed->value]);
        // Learning: higher is better
        self::assertSame(['a' => 0.0, 'b' => 50.0, 'c' => 50.0, 'd' => 100.0], $percentiles[InsightsProfileAxis::Learning->value]);
        self::assertArrayNotHasKey(InsightsProfileAxis::Events->value, $percentiles);
    }

    /**
     * @return array{coordination_written: int, profiles: int, profiles_written: int, profiles_deleted: int, generation: int}
     */
    private function dispatch(): array
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $handled = $bus->dispatch(new RecalculateInsightsDaily())->last(HandledStamp::class);
        self::assertNotNull($handled);

        /** @var array{coordination_written: int, profiles: int, profiles_written: int, profiles_deleted: int, generation: int} */
        return $handled->getResult();
    }

    private function countRows(string $sql): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne($sql);

        return (int) $count;
    }
}
