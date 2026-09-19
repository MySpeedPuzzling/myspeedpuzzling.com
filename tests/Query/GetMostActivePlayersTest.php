<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetMostActivePlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetMostActivePlayersTest extends KernelTestCase
{
    private GetMostActivePlayers $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetMostActivePlayers::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testBlockedPlayerIsLeftOutOfMostActiveSoloPlayers(): void
    {
        $everyone = array_map(static fn ($p) => $p->playerId, $this->query->mostActiveSoloPlayers(100));
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $everyone);

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $visible = array_map(static fn ($p) => $p->playerId, $this->query->mostActiveSoloPlayers(100));
        // PLAYER_PRIVATE is blocked by the fixture, PLAYER_ADMIN by this test
        self::assertSame(
            array_values(array_diff($everyone, [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE])),
            $visible,
        );

        // The limit applies after the filter
        self::assertSame(
            array_slice($visible, 0, 2),
            array_map(static fn ($p) => $p->playerId, $this->query->mostActiveSoloPlayers(2)),
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame($everyone, array_map(static fn ($p) => $p->playerId, $this->query->mostActiveSoloPlayers(100)));
    }

    public function testBlockedPlayerIsLeftOutOfMostActiveSoloPlayersInMonth(): void
    {
        $this->database->executeStatement("UPDATE puzzle_solving_time SET finished_at = '2024-05-15 12:00:00' WHERE puzzling_type = 'solo'");

        $everyone = array_map(static fn ($p) => $p->playerId, $this->query->mostActiveSoloPlayersInMonth(100, 5, 2024));
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $everyone);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame(
            array_values(array_diff($everyone, [PlayerFixture::PLAYER_PRIVATE])),
            array_map(static fn ($p) => $p->playerId, $this->query->mostActiveSoloPlayersInMonth(100, 5, 2024)),
        );
    }

    public function testMostActivePlayersQueryLeavesOutTheBlockedPlayer(): void
    {
        $everyone = $this->database->executeQuery($this->query->mostActivePlayersQuery(), ['limit' => 100])->fetchFirstColumn();
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $everyone);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        $visible = $this->database->executeQuery($this->query->mostActivePlayersQuery(), ['limit' => 100])->fetchFirstColumn();
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $visible);
        self::assertCount(count($everyone) - 1, $visible);
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
