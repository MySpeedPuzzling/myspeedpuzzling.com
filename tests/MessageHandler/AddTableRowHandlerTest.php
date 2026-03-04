<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddTableRow;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddTableRowHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddRowIncrementsPosition(): void
    {
        $roundId = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

        $this->messageBus->dispatch(new AddTableRow(
            rowId: Uuid::uuid7(),
            roundId: $roundId,
        ));

        /** @var int|string|false $maxPosition */
        $maxPosition = $this->database->fetchOne(
            'SELECT MAX(position) FROM table_row WHERE round_id = :roundId',
            ['roundId' => $roundId],
        );
        self::assertSame(3, (int) $maxPosition);
    }
}
