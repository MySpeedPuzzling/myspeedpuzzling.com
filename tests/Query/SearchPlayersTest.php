<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchPlayersTest extends KernelTestCase
{
    private SearchPlayers $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(SearchPlayers::class);
        $this->database = $container->get(Connection::class);
    }

    public function testBlockerDoesNotFindTheBlockedPlayer(): void
    {
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME, limit: 5));
        // Every other match is still found: the filter applies to the whole OR chain, not its last arm
        self::assertNotEmpty($this->search('a'));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->search('o'));
    }

    public function testOrganiserToolingStillFindsTheBlockedPlayer(): void
    {
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME, limit: 10, includeHidden: true));
    }

    /**
     * @return list<string>
     */
    private function search(string $search, null|int $limit = null, bool $includeHidden = false): array
    {
        return array_map(
            static fn (PlayerIdentification $player): string => $player->playerId,
            $this->query->fulltext($search, $limit, $includeHidden),
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
