<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeletePlayerHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testThrowsWhenPlayerDoesNotExist(): void
    {
        try {
            $this->messageBus->dispatch(new DeletePlayer('00000000-0000-0000-0000-000000000999'));
            self::fail('Expected PlayerNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(PlayerNotFound::class, $e->getPrevious());
        }
    }

    public function testDeletesPlayerRowAndCascadesPersonalData(): void
    {
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR));
    }

    public function testTransfersTeamOwnershipWhenOwnerIsDeleted(): void
    {
        // TIME_12: owner = PLAYER_REGULAR, team = [PLAYER_REGULAR, PLAYER_PRIVATE]
        $regular = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($regular);
        $regularName = $regular->name;

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        $time = $this->entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_12);

        self::assertNotNull($time, 'TIME_12 must still exist; ownership should have transferred');
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $time->player->id->toString());
        self::assertNotNull($time->team);

        $puzzlerIds = array_map(static fn($p) => $p->playerId, $time->team->puzzlers);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $puzzlerIds, 'Deleted player must be removed from puzzlers');
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $puzzlerIds, 'New owner stays in puzzlers (current invariant)');

        // The anonymized entry preserves the original name
        $anonymizedNames = array_filter(array_map(
            static fn($p) => $p->playerId === null ? $p->playerName : null,
            $time->team->puzzlers,
        ));
        self::assertContains($regularName, $anonymizedNames);
    }

    public function testAnonymizesTeamMemberWhenNonOwnerIsDeleted(): void
    {
        // TIME_12: owner = PLAYER_REGULAR, team = [PLAYER_REGULAR, PLAYER_PRIVATE]
        $private = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($private);
        $privateName = $private->name;

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_PRIVATE));
        $this->entityManager->clear();

        $time = $this->entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_12);

        self::assertNotNull($time);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $time->player->id->toString(), 'Ownership unchanged');
        self::assertNotNull($time->team);
        self::assertCount(2, $time->team->puzzlers, 'Anonymized entry preserved, count unchanged');

        $puzzlerIds = array_map(static fn($p) => $p->playerId, $time->team->puzzlers);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $puzzlerIds);

        $anonymizedNames = array_filter(array_map(
            static fn($p) => $p->playerId === null ? $p->playerName : null,
            $time->team->puzzlers,
        ));
        self::assertContains($privateName, $anonymizedNames);
    }

    public function testRemovesSolvingTimeWhenOwnerHasNoOtherTeamMemberWithPlayerId(): void
    {
        // TIME_06: PLAYER_REGULAR solo solve (team = null) — should be hard-deleted
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        $time = $this->entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_06);

        self::assertNull($time, 'Solo solve of deleted player must be removed');
    }

    public function testScrubsFavoritePlayersFromOtherPlayers(): void
    {
        // PLAYER_WITH_FAVORITES has PLAYER_REGULAR in their favoritePlayers JSON
        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        $favorites = $this->entityManager->getConnection()
            ->fetchOne(
                'SELECT favorite_players::text FROM player WHERE id = :id',
                ['id' => PlayerFixture::PLAYER_WITH_FAVORITES],
            );

        self::assertIsString($favorites);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR, $favorites);
    }
}
