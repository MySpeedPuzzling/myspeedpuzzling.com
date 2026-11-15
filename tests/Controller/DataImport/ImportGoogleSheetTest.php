<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\DataImport\GoogleSheetFile;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Message\BeginImportFetch;
use SpeedPuzzling\Web\Message\CreateImportFromGoogleSheet;
use SpeedPuzzling\Web\Message\DiscardImport;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\ReadWaitingImports;
use SpeedPuzzling\Web\Message\RecordImportFetched;
use SpeedPuzzling\Web\Message\StartImportFromGoogleSheet;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\MessageHandler\StartImportFromGoogleSheetHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestDouble\FixedHostAddresses;
use SpeedPuzzling\Web\Tests\TestDouble\GoogleSheetHttpMock;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\ImportFetchJob;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Ramsey\Uuid\Uuid;

/**
 * docs/features/data-import/google-sheets.md - a spreadsheet brought as a Google Sheets link: the import is made at
 * once, the worker fetches the export, stores it like an upload and asks for the reading; what Google answers
 * instead ends in a state the page explains, with "Try again". Google is a recorded answer, every id is made up.
 *
 * The `imports` queue is in memory in tests: the worker is played by calling the handlers.
 */
final class ImportGoogleSheetTest extends WebTestCase
{
    private const string ID = '1MadeUpDocumentId_0123456789-abcdefghijklmnop';
    private const string LINK = 'https://docs.google.com/spreadsheets/d/' . self::ID . '/edit?usp=sharing#gid=0';

    private KernelBrowser $browser;
    private Connection $database;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        GoogleSheetHttpMock::reset();
        FixedHostAddresses::reset();
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
    }

    protected function tearDown(): void
    {
        GoogleSheetHttpMock::reset();
        FixedHostAddresses::reset();

        parent::tearDown();
    }

    public function testTheLinkComesFirstOnThePage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/new');
        $this->assertResponseIsSuccessful();

        $forms = $crawler->filter('[data-testid="data-import-new-page"] form');
        self::assertCount(2, $forms);
        // The link's form is a plain one - no script, posted to the page itself
        self::assertCount(1, $forms->eq(0)->filter('input[name="google_link"][type="url"][required]'));
        self::assertSame('/en/import/new', $forms->eq(0)->attr('action'));
        self::assertNull($forms->eq(0)->attr('data-controller'));
        self::assertCount(1, $forms->eq(0)->filter('input[name="_token"]'));
        self::assertCount(1, $forms->eq(1)->filter('input[type="file"]'));
        self::assertStringContainsString('Share → Anyone with the link → Viewer', $crawler->filter('#data-import-google-help')->text());
        self::assertCount(0, $crawler->filter('[data-testid="import-link-refusal"]'));
    }

    public function testAPublicSheetIsFetchedStoredAndRead(): void
    {
        $content = $this->workbook();
        $importId = $this->pasted(self::LINK);

        // Made at once: the player is on the import's page, the worker has its message - only the id was kept
        self::assertSame(
            ['status' => 'fetching', 'source' => 'google_sheet', 'google_spreadsheet_id' => self::ID, 'file_name' => '', 'file_kind' => 'xlsx', 'file_size' => null, 'fetch_attempt' => 0],
            $this->row($importId, 'status, source, google_spreadsheet_id, file_name, file_kind, file_size, fetch_attempt'),
        );
        self::assertSame([$importId], $this->queued(StartImportFromGoogleSheet::class));
        self::assertSame([], GoogleSheetHttpMock::requests(), 'The request itself asks Google nothing');

        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        self::assertSame('Google sheet', $crawler->filter('h1')->text());
        self::assertSame('Fetching from Google', $crawler->filter('[data-testid="import-status"]')->text());
        self::assertSame('Google Sheets link', $crawler->filter('[data-testid="import-source"]')->text());
        $line = $crawler->filter('[data-testid="import-reading"]');
        self::assertSame('data-import-progress', $line->attr('data-controller'));
        self::assertSame('Fetching the sheet from Google…', $line->attr('data-data-import-progress-fetching-text-value'));
        self::assertSame('Fetching the sheet from Google…', $crawler->filter('[data-testid="import-reading-state"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="import-soon"]'), 'No file is kept yet');

        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        self::assertSame(['fetching', true], [$this->json()['status'] ?? null, $this->json()['moving'] ?? null]);

        // The worker
        GoogleSheetHttpMock::answerWithExport(self::ID, $content, 'attachment; filename="Mypuzzles.xlsx"; filename*=UTF-8\'\'My%20puzzles.xlsx');
        $this->fetch($importId);

        self::assertSame(
            ['https://docs.google.com/spreadsheets/d/' . self::ID . '/export?format=xlsx', GoogleSheetHttpMock::downloadUrl(self::ID)],
            GoogleSheetHttpMock::requestedUrls(),
        );
        self::assertSame(
            ['status' => 'uploaded', 'file_name' => 'My puzzles.xlsx', 'file_size' => strlen($content), 'fetch_attempt' => 1, 'read_error' => null],
            $this->row($importId, 'status, file_name, file_size, fetch_attempt, read_error'),
        );
        // Stored like an upload, under the server's key
        $key = ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, DataImportFileKind::Xlsx);
        self::assertSame($key, $this->row($importId, 'file_key')['file_key']);
        self::assertSame($content, self::getContainer()->get(Filesystem::class)->read($key));
        // … and the reading was asked for
        self::assertSame([$importId], $this->queued(ReadImportFile::class));
        self::assertNotNull($this->row($importId, 'read_requested_at')['read_requested_at']);
        self::assertSame([], glob(rtrim(sys_get_temp_dir(), '/') . '/' . ReadImportFileHandler::TEMPORARY_FOLDER . '/*'), 'Nothing of the file stays on the worker\'s disk');

        // The page was told: states only, to the owner's private topic
        $published = $this->published($importId);
        self::assertNotEmpty($published);
        self::assertSame('uploaded', end($published)['status'] ?? null);

        // The same message once more fetches nothing
        $this->fetch($importId);
        self::assertCount(2, GoogleSheetHttpMock::requests());

        // From here on it is an upload like any other
        $this->read($importId);
        self::assertSame(['status' => 'setting_up', 'sheets_total' => 1, 'rows_read' => 2], $this->row($importId, 'status, sheets_total, rows_read'));

        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        self::assertSame('My puzzles.xlsx', $crawler->filter('h1')->text());
        self::assertCount(1, $crawler->filter('[data-testid="import-sheets"]'));
        self::assertStringContainsString('switch its sharing off', $crawler->filter('[data-testid="import-sharing-off"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="import-soon"]'));
    }

    public function testASheetThatIsNotSharedSaysSoAndCanBeTriedAgain(): void
    {
        $importId = $this->pasted(self::LINK);

        GoogleSheetHttpMock::answer(new MockResponse(
            '<!DOCTYPE html><html><head><title>Sign in - Google Accounts</title></head><body><a href="https://accounts.google.com/ServiceLogin">Sign in</a></body></html>',
            ['response_headers' => ['content-type' => 'text/html; charset=utf-8']],
        ));
        $this->fetch($importId);

        self::assertSame(['status' => 'fetch_failed', 'read_error' => 'not_shared', 'file_size' => null], $this->row($importId, 'status, read_error, file_size'));
        self::assertFalse(self::getContainer()->get(Filesystem::class)->fileExists(ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, DataImportFileKind::Xlsx)), 'The sign-in page is never stored');
        self::assertSame([], $this->queued(ReadImportFile::class));
        $published = $this->published($importId);
        self::assertSame(['fetch_failed', false], [end($published)['status'] ?? null, end($published)['moving'] ?? null]);

        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        $failed = $crawler->filter('[data-testid="import-fetch-failed"]');
        self::assertSame('not_shared', $failed->attr('data-reason'));
        self::assertStringContainsString('Google asks to sign in', $failed->text());
        self::assertStringContainsString('Share → Anyone with the link → Viewer', $failed->text());
        self::assertSame('Could not be fetched', $crawler->filter('[data-testid="import-status"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="import-reading"]'));
        $token = (string) $failed->filter('form input[name="_token"]')->attr('value');

        // Without the token nothing happens
        $this->browser->request('POST', '/en/import/' . $importId . '/fetch-again', ['_token' => 'not-the-token']);
        $this->assertResponseStatusCodeSame(403);
        self::assertSame('fetch_failed', $this->row($importId, 'status')['status']);

        // Shared meanwhile: "Try again" fetches again
        $this->browser->request('POST', '/en/import/' . $importId . '/fetch-again', ['_token' => $token]);
        $this->assertResponseRedirects('/en/import/' . $importId);
        self::assertSame(['status' => 'fetching', 'read_error' => null, 'fetch_started_at' => null], $this->row($importId, 'status, read_error, fetch_started_at'));
        self::assertSame([$importId], $this->queued(StartImportFromGoogleSheet::class));

        // Pressed twice: nothing more is queued
        $this->browser->request('POST', '/en/import/' . $importId . '/fetch-again', ['_token' => $token]);
        $this->assertResponseRedirects('/en/import/' . $importId);
        self::assertSame([], $this->queued(StartImportFromGoogleSheet::class));

        $content = $this->workbook();
        GoogleSheetHttpMock::answerWithExport(self::ID, $content);
        $this->fetch($importId);

        // Google gave no title this time: the import keeps the name the pages have for it
        self::assertSame(['status' => 'uploaded', 'file_name' => '', 'fetch_attempt' => 2, 'file_size' => strlen($content)], $this->row($importId, 'status, file_name, fetch_attempt, file_size'));
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('provideFailures')]
    public function testEveryFailureHasItsSentence(array $answer, string $reason, string $sentence): void
    {
        $importId = $this->pasted(self::LINK);
        GoogleSheetHttpMock::answer(new MockResponse(is_string($answer['body'] ?? null) ? $answer['body'] : '', array_diff_key($answer, ['body' => true])));
        // The worker with a limit small enough for a small file
        $this->fetch($importId, maxBytes: 1000);

        self::assertSame(['status' => 'fetch_failed', 'read_error' => $reason], $this->row($importId, 'status, read_error'));

        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        $failed = $crawler->filter('[data-testid="import-fetch-failed"]');
        self::assertStringContainsString($sentence, $failed->text());
        self::assertCount(1, $failed->filter('[data-testid="fetch-again"]'));
        self::assertStringNotContainsString('data_import.', (string) $this->browser->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function provideFailures(): iterable
    {
        yield 'not shared (401)' => [['http_code' => 401], 'not_shared', 'Google asks to sign in'];
        yield 'wrong id (404)' => [['http_code' => 404], 'not_found', 'Google does not know a spreadsheet with this link'];
        yield 'too large' => [['body' => "PK\x03\x04" . str_repeat('a', 2000)], 'too_large', 'larger than 25 MB'];
        yield 'Google refuses (500)' => [['http_code' => 500], 'refused', 'File → Download → Microsoft Excel'];
        yield 'a redirect to another host' => [['http_code' => 302, 'response_headers' => ['location' => 'https://example.com/x.xlsx']], 'refused', 'Try again later'];
        yield 'not a spreadsheet' => [['body' => 'just words', 'response_headers' => ['content-type' => 'text/plain']], 'not_a_spreadsheet', 'What Google sent is not a spreadsheet'];
    }

    public function testAFaultOfOursIsSaidAsOurs(): void
    {
        $importId = $this->pasted(self::LINK);
        GoogleSheetHttpMock::answerWithExport(self::ID, $this->workbook());
        // Object storage does not take the file
        $adapter = new ToggleableFailingFilesystemAdapter(self::getContainer()->get(ClockInterface::class));
        $adapter->setFailing(true);
        $this->fetch($importId, filesystem: new Filesystem($adapter));

        self::assertSame(['status' => 'fetch_failed', 'read_error' => 'failed'], $this->row($importId, 'status, read_error'));
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertStringContainsString('Something went wrong on our side', $crawler->filter('[data-testid="import-fetch-failed"]')->text());
    }

    public function testALinkThatIsNoGoogleSheetIsRefusedAtTheForm(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token();
        $before = $this->countImports(PlayerFixture::PLAYER_REGULAR);

        $docs = 'https://docs.google.com/document/d/' . self::ID . '/edit';
        $crawler = $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => $docs]);
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This is not the link of a Google sheet', $crawler->filter('[data-testid="import-link-refusal"]')->text());
        // Under its own field, which keeps what was typed; the file's form shows nothing
        self::assertSame($docs, $crawler->filter('input[name="google_link"]')->attr('value'));
        self::assertSame('true', $crawler->filter('input[name="google_link"]')->attr('aria-invalid'));
        self::assertNotNull($crawler->filter('[data-testid="import-refusal"]')->attr('hidden'));

        foreach (['https://drive.google.com/drive/folders/' . self::ID, 'https://example.com/spreadsheets/d/' . self::ID, 'my sheet', ''] as $link) {
            $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => $link]);
            $this->assertResponseStatusCodeSame(422);
        }

        $crawler = $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vMadeUpPublishedId_0123456789abcdefghij/pubhtml']);
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('published to the web', $crawler->filter('[data-testid="import-link-refusal"]')->text());

        $this->browser->request('POST', '/en/import/new', ['_token' => 'not-the-token', 'google_link' => self::LINK]);
        $this->assertResponseStatusCodeSame(403);

        self::assertSame($before, $this->countImports(PlayerFixture::PLAYER_REGULAR));
        self::assertSame([], $this->queued(StartImportFromGoogleSheet::class));
        self::assertSame([], GoogleSheetHttpMock::requests());
    }

    public function testAFourthUnfinishedImportIsRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->token();

        foreach ([1, 2, 3] as $ignored) {
            $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => self::LINK]);
            $this->assertResponseStatusCodeSame(302);
        }

        $crawler = $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => self::LINK]);
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('3 unfinished imports', $crawler->filter('[data-testid="import-link-refusal"]')->text());
        self::assertSame(3, $this->countImports(PlayerFixture::PLAYER_WITH_STRIPE));

        // A sheet that was not fetched still holds its place - until it is discarded
        $first = $this->database->fetchOne('SELECT id FROM data_import WHERE player_id = :id ORDER BY created_at, id LIMIT 1', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        self::assertIsString($first);
        GoogleSheetHttpMock::answer(new MockResponse('', ['http_code' => 404]));
        $this->fetch($first);
        $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => self::LINK]);
        $this->assertResponseStatusCodeSame(422);

        $this->browser->request('POST', '/en/import/' . $first . '/discard', ['_token' => $token]);
        $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => self::LINK]);
        $this->assertResponseStatusCodeSame(302);
    }

    public function testLinksAndTriesAreRateLimitedPerPlayer(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->token();

        // The test environment's limiters live on disk: the budget used up here is given back at the end
        $limiter = self::getContainer()->get('limiter.data_import_google')->create(PlayerFixture::PLAYER_WITH_FAVORITES);
        $limiter->reset();

        try {
            $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => self::LINK]);
            $this->assertResponseStatusCodeSame(302);
            $importId = $this->database->fetchOne('SELECT id FROM data_import WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]);
            self::assertIsString($importId);
            GoogleSheetHttpMock::answer(new MockResponse('', ['http_code' => 401]));
            $this->fetch($importId);

            // A link that is none costs nothing
            $remaining = $limiter->consume(0)->getRemainingTokens();
            $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => 'https://example.com/']);
            self::assertSame($remaining, $limiter->consume(0)->getRemainingTokens());

            // The player's budget of this hour used up (the test environment's is far above production's 10)
            $limiter->consume($remaining);

            $crawler = $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => self::LINK]);
            $this->assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Too many Google sheets', $crawler->filter('[data-testid="import-link-refusal"]')->text());
            self::assertSame(1, $this->countImports(PlayerFixture::PLAYER_WITH_FAVORITES));

            // "Try again" asks Google too - it counts the same
            $this->browser->request('POST', '/en/import/' . $importId . '/fetch-again', ['_token' => $token]);
            $this->assertResponseRedirects('/en/import/' . $importId);
            self::assertSame('fetch_failed', $this->row($importId, 'status')['status']);
            self::assertSame([], $this->queued(StartImportFromGoogleSheet::class));
            $crawler = $this->browser->followRedirect();
            self::assertStringContainsString('Too many Google sheets', $crawler->filter('body')->text());

            // An upload is another budget, another player is not held back
            TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
            $this->browser->request('POST', '/en/import/new', ['_token' => $this->token(), 'google_link' => self::LINK]);
            $this->assertResponseStatusCodeSame(302);
        } finally {
            $limiter->reset();
        }
    }

    public function testAnotherPlayersImportIsUntouched(): void
    {
        $importId = $this->pasted(self::LINK);
        GoogleSheetHttpMock::answer(new MockResponse('', ['http_code' => 401]));
        $this->fetch($importId);
        $before = $this->row($importId, 'status, read_error, fetch_attempt, last_activity_at');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->token();

        $this->browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/import/' . $importId . '/progress');
        $this->assertResponseStatusCodeSame(404);
        $this->browser->request('POST', '/en/import/' . $importId . '/fetch-again', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(404);

        self::assertSame([], $this->queued(StartImportFromGoogleSheet::class));
        self::assertSame($before, $this->row($importId, 'status, read_error, fetch_attempt, last_activity_at'));
        self::assertSame(0, $this->countImports(PlayerFixture::PLAYER_WITH_FAVORITES));

        // The same sheet pasted by another player is an import of their own
        $this->browser->request('POST', '/en/import/new', ['_token' => $token, 'google_link' => self::LINK]);
        $this->assertResponseStatusCodeSame(302);
        self::assertSame(1, $this->countImports(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertSame($before, $this->row($importId, 'status, read_error, fetch_attempt, last_activity_at'));
    }

    public function testNothingIsKeptOfASheetWhoseImportWasDiscardedWhileItWasFetched(): void
    {
        $importId = $this->pasted(self::LINK);

        // The worker took it …
        $job = $this->ask(new BeginImportFetch($importId));
        self::assertInstanceOf(ImportFetchJob::class, $job);
        // … the player discards the import while Google is asked …
        $this->bus->dispatch(new DiscardImport($importId, PlayerFixture::PLAYER_REGULAR));
        // … and what the fetch brought is refused
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertFalse($this->ask(new RecordImportFetched($importId, $job->fetchAttempt, 'late.xlsx', 10)));
        self::assertSame(['status' => 'discarded', 'file_size' => null, 'file_name' => ''], $this->row($importId, 'status, file_size, file_name'));

        // The whole handler: discarded before the worker comes - Google is not even asked
        $second = $this->pasted(self::LINK);
        $this->bus->dispatch(new DiscardImport($second, PlayerFixture::PLAYER_REGULAR));
        GoogleSheetHttpMock::answerWithExport(self::ID, $this->workbook());
        $this->fetch($second);
        self::assertSame([], GoogleSheetHttpMock::requests());
        self::assertFalse(self::getContainer()->get(Filesystem::class)->fileExists(ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $second, DataImportFileKind::Xlsx)));
        self::assertSame([], $this->queued(ReadImportFile::class));
    }

    public function testAFetchThatWasGivenUpCanNotWriteIntoTheNextOne(): void
    {
        $importId = $this->pasted(self::LINK);
        $first = $this->ask(new BeginImportFetch($importId));
        self::assertInstanceOf(ImportFetchJob::class, $first);
        self::assertNull($this->ask(new BeginImportFetch($importId)), 'Taken once');

        // Its worker died: the rescue ends the state, the player tries again
        $this->activeAt($importId, 16 * 60);
        self::assertSame(['queued' => 0, 'failed' => 1], $this->ask(new ReadWaitingImports()));
        self::assertSame(['status' => 'fetch_failed', 'read_error' => 'stalled'], $this->row($importId, 'status, read_error'));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/import/' . $importId);
        self::assertStringContainsString('Something went wrong on our side', $crawler->filter('[data-testid="import-fetch-failed"]')->text());
        $this->browser->request('POST', '/en/import/' . $importId . '/fetch-again', ['_token' => $this->token()]);

        $second = $this->ask(new BeginImportFetch($importId));
        self::assertInstanceOf(ImportFetchJob::class, $second);
        self::assertSame($first->fetchAttempt + 1, $second->fetchAttempt);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertFalse($this->ask(new RecordImportFetched($importId, $first->fetchAttempt, 'late.xlsx', 10)));
        self::assertSame('fetching', $this->row($importId, 'status')['status']);
        self::assertTrue($this->ask(new RecordImportFetched($importId, $second->fetchAttempt, 'now.xlsx', 10)));
        self::assertSame(['status' => 'uploaded', 'file_name' => 'now.xlsx'], $this->row($importId, 'status, file_name'));
    }

    public function testAFetchStuckForAQuarterOfAnHourEndsAsOurs(): void
    {
        // One whose message was lost (never taken), one that waits only a few minutes
        $lost = $this->pasted(self::LINK);
        $fresh = $this->pasted(self::LINK);
        $this->activeAt($lost, 15 * 60 + 5);
        $this->activeAt($fresh, 14 * 60);

        self::assertSame(['queued' => 0, 'failed' => 1], $this->ask(new ReadWaitingImports()));

        self::assertSame(['status' => 'fetch_failed', 'read_error' => 'stalled'], $this->row($lost, 'status, read_error'));
        self::assertSame(['status' => 'fetching', 'read_error' => null], $this->row($fresh, 'status, read_error'));
        $published = $this->published($lost);
        self::assertSame('fetch_failed', end($published)['status'] ?? null);

        // Once more: nothing left to end
        self::assertSame(['queued' => 0, 'failed' => 0], $this->ask(new ReadWaitingImports()));
    }

    public function testOnlyADocumentIdIsEverStored(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->bus->dispatch(new CreateImportFromGoogleSheet(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_REGULAR, self::ID . '/edit?usp=sharing'));
    }

    public function testThePagesAreInEveryLanguage(): void
    {
        $fetching = $this->pasted(self::LINK);
        $failed = $this->pasted(self::LINK);
        GoogleSheetHttpMock::answer(new MockResponse('', ['http_code' => 401]));
        $this->fetch($failed);
        $token = $this->token();

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            foreach (['/import', '/import/new', '/import/' . $fetching, '/import/' . $failed] as $path) {
                $this->browser->request('GET', '/' . $locale . $path);
                $this->assertResponseIsSuccessful();
                self::assertStringNotContainsString('data_import.', (string) $this->browser->getResponse()->getContent(), $locale . $path);
            }

            $this->browser->request('POST', '/' . $locale . '/import/new', ['_token' => $token, 'google_link' => 'https://example.com/']);
            $this->assertResponseStatusCodeSame(422);
            self::assertStringNotContainsString('data_import.', (string) $this->browser->getResponse()->getContent(), $locale);
        }
    }

    /**
     * The player pastes the link and lands on the import's page.
     */
    private function pasted(string $link, string $playerId = PlayerFixture::PLAYER_REGULAR): string
    {
        TestingLogin::asPlayer($this->browser, $playerId);
        $this->browser->request('POST', '/en/import/new', ['_token' => $this->token(), 'google_link' => $link]);
        $this->assertResponseStatusCodeSame(302);
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/en/import/[0-9a-f-]{36}$#', $location);

        return substr($location, strlen('/en/import/'));
    }

    private function token(): string
    {
        $crawler = $this->browser->request('GET', '/en/import/new');
        $this->assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-testid="import-google-card"] input[name="_token"]')->attr('value');
    }

    /**
     * The worker: a fresh entity manager, like a message of its own.
     *
     * @param null|int $maxBytes null: the import's own limit
     * @param null|Filesystem $filesystem null: the suite's object storage
     */
    private function fetch(string $importId, null|int $maxBytes = null, null|Filesystem $filesystem = null): void
    {
        $container = self::getContainer();
        $handler = $maxBytes === null && $filesystem === null ? $container->get(StartImportFromGoogleSheetHandler::class) : new StartImportFromGoogleSheetHandler(
            $this->bus,
            $container->get(GoogleSheetFile::class),
            $filesystem === null ? $container->get(ImportFileStorage::class) : new ImportFileStorage($filesystem, $container->get(OnlineMediaStores::class), $container->get(S3QueryPresigner::class), $container->get(ClockInterface::class)),
            $container->get(ImportProgress::class),
            new NullLogger(),
            $maxBytes ?? DataImportLimits::MAX_FILE_BYTES,
        );

        $container->get(EntityManagerInterface::class)->clear();
        $handler(new StartImportFromGoogleSheet($importId));
        $container->get(EntityManagerInterface::class)->clear();
    }

    private function read(string $importId): void
    {
        $container = self::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $container->get(ReadImportFileHandler::class)(new ReadImportFile($importId));
        $container->get(EntityManagerInterface::class)->clear();
    }

    private function ask(object $message): mixed
    {
        return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $importId, string $columns): array
    {
        $row = $this->database->fetchAssociative('SELECT ' . $columns . ' FROM data_import WHERE id = :id', ['id' => $importId]);
        self::assertIsArray($row);

        return $row;
    }

    private function countImports(string $playerId): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM data_import WHERE player_id = :id', ['id' => $playerId]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function activeAt(string $importId, int $secondsAgo): void
    {
        $this->database->executeStatement('UPDATE data_import SET last_activity_at = :at WHERE id = :id', [
            'at' => TestClock::now()->modify('-' . $secondsAgo . ' seconds')->format('Y-m-d H:i:s'),
            'id' => $importId,
        ]);
    }

    /**
     * @param class-string $messageClass
     *
     * @return list<string> the imports queued with that message, in order
     */
    private function queued(string $messageClass): array
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $ids = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $messageClass && property_exists($message, 'importId') && is_string($message->importId)) {
                $ids[] = $message->importId;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>> what was published about the import - to its owner's private topic
     */
    private function published(string $importId): array
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);
        $owner = $this->row($importId, 'player_id')['player_id'];
        self::assertIsString($owner);
        $published = [];

        foreach ($hub->getPublishedUpdates() as $update) {
            $data = json_decode($update->getData(), true);

            if (is_array($data) === false || ($data['importId'] ?? null) !== $importId) {
                continue;
            }

            self::assertTrue($update->isPrivate());
            self::assertSame(['/imports/' . $owner], $update->getTopics());
            /** @var array<string, mixed> $data */
            $published[] = $data;
        }

        return $published;
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One sheet, a header and two rows.
     */
    private function workbook(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'msp-import-test');
        unlink($path);
        $path .= '.xlsx';

        try {
            (new RawXlsx())
                ->sheet('Results', RawXlsx::worksheet([
                    1 => ['A' => RawXlsx::inline('Puzzle'), 'B' => RawXlsx::inline('Pieces')],
                    2 => ['A' => RawXlsx::inline('Made-up harbour at dawn'), 'B' => RawXlsx::n('1000')],
                ]))
                ->save($path);

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
