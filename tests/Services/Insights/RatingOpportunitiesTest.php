<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetInsightsRatingInputs;
use SpeedPuzzling\Web\Query\GetInsightsShelf;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Results\Insights\RatingMove;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\Insights\Model\RatingModel;
use SpeedPuzzling\Web\Services\Insights\Model\RatingWhatIf;
use SpeedPuzzling\Web\Services\Insights\RatingForecast;
use SpeedPuzzling\Web\Services\Insights\RatingModelLoader;
use SpeedPuzzling\Web\Services\Insights\RatingOpportunities;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\InsightsLadderSeeding;
use SpeedPuzzling\Web\Value\RatingForecastScenario;
use SpeedPuzzling\Web\Value\RatingMoveKind;
use SpeedPuzzling\Web\Value\RatingStanding;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Rating what-ifs (docs/features/insights/computation.md §4c.2-4c.3) on the insights ladder: the model reproduces the
 * stored rating, a re-solve's gain is exactly what the batch computes once the solve is really there, a first try's
 * percentile is exactly the stored one afterwards, entries about to leave the window are defend moves, nearly rated
 * puzzles are pending, secret puzzles are never suggested, private players have no rating - and the statement budgets.
 */
final class RatingOpportunitiesTest extends KernelTestCase
{
    use InsightsLadderSeeding;

    /** Mid-ladder: neither the fastest (whose first tries would only lower the mean) nor the slowest */
    private const int PLAYER = 11;

    private Connection $connection;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->now = new DateTimeImmutable('today 12:00:00');
        self::getContainer()->set(ClockInterface::class, new MockClock($this->now));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;

        $this->seedLadder($this->connection, $this->now);

        // 56: rated, everybody but the player has solved it - on the player's shelf
        // 57: 10 solvers incl. the player - nearly rated
        // 58: rated, but secret - on the player's shelf
        // 59: 19 public first-attempt solvers, the player not among them - on the player's shelf: a first try makes it count
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available, hide_until)
            SELECT CAST('019b0001-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 500, 'Insights Puzzle ' || n, true, true,
                CASE WHEN n = 58 THEN CAST(:future AS timestamp) END
            FROM generate_series(56, 59) AS n",
            ['future' => $this->now->modify('+1 year')->format('Y-m-d H:i:s')],
        );
        $this->seedSolves($this->connection, $this->now, [
            'SELECT p, 56, 1600 + 61 * p, 80 + p, true FROM generate_series(1, 22) AS p WHERE p <> ' . self::PLAYER,
            'SELECT p, 57, 1700 + 50 * p, 50 + p, true FROM generate_series(6, 15) AS p',
            'SELECT p, 58, 1650 + 55 * p, 70 + p, true FROM generate_series(1, 22) AS p WHERE p <> ' . self::PLAYER,
            'SELECT p, 59, 1620 + 58 * p, 60 + p, true FROM generate_series(1, 20) AS p WHERE p <> ' . self::PLAYER,
        ]);

        foreach ([56, 58, 59] as $puzzle) {
            $this->connection->executeStatement(
                'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (:id, NULL, :player, :puzzle, NULL, NOW())',
                ['id' => Uuid::uuid7()->toString(), 'player' => self::ladderPlayer(self::PLAYER), 'puzzle' => self::ladderPuzzle($puzzle)],
            );
        }

        // Puzzle 7: the player's only result is 700 days old - its entries leave the window in 30 days
        $this->connection->executeStatement(
            "UPDATE puzzle_solving_time SET tracked_at = CAST(:at AS timestamp), finished_at = CAST(:at AS timestamp) WHERE player_id = :player AND puzzle_id = :puzzle",
            ['at' => $this->now->setTime(3, 0)->modify('-700 days')->format('Y-m-d H:i:s'), 'player' => self::ladderPlayer(self::PLAYER), 'puzzle' => self::ladderPuzzle(7)],
        );

        $this->recalculate();
    }

    public function testTheModelReproducesTheStoredRating(): void
    {
        $model = $this->loader()->load(self::ladderPlayer(self::PLAYER), 500);

        self::assertSame(RatingStanding::OnLadder, $model->standing());
        self::assertEqualsWithDelta($this->storedRating(self::PLAYER), $model->rating(), 1e-12);

        $result = RatingOpportunities::fromModel($model);
        self::assertEqualsWithDelta($this->storedRating(self::PLAYER) * 1000, $result->rating, 1e-9);
        self::assertSame(self::PLAYER, $result->place, 'Ladder times grow with the player number');
        self::assertSame(22, $result->ladderSize);
    }

    public function testAResolveGainIsExactlyWhatTheBatchComputesOnceTheSolveIsThere(): void
    {
        $playerId = self::ladderPlayer(self::PLAYER);
        $puzzleId = self::ladderPuzzle(30);
        $inputs = $this->inputs();
        $solved = $inputs->solvedPuzzles($playerId, 500);
        $best = null;

        foreach ($solved['puzzles'] as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                $best = $puzzle->bestSeconds;
            }
        }

        self::assertNotNull($best);
        $expected = (int) round($best * 0.8);

        $model = RatingModel::build(
            now: $this->now,
            piecesCount: 500,
            private: false,
            optedOut: false,
            solved: $solved['puzzles'],
            shelf: [],
            timeLists: $inputs->ratingTimes([$puzzleId]),
            predictions: [$puzzleId => new TimePredictionResult($expected, $expected, $expected, 1.0)],
            ladder: $inputs->ladderRatings(500, $playerId),
        );

        $move = $this->moveFor($model->moves, $puzzleId);
        self::assertSame(RatingMoveKind::Resolve, $move->kind);
        self::assertSame($expected, $move->expectedSeconds);
        self::assertGreaterThan(0.0, $move->gain);

        $before = $this->storedRating(self::PLAYER);
        $this->seedSolves($this->connection, $this->now, ["SELECT " . self::PLAYER . ", 30, {$expected}, 0, false"]);
        $this->recalculate();

        self::assertEqualsWithDelta($move->gain, ($this->storedRating(self::PLAYER) - $before) * 1000, 1e-9);
    }

    public function testAFirstTryFromTheShelfIsScoredAgainstTheStoredTimes(): void
    {
        $playerId = self::ladderPlayer(self::PLAYER);
        $puzzleId = self::ladderPuzzle(56);
        $model = $this->loader()->load($playerId, 500);
        $move = $this->moveFor($model->moves, $puzzleId);

        self::assertSame(RatingMoveKind::FirstTry, $move->kind);
        self::assertTrue($move->onShelf);
        self::assertSame(21, $move->solverCount);
        self::assertNotNull($move->fasterThanShare);

        $times = $this->inputs()->ratingTimes([$puzzleId])[$puzzleId];
        $expectedPercentile = RatingWhatIf::percentileInserting($times->firstAttemptTimes, $move->expectedSeconds);
        self::assertSame($expectedPercentile, $move->fasterThanShare);

        // Once really solved in that time, the stored entry has exactly that percentile (the difficulty - so the
        // weight - moves with the new solve, the percentile does not)
        $this->seedSolves($this->connection, $this->now, ["SELECT " . self::PLAYER . ", 56, {$move->expectedSeconds}, 0, true"]);
        $this->recalculate();

        /** @var float|string $stored */
        $stored = $this->connection->fetchOne(
            "SELECT percentile FROM player_rating_entry WHERE player_id = :player AND puzzle_id = :puzzle AND portfolio = 'first_attempt'",
            ['player' => $playerId, 'puzzle' => $puzzleId],
        );
        self::assertSame($expectedPercentile, (float) $stored);
    }

    public function testEntriesAboutToLeaveTheWindowAreDefended(): void
    {
        $model = $this->loader()->load(self::ladderPlayer(self::PLAYER), 500);
        $result = RatingOpportunities::fromModel($model);
        $defend = null;

        // Every move, not only the best MOVES_SHOWN
        foreach ($model->moves as $move) {
            if ($move->puzzle->puzzleId === self::ladderPuzzle(7)) {
                $defend = $move;
            }
        }

        self::assertNotNull($defend);
        self::assertSame(RatingMoveKind::Defend, $defend->kind);
        $solvedAt = $this->now->setTime(3, 0)->modify('-700 days');
        self::assertSame((int) $this->now->diff($solvedAt->modify('+24 months'))->days, $defend->daysUntilLeaving);
        self::assertGreaterThanOrEqual(2, $result->entriesLeavingWithin60Days, 'Its first-attempt and best-time entries');
        self::assertGreaterThan(0.0, $result->bestFiveGain);
        self::assertGreaterThanOrEqual($result->bestFiveGain, $result->bestTenGain);
        self::assertNotNull($result->placeAfterBestTen);
        self::assertLessThanOrEqual(self::PLAYER, $result->placeAfterBestTen);
        self::assertLessThanOrEqual(100, count(array_filter($result->firstAttemptEntries, static fn ($entry): bool => $entry->counts)));
    }

    public function testNearlyRatedPuzzlesArePendingAndSecretOnesAreNeverSuggested(): void
    {
        $result = $this->opportunities()->build(self::ladderPlayer(self::PLAYER));

        $pending = null;

        foreach ($result->pending as $candidate) {
            if ($candidate->puzzle->puzzleId === self::ladderPuzzle(57)) {
                $pending = $candidate;
            }
        }

        self::assertNotNull($pending);
        self::assertSame(10, $pending->solverCount);
        self::assertSame(10, $pending->solversNeeded);

        foreach ([...$result->moves, ...$result->movesByGainPerHour] as $move) {
            self::assertNotSame(self::ladderPuzzle(58), $move->puzzle->puzzleId);
        }

        $shelf = $this->shelf()->unsolved(self::ladderPlayer(self::PLAYER));
        self::assertSame([self::ladderPuzzle(56), self::ladderPuzzle(59)], array_map(static fn ($puzzle): string => $puzzle->puzzle->puzzleId, $shelf));
    }

    public function testAFirstTryThatMakesAPuzzleCount(): void
    {
        $playerId = self::ladderPlayer(self::PLAYER);
        $puzzleId = self::ladderPuzzle(59);
        $times = $this->inputs()->ratingTimes([$puzzleId])[$puzzleId];
        self::assertFalse($times->qualifies);
        self::assertSame(19, $times->solverCount);
        self::assertNotNull($times->difficultyWeight);

        $move = $this->moveFor($this->loader()->load($playerId, 500)->moves, $puzzleId);
        self::assertSame(RatingMoveKind::FirstTry, $move->kind);
        self::assertTrue($move->makesPuzzleCount);
        self::assertGreaterThan(0.0, $move->gain);
        self::assertFalse($this->moveFor($this->loader()->load($playerId, 500)->moves, self::ladderPuzzle(56))->makesPuzzleCount);

        // Once really solved in that time the puzzle counts, with the player's entry at exactly that percentile
        $this->seedSolves($this->connection, $this->now, ["SELECT " . self::PLAYER . ", 59, {$move->expectedSeconds}, 0, true"]);
        $this->recalculate();

        self::assertTrue($this->inputs()->ratingTimes([$puzzleId])[$puzzleId]->qualifies);
        /** @var false|float|string $stored */
        $stored = $this->connection->fetchOne(
            "SELECT percentile FROM player_rating_entry WHERE player_id = :player AND puzzle_id = :puzzle AND portfolio = 'first_attempt'",
            ['player' => $playerId, 'puzzle' => $puzzleId],
        );
        self::assertNotFalse($stored);
        self::assertSame($move->fasterThanShare, (float) $stored);
    }

    public function testPlayersOptedOutOfRankingsHaveARatingButNoPlace(): void
    {
        $playerId = self::ladderPlayer(self::PLAYER);
        $this->connection->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :player', ['player' => $playerId]);

        $result = $this->opportunities()->build($playerId);

        self::assertSame(RatingStanding::OptedOut, $result->standing);
        self::assertEqualsWithDelta($this->storedRating(self::PLAYER) * 1000, $result->rating, 1e-9);
        self::assertNull($result->place);
        self::assertNull($result->placeAfterBestTen);
        self::assertNull($result->ladderPath);
        self::assertSame(0, $result->ladderSize);
        self::assertNotSame([], $result->moves, 'The moves stay');

        $forecast = $this->forecast()->build($playerId);
        self::assertSame(RatingStanding::OptedOut, $forecast->standing);
        self::assertNull($forecast->ladder);

        foreach ($forecast->lines as $line) {
            foreach ($line->points as $point) {
                self::assertFalse($point->onLadder);
            }
        }

        // Nearly on the ladder is no path for them either
        $this->connection->executeStatement(
            'DELETE FROM player_rating_entry WHERE player_id = :player AND portfolio = :portfolio AND puzzle_id IN (SELECT puzzle_id FROM player_rating_entry WHERE player_id = :player AND portfolio = :portfolio ORDER BY puzzle_id LIMIT 40)',
            ['player' => $playerId, 'portfolio' => 'first_attempt'],
        );
        $near = $this->opportunities()->build($playerId);
        self::assertSame(RatingStanding::OptedOut, $near->standing);
        self::assertNull($near->ladderPath);
    }

    public function testAPrivatePlayerWithoutSolvesIsPrivateInOneStatement(): void
    {
        $playerId = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO player (id, code, name, registered_at, is_private) VALUES (:id, 'insprivate', 'Private, no solves', NOW(), true)",
            ['id' => $playerId],
        );

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $result = $this->opportunities()->build($playerId);

        self::assertSame(RatingStanding::Private, $result->standing);
        self::assertSame(1, $this->statements($holder));
    }

    public function testPrivatePlayersHaveNoRating(): void
    {
        $result = $this->opportunities()->build(self::ladderPlayer(23));

        self::assertSame(RatingStanding::Private, $result->standing);
        self::assertSame([], $result->moves);
    }

    public function testTheForecast(): void
    {
        $forecast = $this->forecast()->build(self::ladderPlayer(self::PLAYER));
        $rating = $this->storedRating(self::PLAYER) * 1000;

        self::assertCount(3, $forecast->lines);
        [$stop, $keep, $best] = $forecast->lines;
        self::assertSame(RatingForecastScenario::Stop, $stop->scenario);
        self::assertSame(RatingForecastScenario::BestMoves, $best->scenario);

        foreach ($forecast->lines as $line) {
            self::assertCount(RatingForecast::MONTHS + 1, $line->points);
            self::assertEqualsWithDelta($rating, $line->points[0]->rating, 1e-9);
        }

        for ($month = 1; $month <= RatingForecast::MONTHS; $month++) {
            self::assertLessThanOrEqual($stop->points[$month - 1]->rating + 1e-9, $stop->points[$month]->rating, 'Without new results the rating only ages');
            self::assertGreaterThanOrEqual($stop->points[$month]->rating - 1e-9, $keep->points[$month]->rating);
        }

        self::assertGreaterThan($stop->end()->rating, $best->end()->rating);

        // Next milestone place above #11 is #10: the rating #10 has today
        self::assertNotNull($forecast->ladder);
        self::assertSame(self::PLAYER, $forecast->ladder->place);
        self::assertSame(10, $forecast->ladder->targetPlace);
        self::assertEqualsWithDelta($this->storedRating(10) * 1000, $forecast->ladder->ratingNeeded, 1e-9);
        self::assertNotNull($forecast->results);
        self::assertSame(56, $forecast->results->results);
    }

    public function testStatementBudgets(): void
    {
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');

        $holder->reset();
        $this->opportunities()->build(self::ladderPlayer(self::PLAYER));
        self::assertSame(8, $this->statements($holder), 'Rating opportunities');

        // GetPlayerPredictions keeps the player's personal predictions for the request - a new request starts empty
        /** @var GetPlayerPredictions $predictions */
        $predictions = self::getContainer()->get(GetPlayerPredictions::class);
        $predictions->reset();
        $holder->reset();
        $this->forecast()->build(self::ladderPlayer(self::PLAYER));
        self::assertSame(10, $this->statements($holder), 'Rating forecast');

        $holder->reset();
        $this->opportunities()->build(self::ladderPlayer(23));
        self::assertSame(1, $this->statements($holder), 'A private player');
    }

    /**
     * @param list<RatingMove> $moves
     */
    private function moveFor(array $moves, string $puzzleId): RatingMove
    {
        foreach ($moves as $move) {
            if ($move->puzzle->puzzleId === $puzzleId) {
                return $move;
            }
        }

        self::fail("No move for {$puzzleId}");
    }

    private function storedRating(int $player): float
    {
        /** @var float|string $rating */
        $rating = $this->connection->fetchOne('SELECT elo_rating FROM player_elo WHERE player_id = :player AND pieces_count = 500', ['player' => self::ladderPlayer($player)]);

        return (float) $rating;
    }

    private function statements(DebugDataHolder $holder): int
    {
        $queries = $holder->getData()['default'] ?? [];

        return is_array($queries) ? count($queries) : 0;
    }

    private function recalculate(): void
    {
        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
    }

    private function loader(): RatingModelLoader
    {
        /** @var RatingModelLoader $loader */
        $loader = self::getContainer()->get(RatingModelLoader::class);

        return $loader;
    }

    private function opportunities(): RatingOpportunities
    {
        /** @var RatingOpportunities $service */
        $service = self::getContainer()->get(RatingOpportunities::class);

        return $service;
    }

    private function forecast(): RatingForecast
    {
        /** @var RatingForecast $service */
        $service = self::getContainer()->get(RatingForecast::class);

        return $service;
    }

    private function inputs(): GetInsightsRatingInputs
    {
        /** @var GetInsightsRatingInputs $query */
        $query = self::getContainer()->get(GetInsightsRatingInputs::class);

        return $query;
    }

    private function shelf(): GetInsightsShelf
    {
        /** @var GetInsightsShelf $query */
        $query = self::getContainer()->get(GetInsightsShelf::class);

        return $query;
    }
}
