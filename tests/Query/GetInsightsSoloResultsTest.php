<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetInsightsSoloResults;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetInsightsSoloResultsTest extends KernelTestCase
{
    private GetInsightsSoloResults $query;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetInsightsSoloResults::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testEveryCountedSoloResultOldestFirstWithItsAttempt(): void
    {
        $results = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount($this->countedResults(PlayerFixture::PLAYER_REGULAR), $results);

        $previous = null;
        $attempts = [];

        foreach ($results as $result) {
            if ($previous !== null) {
                self::assertGreaterThanOrEqual($previous, $result->solvedAt);
            }

            $previous = $result->solvedAt;
            $attempts[$result->puzzleId] = ($attempts[$result->puzzleId] ?? 0) + 1;
            self::assertSame($attempts[$result->puzzleId], $result->attempt);
            self::assertGreaterThan(0, $result->seconds);
            self::assertGreaterThan(0, $result->piecesCount);
        }

        // PUZZLE_500_01 has 5 solvers: a median, a ratio
        $onPopular = array_values(array_filter($results, static fn ($result) => $result->puzzleId === PuzzleFixture::PUZZLE_500_01));
        self::assertNotEmpty($onPopular);
        self::assertNotNull($onPopular[0]->ratio());
    }

    public function testThePrivatePlayerSeesTheirOwnResults(): void
    {
        self::assertCount($this->countedResults(PlayerFixture::PLAYER_PRIVATE), $this->query->forPlayer(PlayerFixture::PLAYER_PRIVATE));
    }

    public function testSuspiciousResultsAndSecretPuzzlesAreLeftOut(): void
    {
        $before = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);
        $first = $before[0];

        $this->connection->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => $first->timeId]);

        $afterSuspicious = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertCount(count($before) - 1, $afterSuspicious);
        self::assertNotContains($first->timeId, array_map(static fn ($result) => $result->timeId, $afterSuspicious));

        $onPuzzle = count(array_filter($afterSuspicious, static fn ($result) => $result->puzzleId === PuzzleFixture::PUZZLE_500_01));
        self::assertGreaterThan(0, $onPuzzle);

        $this->connection->executeStatement("UPDATE puzzle SET hide_image_until = NOW() + INTERVAL '30 days' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_01]);

        $afterSecret = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertCount(count($afterSuspicious) - $onPuzzle, $afterSecret);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, array_map(static fn ($result) => $result->puzzleId, $afterSecret));
    }

    public function testShelfAndImageTraits(): void
    {
        /** @var list<string> $shelf */
        $shelf = $this->connection->fetchFirstColumn('SELECT DISTINCT puzzle_id::text FROM collection_item WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_REGULAR]);

        $this->connection->executeStatement(
            "INSERT INTO puzzle_tag (puzzle_id, tag_key, score, uncertain, source, updated_at) VALUES (:puzzle, 'trait.dark', 0.9, false, 'analysis', NOW()), (:puzzle, 'theme.animals', 0.9, false, 'analysis', NOW()), (:puzzle, 'style.photo', 0.7, false, 'analysis', NOW())",
            ['puzzle' => PuzzleFixture::PUZZLE_500_01],
        );

        $results = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);
        $onShelf = 0;

        foreach ($results as $result) {
            self::assertSame(in_array($result->puzzleId, $shelf, true), $result->onShelf);
            $onShelf += $result->onShelf ? 1 : 0;

            self::assertSame($result->puzzleId === PuzzleFixture::PUZZLE_500_01 ? ['style.photo', 'trait.dark'] : [], $result->imageTagKeys);
        }

        self::assertGreaterThan(0, $onShelf);
    }

    private function countedResults(string $playerId): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM puzzle_solving_time pst JOIN puzzle p ON p.id = pst.puzzle_id
             WHERE pst.player_id = :player AND pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve > 0
                AND (p.hide_until IS NULL OR p.hide_until <= NOW()) AND (p.hide_image_until IS NULL OR p.hide_image_until <= NOW())",
            ['player' => $playerId],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
