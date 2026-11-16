<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\Json;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\ResultsImportCheck;
use SpeedPuzzling\Web\Message\ImportResultsFromCheck;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportEvent;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportComparison;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\EventResultsImport\PreviousSheetMatch;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsImportStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * No duplicates after the organizer changed the spreadsheet (docs/features/event-results-import/results-import-page.md
 * "As built: PR 4"): the new sheet's tabs have other gids; an event with the date and name of one imported before is
 * shown against it ("from your previous sheet") and importing it moves the stored item to the new key and updates the
 * same event - from the page and from the admins' sync. Uncertain matches are "Needs fixing" and never imported.
 * Made-up events and teams.
 */
final class PreviousSheetEventsImportTest extends KernelTestCase
{
    use ResultsImportScenario;

    private EventResultsSheet $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->source = $this->createResultsSource(self::getContainer());
    }

    public function testTheNewSheetsEventsUpdateTheEventsImportedFromThePreviousSheet(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '101', 'Maple', self::tab('Maple Night', '10/5/26', '1:10:00'));
        self::applyTab($container, $this->source, '102', 'Corner', self::tab('Corner Night', '10/7/26', '0:50:00'));
        $cornerBefore = $this->storedItem('102');

        // The new spreadsheet: the same two events under other gids (one changed since), and a new one
        $check = $this->check([
            new SheetSnapshotTab('901', 'Maple 10/5', self::tab('Maple Night', '10/5/26', '1:10:00')),
            new SheetSnapshotTab('902', 'Corner 10/7', self::tab('Corner Night', '10/7/26', '0:49:00')),
            new SheetSnapshotTab('903', 'Elm 10/9', self::tab('Elm Night', '10/9/26', '0:45:00')),
        ]);

        $overview = $container->get(ResultsImportComparison::class)->compare($this->source, $check);
        self::assertSame([
            ['901', ResultsImportStatus::Unchanged, '101'],
            ['902', ResultsImportStatus::Changed, '102'],
            ['903', ResultsImportStatus::New, null],
        ], self::statuses($overview->events));
        self::assertSame($cornerBefore['competition_id'], $overview->event('902')?->stored?->competitionId);

        $this->import($check, ['902', '903']);

        // The same event, its stored item moved to the new key, its result updated - never a second event
        $connection = $container->get(Connection::class);
        self::assertEquals(1, $connection->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Corner Night'"));
        $cornerAfter = $this->storedItem('902');
        self::assertSame($cornerBefore['id'], $cornerAfter['id']);
        self::assertSame($cornerBefore['competition_id'], $cornerAfter['competition_id']);
        self::assertFalse($this->storedItemExists('102'));
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM event_imported_result WHERE tab_id = :id', ['id' => $cornerAfter['id']]));
        self::assertEquals(2940, $connection->fetchOne("SELECT pst.seconds_to_solve FROM puzzle_solving_time pst JOIN competition c ON c.id = pst.competition_id WHERE c.name = 'Corner Night'"));
        self::assertEquals(1, $connection->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Elm Night'"));
        self::assertSame([['imported', 'imported']], $this->outcomes());

        // Checked again: the moved event is matched by its own key now; the unchanged one is still found by its name
        $after = $container->get(ResultsImportComparison::class)->compare($this->source, $check);
        self::assertSame([
            ['901', ResultsImportStatus::Unchanged, '101'],
            ['902', ResultsImportStatus::Unchanged, null],
            ['903', ResultsImportStatus::Unchanged, null],
        ], self::statuses($after->events));
        self::assertEquals(1, $connection->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Maple Night'"));
    }

    public function testTwoImportedEventsWithItsDateAndNameAreNeedsFixingAndNothingIsImported(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '201', 'Birch A', self::tab('Birch Night', '10/9/26', '0:45:00'));
        self::applyTab($container, $this->source, '202', 'Birch B', self::tab('Birch Night', '10/9/26', '0:46:00'));

        $check = $this->check([new SheetSnapshotTab('911', 'Birch', self::tab('birch night', '10/9/26', '0:44:00'))]);
        $event = $container->get(ResultsImportComparison::class)->compare($this->source, $check)->event('911');

        self::assertNotNull($event);
        self::assertSame(ResultsImportStatus::NeedsFixing, $event->status);
        self::assertSame(PreviousSheetMatch::MATCHES_SEVERAL_IMPORTED_EVENTS, $event->blockers[0]->code ?? null);

        $this->import($check, ['911']);

        self::assertSame([['not_imported']], $this->outcomes());
        self::assertEquals(2, $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE LOWER(name) = 'birch night'"));
        self::assertFalse($this->storedItemExists('911'));
    }

    public function testTwoEventsOfTheSheetMatchingOneImportedEventAreNeedsFixing(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '301', 'Oak', self::tab('Oak Night', '10/11/26', '0:45:00'));

        $check = $this->check([
            new SheetSnapshotTab('921', 'Oak', self::tab('Oak Night', '10/11/26', '0:44:00')),
            new SheetSnapshotTab('922', 'Oak (copy)', self::tab('Oak Night', '10/11/26', '0:44:00')),
        ]);
        $overview = $container->get(ResultsImportComparison::class)->compare($this->source, $check);

        foreach (['921', '922'] as $key) {
            $event = $overview->event($key);
            self::assertNotNull($event);
            self::assertSame(ResultsImportStatus::NeedsFixing, $event->status);
            self::assertSame(PreviousSheetMatch::SHARES_IMPORTED_EVENT, $event->blockers[0]->code ?? null);
        }

        $this->import($check, ['921', '922']);

        self::assertSame([['not_imported', 'not_imported']], $this->outcomes());
        self::assertEquals(1, $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Oak Night'"));
    }

    public function testANeverImportedItemUnderTheNewKeyMakesWayForTheImportedEvent(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '401', 'Cedar', self::tab('Cedar Night', '10/13/26', '0:45:00'));
        $cedarBefore = $this->storedItem('401');

        // The new sheet's tab was synced while it waited for its puzzle - stored, never imported
        self::applyTab($container, $this->source, '931', 'Cedar 10/13', self::tab('Cedar Night', '10/13/26', '0:44:00', withPuzzle: false));
        self::assertNull($this->storedItem('931')['competition_id']);

        $check = $this->check([new SheetSnapshotTab('931', 'Cedar 10/13', self::tab('Cedar Night', '10/13/26', '0:44:00'))]);
        $event = $container->get(ResultsImportComparison::class)->compare($this->source, $check)->event('931');
        self::assertNotNull($event);
        self::assertSame(ResultsImportStatus::Changed, $event->status);
        self::assertSame('401', $event->previousSheetKey);

        $this->import($check, ['931']);

        self::assertSame([['imported']], $this->outcomes());
        self::assertEquals(1, $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Cedar Night'"));
        $cedarAfter = $this->storedItem('931');
        self::assertSame($cedarBefore['id'], $cedarAfter['id']);
        self::assertSame($cedarBefore['competition_id'], $cedarAfter['competition_id']);
        self::assertFalse($this->storedItemExists('401'));
    }

    public function testTheSyncMovesTheEventToo(): void
    {
        $container = self::getContainer();
        $this->fakeGoogle($container);
        self::applyTab($container, $this->source, '501', 'Pine', self::tab('Pine Night', '10/15/26', '0:45:00'));
        self::applyTab($container, $this->source, '502', 'Fir A', self::tab('Fir Night', '10/16/26', '0:45:00'));
        self::applyTab($container, $this->source, '503', 'Fir B', self::tab('Fir Night', '10/16/26', '0:47:00'));
        $pineBefore = $this->storedItem('501');

        $this->fakeSheetTabs = [
            '951' => ['title' => 'Pine 10/15', 'csv' => self::tab('Pine Night', '10/15/26', '0:43:00')],
            '952' => ['title' => 'Fir 10/16', 'csv' => self::tab('Fir Night', '10/16/26', '0:43:00')],
        ];
        $container->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($this->source->id->toString()));
        $container->get(EntityManagerInterface::class)->clear();

        $connection = $container->get(Connection::class);
        self::assertEquals(1, $connection->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Pine Night'"));
        self::assertSame($pineBefore['id'], $this->storedItem('951')['id']);
        self::assertEquals(2580, $connection->fetchOne("SELECT pst.seconds_to_solve FROM puzzle_solving_time pst JOIN competition c ON c.id = pst.competition_id WHERE c.name = 'Pine Night'"));

        // Uncertain: not applied, said in the source's fetch error
        self::assertEquals(2, $connection->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Fir Night'"));
        self::assertFalse($this->storedItemExists('952'));
        self::assertStringContainsString('Fir 10/16', (string) $connection->fetchOne('SELECT last_fetch_error FROM event_results_sheet WHERE id = :id', ['id' => $this->source->id->toString()]));
    }

    private static function tab(string $name, string $date, string $time, bool $withPuzzle = true): string
    {
        $link = $withPuzzle ? 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 : '';

        return self::sheetCsv(["Only Team,$time,4,,$name,Hall,\"Springfield, MN\",$date,7:00 PM,90,Teams,$link"]);
    }

    /**
     * @param list<ResultsImportEvent> $events
     * @return list<array{string, ResultsImportStatus, null|string}>
     */
    private static function statuses(array $events): array
    {
        return array_map(static fn (ResultsImportEvent $event): array => [$event->event->key, $event->status, $event->previousSheetKey], $events);
    }

    /**
     * @param list<SheetSnapshotTab> $tabs
     */
    private function check(array $tabs): ResultsImportCheck
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $source = $entityManager->find(EventResultsSheet::class, $this->source->id);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($source);

        // The test clock stands still: the tabs applied above were applied at this very moment - not after the read
        $check = ResultsImportCheck::read(Uuid::uuid7(), $source, $player, TestClock::now(), new SheetSnapshot($tabs));
        $entityManager->persist($check);
        $entityManager->flush();

        return $check;
    }

    /**
     * @param list<string> $keys
     */
    private function import(ResultsImportCheck $check, array $keys): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ImportResultsFromCheck(
            importId: Uuid::uuid7()->toString(),
            sourceId: $this->source->id->toString(),
            checkId: $check->id->toString(),
            eventKeys: $keys,
            importedByPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return array{id: string, competition_id: null|string}
     */
    private function storedItem(string $key): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT id, competition_id FROM event_results_sheet_tab WHERE sheet_id = :source AND gid = :key',
            ['source' => $this->source->id->toString(), 'key' => $key],
        );
        self::assertIsArray($row, 'stored item ' . $key);
        self::assertIsString($row['id']);
        self::assertTrue($row['competition_id'] === null || is_string($row['competition_id']));

        return ['id' => $row['id'], 'competition_id' => $row['competition_id']];
    }

    private function storedItemExists(string $key): bool
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM event_results_sheet_tab WHERE sheet_id = :source AND gid = :key',
            ['source' => $this->source->id->toString(), 'key' => $key],
        );

        return is_numeric($count) && (int) $count > 0;
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
