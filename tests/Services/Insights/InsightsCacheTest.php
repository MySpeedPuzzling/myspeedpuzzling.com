<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\Insights\InsightsCache;
use SpeedPuzzling\Web\Services\Insights\InsightsGeneration;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Insights cache key (docs/features/insights/computation.md §4c.8): a cached part is served while nothing it was
 * built from changed, and recomputed after any change of the player's results, collections, the batch generation,
 * the viewer or the part's context.
 */
final class InsightsCacheTest extends KernelTestCase
{
    private Connection $connection;
    private int $computed = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
    }

    public function testServedUntilSomethingItWasBuiltFromChanges(): void
    {
        $player = PlayerFixture::PLAYER_REGULAR;

        self::assertSame(1, $this->part($player), 'First computed');
        self::assertSame(1, $this->part($player), 'Then cached');
        self::assertSame(2, $this->part($player, ['pieces' => 500]), 'Another context is another entry');

        // An edit of one of the player's results
        $this->connection->executeStatement(
            "UPDATE puzzle_solving_time SET seconds_to_solve = seconds_to_solve + 1 WHERE id = (SELECT id FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' ORDER BY id LIMIT 1)",
            ['player' => $player],
        );
        self::assertSame(3, $this->part($player));
        self::assertSame(3, $this->part($player));

        // A suspicious flag set by SQL
        $this->connection->executeStatement(
            "UPDATE puzzle_solving_time SET suspicious = true WHERE id = (SELECT id FROM puzzle_solving_time WHERE player_id = :player AND puzzling_type = 'solo' ORDER BY id LIMIT 1)",
            ['player' => $player],
        );
        self::assertSame(4, $this->part($player));

        // A pair result someone else tracked: the fixture pair's time moved to PLAYER_PRIVATE as the tracker, then edited
        $this->connection->executeStatement(
            "UPDATE puzzle_solving_time SET player_id = :other WHERE puzzling_team_id IS NOT NULL AND player_id = :player",
            ['player' => $player, 'other' => PlayerFixture::PLAYER_PRIVATE],
        );
        self::assertSame(5, $this->part($player));
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = seconds_to_solve + 1 WHERE puzzling_team_id IS NOT NULL AND player_id = :other',
            ['other' => PlayerFixture::PLAYER_PRIVATE],
        );
        self::assertSame(6, $this->part($player));

        // The collection
        $this->connection->executeStatement(
            'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (:id, NULL, :player, :puzzle, NULL, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'player' => $player, 'puzzle' => PuzzleFixture::PUZZLE_500_04],
        );
        self::assertSame(7, $this->part($player));

        // A teammate turning public (the page would name them now), then the pair renamed
        $this->connection->executeStatement('UPDATE player SET is_private = false WHERE id = :player', ['player' => PlayerFixture::PLAYER_PRIVATE]);
        self::assertSame(8, $this->part($player));
        $this->connection->executeStatement(
            "UPDATE puzzling_team SET name = 'The Fixtures' WHERE id IN (SELECT team_id FROM puzzling_team_member WHERE player_id = :player)",
            ['player' => $player],
        );
        self::assertSame(9, $this->part($player));

        // The batch generation
        /** @var InsightsGeneration $generation */
        $generation = self::getContainer()->get(InsightsGeneration::class);
        $generation->advance();
        self::assertSame(10, $this->part($player));

        // The viewer: PLAYER_REGULAR blocks PLAYER_PRIVATE
        TestingViewer::signIn(self::getContainer(), $player);
        self::assertSame(11, $this->part($player));
        self::assertSame(11, $this->part($player));

        // Another player's change does not touch this player's entries
        $this->connection->executeStatement(
            "UPDATE puzzle_solving_time SET seconds_to_solve = seconds_to_solve + 1 WHERE player_id = :other AND puzzling_type = 'solo'",
            ['other' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        self::assertSame(11, $this->part($player));
    }

    public function testThePerGenerationPartIgnoresTheResultsButNotTheGeneration(): void
    {
        $cache = $this->cache();
        $compute = function (): int {
            return ++$this->computed;
        };

        self::assertSame(1, $cache->getForGeneration('close_calls', PlayerFixture::PLAYER_REGULAR, [], $compute));

        $this->connection->executeStatement(
            "UPDATE puzzle_solving_time SET seconds_to_solve = seconds_to_solve + 1 WHERE player_id = :player AND puzzling_type = 'solo'",
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );
        self::assertSame(1, $cache->getForGeneration('close_calls', PlayerFixture::PLAYER_REGULAR, [], $compute));

        /** @var InsightsGeneration $generation */
        $generation = self::getContainer()->get(InsightsGeneration::class);
        $generation->advance();
        self::assertSame(2, $cache->getForGeneration('close_calls', PlayerFixture::PLAYER_REGULAR, [], $compute));
    }

    /**
     * The generation rides on the fingerprint's statement (the Insights page's first response stays within its budget)
     */
    public function testTheKeyCostsOneStatement(): void
    {
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $this->cache()->keyFor(PlayerFixture::PLAYER_WITH_STRIPE);

        $queries = $holder->getData()['default'] ?? [];
        self::assertCount(1, is_array($queries) ? $queries : []);
    }

    /**
     * @param array<string, null|bool|float|int|string> $context
     */
    private function part(string $playerId, array $context = []): int
    {
        $cache = $this->cache();

        return $cache->get($cache->keyFor($playerId), 'test_part', $context, function (): int {
            return ++$this->computed;
        });
    }

    private function cache(): InsightsCache
    {
        /** @var InsightsCache $cache */
        $cache = self::getContainer()->get(InsightsCache::class);

        return $cache;
    }
}
