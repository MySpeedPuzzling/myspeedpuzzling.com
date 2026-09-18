<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetTeamPlayers;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetTeamPlayersTest extends KernelTestCase
{
    private GetTeamPlayers $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var GetTeamPlayers $query */
        $query = $container->get(GetTeamPlayers::class);
        $this->query = $query;

        /** @var Connection $database */
        $database = $container->get(Connection::class);
        $this->database = $database;
    }

    /**
     * Player profile and statistics ask for the members of the player's duo and team times -
     * a player without any used to run the query as "WHERE id IN (NULL)" (~2x per request).
     */
    public function testNoTimesRunNoQuery(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        self::assertSame([], $this->query->byIds([]));
        self::assertSame([], $debugDataHolder->getData()['default'] ?? []);
    }

    public function testMembersOfTeamTimesInTeamOrder(): void
    {
        /** @var list<array{id: string, team: string}> $teamTimes */
        $teamTimes = $this->database->fetchAllAssociative(
            'SELECT id, team FROM puzzle_solving_time WHERE team IS NOT NULL ORDER BY id',
        );
        self::assertNotEmpty($teamTimes);

        $members = $this->query->byIds(array_column($teamTimes, 'id'));

        foreach ($teamTimes as $teamTime) {
            /** @var array{puzzlers: list<array{player_id: null|string, player_name: null|string}>} $team */
            $team = json_decode($teamTime['team'], true, flags: JSON_THROW_ON_ERROR);
            $expectedIds = array_map(static fn (array $puzzler): null|string => $puzzler['player_id'], $team['puzzlers']);

            self::assertArrayHasKey($teamTime['id'], $members);
            self::assertSame($expectedIds, array_map(static fn ($puzzler): null|string => $puzzler->playerId, $members[$teamTime['id']]));
        }
    }
}
