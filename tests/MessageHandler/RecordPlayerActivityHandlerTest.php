<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecordPlayerActivity;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordPlayerActivityHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testRecordsOneRowPerPlayerAndDayIdempotently(): void
    {
        $this->messageBus->dispatch(new RecordPlayerActivity(PlayerFixture::PLAYER_REGULAR_USER_ID));
        $this->messageBus->dispatch(new RecordPlayerActivity(PlayerFixture::PLAYER_REGULAR_USER_ID));

        self::assertSame(1, $this->countRowsFor(PlayerFixture::PLAYER_REGULAR));
    }

    public function testUnknownUserIdRecordsNothing(): void
    {
        $this->messageBus->dispatch(new RecordPlayerActivity('msp|does-not-exist'));

        /** @var int|string $count */
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM player_activity_day');

        self::assertSame(0, (int) $count);
    }

    private function countRowsFor(string $playerId): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM player_activity_day WHERE player_id = :id',
            ['id' => $playerId],
        );

        return (int) $count;
    }
}
