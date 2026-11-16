<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordResultsImportCheck;
use SpeedPuzzling\Web\MessageHandler\RecordResultsImportCheckHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A source keeps its newest checks only - each holds the whole sheet; a failed read is a check too.
 */
final class RecordResultsImportCheckHandlerTest extends KernelTestCase
{
    use ResultsImportScenario;

    public function testOnlyTheNewestChecksAreKept(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $source = $this->createResultsSource($container);
        $bus = $container->get(MessageBusInterface::class);
        $lastId = null;

        for ($i = 1; $i <= RecordResultsImportCheckHandler::KEEP + 2; $i++) {
            $lastId = Uuid::uuid7()->toString();
            $bus->dispatch(new RecordResultsImportCheck(
                checkId: $lastId,
                sourceId: $source->id->toString(),
                checkedByPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
                checkedAt: new DateTimeImmutable(sprintf('2026-11-16 10:%02d:00', $i)),
                snapshot: $i % 2 === 0 ? new SheetSnapshot([new SheetSnapshotTab('1', 'Tab', 'Name')]) : null,
                readError: $i % 2 === 0 ? null : 'Google did not answer',
            ));
        }

        $connection = $container->get(Connection::class);
        $kept = $connection->fetchFirstColumn('SELECT checked_at FROM results_import_check WHERE source_id = :id ORDER BY checked_at', ['id' => $source->id->toString()]);

        self::assertCount(RecordResultsImportCheckHandler::KEEP, $kept);
        self::assertSame('2026-11-16 10:03:00', $kept[0]);
        // Checks 3, 5, 7, 9 and 11 failed to read - kept like the others, with the reason and no sheet
        self::assertEquals(5, $connection->fetchOne("SELECT COUNT(*) FROM results_import_check WHERE source_id = :id AND read_error = 'Google did not answer' AND snapshot IS NULL", ['id' => $source->id->toString()]));
        self::assertNull($connection->fetchOne('SELECT read_error FROM results_import_check WHERE id = :id', ['id' => $lastId]));
    }
}
