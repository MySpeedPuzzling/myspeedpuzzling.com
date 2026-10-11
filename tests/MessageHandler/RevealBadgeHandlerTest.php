<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RevealBadge;
use SpeedPuzzling\Web\MessageHandler\RevealBadgeHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RevealBadgeHandlerTest extends KernelTestCase
{
    private RevealBadgeHandler $handler;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->handler = $container->get(RevealBadgeHandler::class);
        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testRevealFlipsBadgeAndItsLowerTiers(): void
    {
        $bronze = $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 1);
        $silver = $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 2);
        $gold = $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 3);
        $unrelated = $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'streak', 1);

        ($this->handler)(new RevealBadge(PlayerFixture::PLAYER_REGULAR, $silver));
        $this->entityManager->flush();

        self::assertNotNull($this->revealedAt($bronze), 'Lower tier flips together with the clicked one');
        self::assertNotNull($this->revealedAt($silver));
        self::assertNull($this->revealedAt($gold), 'Higher tier stays unrevealed');
        self::assertNull($this->revealedAt($unrelated), 'Other badge types stay untouched');
    }

    public function testRevealIgnoresNonOwners(): void
    {
        $badge = $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 1);

        ($this->handler)(new RevealBadge(PlayerFixture::PLAYER_PRIVATE, $badge));
        $this->entityManager->flush();

        self::assertNull($this->revealedAt($badge));
    }

    private function insertBadge(string $playerId, string $type, int $tier): string
    {
        $id = Uuid::uuid7()->toString();

        $this->database->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (:id, :playerId, :type, NOW(), :tier)',
            ['id' => $id, 'playerId' => $playerId, 'type' => $type, 'tier' => $tier],
        );

        return $id;
    }

    private function revealedAt(string $badgeId): null|string
    {
        $value = $this->database->fetchOne('SELECT revealed_at FROM badge WHERE id = :id', ['id' => $badgeId]);

        return is_string($value) ? $value : null;
    }
}
