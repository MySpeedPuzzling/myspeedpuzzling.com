<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DeleteRoundTable;
use SpeedPuzzling\Web\Tests\DataFixtures\TableLayoutFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteRoundTableHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeleteTableRemovesSpots(): void
    {
        $this->messageBus->dispatch(new DeleteRoundTable(
            tableId: TableLayoutFixture::ROUND_TABLE_1,
        ));

        /** @var int|string|false $tableCount */
        $tableCount = $this->database->fetchOne(
            'SELECT COUNT(*) FROM round_table WHERE id = :id',
            ['id' => TableLayoutFixture::ROUND_TABLE_1],
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
