<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\Json;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\ResultsImportCheck;
use SpeedPuzzling\Web\Message\ImportResultsFromCheck;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ImportResultsFromCheck (docs/features/event-results-import/results-import-page.md "As built: PR 3"): an event applied
 * after the check was read is refused (a newer check won), one event that fails holds back no other and the log is
 * written anyway, the same import id imports nothing twice, and only events that can be imported are applied.
 * Made-up events and teams.
 */
final class ImportResultsFromCheckHandlerTest extends KernelTestCase
{
    use ResultsImportScenario;

    private EventResultsSheet $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->source = $this->createResultsSource(self::getContainer());
    }

    public function testAnOlderCheckNeverOverwritesWhatANewerOnePublished(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $older = $this->check(TestClock::now()->modify('-10 minutes'), [new SheetSnapshotTab('11', 'Maple', self::sheetCsv([
            "Puzzle Pals,1:10:13,4,,Maple Night,Maple Taproom,\"Springfield, MN\",10/5/26,7:00 PM,120,Teams,$link",
        ]))]);
        $newer = $this->check(TestClock::now()->modify('-5 minutes'), [new SheetSnapshotTab('11', 'Maple', self::sheetCsv([
            "Puzzle Pals,1:09:00,4,,Maple Night,Maple Taproom,\"Springfield, MN\",10/5/26,7:00 PM,120,Teams,$link",
        ]))]);

        // The test clock stands still, so the newer import is applied "now" - after both reads; the older read is older
        $this->import($newer, ['11']);
        $this->import($older, ['11']);

        $connection = self::getContainer()->get(Connection::class);
        self::assertEquals(4140, $connection->fetchOne("SELECT pst.seconds_to_solve FROM puzzle_solving_time pst JOIN competition c ON c.id = pst.competition_id WHERE c.name = 'Maple Night'"));

        $outcomes = $this->outcomes();
        self::assertSame([['imported'], ['stale']], $outcomes);
    }

    public function testAFailingEventHoldsBackNoOtherAndTheLogIsWritten(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $tab = static fn (string $name): string => self::sheetCsv([
            "Team A,0:45:00,4,,$name,Hall,\"Springfield, MN\",10/11/26,,,Teams,$link",
        ]);
        // A key longer than the stored column (20 characters) - its tab cannot be written, the database refuses it
        $check = $this->check(new DateTimeImmutable('2026-11-16 10:00:00'), [
            new SheetSnapshotTab('21', 'First', $tab('First Night')),
            new SheetSnapshotTab('222222222222222222222222', 'Broken', $tab('Broken Night')),
            new SheetSnapshotTab('23', 'Third', $tab('Third Night')),
            new SheetSnapshotTab('24', 'Waiting', self::sheetCsv(['Team B,0:45:00,4,,Waiting Night,Hall,"Springfield, MN",10/11/26,,,Teams,'])),
        ]);

        $this->import($check, ['21', '222222222222222222222222', '23', '24', '99']);

        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(['First Night', 'Third Night'], $connection->fetchFirstColumn("SELECT name FROM competition WHERE name IN ('First Night', 'Broken Night', 'Third Night', 'Waiting Night') ORDER BY name"));
        self::assertSame([['imported', 'failed', 'imported', 'not_imported', 'not_in_check']], $this->outcomes());
    }

    public function testTheSameImportIdImportsNothingTwice(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $check = $this->check(new DateTimeImmutable('2026-11-16 10:00:00'), [new SheetSnapshotTab('31', 'Once', self::sheetCsv([
            "Once Team,0:45:00,4,,Once Night,Hall,\"Springfield, MN\",10/11/26,,,Teams,$link",
        ]))]);

        $importId = Uuid::uuid7()->toString();
        $this->import($check, ['31'], $importId);
        $this->import($check, ['31'], $importId);

        $connection = self::getContainer()->get(Connection::class);
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM results_import_log'));
        self::assertEquals(1, $connection->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Once Night'"));
    }

    /**
     * @param list<SheetSnapshotTab> $tabs
     */
    private function check(DateTimeImmutable $checkedAt, array $tabs): ResultsImportCheck
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $source = $entityManager->find(EventResultsSheet::class, $this->source->id);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($source);

        $check = ResultsImportCheck::read(Uuid::uuid7(), $source, $player, $checkedAt, new SheetSnapshot($tabs));
        $entityManager->persist($check);
        $entityManager->flush();

        return $check;
    }

    /**
     * @param list<string> $keys
     */
    private function import(ResultsImportCheck $check, array $keys, null|string $importId = null): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ImportResultsFromCheck(
            importId: $importId ?? Uuid::uuid7()->toString(),
            sourceId: $this->source->id->toString(),
            checkId: $check->id->toString(),
            eventKeys: $keys,
            importedByPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return list<list<string>> per log (oldest first), the outcome of each event
     */
    private function outcomes(): array
    {
        $outcomes = [];

        foreach (self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT events FROM results_import_log ORDER BY imported_at, id') as $events) {
            self::assertIsString($events);
            /** @var list<array{outcome: string}> $decoded */
            $decoded = Json::decode($events, forceArrays: true);
            $outcomes[] = array_map(static fn (array $event): string => $event['outcome'], $decoded);
        }

        return $outcomes;
    }
}
