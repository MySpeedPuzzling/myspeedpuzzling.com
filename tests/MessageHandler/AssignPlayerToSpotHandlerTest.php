<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\AssignPlayerToSpot;
use SpeedPuzzling\Web\Repository\TableSpotRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TableLayoutFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AssignPlayerToSpotHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private TableSpotRepository $tableSpotRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->tableSpotRepository = self::getContainer()->get(TableSpotRepository::class);
    }

    public function testAssignPlayerFromDatabase(): void
    {
        $this->messageBus->dispatch(new AssignPlayerToSpot(
            spotId: TableLayoutFixture::SPOT_EMPTY,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $spot = $this->tableSpotRepository->get(TableLayoutFixture::SPOT_EMPTY);
        self::assertNotNull($spot->player);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $spot->player->id->toString());
        self::assertNull($spot->playerName);
    }

    public function testAssignManualName(): void
    {
        $this->messageBus->dispatch(new AssignPlayerToSpot(
            spotId: TableLayoutFixture::SPOT_EMPTY,
            playerName: 'Jane Smith',
        ));

        $spot = $this->tableSpotRepository->get(TableLayoutFixture::SPOT_EMPTY);
        self::assertSame('Jane Smith', $spot->playerName);
        self::assertNull($spot->player);
    }

    public function testClearAssignment(): void
    {
        $this->messageBus->dispatch(new AssignPlayerToSpot(
            spotId: TableLayoutFixture::SPOT_ASSIGNED_PLAYER,
        ));

        $spot = $this->tableSpotRepository->get(TableLayoutFixture::SPOT_ASSIGNED_PLAYER);
        self::assertNull($spot->player);
        self::assertNull($spot->playerName);
    }

    public function testReassignOverwritesPrevious(): void
    {
        $this->messageBus->dispatch(new AssignPlayerToSpot(
            spotId: TableLayoutFixture::SPOT_ASSIGNED_PLAYER,
            playerName: 'New Manual Name',
        ));

        $spot = $this->tableSpotRepository->get(TableLayoutFixture::SPOT_ASSIGNED_PLAYER);
        self::assertNull($spot->player);
        self::assertSame('New Manual Name', $spot->playerName);
    }
}
