<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddTableSpot;
use SpeedPuzzling\Web\Tests\DataFixtures\TableLayoutFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddTableSpotHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddSpotToTable(): void
    {
        $spotId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddTableSpot(
            spotId: $spotId,
            tableId: TableLayoutFixture::ROUND_TABLE_1,
        ));

        /** @var int|string|false $position */
        $position = $this->database->fetchOne(
            'SELECT position FROM table_spot WHERE id = :id',
            ['id' => $spotId->toString()],
        );
        self::assertSame(3, (int) $position);
    }
}
