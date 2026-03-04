<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DeleteTableRow;
use SpeedPuzzling\Web\Tests\DataFixtures\TableLayoutFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteTableRowHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeleteRowRemovesTablesAndSpots(): void
    {
        $this->messageBus->dispatch(new DeleteTableRow(
            rowId: TableLayoutFixture::TABLE_ROW_1,
        ));

        /** @var int|string|false $rowCount */
        $rowCount = $this->database->fetchOne(
            'SELECT COUNT(*) FROM table_row WHERE id = :id',
            ['id' => TableLayoutFixture::TABLE_ROW_1],
        );
        self::assertSame(0, (int) $rowCount);

        /** @var int|string|false $tableCount */
        $tableCount = $this->database->fetchOne(
            'SELECT COUNT(*) FROM round_table WHERE row_id = :rowId',
            ['rowId' => TableLayoutFixture::TABLE_ROW_1],
        );
        self::assertSame(0, (int) $tableCount);

        /** @var int|string|false $spotCount */
        $spotCount = $this->database->fetchOne(
            'SELECT COUNT(*) FROM table_spot WHERE table_id = :tableId',
            ['tableId' => TableLayoutFixture::ROUND_TABLE_1],
        );
        self::assertSame(0, (int) $spotCount);
    }
}
