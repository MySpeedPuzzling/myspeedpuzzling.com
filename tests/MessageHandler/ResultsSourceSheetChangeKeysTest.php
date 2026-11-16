<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\EventResultsSheetTab;
use SpeedPuzzling\Web\Message\ChangeResultsSourceSheet;
use SpeedPuzzling\Web\Message\ImportResultsFromCheck;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportEvent;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportOverview;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportComparison;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsImportStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsSourceSheetChange;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A change of the spreadsheet sets every stored item aside under the previous-sheet prefix ("p:" + its key), in the
 * change's transaction (docs/features/event-results-import/results-import-page.md "As built: PR 4"): a brand-new
 * spreadsheet's first tab is gid 0 again, and it must never be taken for the event imported from the old gid 0. From
 * then on only date + name connect an old event to the new sheet. Made-up spreadsheet ids, events and teams.
 */
final class ResultsSourceSheetChangeKeysTest extends KernelTestCase
{
    use ResultsImportScenario;

    private EventResultsSheet $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->source = $this->createResultsSource(self::getContainer(), 'first-test-spreadsheet-0123456789');
        $this->fakeGoogle(self::getContainer());
    }

    public function testANewSheetsGidZeroIsANewEventAndTheOldOneIsFoundByDateAndName(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '0', 'Leaf', self::tab('Leaf Night', '10/5/26', '1:10:00'));
        $leafBefore = $this->item('0');

        // The new spreadsheet's first tab (gid 0) is another event; the old one is its second tab
        $this->fakeSheetTabs = [
            '0' => ['title' => 'Brook', 'csv' => self::tab('Brook Night', '10/20/26', '0:50:00')],
            '777' => ['title' => 'Leaf', 'csv' => self::tab('Leaf Night', '10/5/26', '1:09:00')],
        ];
        self::assertSame(ResultsSourceSheetChange::Changed, $this->changeSheet('second-test-spreadsheet-0123456789'));
        self::assertSame(['p:0'], $this->keys());

        self::assertSame([
            ['0', ResultsImportStatus::New, null],
            ['777', ResultsImportStatus::Changed, 'p:0'],
        ], self::statuses($this->overview()));

        $this->importLatestCheck(['0', '777']);

        // Two events: Leaf Night updated in place (its item under the new tab's key), Brook Night new
        $connection = $container->get(Connection::class);
        self::assertSame(['Brook Night', 'Leaf Night'], $connection->fetchFirstColumn("SELECT name FROM competition WHERE name IN ('Leaf Night', 'Brook Night') ORDER BY name"));
        self::assertSame($leafBefore, $this->item('777'));
        self::assertEquals(4140, $this->secondsOf('Leaf Night'));
        self::assertEquals(3000, $this->secondsOf('Brook Night'));
        self::assertSame(['0', '777'], $this->keys());
    }

    public function testAnOldEventNoTabMatchesStaysUntouched(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '0', 'Leaf', self::tab('Leaf Night', '10/5/26', '1:10:00'));
        $leafBefore = $this->item('0');

        $this->fakeSheetTabs = ['0' => ['title' => 'Brook', 'csv' => self::tab('Brook Night', '10/20/26', '0:50:00')]];
        $this->changeSheet('second-test-spreadsheet-0123456789');

        self::assertSame([['0', ResultsImportStatus::New, null]], self::statuses($this->overview()));
        $this->importLatestCheck(['0']);

        self::assertSame($leafBefore, $this->item('p:0'));
        self::assertEquals(4200, $this->secondsOf('Leaf Night'));
        self::assertEquals(1, $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Brook Night'"));
    }

    public function testThePrefixIsAppliedOnceAndNeverTwiceToOneKey(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '0', 'Leaf', self::tab('Leaf Night', '10/5/26', '1:10:00'));

        // Second sheet: its gid 0 is another event, imported as new
        $this->fakeSheetTabs = ['0' => ['title' => 'Brook', 'csv' => self::tab('Brook Night', '10/20/26', '0:50:00')]];
        $this->changeSheet('second-test-spreadsheet-0123456789');
        $this->importLatestCheck(['0']);
        self::assertSame(['0', 'p:0'], $this->keys());

        // Third sheet: Leaf Night keeps "p:0" (never "p:p:0"); Brook Night's "p:0" is taken - its item's id instead
        $this->fakeSheetTabs = ['5' => ['title' => 'Elm', 'csv' => self::tab('Elm Night', '10/22/26', '0:40:00')]];
        $this->changeSheet('third-test-spreadsheet-0123456789');
        $keys = $this->keys();
        self::assertCount(2, $keys);
        self::assertSame('p:0', $keys[0]);
        self::assertStringStartsWith(EventResultsSheetTab::PREVIOUS_SHEET_FALLBACK_PREFIX, $keys[1]);
        self::assertSame(EventResultsSheetTab::KEY_MAX_LENGTH, strlen($keys[1]));

        // A change again sets nothing aside twice
        $this->fakeSheetTabs = ['6' => ['title' => 'Fir', 'csv' => self::tab('Fir Night', '10/24/26', '0:40:00')]];
        $this->changeSheet('fourth-test-spreadsheet-0123456789');
        self::assertSame($keys, $this->keys());
    }

    public function testAKeyTooLongForThePrefixGetsTheItemsIdAndEveryKeyFits(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $source = $entityManager->find(EventResultsSheet::class, $this->source->id);
        self::assertNotNull($source);

        $short = new EventResultsSheetTab(Uuid::uuid7(), $source, '1234567890', 'A gid');
        self::assertSame('p:1234567890', $short->setAsideForPreviousSheet([]));
        self::assertTrue($short->isFromPreviousSheet());
        self::assertSame('p:1234567890', $short->setAsideForPreviousSheet([]), 'idempotent');

        $long = new EventResultsSheetTab(Uuid::fromString('0196f5a0-0000-7000-8abc-def012345678'), $source, '1234567890123456789', 'A long key');
        $key = $long->setAsideForPreviousSheet([]);
        self::assertSame('p~008abcdef012345678', $key);
        self::assertLessThanOrEqual(EventResultsSheetTab::KEY_MAX_LENGTH, strlen($key));

        $taken = new EventResultsSheetTab(Uuid::fromString('0196f5a0-0000-7000-8000-000000000042'), $source, '7', 'Taken');
        self::assertSame('p~008000000000000042', $taken->setAsideForPreviousSheet(['p:7' => true]));
    }

    public function testChangingBackToTheOldSheetMatchesByDateAndNameOnly(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '101', 'Maple', self::tab('Maple Night', '10/5/26', '1:10:00'));
        $mapleBefore = $this->item('101');

        $this->fakeSheetTabs = ['0' => ['title' => 'Brook', 'csv' => self::tab('Brook Night', '10/20/26', '0:50:00')]];
        $this->changeSheet('second-test-spreadsheet-0123456789');

        // Back to the first sheet, its tab corrected meanwhile
        $this->fakeSheetTabs = ['101' => ['title' => 'Maple', 'csv' => self::tab('Maple Night', '10/5/26', '1:08:00')]];
        self::assertSame(ResultsSourceSheetChange::Changed, $this->changeSheet('first-test-spreadsheet-0123456789'));
        self::assertSame(['p:101'], $this->keys());
        self::assertSame([['101', ResultsImportStatus::Changed, 'p:101']], self::statuses($this->overview()));

        $this->importLatestCheck(['101']);

        self::assertSame($mapleBefore, $this->item('101'));
        self::assertEquals(1, $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Maple Night'"));
        self::assertEquals(4080, $this->secondsOf('Maple Night'));
    }

    public function testTheSyncAfterASheetChangeNeverRenamesTheWrongEvent(): void
    {
        $container = self::getContainer();
        self::applyTab($container, $this->source, '0', 'Leaf', self::tab('Leaf Night', '10/5/26', '1:10:00'));
        $leafBefore = $this->item('0');

        $this->fakeSheetTabs = ['0' => ['title' => 'Brook', 'csv' => self::tab('Brook Night', '10/20/26', '0:50:00')]];
        $this->changeSheet('second-test-spreadsheet-0123456789');

        $container->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($this->source->id->toString()));
        $container->get(EntityManagerInterface::class)->clear();

        $connection = $container->get(Connection::class);
        self::assertSame($leafBefore, $this->item('p:0'));
        self::assertSame('Leaf Night', $connection->fetchOne('SELECT name FROM competition WHERE id = :id', ['id' => $leafBefore['competition_id']]));
        self::assertEquals(4200, $this->secondsOf('Leaf Night'));
        self::assertEquals(1, $connection->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Brook Night'"));
        self::assertNotSame($leafBefore['competition_id'], $this->item('0')['competition_id']);
    }

    private static function tab(string $name, string $date, string $time): string
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        return self::sheetCsv(["Only Team,$time,4,,$name,Hall,\"Springfield, MN\",$date,7:00 PM,90,Teams,$link"]);
    }

    private function changeSheet(string $spreadsheetId): mixed
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeResultsSourceSheet(
            checkId: Uuid::uuid7()->toString(),
            sourceId: $this->source->id->toString(),
            spreadsheetId: $spreadsheetId,
            changedByPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $envelope->last(HandledStamp::class)?->getResult();
    }

    private function overview(): ResultsImportOverview
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $source = $entityManager->find(EventResultsSheet::class, $this->source->id);
        self::assertNotNull($source);
        $check = self::getContainer()->get(ResultsImportCheckRepository::class)->latestOf($source);
        self::assertNotNull($check);

        return self::getContainer()->get(ResultsImportComparison::class)->compare($source, $check);
    }

    /**
     * @param list<string> $keys
     */
    private function importLatestCheck(array $keys): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ImportResultsFromCheck(
            importId: Uuid::uuid7()->toString(),
            sourceId: $this->source->id->toString(),
            checkId: $this->overview()->checkId,
            eventKeys: $keys,
            importedByPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        ));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return list<array{string, ResultsImportStatus, null|string}>
     */
    private static function statuses(ResultsImportOverview $overview): array
    {
        return array_map(static fn (ResultsImportEvent $event): array => [$event->event->key, $event->status, $event->previousSheetKey], $overview->events);
    }

    /**
     * @return list<string> the source's stored keys, sorted
     */
    private function keys(): array
    {
        $keys = array_map(
            static fn (mixed $key): string => is_scalar($key) ? (string) $key : '',
            self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT gid FROM event_results_sheet_tab WHERE sheet_id = :id ORDER BY gid', ['id' => $this->source->id->toString()]),
        );
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @return array{id: string, competition_id: null|string}
     */
    private function item(string $key): array
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

    private function secondsOf(string $competitionName): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT pst.seconds_to_solve FROM puzzle_solving_time pst JOIN competition c ON c.id = pst.competition_id WHERE c.name = :name',
            ['name' => $competitionName],
        );
    }
}
