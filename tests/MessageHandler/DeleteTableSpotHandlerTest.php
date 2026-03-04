<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DeleteTableSpot;
use SpeedPuzzling\Web\Tests\DataFixtures\TableLayoutFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteTableSpotHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeleteSpot(): void
    {
        $this->messageBus->dispatch(new DeleteTableSpot(
            spotId: TableLayoutFixture::SPOT_EMPTY,
        ));

        /** @var int|string|false $count */
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM table_spot WHERE id = :id',
            ['id' => TableLayoutFixture::SPOT_EMPTY],
        );
        self::assertSame(0, (int) $count);
    }
}
