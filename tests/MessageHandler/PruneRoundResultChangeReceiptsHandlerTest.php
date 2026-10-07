<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\PruneRoundResultChangeReceipts;
use SpeedPuzzling\Web\MessageHandler\PruneRoundResultChangeReceiptsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PruneRoundResultChangeReceiptsHandlerTest extends KernelTestCase
{
    public function testDeletesOldReceiptsAndKeepsRecentOnes(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);

        $old = $this->insert($connection, new \DateTimeImmutable('-91 days'));
        $recent = $this->insert($connection, new \DateTimeImmutable('-2 days'));

        $deleted = $container->get(PruneRoundResultChangeReceiptsHandler::class)(new PruneRoundResultChangeReceipts(90));

        self::assertSame(1, $deleted);
        self::assertFalse($connection->fetchOne('SELECT 1 FROM round_result_change_receipt WHERE id = :id', ['id' => $old]));
        self::assertSame(1, $connection->fetchOne('SELECT 1 FROM round_result_change_receipt WHERE id = :id', ['id' => $recent]));
    }

    private function insert(Connection $connection, \DateTimeImmutable $receivedAt): string
    {
        $id = Uuid::uuid4()->toString();
        $connection->insert('round_result_change_receipt', [
            'id' => $id,
            'round_id' => OfficialResultsFixture::ROUND_GROUP_A,
            'status' => 'applied',
            'received_at' => $receivedAt->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }
}
