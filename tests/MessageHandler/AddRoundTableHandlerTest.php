<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddRoundTable;
use SpeedPuzzling\Web\Tests\DataFixtures\TableLayoutFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddRoundTableHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddTableToRow(): void
    {
        $tableId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddRoundTable(
            tableId: $tableId,
            rowId: TableLayoutFixture::TABLE_ROW_1,
        ));

        /** @var int|string|false $tableExists */
        $tableExists = $this->database->fetchOne(
            'SELECT COUNT(*) FROM round_table WHERE id = :id',
            ['id' => $tableId->toString()],
        );
        self::assertSame(1, (int) $tableExists);

        /** @var int|string|false $spotCount */
        $spotCount = $this->database->fetchOne(
            'SELECT COUNT(*) FROM table_spot WHERE table_id = :tableId',
            ['tableId' => $tableId->toString()],
        );
        self::assertSame(1, (int) $spotCount);
    }
}
