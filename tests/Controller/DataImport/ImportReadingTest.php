<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Exceptions\ImportDownloadTimedOut;
use SpeedPuzzling\Web\Message\BeginImportReading;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\DiscardImport;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\ReadWaitingImports;
use SpeedPuzzling\Web\Message\RecordImportFileHash;
use SpeedPuzzling\Web\Message\RecordImportRead;
use SpeedPuzzling\Web\Message\RecordImportReadFailed;
use SpeedPuzzling\Web\Message\RecordImportRows;
use SpeedPuzzling\Web\Message\RecordImportSheets;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;
use SpeedPuzzling\Web\Value\ImportReadingJob;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/reading.md - an uploaded spreadsheet is read into the import's sheets and rows, with its
 * progress stored and published; what can not be read ends in a state the page explains; the same file twice is one
 * import; a read that stalled is rescued. Every file here is made up.
 *
 * The `imports` queue is in memory in tests: the worker is played by calling ReadImportFileHandler.
 */
final class ImportReadingTest extends WebTestCase
{
    /** A worker's limits small enough for small files */
    private const array LIMITS = ['sheets' => 5, 'rows' => 120, 'cells' => 300];

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $bus;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testAWorkbookOfTwoSheetsOneHiddenIsReadIntoSheetsAndRows(): void
    {
        $content = $this->workbook();
        $importId = $this->uploaded('My results 2024.xlsx', $content);

        // Uploaded: queued for the worker, and the page waits with a live line
        self::assertSame([$importId], $this->queuedForReading());
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        $line = $crawler->filter('[data-testid="import-reading"]');
        self::assertSame('data-import-progress', $line->attr('data-controller'));
        self::assertSame('/en/import/' . $importId . '/progress', $line->attr('data-data-import-progress-url-value'));
        self::assertSame('Waiting to be read…', $crawler->filter('[data-testid="import-reading-state"]')->text());
        self::assertContains('/imports/' . PlayerFixture::PLAYER_REGULAR, $this->subscribedTopics($crawler));

        $this->read($importId);

        self::assertSame(
            ['status' => 'setting_up', 'file_sha256' => hash('sha256', $content), 'read_error' => null, 'read_attempt' => 1, 'sheets_total' => 2, 'sheets_read' => 2, 'rows_read' => 4],
            $this->database->fetchAssociative('SELECT status, file_sha256, read_error, read_attempt, sheets_total, sheets_read, rows_read FROM data_import WHERE id = :id', ['id' => $importId]),
        );
        self::assertNotNull($this->database->fetchOne('SELECT read_finished_at FROM data_import WHERE id = :id', ['id' => $importId]));

        $sheets = $this->database->fetchAllAssociative('SELECT position, title, hidden, rows_total, columns_total, hints FROM data_import_sheet WHERE import_id = :id ORDER BY position', ['id' => $importId]);
        self::assertCount(2, $sheets);
        self::assertSame([1, 'Results', false, 3, 3], [$sheets[0]['position'], $sheets[0]['title'], $sheets[0]['hidden'], $sheets[0]['rows_total'], $sheets[0]['columns_total']]);
        self::assertSame([2, 'Old notes', true, 1, 2], [$sheets[1]['position'], $sheets[1]['title'], $sheets[1]['hidden'], $sheets[1]['rows_total'], $sheets[1]['columns_total']]);
        self::assertIsString($sheets[0]['hints']);
        // jsonb keeps the keys in an order of its own
        self::assertEquals(['frozenPane' => ['row' => 2, 'column' => 1], 'usedRange' => 'A1:C4'], json_decode($sheets[0]['hints'], true));
        self::assertNull($sheets[1]['hints']);

        $rows = $this->database->fetchAllAssociative(
            'SELECT s.position, r.row_number, r.cells, r.state, r.first_seen_version, r.last_seen_version FROM data_import_row r INNER JOIN data_import_sheet s ON s.id = r.sheet_id WHERE r.import_id = :id ORDER BY s.position, r.row_number',
            ['id' => $importId],
        );
        self::assertSame([[1, 1], [1, 2], [1, 4], [2, 7]], array_map(static fn (array $row): array => [$row['position'], $row['row_number']], $rows));
        self::assertSame(['waiting', 1, 1], [$rows[0]['state'], $rows[0]['first_seen_version'], $rows[0]['last_seen_version']]);
        self::assertIsString($rows[1]['cells']);
        self::assertIsString($rows[3]['cells']);
        // The typed value and, where it says more, the text the player saw (jsonb orders the keys its own way)
        self::assertEquals(
            [
                '1' => ['k' => 'text', 'v' => 'Made-up harbour at dawn'],
                '2' => ['k' => 'number', 'v' => 1000],
                '3' => ['k' => 'duration', 'v' => 3723, 's' => '1:02:03'],
            ],
            json_decode($rows[1]['cells'], true),
        );
        self::assertSame(['2' => ['k' => 'text', 'v' => 'kept from an older sheet']], json_decode($rows[3]['cells'], true));

        // Told to the player's own private topic - states and numbers only
        $published = $this->published();
        self::assertNotEmpty($published);
        $last = end($published);
        self::assertSame(
            ['type' => 'import_progress', 'importId' => $importId, 'status' => 'setting_up', 'moving' => false, 'sheetsTotal' => 2, 'sheetsRead' => 2, 'rowsRead' => 4, 'sheetsMapped' => 0, 'sheetsParsed' => 0, 'sheetsWaiting' => 0, 'working' => true, 'rowsToParse' => 0, 'rowsParsed' => 0],
            array_diff_key($last, ['revision' => true, 'pairing' => true]),
        );
        // ... and of the pairing that follows the parse, numbers only (docs/features/data-import/pairing-screens.md "The live line")
        self::assertSame(['running' => false, 'puzzles' => 0, 'found' => 0, 'probably' => 0, 'choose' => 0, 'notFound' => 0, 'searching' => 0, 'revision' => '0:0'], $last['pairing']);
        // What the "Sheets and columns" screen loads its lists again by: a sign of the sheets' states, nothing of the file
        self::assertIsString($last['revision']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $last['revision']);
        self::assertSame('reading', $published[0]['status']);

        // Nothing of the file stays on the worker's disk
        // The folder is shared by every test process of a parallel run: another worker's file may be there for a
        // moment, so the folder gets a few seconds to be empty
        $folder = rtrim(sys_get_temp_dir(), '/') . '/' . ReadImportFileHandler::TEMPORARY_FOLDER . '/*';
        for ($attempt = 0; $attempt < 50 && glob($folder) !== []; $attempt++) {
            usleep(100_000);
        }

        self::assertSame([], glob($folder), 'Nothing of the file stays on the worker\'s disk');

        // The same message once more finds nothing to read
        $this->read($importId);
        self::assertSame(4, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $importId]));

        // The page lists what the file holds
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        self::assertSame('Read', $crawler->filter('[data-testid="import-status"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="import-reading"]'));
        $listed = $crawler->filter('[data-testid="import-sheet"]');
        self::assertCount(2, $listed);
        self::assertStringContainsString('Results', $listed->eq(0)->text());
        self::assertSame('3', $listed->eq(0)->filter('[data-testid="import-sheet-rows"]')->text());
        self::assertSame('3', $listed->eq(0)->filter('[data-testid="import-sheet-columns"]')->text());
        self::assertCount(0, $listed->eq(0)->filter('[data-testid="import-sheet-hidden"]'));
        self::assertStringContainsString('Old notes', $listed->eq(1)->text());
        self::assertCount(1, $listed->eq(1)->filter('[data-testid="import-sheet-hidden"]'));
        self::assertStringContainsString('arrives soon', $crawler->filter('[data-testid="import-soon"]')->text());

        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        $this->assertResponseIsSuccessful();
        self::assertSame([...$last, 'url' => '/en/import/' . $importId], $this->json());
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testACsvFileWithSemicolonsIsRead(): void
    {
        $importId = $this->uploaded('times.csv', "Puzzle;Pieces;Time\nMade-up lighthouse;500;0:45:10\n\nInvented meadow;1000;\n");

        $this->read($importId);

        self::assertSame('setting_up', $this->statusOf($importId));
        self::assertSame(
            [['position' => 1, 'title' => '', 'hidden' => false, 'rows_total' => 3, 'columns_total' => 3]],
            $this->database->fetchAllAssociative('SELECT position, title, hidden, rows_total, columns_total FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]),
        );
        $cells = $this->database->fetchFirstColumn('SELECT cells FROM data_import_row WHERE import_id = :id ORDER BY row_number', ['id' => $importId]);
        self::assertCount(3, $cells);
        self::assertIsString($cells[1]);
        self::assertSame(
            ['1' => ['k' => 'text', 'v' => 'Made-up lighthouse'], '2' => ['k' => 'text', 'v' => '500'], '3' => ['k' => 'text', 'v' => '0:45:10']],
            json_decode($cells[1], true),
        );

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertStringContainsString('The whole file', $crawler->filter('[data-testid="import-sheet"]')->text());
    }

    /**
     * @return iterable<string, array{string, \Closure(): string, string, string}> file name, content, reason, what the page says
     */
    public static function filesThatAreNotRead(): iterable
    {
        // The limits themselves are DataImportLimits' (100 sheets, 50,000 rows, 1,000,000 cells); these three are read
        // by a worker with small ones (LIMITS), so the files stay small - the page names the real numbers
        yield 'more sheets than an import takes' => ['many.xlsx', static function (): string {
            $workbook = new RawXlsx();

            for ($sheet = 0; $sheet <= self::LIMITS['sheets']; $sheet++) {
                $workbook->sheet('Sheet ' . $sheet, RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]]));
            }

            return self::bytesOf($workbook);
        }, 'too_many_sheets', 'more than 100 sheets'];

        yield 'more rows than an import takes' => ['long.csv', static fn (): string => str_repeat("made-up\n", self::LIMITS['rows'] + 1), 'too_many_rows', 'more than 50 000 rows'];

        yield 'more cells than an import takes' => [
            'wide.csv',
            static fn (): string => str_repeat(rtrim(str_repeat('x;', 10), ';') . "\n", intdiv(self::LIMITS['cells'], 10) + 1),
            'too_many_cells',
            'more than 1 000 000 filled cells',
        ];

        yield 'protected by a password' => [
            'secret.xlsx',
            static fn (): string => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504)
                . mb_convert_encoding('EncryptionInfo', 'UTF-16LE', 'ASCII') . str_repeat("\0", 90)
                . mb_convert_encoding('EncryptedPackage', 'UTF-16LE', 'ASCII') . str_repeat("\0", 400),
            'password_protected',
            'protected by a password',
        ];

        yield 'cut short' => ['broken.xlsx', static fn (): string => substr(self::bytesOf((new RawXlsx())->sheet('Sheet', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]]))), 0, 700), 'damaged', 'seems to be damaged'];

        yield 'a picture' => ['photo.xlsx', static fn (): string => "\x89PNG\r\n\x1a\n" . str_repeat("\x00\x01\x02made-up", 200), 'not_a_spreadsheet', 'does not look like a spreadsheet'];
    }

    /**
     * @param \Closure(): string $content
     */
    #[DataProvider('filesThatAreNotRead')]
    public function testAFileThatCanNotBeReadEndsInAStateThePageExplains(string $name, \Closure $content, string $reason, string $said): void
    {
        $importId = $this->uploaded($name, $content());
        $kind = DataImportFileKind::ofFileName($name);
        self::assertNotNull($kind);

        $this->read($importId, self::LIMITS);

        self::assertSame(
            ['status' => 'read_failed', 'read_error' => $reason, 'sheets_total' => null, 'sheets_read' => 0, 'rows_read' => 0],
            $this->database->fetchAssociative('SELECT status, read_error, sheets_total, sheets_read, rows_read FROM data_import WHERE id = :id', ['id' => $importId]),
        );
        // Nothing half-read stays; the import and its file do
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $importId]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]));
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists(ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, $kind)));

        $published = $this->published();
        $last = end($published);
        self::assertIsArray($last);
        self::assertSame(['read_failed', false], [$last['status'], $last['moving']]);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        self::assertSame('Could not be read', $crawler->filter('[data-testid="import-status"]')->text());
        $failed = $crawler->filter('[data-testid="import-read-failed"]');
        self::assertSame($reason, $failed->attr('data-reason'));
        self::assertStringContainsString($said, $failed->text());
        self::assertCount(1, $failed->filter('button[data-testid="read-again"]'));
        self::assertCount(1, $crawler->filter('button[data-testid="discard-import"]'));
    }

    public function testAFaultOfOursIsNotTheFilesFaultAndCanBeTriedAgain(): void
    {
        $content = $this->workbook();
        $importId = $this->uploaded('results.xlsx', $content);
        $key = ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, DataImportFileKind::Xlsx);
        $filesystem = self::getContainer()->get(Filesystem::class);
        // Object storage lost the file between the upload and the read
        $filesystem->delete($key);

        $this->read($importId);

        self::assertSame(['read_failed', 'failed'], $this->database->fetchNumeric('SELECT status, read_error FROM data_import WHERE id = :id', ['id' => $importId]));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $failed = $crawler->filter('[data-testid="import-read-failed"]');
        self::assertStringContainsString('Something went wrong on our side', $failed->text());
        self::assertStringNotContainsString('damaged', $failed->text());

        // "Try again": waits for the worker once more, and reads this time
        $filesystem->write($key, $content);
        $this->queue()->reset();
        $this->browser->submit($failed->filter('button[data-testid="read-again"]')->form());
        $this->assertResponseRedirects('/en/import/' . $importId);
        self::assertSame(['uploaded', null], $this->database->fetchNumeric('SELECT status, read_error FROM data_import WHERE id = :id', ['id' => $importId]));
        self::assertSame([$importId], $this->queuedForReading());

        $this->read($importId);

        self::assertSame(['setting_up', 2, 4], $this->database->fetchNumeric('SELECT status, read_attempt, rows_read FROM data_import WHERE id = :id', ['id' => $importId]));

        // Not an import to try again any more: the button's request changes nothing
        $this->browser->request('POST', '/en/import/' . $importId . '/read-again', ['_token' => $failed->filter('input[name="_token"]')->attr('value')]);
        $this->assertResponseRedirects('/en/import/' . $importId);
        self::assertSame('setting_up', $this->statusOf($importId));
    }

    public function testTheSameFileAgainIsClosedInFavourOfTheImportThePlayerHas(): void
    {
        $content = $this->workbook();
        $first = $this->uploaded('results.xlsx', $content);
        $this->read($first);

        // Another player's import of the very same bytes is nobody else's business
        $others = $this->uploaded('results.xlsx', $content, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->read($others);
        self::assertSame('setting_up', $this->statusOf($others));
        self::assertSame(4, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $others]));

        $second = $this->uploaded('results (copy).xlsx', $content);
        $this->read($second);

        self::assertSame(['discarded', $first], $this->database->fetchNumeric('SELECT status, same_file_as_import_id FROM data_import WHERE id = :id', ['id' => $second]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_sheet WHERE import_id = :id', ['id' => $second]));
        self::assertFalse(self::getContainer()->get(Filesystem::class)->fileExists(ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $second, DataImportFileKind::Xlsx)));
        // The one the player has is untouched
        self::assertSame(['setting_up', 4], $this->database->fetchNumeric('SELECT status, rows_read FROM data_import WHERE id = :id', ['id' => $first]));
        self::assertSame(4, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $first]));

        $published = $this->published();
        $last = end($published);
        self::assertIsArray($last);
        self::assertSame([$second, 'same_file', false], [$last['importId'], $last['status'], $last['moving']]);

        // The page sends the player there, with a notice
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', '/en/import/' . $second . '/progress');
        $this->assertResponseIsSuccessful();
        self::assertSame(['same_file', '/en/import/' . $first], [$this->json()['status'] ?? null, $this->json()['url'] ?? null]);

        $this->browser->request('GET', '/en/import/' . $second);
        $this->assertResponseRedirects('/en/import/' . $first);
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('You already imported this very file', $crawler->filter('.alert-info')->first()->text());
        self::assertCount(2, $crawler->filter('[data-testid="import-sheet"]'));

        // Only the one import is on the list
        $crawler = $this->browser->request('GET', '/en/import');
        self::assertCount(1, $crawler->filter('[data-testid="data-import"]'));
    }

    public function testAnotherPlayersImportIsNotFound(): void
    {
        $importId = $this->uploaded('results.xlsx', $this->workbook());
        $this->read($importId);
        $discarded = $this->uploaded('gone.csv', "a;b\n");
        $this->bus->dispatch(new DiscardImport($discarded, PlayerFixture::PLAYER_REGULAR));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        $this->assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseStatusCodeSame(404);

        $token = $this->browser->request('GET', '/en/import/new')->filter('input[name="_token"]')->attr('value');
        $this->browser->request('POST', '/en/import/' . $importId . '/read-again', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(404);
        self::assertSame('setting_up', $this->statusOf($importId));

        // The owner's own discarded import is gone for them too
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', '/en/import/' . $discarded . '/progress');
        $this->assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/import/' . Uuid::uuid7()->toString() . '/progress');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAReadThatStalledIsFailedAndAnImportNobodyReadIsQueuedAgain(): void
    {
        $stalled = $this->uploaded('stalled.xlsx', $this->workbook());
        $running = $this->uploaded('running.csv', "a;b\n1;2\n");
        $waiting = $this->uploaded('waiting.csv', "c;d\n3;4\n");
        $fresh = $this->uploaded('fresh.csv', "e;f\n5;6\n", PlayerFixture::PLAYER_WITH_STRIPE);

        // Two workers took their imports; one died after the sheets were stored
        $job = $this->ask(new BeginImportReading($stalled));
        self::assertInstanceOf(ImportReadingJob::class, $job);
        $sheetId = Uuid::uuid7()->toString();
        self::assertTrue($this->ask(new RecordImportSheets($stalled, $job->readAttempt, [['id' => $sheetId, 'position' => 1, 'title' => 'Results', 'hidden' => false, 'hints' => null]])));
        self::assertTrue($this->ask(new RecordImportRows($stalled, $job->readAttempt, $sheetId, [['number' => 1, 'cells' => ['1' => ['k' => 'text', 'v' => 'made-up']]]], 1, false)));
        self::assertInstanceOf(ImportReadingJob::class, $this->ask(new BeginImportReading($running)));

        $this->activeAt($stalled, DataImportLimits::READING_STALLED_AFTER_SECONDS + 60);
        $this->activeAt($running, DataImportLimits::READING_STALLED_AFTER_SECONDS - 60);
        // Asked for long ago and still waiting: its message was lost. Asked for a few minutes ago: on its way.
        // Never asked for (uploaded before reading existed) and resting: asked for now
        $this->requestedAt($waiting, DataImportLimits::READ_REQUESTED_AGAIN_AFTER_SECONDS + 60);
        $this->requestedAt($fresh, DataImportLimits::READ_REQUESTED_AGAIN_AFTER_SECONDS - 60);
        $this->activeAt($fresh, DataImportLimits::READ_REQUESTED_AGAIN_AFTER_SECONDS - 60);
        $neverAsked = $this->uploaded('older.csv', "g;h\n7;8\n", PlayerFixture::PLAYER_WITH_STRIPE);
        $this->requestedAt($neverAsked, null);
        $this->activeAt($neverAsked, DataImportLimits::UNREAD_AFTER_SECONDS + 60);
        $this->queue()->reset();
        $this->hub()->reset();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(['queued' => 2, 'failed' => 1], $this->ask(new ReadWaitingImports()));

        self::assertEqualsCanonicalizing([$waiting, $neverAsked], $this->queuedForReading());
        self::assertSame(TestClock::FROZEN_NOW, $this->database->fetchOne('SELECT read_requested_at FROM data_import WHERE id = :id', ['id' => $neverAsked]));

        // The next run - five minutes later - queues none of them a second time
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(['queued' => 0, 'failed' => 0], $this->ask(new ReadWaitingImports()));
        self::assertCount(2, $this->queuedForReading());
        self::assertSame(['read_failed', 'stalled', 0], $this->database->fetchNumeric('SELECT status, read_error, rows_read FROM data_import WHERE id = :id', ['id' => $stalled]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $stalled]));
        self::assertSame('reading', $this->statusOf($running));
        self::assertSame('uploaded', $this->statusOf($fresh));
        self::assertSame([['read_failed', $stalled]], array_map(static fn (array $update): array => [$update['status'], $update['importId']], $this->published()));

        // What the dead worker's read would still write is refused …
        self::assertFalse($this->ask(new RecordImportRows($stalled, $job->readAttempt, $sheetId, [['number' => 2, 'cells' => []]], 1, true)));

        // … the page says it was us, and the player tries again
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $stalled);
        $failed = $crawler->filter('[data-testid="import-read-failed"]');
        self::assertSame('failed', $failed->attr('data-reason'));
        self::assertStringContainsString('Something went wrong on our side', $failed->text());
        $this->browser->submit($failed->filter('button[data-testid="read-again"]')->form());

        $this->read($stalled);
        self::assertSame(['setting_up', 2, 4], $this->database->fetchNumeric('SELECT status, read_attempt, rows_read FROM data_import WHERE id = :id', ['id' => $stalled]));

        // A row of the first read arriving now belongs to no read any more
        self::assertFalse($this->ask(new RecordImportRows($stalled, $job->readAttempt, $sheetId, [['number' => 3, 'cells' => []]], 1, true)));
        self::assertSame(4, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $stalled]));
    }

    public function testNothingIsWrittenIntoAnImportDiscardedWhileItWasRead(): void
    {
        $importId = $this->uploaded('results.csv', "a;b\n1;2\n");
        $job = $this->ask(new BeginImportReading($importId));
        self::assertInstanceOf(ImportReadingJob::class, $job);
        $sheetId = Uuid::uuid7()->toString();
        self::assertTrue($this->ask(new RecordImportSheets($importId, $job->readAttempt, [['id' => $sheetId, 'position' => 1, 'title' => '', 'hidden' => false, 'hints' => null]])));

        $this->bus->dispatch(new DiscardImport($importId, PlayerFixture::PLAYER_REGULAR));

        self::assertFalse($this->ask(new RecordImportRows($importId, $job->readAttempt, $sheetId, [['number' => 1, 'cells' => ['1' => ['k' => 'text', 'v' => 'a']]]], 2, true)));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $importId]));
        self::assertSame('discarded', $this->statusOf($importId));
    }

    /**
     * The worker's entity manager still holds the import as it was when the read began; another process (the web
     * request of a discard, a second worker after "Try again") changed it since. Every write of the read must see that.
     *
     * @return iterable<string, array{string, int, string}> what the other process left: status, read attempt; expected status
     */
    public static function changedByAnotherProcess(): iterable
    {
        yield 'discarded while the file was downloaded or opened' => ['discarded', 1, 'discarded'];
        yield 'given up as stalled, tried again and taken by a second worker' => ['reading', 2, 'reading'];
        yield 'given up as stalled' => ['read_failed', 1, 'read_failed'];
    }

    #[DataProvider('changedByAnotherProcess')]
    public function testAReadWritesNothingIntoAnImportAnotherProcessChangedMeanwhile(string $status, int $attempt, string $expected): void
    {
        $importId = $this->uploaded('results.xlsx', $this->workbook());
        $job = $this->ask(new BeginImportReading($importId));
        self::assertInstanceOf(ImportReadingJob::class, $job);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // In the worker's memory: being read, attempt 1
        self::assertSame(DataImportStatus::Reading, $entityManager->find(DataImport::class, $importId)?->status);

        // Another process - straight in the database, behind the entity manager's back
        $this->database->executeStatement(
            'UPDATE data_import SET status = :status, read_attempt = :attempt, read_error = :error WHERE id = :id',
            ['status' => $status, 'attempt' => $attempt, 'error' => $status === 'read_failed' ? 'stalled' : null, 'id' => $importId],
        );

        $sheet = [['id' => Uuid::uuid7()->toString(), 'position' => 1, 'title' => 'Results', 'hidden' => false, 'hints' => null]];
        self::assertFalse($this->ask(new RecordImportFileHash($importId, $job->readAttempt, str_repeat('a', 64))));
        self::assertFalse($this->ask(new RecordImportSheets($importId, $job->readAttempt, $sheet)));
        self::assertFalse($this->ask(new RecordImportRows($importId, $job->readAttempt, $sheet[0]['id'], [['number' => 1, 'cells' => []]], 1, true)));
        self::assertFalse($this->ask(new RecordImportRead($importId, $job->readAttempt)));
        // The file proves unreadable after all: not written over what the import is now
        self::assertFalse($this->ask(new RecordImportReadFailed($importId, 'damaged', $job->readAttempt)));

        $entityManager->clear();
        self::assertSame(
            ['status' => $expected, 'read_attempt' => $attempt, 'file_sha256' => null, 'sheets_total' => null, 'read_error' => $status === 'read_failed' ? 'stalled' : null],
            $this->database->fetchAssociative('SELECT status, read_attempt, file_sha256, sheets_total, read_error FROM data_import WHERE id = :id', ['id' => $importId]),
        );
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_sheet WHERE import_id = :id', ['id' => $importId]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id', ['id' => $importId]));
    }

    public function testADownloadThatTakesTooLongIsGivenUp(): void
    {
        $importId = $this->uploaded('results.csv', str_repeat("made-up;row\n", 200));
        $path = (string) tempnam(sys_get_temp_dir(), 'msp-import-test');
        $this->temporaryFiles[] = $path;
        $storage = self::getContainer()->get(ImportFileStorage::class);
        $key = ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, DataImportFileKind::Csv);

        self::assertSame(2400, $storage->downloadTo($key, $path)['bytes']);

        $this->expectException(ImportDownloadTimedOut::class);
        $storage->downloadTo($key, $path, totalSeconds: -1.0);
    }

    public function testAHubThatDoesNotAnswerNeverStopsTheRead(): void
    {
        $importId = $this->uploaded('results.xlsx', $this->workbook());
        $hub = $this->hub();
        $hub->failWith(new \RuntimeException('The hub is down.'));

        try {
            $this->read($importId);
        } finally {
            $hub->failWith(null);
        }

        self::assertSame(['setting_up', 4], $this->database->fetchNumeric('SELECT status, rows_read FROM data_import WHERE id = :id', ['id' => $importId]));
    }

    /**
     * Two sheets, the second hidden: a frozen header, a text, a number, a duration, a gap in the row numbers.
     */
    private function workbook(): string
    {
        return self::bytesOf(
            (new RawXlsx())
                ->styles(['[h]:mm:ss'])
                ->sheet('Results', RawXlsx::worksheet(
                    [
                        1 => ['A' => RawXlsx::inline('Puzzle'), 'B' => RawXlsx::inline('Pieces'), 'C' => RawXlsx::inline('Time')],
                        2 => ['A' => RawXlsx::inline('Made-up harbour at dawn'), 'B' => RawXlsx::n('1000'), 'C' => RawXlsx::n((string) (3723 / 86400), 1)],
                        4 => ['A' => RawXlsx::inline('Invented orchard'), 'B' => RawXlsx::n('500')],
                    ],
                    '<dimension ref="A1:C4"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews>',
                ))
                ->sheet('Old notes', RawXlsx::worksheet([7 => ['B' => RawXlsx::inline('kept from an older sheet')]]), 'hidden'),
        );
    }

    private static function bytesOf(RawXlsx $workbook): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'msp-import-test');
        unlink($path);
        $path .= '.xlsx';

        try {
            $workbook->save($path);

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * What the browser does (the start, the PUT, the confirmation) - by its messages.
     */
    private function uploaded(string $name, string $content, string $playerId = PlayerFixture::PLAYER_REGULAR): string
    {
        $importId = Uuid::uuid7()->toString();
        $kind = DataImportFileKind::ofFileName($name);
        self::assertNotNull($kind);

        $this->bus->dispatch(new StartImport($importId, $playerId, $name, strlen($content)));
        self::getContainer()->get(Filesystem::class)->write(ImportFileStorage::objectKey($playerId, $importId, $kind), $content);
        $this->bus->dispatch(new ConfirmImportUploaded($importId, $playerId));

        return $importId;
    }

    /**
     * The worker: a fresh entity manager, like a message of its own.
     *
     * @param null|array{sheets: int, rows: int, cells: int} $limits null: the import's own
     */
    private function read(string $importId, null|array $limits = null): void
    {
        $container = self::getContainer();
        $handler = $limits === null ? $container->get(ReadImportFileHandler::class) : new ReadImportFileHandler(
            $this->bus,
            $container->get(ImportFileStorage::class),
            $container->get(SpreadsheetReader::class),
            $container->get(ImportProgress::class),
            $container->get(ClockInterface::class),
            new NullLogger(),
            $limits['sheets'],
            $limits['rows'],
            $limits['cells'],
        );

        $container->get(EntityManagerInterface::class)->clear();
        $handler(new ReadImportFile($importId));
        $container->get(EntityManagerInterface::class)->clear();
    }

    private function ask(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    private function statusOf(string $importId): string
    {
        $status = $this->database->fetchOne('SELECT status FROM data_import WHERE id = :id', ['id' => $importId]);
        self::assertIsString($status);

        return $status;
    }

    private function activeAt(string $importId, int $secondsAgo): void
    {
        $this->database->executeStatement('UPDATE data_import SET last_activity_at = :at WHERE id = :id', [
            'at' => TestClock::now()->modify('-' . $secondsAgo . ' seconds')->format('Y-m-d H:i:s'),
            'id' => $importId,
        ]);
    }

    private function requestedAt(string $importId, null|int $secondsAgo): void
    {
        $this->database->executeStatement('UPDATE data_import SET read_requested_at = :at WHERE id = :id', [
            'at' => $secondsAgo === null ? null : TestClock::now()->modify('-' . $secondsAgo . ' seconds')->format('Y-m-d H:i:s'),
            'id' => $importId,
        ]);
    }

    private function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<string> the imports queued for reading, in order
     */
    private function queuedForReading(): array
    {
        $ids = [];

        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof ReadImportFile) {
                $ids[] = $message->importId;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>> what was published to players' import topics - every one private
     */
    private function published(): array
    {
        $published = [];

        foreach ($this->hub()->getPublishedUpdates() as $update) {
            $topic = $update->getTopics()[0] ?? null;

            if (is_string($topic) === false || str_starts_with($topic, '/imports/') === false) {
                continue;
            }

            self::assertTrue($update->isPrivate());
            $data = json_decode($update->getData(), true);
            self::assertIsArray($data);
            self::assertIsString($data['importId'] ?? null);
            // The topic is the owner's
            $owner = $this->database->fetchOne('SELECT player_id FROM data_import WHERE id = :id', ['id' => $data['importId']]);
            self::assertIsString($owner);
            self::assertSame('/imports/' . $owner, $topic);
            /** @var array<string, mixed> $data */
            $published[] = $data;
        }

        return $published;
    }

    /**
     * @return list<string>
     */
    private function subscribedTopics(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        $topics = json_decode((string) $crawler->filter('[data-controller="mercure-hub"]')->attr('data-mercure-hub-topics-value'), true);
        self::assertIsArray($topics);

        /** @var list<string> $topics */
        return $topics;
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), associative: true);

        return is_array($decoded) ? $decoded : [];
    }
}
