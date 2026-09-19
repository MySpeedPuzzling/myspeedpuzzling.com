<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetTeamPlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
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

    public function testMembersOfATimeWithAHiddenPlayerAreNotHandedOutUnlessTheViewerTookPart(): void
    {
        // TIME_12: PLAYER_REGULAR together with PLAYER_PRIVATE
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE);

        self::assertArrayHasKey(PuzzleSolvingTimeFixture::TIME_12, $this->query->byIds([PuzzleSolvingTimeFixture::TIME_12]));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertArrayNotHasKey(PuzzleSolvingTimeFixture::TIME_12, $this->query->byIds([PuzzleSolvingTimeFixture::TIME_12]));

        // UserBlockFixture: PLAYER_REGULAR blocks the partner as well, but took part
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertCount(2, $this->query->byIds([PuzzleSolvingTimeFixture::TIME_12])[PuzzleSolvingTimeFixture::TIME_12] ?? []);
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
