<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ClearTableLayout;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ClearTableLayoutHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testClearRemovesEverything(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

        $this->messageBus->dispatch(new ClearTableLayout(
            roundId: $roundId,
        ));

        /** @var int|string|false $rowCount */
        $rowCount = $this->database->fetchOne(
            'SELECT COUNT(*) FROM table_row WHERE round_id = :roundId',
            ['roundId' => $roundId],
        );
        self::assertSame(0, (int) $rowCount);
    }
}
