<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportMappingChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ConfirmImportMapping;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\DiscardImport;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\ReadWaitingImports;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Message\RecordImportMappings;
use SpeedPuzzling\Web\Message\RecordParsedImportRows;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ParseImportSheetHandler;
use SpeedPuzzling\Web\MessageHandler\ProposeImportMappingsHandler;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\DataImport\ImportRowParser;
use SpeedPuzzling\Web\Services\DataImport\SheetLookupFactory;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/columns.md - a read import is recognised and its rows are parsed by themselves; a sheet
 * with a question waits; a confirmed mapping parses only its sheet (and the sheets that look puzzles up in it); a
 * job of an older mapping writes nothing; equal rows are linked. Every file here is made up.
 *
 * The `imports` queue is in memory in tests: the worker is played by work(), which hands every queued message to
 * its handler until the queue is empty.
 */
final class ImportParsingTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testAReadWorkbookIsRecognisedAndItsRowsAreParsedByThemselves(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $handled = $this->work();

        // Read → recognised → every sheet that asks nothing parsed (the rows of "Stats" are left out); "2023" waits
        self::assertSame(
            ['read', 'propose', 'parse Puzzle List v1', 'parse Results v1', 'parse Competitions v1', 'parse Stats v1'],
            $this->named($importId, $handled),
        );
        self::assertSame('setting_up', $this->database->fetchOne('SELECT status FROM data_import WHERE id = :id', ['id' => $importId]));

        self::assertSame(
            [
                'Puzzle List' => ['puzzle_list', 1, 1, 4, 0, 1, 0, 'A1:C'],
                'Results' => ['results', 1, 1, 5, 1, 2, 0, 'A1:E'],
                'Competitions' => ['results', 1, 1, 2, 0, 1, 0, 'A1:C'],
                'Stats' => ['not_puzzles', 1, 1, 0, 0, 2, 0, 'A1:B'],
                '2023' => ['results', 1, null, 0, 0, 0, 1, 'A1:C'],
            ],
            $this->sheets($importId),
        );

        $rows = $this->rows($importId);

        // Brand and pieces come from the puzzle list; the keys are the key functions' own
        $kites = ImportPuzzleKey::of('Circle of Kites', 500, 'Ravensburger');
        self::assertNotNull($kites);
        $row = $rows['Results'][2];
        self::assertSame(['result', 'waiting', 1, $kites], [$row['kind'], $row['state'], $row['parsed_version'], $row['puzzle_key']]);
        self::assertSame(ImportSourceKey::result($kites, new \DateTimeImmutable('2026-01-10'), DatePrecision::Day, 2893, PuzzlingType::Solo), $row['source_key']);
        self::assertEquals(
            ['name' => 'Circle of Kites', 'brand' => 'Ravensburger', 'pieces' => 500, 'date' => '2026-01-10', 'seconds' => 2893, 'type' => 'solo', 'attempt' => 1, 'firstTry' => true, 'from' => ['brand' => 'lookup', 'pieces' => 'lookup']],
            array_intersect_key($row['parsed'], array_flip(['name', 'brand', 'pieces', 'date', 'seconds', 'type', 'attempt', 'firstTry', 'from'])),
        );
        self::assertNull($row['problems']);

        // What is unclear is a problem of its field; what is no data is left out with its reason
        self::assertEquals([['field' => 'time', 'code' => 'time_unreadable', 'value' => '1:13:??']], $rows['Results'][7]['problems']);
        self::assertSame(['not_a_puzzle', 'left_out', ['leftOut' => 'header']], [$rows['Results'][1]['kind'], $rows['Results'][1]['state'], $rows['Results'][1]['parsed']]);
        self::assertSame(['leftOut' => 'template'], $rows['Results'][8]['parsed']);
        self::assertSame(['owned', $kites], [$rows['Puzzle List'][2]['kind'], $rows['Puzzle List'][2]['puzzle_key']]);
        self::assertSame(ImportSourceKey::owned($kites), $rows['Puzzle List'][2]['source_key']);

        // A sheet that waits: nothing parsed. A sheet that is not puzzles: its rows are left out, as after a confirm
        self::assertSame([null, 'waiting'], [$rows['2023'][2]['kind'], $rows['2023'][2]['state']]);
        self::assertSame(['not_a_puzzle', 'left_out', ['leftOut' => 'sheet_left_out']], [$rows['Stats'][2]['kind'], $rows['Stats'][2]['state'], $rows['Stats'][2]['parsed']]);

        // The page says it - read only
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        $states = $crawler->filter('[data-testid="import-sheet-state"]')->each(static fn ($node): string => trim((string) preg_replace('/\s+/', ' ', $node->text())));
        self::assertSame(
            [
                'Puzzle list the other sheets point to Rows understood: 4 · need a look: 0 · Left out: 1',
                'Results Rows understood: 5 · need a look: 1 · Left out: 2',
                'Results Rows understood: 2 · need a look: 0 · Left out: 1',
                'Not puzzles',
                'Results Waiting for your answer',
            ],
            $states,
        );
        self::assertCount(0, $crawler->filter('[data-testid="import-sheets-working"]'));

        // And the player's own topic was told - numbers only
        $published = $this->lastPublished($importId);
        self::assertSame([5, 4, 1], [$published['sheetsMapped'], $published['sheetsParsed'], $published['sheetsWaiting']]);
    }

    public function testEqualRowsAreNumberedAndLinkedOnTheirSheetAndAcrossSheets(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->work();
        $rows = $this->rows($importId);

        $marbles = ImportPuzzleKey::of('Glass Marbles', 500, 'Jumbo');
        self::assertNotNull($marbles);
        $key = static fn (int $occurrence): string => ImportSourceKey::result($marbles, new \DateTimeImmutable('2026-02-08'), DatePrecision::Day, 2750, PuzzlingType::Solo, $occurrence);

        // The same puzzle, day and time with attempt numbers 3 and 4: two results, numbered by the attempt
        self::assertSame([$key(1), null, 1], [$rows['Results'][3]['source_key'], $rows['Results'][3]['same_as_row_id'], $rows['Results'][3]['parsed']['occurrence']]);
        self::assertSame([$key(2), null, 2], [$rows['Results'][4]['source_key'], $rows['Results'][4]['same_as_row_id'], $rows['Results'][4]['parsed']['occurrence']]);
        self::assertEquals(['puzzle' => $marbles, 'result' => $key(2)], $rows['Results'][4]['parsed']['keys']);

        // The same puzzle, day and time without attempt numbers: one result unless the player says two
        self::assertNull($rows['Results'][5]['same_as_row_id']);
        self::assertSame($rows['Results'][5]['id'], $rows['Results'][6]['same_as_row_id']);
        self::assertNotSame($rows['Results'][5]['source_key'], $rows['Results'][6]['source_key']);

        // The same result on two sheets is one result: the later sheet's row points at the earlier one's
        self::assertSame($rows['Results'][2]['source_key'], $rows['Competitions'][2]['source_key']);
        self::assertSame($rows['Results'][2]['id'], $rows['Competitions'][2]['same_as_row_id']);
        self::assertNull($rows['Results'][2]['same_as_row_id']);
        self::assertNull($rows['Competitions'][3]['same_as_row_id']);
    }

    public function testAnAnswerParsesOnlyItsSheetAndEveryKeyStaysWhatItWas(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->work();
        $before = $this->rows($importId);
        $sheetId = $this->sheetId($importId, '2023');

        $this->bus->dispatch(new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, answers: ['duration_shape.two_part_text' => 'minutes_seconds']));

        // Only that sheet is parsed again, for its new version
        self::assertSame(['parse 2023 v2'], $this->named($importId, $this->work()));
        $after = $this->rows($importId);
        $sheets = $this->sheets($importId);
        self::assertSame(['results', 2, 2, 2, 0, 1, 0, 'A1:C'], $sheets['2023']);
        self::assertSame([2710, 2, 'result'], [$after['2023'][2]['parsed']['seconds'], $after['2023'][2]['parsed_version'], $after['2023'][2]['kind']]);
        self::assertEquals(['durations' => ['two_part_text' => ['rule' => 'minutes_seconds', 'by' => 'player']]], array_intersect_key($this->options($importId, '2023'), ['durations' => 1]));

        foreach (['Puzzle List', 'Results', 'Competitions', 'Stats'] as $untouched) {
            self::assertSame($before[$untouched], $after[$untouched], $untouched);
            self::assertSame(1, $sheets[$untouched][1]);
        }

        // A second delivery for a sheet that is parsed: nothing is parsed again - not even started
        $this->parse($importId, $this->sheetId($importId, 'Results'), 1);
        self::assertSame($after, $this->rows($importId));
        self::assertSame(1, $this->database->fetchOne("SELECT set_up_attempts FROM data_import_sheet WHERE import_id = :id AND title = 'Results'", ['id' => $importId]));

        // Parsed again for the same mapping after a parse that died half-way - groups of equal rows taken one at a
        // time: every row is what it was, keys, occurrences and links included
        $this->database->executeStatement("UPDATE data_import_sheet SET parsed_version = NULL, parsing_started_at = NULL WHERE import_id = :id AND title IN ('Results', 'Competitions')", ['id' => $importId]);
        // What the dead job left: one of two equal rows numbered, the other not yet
        $this->database->executeStatement("UPDATE data_import_row SET source_key = equal_key WHERE import_id = :id AND row_number = 4 AND sheet_id = :sheet", ['id' => $importId, 'sheet' => $this->sheetId($importId, 'Results')]);
        $container = self::getContainer();
        $onePageAtATime = new ParseImportSheetHandler(
            $container->get(GetImportSheetRows::class),
            $container->get(ImportRowParser::class),
            $container->get(SheetLookupFactory::class),
            $this->bus,
            $container->get(ImportProgress::class),
            new NullLogger(),
            1,
        );
        $container->get(EntityManagerInterface::class)->clear();
        $onePageAtATime(new ParseImportSheet($importId, $this->sheetId($importId, 'Results'), 1));
        $container->get(EntityManagerInterface::class)->clear();
        $this->parse($importId, $this->sheetId($importId, 'Competitions'), 1);

        self::assertSame($after, $this->rows($importId));
        self::assertSame(2, $this->database->fetchOne("SELECT set_up_attempts FROM data_import_sheet WHERE import_id = :id AND title = 'Results'", ['id' => $importId]));
    }

    public function testAParseIsClaimedOnceAndGivenUpAfterThreeStartsThatNeverEnded(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->work();
        $sheetId = $this->sheetId($importId, 'Results');
        $rows = $this->rows($importId);
        $claim = function (null|int $minutesAgo, int $attempts) use ($importId, $sheetId): void {
            $this->database->executeStatement('UPDATE data_import_sheet SET parsed_version = NULL, parsing_version = 1, parsing_started_at = :at, set_up_attempts = :attempts WHERE id = :id', [
                'at' => $minutesAgo === null ? null : TestClock::now()->modify('-' . $minutesAgo . ' minutes')->format('Y-m-d H:i:s'),
                'attempts' => $attempts,
                'id' => $sheetId,
            ]);
            $this->database->executeStatement('UPDATE data_import SET last_activity_at = :at WHERE id = :id', ['at' => TestClock::now()->modify('-16 minutes')->format('Y-m-d H:i:s'), 'id' => $importId]);
        };
        $state = fn (): array => array_values((array) $this->database->fetchAssociative('SELECT parsed_version, set_up_attempts, set_up_failure FROM data_import_sheet WHERE id = :id', ['id' => $sheetId]));

        // Another job is at it (it gave news a minute ago): this one leaves it alone, and so does the safety net
        $claim(1, 1);
        $this->parse($importId, $sheetId, 1);
        self::assertSame([null, 1, null], $state());
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame([], $this->queued());

        // That job is dead (no news for a quarter of an hour): the safety net queues the parse, and it is taken over
        $claim(16, 1);
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame(['parse Results v1'], $this->named($importId, $this->work()));
        self::assertSame([1, 2, null], $state());
        self::assertSame($rows, $this->rows($importId));

        // Started three times and never ended - a worker that dies on it: given up, visibly, and queued no more
        $claim(16, 3);
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame(['parse Results v1'], $this->named($importId, $this->work()));
        self::assertSame([null, 3, 'parse_failed'], $state());
        $this->database->executeStatement('UPDATE data_import SET last_activity_at = :at WHERE id = :id', ['at' => TestClock::now()->modify('-16 minutes')->format('Y-m-d H:i:s'), 'id' => $importId]);
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame([], $this->queued());

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertSame('We could not work this sheet out - a fault on our side', trim($crawler->filter('[data-testid="import-sheet-failed"]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="import-sheets-working"]'));

        // A mapping the player confirms starts over
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->bus->dispatch(new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, sheetWide: ['solvingType' => 'solo']));
        $this->work();
        self::assertSame([2, 1, null], $state());
    }

    public function testAProposalThatCanNotBeStoredFailsItsSheetOnlyAndIsNotAskedForAgain(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->read($importId);
        $this->queue()->reset();

        $notPuzzles = ['shape' => 1, 'layout' => null, 'kind' => 'not_puzzles', 'kindCertainty' => 'certain', 'rowMeaning' => 'result', 'region' => 0, 'regions' => [], 'columns' => [], 'questions' => []];
        $proposals = [];

        foreach (['Puzzle List', 'Results', 'Competitions', 'Stats', '2023'] as $title) {
            // One of them is no mapping at all (a bug of ours)
            $proposals[$this->sheetId($importId, $title)] = ['mapping' => $title === 'Results' ? ['shape' => 99] : $notPuzzles, 'options' => [], 'valueAnswers' => [], 'headerSignature' => null];
        }

        $this->bus->dispatch(new RecordImportMappings($importId, $proposals));

        self::assertSame(
            ['Puzzle List' => [1, null], 'Results' => [0, 'mapping_invalid'], 'Competitions' => [1, null], 'Stats' => [1, null], '2023' => [1, null]],
            array_map(
                static fn (array $sheet): array => [$sheet['mapping_version'], $sheet['set_up_failure']],
                array_column($this->database->fetchAllAssociative('SELECT title, mapping_version, set_up_failure FROM data_import_sheet WHERE import_id = :id ORDER BY position', ['id' => $importId]), null, 'title'),
            ),
        );
        self::assertCount(4, $this->work());

        // Nothing asks for the failed sheet's proposal again
        $this->database->executeStatement('UPDATE data_import SET last_activity_at = :at WHERE id = :id', ['at' => TestClock::now()->modify('-16 minutes')->format('Y-m-d H:i:s'), 'id' => $importId]);
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame([], $this->queued());
    }

    public function testAProposalThatNeverArrivesIsAskedForThreeTimesThenTheSheetsAreFailed(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->read($importId);

        foreach ([['propose'], ['propose'], ['propose'], []] as $expected) {
            $this->queue()->reset();
            $this->database->executeStatement('UPDATE data_import SET last_activity_at = :at WHERE id = :id', ['at' => TestClock::now()->modify('-16 minutes')->format('Y-m-d H:i:s'), 'id' => $importId]);
            $this->bus->dispatch(new ReadWaitingImports());
            self::assertSame($expected, $this->named($importId, $this->queued()));
        }

        self::assertSame(5, $this->database->fetchOne("SELECT COUNT(*) FROM data_import_sheet WHERE import_id = :id AND set_up_failure = 'proposal_failed'", ['id' => $importId]));
    }

    public function testRowsAreReadInPagesBoundedByBytesAndOnlyWithinATable(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->read($importId);
        $rows = self::getContainer()->get(GetImportSheetRows::class);
        $sheetId = $this->sheetId($importId, 'Results');
        $numbers = static fn (array $page): array => array_column($page, 'number');

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], $numbers($rows->page($sheetId, 0, 500)));
        self::assertSame([3, 4], $numbers($rows->page($sheetId, 2, 2)));
        // A byte bound smaller than a row: one row a page - never none while a row is left
        self::assertSame([3], $numbers($rows->page($sheetId, 2, 500, null, 1)));
        // The first row's worth of cells and a byte: the second row crosses the bound and is the page's last
        $firstRow = $this->database->fetchOne('SELECT octet_length(cells::text) FROM data_import_row WHERE sheet_id = :sheet AND row_number = 1', ['sheet' => $sheetId]);
        self::assertIsInt($firstRow);
        self::assertSame([1, 2], $numbers($rows->page($sheetId, 0, 500, null, $firstRow + 1)));
        self::assertSame([], $rows->page($sheetId, 8, 500));

        // What settles a table's questions reads the table's rows only, capped
        self::assertSame([2, 3, 4, 5], array_keys(iterator_to_array($rows->rows($sheetId, 5000, 2, 5))));
        self::assertSame([2, 3, 4], array_keys(iterator_to_array($rows->rows($sheetId, 3, 2))));
    }

    public function testAJobOfAnOlderMappingWritesNothing(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->work();
        $sheetId = $this->sheetId($importId, 'Puzzle List');
        $mapping = $this->mapping($importId, 'Puzzle List');

        // The player says: the brand column is not imported
        $mapping['columns'][1]['field'] = null;
        $this->bus->dispatch(new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, $mapping));
        $rows = $this->rows($importId);

        // The job of version 1 arrives late - a second delivery, a slow worker
        $this->parse($importId, $sheetId, 1);
        self::assertSame($rows, $this->rows($importId));
        self::assertSame(1, $this->sheets($importId)['Puzzle List'][2]);

        // Not even a batch that was already on its way
        $rowId = $rows['Puzzle List'][2]['id'];
        $written = $this->bus->dispatch(new RecordParsedImportRows($importId, $sheetId, 1, [
            $rowId => ['kind' => 'owned', 'parsed' => ['name' => 'written by an old job'], 'problems' => [], 'puzzleKey' => null, 'sourceKey' => null, 'sourceRowId' => null, 'leftOut' => false],
        ]))->last(HandledStamp::class)?->getResult();
        self::assertFalse($written);
        self::assertSame($rows, $this->rows($importId));

        // The job of version 2 writes: the brand is gone from the rows - and so from the puzzle's key
        $this->work();
        $after = $this->rows($importId);
        self::assertSame([2, 2], array_slice($this->sheets($importId)['Puzzle List'], 1, 2));
        self::assertArrayNotHasKey('brand', $after['Puzzle List'][2]['parsed']);
        self::assertSame(ImportPuzzleKey::of('Circle of Kites', 500, null), $after['Puzzle List'][2]['puzzle_key']);
        self::assertSame(['certain', 'player'], [$this->mapping($importId, 'Puzzle List')['columns'][0]['certainty'], $this->mapping($importId, 'Puzzle List')['columns'][1]['reason']]);
    }

    public function testAChangedPuzzleListParsesTheSheetsThatLookPuzzlesUpInItAgain(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->work();
        $mapping = $this->mapping($importId, 'Puzzle List');
        $mapping['columns'][1]['field'] = null;

        $this->bus->dispatch(new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $this->sheetId($importId, 'Puzzle List'), 1, $mapping));

        self::assertEqualsCanonicalizing(['parse Puzzle List v2', 'parse Results v2', 'parse Competitions v2'], $this->named($importId, $this->work()));
        $rows = $this->rows($importId);
        // The list has no brand any more: the results that looked it up there have none either
        self::assertSame(['pieces' => 'lookup'], $rows['Results'][2]['parsed']['from']);
        self::assertSame(ImportPuzzleKey::of('Circle of Kites', 500, null), $rows['Results'][2]['puzzle_key']);
        // ... and the same result on two sheets is still one
        self::assertSame($rows['Results'][2]['id'], $rows['Competitions'][2]['same_as_row_id']);
        self::assertSame([1, null], array_slice($this->sheets($importId)['2023'], 1, 2));
    }

    public function testWhatIsNotThePlayersToConfirmIsRefusedAndChangesNothing(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->work();
        $sheetId = $this->sheetId($importId, 'Results');
        $mapping = $this->mapping($importId, 'Results');
        $twoTimes = $mapping;
        $twoTimes['columns'][3]['field'] = 'time';
        $before = [$this->sheets($importId), $this->rows($importId)];

        $refused = [
            DataImportNotFound::class => [
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_ADMIN, $sheetId, 1, $mapping),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, Uuid::uuid7()->toString(), 1, $mapping),
            ],
            ImportMappingChangedMeanwhile::class => [
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 7, $mapping),
            ],
            InvalidImportMapping::class => [
                // Two columns on one single-valued field
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, $twoTimes),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, answers: ['date_order' => 'dmy']),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, sheetWide: ['lookupSheetId' => Uuid::uuid7()->toString()]),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, sheetWide: ['solvingType' => 'quartet']),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, valueAnswers: ['4' => ['pb' => 'personal best']]),
                // More words than a column can have, a word longer than a word, a column that is not the table's
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, valueAnswers: ['4' => array_fill_keys(array_map(static fn (int $word): string => 'word ' . $word, range(1, 501)), 'solo')]),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, valueAnswers: ['4' => [str_repeat('x', 201) => 'solo']]),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, valueAnswers: ['40' => ['pb' => 'solo']]),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, valueAnswers: array_fill(1, 61, ['pb' => 'solo'])),
                new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, ['columns' => [['role' => 'source_row_id', 'field' => null] + $mapping['columns'][3], ...array_slice($mapping['columns'], 0, 3)]] + $mapping),
            ],
        ];

        foreach ($refused as $exception => $messages) {
            foreach ($messages as $index => $message) {
                $thrown = null;

                try {
                    $this->bus->dispatch($message);
                } catch (\Throwable $failed) {
                    $thrown = $failed instanceof HandlerFailedException ? $failed->getPrevious() : $failed;
                }

                self::assertInstanceOf($exception, $thrown, sprintf('%s #%d', $exception, $index));
                self::getContainer()->get(EntityManagerInterface::class)->clear();
            }
        }

        self::assertSame([], $this->work());
        self::assertSame($before, [$this->sheets($importId), $this->rows($importId)]);
    }

    public function testWhatHoldsForTheWholeSheetAndWhatAWordMeansIsConfirmedToo(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->work();
        $sheetId = $this->sheetId($importId, 'Competitions');

        // "Every row here is a pair result", and puzzles are not looked up any more
        $this->bus->dispatch(new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 1, sheetWide: ['solvingType' => 'duo', 'lookupSheetId' => null]));
        self::assertSame(['parse Competitions v2'], $this->named($importId, $this->work()));

        $rows = $this->rows($importId);
        self::assertSame('duo', $rows['Competitions'][2]['parsed']['type']);
        self::assertArrayNotHasKey('brand', $rows['Competitions'][2]['parsed']);
        // No longer the same result as the solo one on the other sheet
        self::assertNull($rows['Competitions'][2]['same_as_row_id']);

        // The sheet is not puzzles after all: its rows are left out by the same job
        $mapping = $this->mapping($importId, 'Competitions');
        $mapping['kind'] = 'not_puzzles';
        $this->bus->dispatch(new ConfirmImportMapping($importId, PlayerFixture::PLAYER_REGULAR, $sheetId, 2, $mapping));
        $this->work();
        self::assertSame(['not_puzzles', 3, 3, 0, 0, 3, 0, 'A1:C'], $this->sheets($importId)['Competitions']);
        self::assertSame(['left_out', ['leftOut' => 'sheet_left_out'], null], [$this->rows($importId)['Competitions'][2]['state'], $this->rows($importId)['Competitions'][2]['parsed'], $this->rows($importId)['Competitions'][2]['source_key']]);
    }

    public function testPuzzleTrackersExportNeedsNoMappingAndNoAnswer(): void
    {
        $header = 'ID,Name,Brand,Number of elements,Status,Time,Creation date,Start date,End date,Satisfaction,Difficulty,Quality,Price,Sell price,Missing pieces,Place of purchase,Artist,Puzzle type,Bar code,Tags,Notes,Picture id,Picture URL';
        $csv = "\xEF\xBB\xBF" . implode("\r\n", [
            $header,
            '0d6e1c5a-0000-4000-8000-000000000001,Vymyšlený přístav,Ravensburger,1000,Completed,0:56:4,01.01.2024,03.02.2024,03.02.2024,4,3,5,,,,,,Brand new,8590000000015,#02-pair,with Petra,pic-1,https://pictures.example/1.jpg',
            '0d6e1c5a-0000-4000-8000-000000000002,Mlýn u potoka,Ravensburger,1000,Waiting list,,01.01.2024,,,,,,,,,,,Brand new,,,,pic-2,https://pictures.example/2.jpg',
            '0d6e1c5a-0000-4000-8000-000000000003,Sedm luceren,Schmidt,1500,Wish list,,01.01.2024,,,,,,,,,,,Second-hand,,,,pic-3,https://pictures.example/3.jpg',
            '0d6e1c5a-0000-4000-8000-000000000004,Tichá zátoka,Dino,1000,Completed,2:14:33,01.01.2024,04.03.2024,04.03.2024,5,2,4,,,,,,Brand new,,#01-individual #xSOLD,,pic-4,https://pictures.example/4.jpg',
        ]) . "\r\n";

        $importId = $this->uploaded('PuzzleTrackerExport.csv', $csv);
        self::assertSame(['read', 'propose', 'parse  v1'], $this->named($importId, $this->work()));

        self::assertSame(['' => ['results', 1, 1, 4, 0, 1, 0, 'A1:W']], $this->sheets($importId));
        self::assertSame('puzzle_tracker', $this->mapping($importId, '')['layout']);
        self::assertSame([], $this->mapping($importId, '')['questions']);

        $rows = $this->rows($importId)[''];
        self::assertSame(
            [2 => ['both', '0d6e1c5a-0000-4000-8000-000000000001'], 3 => ['owned', '0d6e1c5a-0000-4000-8000-000000000002'], 4 => ['wish_list', '0d6e1c5a-0000-4000-8000-000000000003'], 5 => ['result', '0d6e1c5a-0000-4000-8000-000000000004']],
            array_map(static fn (array $row): array => [$row['kind'], $row['source_row_id']], array_slice($rows, 1, null, true)),
        );
        self::assertSame(['2024-02-03', 3364, 'duo', ['8590000000015']], [$rows[2]['parsed']['date'], $rows[2]['parsed']['seconds'], $rows[2]['parsed']['type'], $rows[2]['parsed']['barcodes']]);
        $wished = ImportPuzzleKey::of('Sedm luceren', 1500, 'Schmidt');
        self::assertNotNull($wished);
        self::assertSame(ImportSourceKey::wishList($wished), $rows[4]['source_key']);
    }

    public function testADiscardedImportIsNeitherRecognisedNorParsed(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->read($importId);
        $this->queue()->reset();
        $this->bus->dispatch(new DiscardImport($importId, PlayerFixture::PLAYER_REGULAR));

        $this->propose($importId);

        self::assertSame([], $this->work());
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]));
    }

    public function testAReadImportWhoseStepWasLostGetsItQueuedAgain(): void
    {
        $importId = $this->uploaded('Training.xlsx', self::workbook());
        $this->read($importId);
        // The message that would have recognised it never arrived
        $this->queue()->reset();

        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame([], $this->named($importId, $this->queued()));

        $standing = static function (Connection $database) use ($importId): void {
            $database->executeStatement('UPDATE data_import SET last_activity_at = :at WHERE id = :id', [
                'at' => TestClock::now()->modify('-16 minutes')->format('Y-m-d H:i:s'),
                'id' => $importId,
            ]);
        };
        $standing($this->database);
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame(['propose'], $this->named($importId, $this->queued()));

        // Recognised, but the parse of one sheet was lost
        $this->queue()->reset();
        $this->propose($importId);
        $this->queue()->reset();
        $standing($this->database);
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertEqualsCanonicalizing(['parse Puzzle List v1', 'parse Results v1', 'parse Competitions v1', 'parse Stats v1'], $this->named($importId, $this->queued()));

        // Everything done (a sheet that waits for an answer is nobody's lost step): nothing is queued
        $this->work();
        $standing($this->database);
        $this->bus->dispatch(new ReadWaitingImports());
        self::assertSame([], $this->queued());
    }

    /**
     * A workbook in the shape of a training workbook (docs/features/data-import/research.md), with invented puzzles:
     * a puzzle list, results that name the puzzle only, the same result on a second sheet, statistics, and a sheet
     * whose times nothing settles.
     */
    private static function workbook(): string
    {
        $text = RawXlsx::inline(...);
        $number = static fn (int $value): string => RawXlsx::n((string) $value);
        $time = static fn (int $seconds): string => RawXlsx::n((string) ($seconds / 86400), 1);
        $date = static fn (string $day): string => RawXlsx::n((string) (intdiv((int) (new \DateTimeImmutable($day . ' UTC'))->format('U'), 86400) + 25569), 2);
        $rows = self::worksheet(...);

        $path = tempnam(sys_get_temp_dir(), 'msp-import-test');
        assert($path !== false);

        (new RawXlsx())
            ->styles(['[h]:mm:ss', 'yyyy-mm-dd'])
            ->sheet('Puzzle List', $rows([
                1 => [$text('Puzzle'), $text('Brand'), $text('Pieces')],
                2 => [$text('Circle of Kites'), $text('Ravensburger'), $number(500)],
                3 => [$text('Glass Marbles'), $text('Jumbo'), $number(500)],
                4 => [$text('Tea House'), $text('Trefl'), $number(500)],
                5 => [$text('Marble Run'), $text('Ravensburger'), $number(500)],
            ]))
            ->sheet('Results', $rows([
                1 => [$text('Puzzle'), $text('Date'), $text('Time'), $text('Attempt'), $text('Notes')],
                2 => [$text('Circle of Kites'), $date('2026-01-10'), $time(2893), $number(1)],
                // Equal in puzzle, day and time - a log of attempts keeps them apart
                3 => [$text('Glass Marbles'), $date('2026-02-08'), $time(2750), $number(3)],
                4 => [$text('Glass Marbles'), $date('2026-02-08'), $time(2750), $number(4)],
                // Equal, and nothing tells them apart
                5 => [$text('Tea House'), $date('2026-02-01'), $time(2411), null, $text('typed twice')],
                6 => [$text('Tea House'), $date('2026-02-01'), $time(2411), null, $text('typed twice')],
                7 => [$text('Marble Run'), $date('2026-02-15'), $text('1:13:??'), $number(1)],
                // Prefilled, waiting for a date and a time
                8 => [$text('Marble Run')],
            ]))
            ->sheet('Competitions', $rows([
                1 => [$text('Puzzle'), $text('Date'), $text('Time')],
                // The same result as on "Results"
                2 => [$text('Circle of Kites'), $date('2026-01-10'), $time(2893)],
                3 => [$text('Tea House'), $date('2026-03-07'), $time(2380)],
            ]))
            ->sheet('Stats', $rows([
                1 => [$text('Month'), $text('Hours')],
                2 => [$text('January'), $number(4)],
            ]))
            ->sheet('2023', $rows([
                1 => [$text('Puzzle'), $text('Pieces'), $text('Time')],
                2 => [$text('Plum Orchard'), $number(500), $text('45:10')],
                3 => [$text('Chalk Cliffs'), $number(500), $text('52:31')],
            ]))
            ->save($path);

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * @param array<int, list<null|string>> $rows row number => its cells from column A
     */
    private static function worksheet(array $rows): string
    {
        $sheet = [];

        foreach ($rows as $number => $cells) {
            foreach ($cells as $index => $cell) {
                if ($cell !== null) {
                    $sheet[$number][['A', 'B', 'C', 'D', 'E'][$index]] = $cell;
                }
            }
        }

        return RawXlsx::worksheet($sheet);
    }

    private function uploaded(string $name, string $content): string
    {
        $importId = Uuid::uuid7()->toString();
        $kind = DataImportFileKind::ofFileName($name);
        self::assertNotNull($kind);

        $this->bus->dispatch(new StartImport($importId, PlayerFixture::PLAYER_REGULAR, $name, strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, $kind), $content);
        $this->bus->dispatch(new ConfirmImportUploaded($importId, PlayerFixture::PLAYER_REGULAR));

        return $importId;
    }

    /**
     * The worker: every queued message goes to its handler, and what those queue too - until nothing is left.
     *
     * @return list<object> the messages handled, in order
     */
    private function work(): array
    {
        $handled = [];

        while (($messages = $this->queued()) !== []) {
            $this->queue()->reset();

            foreach ($messages as $message) {
                match (true) {
                    $message instanceof ReadImportFile => $this->read($message->importId),
                    $message instanceof ProposeImportMappings => $this->propose($message->importId),
                    $message instanceof ParseImportSheet => $this->parse($message->importId, $message->sheetId, $message->mappingVersion),
                    default => self::fail('Nothing handles ' . $message::class),
                };

                $handled[] = $message;
            }
        }

        return $handled;
    }

    /**
     * @return list<object>
     */
    private function queued(): array
    {
        return array_values(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->queue()->getSent()));
    }

    private function read(string $importId): void
    {
        $this->handle(ReadImportFileHandler::class, new ReadImportFile($importId));
    }

    private function propose(string $importId): void
    {
        $this->handle(ProposeImportMappingsHandler::class, new ProposeImportMappings($importId));
    }

    private function parse(string $importId, string $sheetId, int $version): void
    {
        $this->handle(ParseImportSheetHandler::class, new ParseImportSheet($importId, $sheetId, $version));
    }

    /**
     * @param class-string $handler
     */
    private function handle(string $handler, object $message): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $service = self::getContainer()->get($handler);
        self::assertIsCallable($service);
        $service($message);
        $entityManager->clear();
    }

    /**
     * @param list<object> $messages
     *
     * @return list<string> "read", "propose", "parse <sheet title> v<version>"
     */
    private function named(string $importId, array $messages): array
    {
        /** @var array<string, string> $titles */
        $titles = $this->database->fetchAllKeyValue('SELECT id, title FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]);

        return array_map(static fn (object $message): string => match (true) {
            $message instanceof ReadImportFile => 'read',
            $message instanceof ProposeImportMappings => 'propose',
            $message instanceof ParseImportSheet => sprintf('parse %s v%d', $titles[$message->sheetId] ?? '?', $message->mappingVersion),
            default => $message::class,
        }, $messages);
    }

    /**
     * @return array<array-key, array{null|string, int, null|int, int, int, int, int, null|string}> title => kind, mapping version,
     *         parsed version, rows understood, need a look, left out, questions, the table's range
     */
    private function sheets(string $importId): array
    {
        $sheets = [];

        foreach ($this->database->fetchAllAssociative("SELECT title, kind, mapping_version, parsed_version, rows_understood, rows_need_look, rows_left_out, jsonb_array_length(mapping -> 'questions') AS questions, region FROM data_import_sheet WHERE import_id = :id ORDER BY position", ['id' => $importId]) as $sheet) {
            /** @var array{title: string, kind: null|string, mapping_version: int, parsed_version: null|int, rows_understood: int, rows_need_look: int, rows_left_out: int, questions: int, region: null|string} $sheet */
            $sheets[$sheet['title']] = [$sheet['kind'], $sheet['mapping_version'], $sheet['parsed_version'], $sheet['rows_understood'], $sheet['rows_need_look'], $sheet['rows_left_out'], $sheet['questions'], $sheet['region']];
        }

        return $sheets;
    }

    /**
     * @return array<array-key, array<int, array{id: string, kind: null|string, state: string, parsed_version: null|int, parsed: array<string, mixed>, problems: null|array<mixed>, puzzle_key: null|string, source_key: null|string, source_row_id: null|string, same_as_row_id: null|string}>> sheet title => row number => the row
     */
    private function rows(string $importId): array
    {
        $rows = [];
        $query = 'SELECT s.title, r.row_number, r.id, r.kind, r.state, r.parsed_version, r.parsed, r.problems, r.puzzle_key, r.source_key, r.source_row_id, r.same_as_row_id FROM data_import_row r INNER JOIN data_import_sheet s ON s.id = r.sheet_id WHERE r.import_id = :id ORDER BY s.position, r.row_number';

        foreach ($this->database->fetchAllAssociative($query, ['id' => $importId]) as $row) {
            /** @var array{title: string, row_number: int, id: string, kind: null|string, state: string, parsed_version: null|int, parsed: null|string, problems: null|string, puzzle_key: null|string, source_key: null|string, source_row_id: null|string, same_as_row_id: null|string} $row */
            $parsed = $row['parsed'] === null ? [] : json_decode($row['parsed'], true);
            $problems = $row['problems'] === null ? null : json_decode($row['problems'], true);
            self::assertIsArray($parsed);
            /** @var array<string, mixed> $parsed */
            /** @var null|array<mixed> $problems */
            // jsonb keeps the keys in an order of its own
            ksort($parsed);
            $rows[$row['title']][$row['row_number']] = [
                'id' => $row['id'],
                'kind' => $row['kind'],
                'state' => $row['state'],
                'parsed_version' => $row['parsed_version'],
                'parsed' => $parsed,
                'problems' => $problems,
                'puzzle_key' => $row['puzzle_key'],
                'source_key' => $row['source_key'],
                'source_row_id' => $row['source_row_id'],
                'same_as_row_id' => $row['same_as_row_id'],
            ];
        }

        return $rows;
    }

    private function sheetId(string $importId, string $title): string
    {
        $id = $this->database->fetchOne('SELECT id FROM data_import_sheet WHERE import_id = :id AND title = :title', ['id' => $importId, 'title' => $title]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array{kind: string, layout: null|string, columns: list<array<string, mixed>>, questions: list<mixed>}
     */
    private function mapping(string $importId, string $title): array
    {
        /** @var array{kind: string, layout: null|string, columns: list<array<string, mixed>>, questions: list<mixed>} $mapping */
        $mapping = $this->stored('mapping', $importId, $title);

        return $mapping;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $importId, string $title): array
    {
        return $this->stored('options', $importId, $title);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $column, string $importId, string $title): array
    {
        $json = $this->database->fetchOne(sprintf('SELECT %s FROM data_import_sheet WHERE import_id = :id AND title = :title', $column), ['id' => $importId, 'title' => $title]);
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPublished(string $importId): array
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);
        $last = [];

        foreach ($hub->getPublishedUpdates() as $update) {
            $data = json_decode($update->getData(), true);

            if (is_array($data) && ($data['importId'] ?? null) === $importId) {
                self::assertTrue($update->isPrivate());
                /** @var array<string, mixed> $data */
                $last = $data;
            }
        }

        return $last;
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
