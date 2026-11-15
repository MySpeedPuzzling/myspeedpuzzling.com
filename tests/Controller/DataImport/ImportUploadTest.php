<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DataImport;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * docs/features/data-import/upload.md - a spreadsheet uploaded straight to object storage and confirmed, the fallback
 * form, the imports pages and Discard. The browser's PUT is played by writing the file where the presigned URL points.
 */
final class ImportUploadTest extends WebTestCase
{
    private const string XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testAFileUploadedStraightToObjectStorageBecomesAnUploadedImport(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($browser);
        $content = str_repeat('x', 2048);

        $answer = $this->start($browser, $token, ['name' => 'My puzzles 2024.xlsx', 'bytes' => strlen($content)]);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertIsString($answer['importId'] ?? null);
        self::assertIsString($answer['url'] ?? null);
        $importId = $answer['importId'];
        $key = 'tmp-imports/player-' . PlayerFixture::PLAYER_REGULAR . '/' . $importId . '/file.xlsx';

        // The key and the content type are the server's - nothing of the file's name is in the key
        self::assertStringContainsString('/' . $key . '?', $answer['url']);
        self::assertSame(['Content-Type' => self::XLSX_TYPE, 'Content-Length' => '2048'], $answer['headers'] ?? null);
        self::assertSame('/en/import/' . $importId . '/uploaded', $answer['confirmUrl'] ?? null);
        self::assertSame('/en/import/' . $importId, $answer['importUrl'] ?? null);
        self::assertSame(['uploading', 'My puzzles 2024.xlsx', 2048, $key], $this->importRow($importId));

        // Not on the list while it is on its way
        $crawler = $browser->request('GET', '/en/import');
        self::assertCount(0, $crawler->filter('[data-testid="data-import"]'));

        self::getContainer()->get(Filesystem::class)->write($key, $content);

        $confirmed = $this->confirm($browser, $token, $importId);

        $this->assertResponseIsSuccessful();
        self::assertSame(['status' => 'uploaded', 'importUrl' => '/en/import/' . $importId], $confirmed);
        self::assertSame('uploaded', $this->importRow($importId)[0]);

        // Confirmed twice: nothing changes
        $this->confirm($browser, $token, $importId);
        $this->assertResponseIsSuccessful();

        $crawler = $browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('My puzzles 2024.xlsx', $crawler->filter('[data-testid="import-flow-file"]')->text());
        self::assertSame('uploaded', $crawler->filter('[data-testid="data-import-page"]')->attr('data-status'));
        self::assertSame('2 kB', trim($crawler->filter('[data-testid="import-size"]')->text()));
        self::assertStringNotContainsString('arrive soon', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('The file is kept until', $crawler->filter('[data-testid="import-file-kept"]')->text());

        $crawler = $browser->request('GET', '/en/import');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(1, $crawler->filter('[data-testid="data-import"]'));
        self::assertStringContainsString('My puzzles 2024.xlsx', $crawler->filter('[data-testid="data-import"]')->text());
        self::assertCount(1, $crawler->filter('a[data-testid="import-a-spreadsheet"][href="/en/import/new"]'));
    }

    public function testAnUploadWhoseUrlRanOutIsSignedAgainWithoutASecondImport(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($browser);

        $first = $this->start($browser, $token, ['name' => 'times.csv', 'bytes' => 500]);
        $again = $this->start($browser, $token, ['name' => 'times.csv', 'bytes' => 500, 'importId' => $first['importId'] ?? null]);

        $this->assertResponseIsSuccessful();
        self::assertSame($first['importId'] ?? null, $again['importId'] ?? null);
        self::assertSame(['Content-Type' => 'text/csv', 'Content-Length' => '500'], $again['headers'] ?? null);
        self::assertSame(1, $this->countImports(PlayerFixture::PLAYER_REGULAR));

        // Another file under the same id is a new import
        $other = $this->start($browser, $token, ['name' => 'times.csv', 'bytes' => 501, 'importId' => $first['importId'] ?? null]);
        self::assertNotSame($first['importId'] ?? null, $other['importId'] ?? null);
        self::assertSame(2, $this->countImports(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAFileOverTheLimitOrOfAnotherKindIsRefused(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($browser);

        $answer = $this->start($browser, $token, ['name' => 'huge.xlsx', 'bytes' => DataImportLimits::MAX_FILE_BYTES + 1]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('too_large', $answer['error'] ?? null);
        self::assertSame('The file is larger than 25 MB.', $answer['message'] ?? null);

        // Exactly the limit is taken
        $this->start($browser, $token, ['name' => 'large.xlsx', 'bytes' => DataImportLimits::MAX_FILE_BYTES]);
        $this->assertResponseIsSuccessful();

        foreach (['notes.pdf', 'old.xls', 'page.html', 'no-extension', 'tricky.xlsx.exe'] as $name) {
            $answer = $this->start($browser, $token, ['name' => $name, 'bytes' => 1000]);
            $this->assertResponseStatusCodeSame(422);
            self::assertSame('type', $answer['error'] ?? null, $name);
        }

        $answer = $this->start($browser, $token, ['name' => 'empty.csv', 'bytes' => 0]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('empty', $answer['error'] ?? null);

        $this->start($browser, $token, ['name' => 'tabs.TSV', 'bytes' => 10]);
        $this->assertResponseIsSuccessful();

        $this->start($browser, $token, ['bytes' => 10]);
        $this->assertResponseStatusCodeSame(400);

        self::assertSame(2, $this->countImports(PlayerFixture::PLAYER_REGULAR), 'A refused start leaves nothing behind');
    }

    public function testStartingIsRefusedWithoutASignInOrTheToken(): void
    {
        $browser = $this->browser();

        // Signed out: the sign-in page, never a URL
        $this->start($browser, 'anything', ['name' => 'a.csv', 'bytes' => 10]);
        self::assertFalse($browser->getResponse()->isSuccessful());
        $browser->request('GET', '/en/import');
        $this->assertResponseRedirects();
        $browser->request('GET', '/en/import/new');
        $this->assertResponseRedirects();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $answer = $this->start($browser, 'not-the-token', ['name' => 'a.csv', 'bytes' => 10]);
        $this->assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf_token', $answer['error'] ?? null);
        self::assertSame(0, $this->countImports(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAConfirmationWithoutTheObjectOrWithAnotherSizeIsRefused(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($browser);

        $answer = $this->start($browser, $token, ['name' => 'times.csv', 'bytes' => 100]);
        self::assertIsString($answer['importId'] ?? null);
        $importId = $answer['importId'];
        $key = 'tmp-imports/player-' . PlayerFixture::PLAYER_REGULAR . '/' . $importId . '/file.csv';

        $refused = $this->confirm($browser, $token, $importId);
        $this->assertResponseStatusCodeSame(409);
        self::assertSame('missing', $refused['error'] ?? null);
        self::assertSame('uploading', $this->importRow($importId)[0]);

        self::getContainer()->get(Filesystem::class)->write($key, str_repeat('x', 99));

        $refused = $this->confirm($browser, $token, $importId);
        $this->assertResponseStatusCodeSame(409);
        self::assertSame('size', $refused['error'] ?? null);
        self::assertSame('uploading', $this->importRow($importId)[0]);

        // Its page says so and offers to discard it
        $crawler = $browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="import-not-finished"]'));

        $this->confirm($browser, 'not-the-token', $importId);
        $this->assertResponseStatusCodeSame(403);

        self::getContainer()->get(Filesystem::class)->write($key, str_repeat('x', 100));
        $this->confirm($browser, $token, $importId);
        $this->assertResponseIsSuccessful();
    }

    public function testAnotherPlayersImportIsNotFoundOnEveryRoute(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $importId = $this->uploadedImport($browser, 'mine.xlsx', 'my rows');
        $key = 'tmp-imports/player-' . PlayerFixture::PLAYER_REGULAR . '/' . $importId . '/file.xlsx';

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->token($browser);

        $browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseStatusCodeSame(404);

        $this->confirm($browser, $token, $importId);
        $this->assertResponseStatusCodeSame(404);

        $browser->request('POST', '/en/import/' . $importId . '/discard', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(404);

        $browser->request('POST', '/en/import/' . $importId . '/finish', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(404);

        // Signing "again" somebody else's id makes an import of one's own, never a URL for theirs
        $answer = $this->start($browser, $token, ['name' => 'mine.xlsx', 'bytes' => 7, 'importId' => $importId]);
        $this->assertResponseIsSuccessful();
        self::assertNotSame($importId, $answer['importId'] ?? null);
        self::assertIsString($answer['url'] ?? null);
        self::assertStringContainsString('/player-' . PlayerFixture::PLAYER_WITH_STRIPE . '/', $answer['url']);

        $crawler = $browser->request('GET', '/en/import');
        self::assertCount(0, $crawler->filter('[data-testid="data-import"]'));

        self::assertSame('uploaded', $this->importRow($importId)[0]);
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($key));

        $browser->request('GET', '/en/import/not-an-id');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDiscardingAnImportDeletesItsFile(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $importId = $this->uploadedImport($browser, 'mine.xlsx', 'my rows');
        $key = 'tmp-imports/player-' . PlayerFixture::PLAYER_REGULAR . '/' . $importId . '/file.xlsx';
        $token = $this->token($browser);

        $browser->request('POST', '/en/import/' . $importId . '/discard', ['_token' => 'not-the-token']);
        $this->assertResponseStatusCodeSame(403);
        self::assertSame('uploaded', $this->importRow($importId)[0]);

        $browser->request('POST', '/en/import/' . $importId . '/discard', ['_token' => $token]);
        $this->assertResponseRedirects('/en/import');

        // The key stays on the row until the prune removes it: a signed upload URL could still bring the object back
        self::assertSame(['discarded', 'mine.xlsx', 7, $key], $this->importRow($importId));
        self::assertFalse(self::getContainer()->get(Filesystem::class)->fileExists($key));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The import was discarded', (string) $browser->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-testid="data-import"]'));

        $browser->request('GET', '/en/import/' . $importId);
        $this->assertResponseStatusCodeSame(404);

        // Discarded twice: still gone, nothing breaks
        $browser->request('POST', '/en/import/' . $importId . '/discard', ['_token' => $token]);
        $this->assertResponseRedirects('/en/import');
    }

    public function testTheFormIsTheFallbackWithTheSameLimits(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/import/new');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        $form = $crawler->filter('form[data-controller="data-import-upload"]');
        self::assertSame('/en/import/new', $form->attr('action'));
        self::assertSame('/en/import/upload', $form->attr('data-data-import-upload-start-url-value'));
        self::assertSame('.xlsx,.csv,.tsv', $form->filter('input[type="file"]')->attr('accept'));
        $token = (string) $form->filter('input[name="_token"]')->attr('value');

        $content = "puzzle;time\nSunset 1000;01:02:03\n";
        $browser->request('POST', '/en/import/new', ['_token' => $token], ['file' => $this->uploadedFile('results.csv', $content)]);

        self::assertSame(1, preg_match('~^/en/import/([0-9a-f-]{36})$~', (string) $browser->getResponse()->headers->get('Location'), $match));
        $importId = $match[1];
        $this->assertResponseRedirects('/en/import/' . $importId);

        $key = 'tmp-imports/player-' . PlayerFixture::PLAYER_REGULAR . '/' . $importId . '/file.csv';
        self::assertSame(['uploaded', 'results.csv', strlen($content), $key], $this->importRow($importId));
        self::assertSame($content, self::getContainer()->get(Filesystem::class)->read($key));

        $browser->followRedirect();
        $this->assertResponseIsSuccessful();

        // Another kind: said on the page, 422, nothing kept
        $crawler = $browser->request('POST', '/en/import/new', ['_token' => $token], ['file' => $this->uploadedFile('notes.txt', 'hello')]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('Only Excel (.xlsx), CSV and TSV files can be imported.', $crawler->filter('[data-testid="import-refusal"]')->text());

        $crawler = $browser->request('POST', '/en/import/new', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('Please pick a file first.', $crawler->filter('[data-testid="import-refusal"]')->text());

        $browser->request('POST', '/en/import/new', ['_token' => 'not-the-token'], ['file' => $this->uploadedFile('results.csv', $content)]);
        $this->assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->countImports(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAFourthUnfinishedImportIsRefused(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $first = $this->uploadedImport($browser, 'one.csv', '1');
        $this->uploadedImport($browser, 'two.csv', '2');
        $this->uploadedImport($browser, 'three.csv', '3');
        $token = $this->token($browser);

        $answer = $this->start($browser, $token, ['name' => 'four.csv', 'bytes' => 1]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('too_many', $answer['error'] ?? null);
        self::assertSame('You have 3 imports in progress. Finish or discard one first.', $answer['message'] ?? null);

        $crawler = $browser->request('POST', '/en/import/new', ['_token' => $token], ['file' => $this->uploadedFile('four.csv', '4')]);
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('3 imports in progress', $crawler->filter('[data-testid="import-refusal"]')->text());

        $browser->request('POST', '/en/import/' . $first . '/discard', ['_token' => $token]);
        $this->start($browser, $token, ['name' => 'four.csv', 'bytes' => 1]);
        $this->assertResponseIsSuccessful();
    }

    /**
     * Ten uploads started side by side must not all become imports: the uploads on their way hold a place too
     */
    public function testUploadsStartedSideBySideCountAgainstTheLimitTogether(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($browser);

        $started = [];

        foreach (['one.csv', 'two.csv', 'three.csv'] as $name) {
            $answer = $this->start($browser, $token, ['name' => $name, 'bytes' => 1]);
            $this->assertResponseIsSuccessful();
            self::assertIsString($answer['importId'] ?? null);
            $started[] = $answer['importId'];
        }

        $answer = $this->start($browser, $token, ['name' => 'four.csv', 'bytes' => 1]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('too_many', $answer['error'] ?? null);

        // All three can be confirmed - they were counted when they started
        foreach ($started as $importId) {
            self::getContainer()->get(Filesystem::class)->write($this->key($importId, 'csv'), 'x');
            $this->confirm($browser, $token, $importId);
            $this->assertResponseIsSuccessful();
        }

        self::assertSame(3, $this->countImports(PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * Should an upload ever get around the start's count, its confirmation does not
     */
    public function testAConfirmationOverTheLimitIsRefused(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->uploadedImport($browser, 'one.csv', '1');
        $this->uploadedImport($browser, 'two.csv', '2');
        $third = $this->uploadedImport($browser, 'three.csv', '3');
        $token = $this->token($browser);
        $database = self::getContainer()->get(Connection::class);

        // A fourth upload on its way although three imports are unfinished - what the start never lets happen
        $database->executeStatement("UPDATE data_import SET status = 'discarded' WHERE id = :id", ['id' => $third]);
        $fourth = $this->start($browser, $token, ['name' => 'four.csv', 'bytes' => 1]);
        $this->assertResponseIsSuccessful();
        self::assertIsString($fourth['importId'] ?? null);
        $database->executeStatement("UPDATE data_import SET status = 'uploaded' WHERE id = :id", ['id' => $third]);
        self::getContainer()->get(Filesystem::class)->write($this->key($fourth['importId'], 'csv'), '4');

        $refused = $this->confirm($browser, $token, $fourth['importId']);
        $this->assertResponseStatusCodeSame(409);
        self::assertSame('too_many', $refused['error'] ?? null);
        self::assertSame('uploading', $this->importRow($fourth['importId'])[0]);
    }

    /**
     * The file arrived but the browser's confirmation never did: the import is not hidden for ever
     */
    public function testAnUploadThatStayedUnconfirmedIsListedAndCanBeFinished(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($browser);

        $answer = $this->start($browser, $token, ['name' => 'times.csv', 'bytes' => 5]);
        self::assertIsString($answer['importId'] ?? null);
        $importId = $answer['importId'];

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE data_import SET created_at = :at WHERE id = :id',
            ['at' => TestClock::now()->modify('-2 minutes')->format('Y-m-d H:i:s'), 'id' => $importId],
        );

        $crawler = $browser->request('GET', '/en/import');
        $card = $crawler->filter('[data-testid="data-import"]');
        self::assertCount(1, $card);
        self::assertSame('The upload did not finish.', trim($card->filter('[data-testid="data-import-status"]')->text()));
        self::assertSame('Finish the upload', trim($card->filter('[data-testid="finish-upload"]')->text()));
        self::assertSame('/en/import/' . $importId . '/finish', $card->filter('form')->attr('action'));

        // Nothing arrived yet: said on the import's page, which keeps the button
        $browser->request('POST', '/en/import/' . $importId . '/finish', ['_token' => $token]);
        $this->assertResponseRedirects('/en/import/' . $importId);
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The file did not arrive', (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-testid="finish-upload"]'));

        $browser->request('POST', '/en/import/' . $importId . '/finish', ['_token' => 'not-the-token']);
        $this->assertResponseStatusCodeSame(403);

        self::getContainer()->get(Filesystem::class)->write($this->key($importId, 'csv'), '12345');

        $browser->request('POST', '/en/import/' . $importId . '/finish', ['_token' => $token]);
        $this->assertResponseRedirects('/en/import/' . $importId);
        $crawler = $browser->followRedirect();
        self::assertSame('uploaded', $this->importRow($importId)[0]);
        self::assertCount(0, $crawler->filter('[data-testid="finish-upload"]'));
        self::assertSame('uploaded', $crawler->filter('[data-testid="data-import-page"]')->attr('data-status'));
    }

    /**
     * The script started an upload, could not send the file and sends the form instead: the same import goes on
     */
    public function testTheFormGoesOnWithTheUploadTheScriptStarted(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->token($browser);
        $content = "puzzle;time\nSunset 1000;01:02:03\n";
        $limiter = self::getContainer()->get('limiter.data_import_upload')->create(PlayerFixture::PLAYER_WITH_STRIPE);
        $limiter->reset();

        try {
            $started = $this->start($browser, $token, ['name' => 'results.csv', 'bytes' => strlen($content)]);
            self::assertIsString($started['importId'] ?? null);
            $remaining = $limiter->consume(0)->getRemainingTokens();

            $browser->request('POST', '/en/import/new', ['_token' => $token, 'import_id' => $started['importId']], ['file' => $this->uploadedFile('results.csv', $content)]);

            $this->assertResponseRedirects('/en/import/' . $started['importId']);
            self::assertSame(1, $this->countImports(PlayerFixture::PLAYER_WITH_STRIPE), 'One file, one import');
            self::assertSame('uploaded', $this->importRow($started['importId'])[0]);
            self::assertSame($content, self::getContainer()->get(Filesystem::class)->read($this->key($started['importId'], 'csv', PlayerFixture::PLAYER_WITH_STRIPE)));
            self::assertSame($remaining, $limiter->consume(0)->getRemainingTokens(), 'One file, one upload of the limit');

            // Another file under that id (or an id that is nobody's upload) is an import of its own
            $browser->request('POST', '/en/import/new', ['_token' => $token, 'import_id' => $started['importId']], ['file' => $this->uploadedFile('other.csv', 'other')]);
            self::assertSame(2, $this->countImports(PlayerFixture::PLAYER_WITH_STRIPE));
        } finally {
            $limiter->reset();
        }
    }

    /**
     * An upload is removed by the prune a day after it started - nothing is signed for it in its last hour
     */
    public function testAnOldUploadIsNotSignedAgain(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->token($browser);

        $first = $this->start($browser, $token, ['name' => 'times.csv', 'bytes' => 5]);
        self::assertIsString($first['importId'] ?? null);

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE data_import SET created_at = :at WHERE id = :id',
            ['at' => TestClock::now()->modify('-23 hours -1 minute')->format('Y-m-d H:i:s'), 'id' => $first['importId']],
        );

        $again = $this->start($browser, $token, ['name' => 'times.csv', 'bytes' => 5, 'importId' => $first['importId']]);
        $this->assertResponseIsSuccessful();
        self::assertNotSame($first['importId'], $again['importId'] ?? null);
    }

    public function testUploadsAreRateLimitedPerPlayer(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $token = $this->token($browser);

        // The test environment's limiters live on disk (they must survive the kernel's reboots): the budget used up
        // here is given back at the end, and a run that died in between is cleaned up first
        $limiter = self::getContainer()->get('limiter.data_import_upload')->create(PlayerFixture::PLAYER_WITH_FAVORITES);
        $limiter->reset();

        try {
            $first = $this->start($browser, $token, ['name' => 'a.csv', 'bytes' => 10]);
            $this->assertResponseIsSuccessful();

            // The player's budget of this hour used up (the test environment's is far above production's 10)
            $limiter->consume($limiter->consume(0)->getRemainingTokens());

            $answer = $this->start($browser, $token, ['name' => 'b.csv', 'bytes' => 10]);
            $this->assertResponseStatusCodeSame(429);
            self::assertSame('rate_limited', $answer['error'] ?? null);

            $crawler = $browser->request('POST', '/en/import/new', ['_token' => $token], ['file' => $this->uploadedFile('b.csv', 'b')]);
            $this->assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Too many uploads', $crawler->filter('[data-testid="import-refusal"]')->text());

            // Signing a running upload again is no new upload
            $again = $this->start($browser, $token, ['name' => 'a.csv', 'bytes' => 10, 'importId' => $first['importId'] ?? null]);
            $this->assertResponseIsSuccessful();
            self::assertSame($first['importId'] ?? null, $again['importId'] ?? null);

            self::assertSame(1, $this->countImports(PlayerFixture::PLAYER_WITH_FAVORITES));

            // Another player is not held back
            TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
            $this->start($browser, $this->token($browser), ['name' => 'c.csv', 'bytes' => 10]);
            $this->assertResponseIsSuccessful();
        } finally {
            $limiter->reset();
        }
    }

    public function testThePagesAreInEveryLanguage(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $importId = $this->uploadedImport($browser, 'mine.xlsx', 'my rows');

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            foreach (['/import', '/import/new', '/import/' . $importId] as $path) {
                $browser->request('GET', '/' . $locale . $path);
                $this->assertResponseIsSuccessful();
                self::assertStringNotContainsString('data_import.', (string) $browser->getResponse()->getContent(), $locale . $path);
            }
        }
    }

    /**
     * The storage of the test kernel is in memory and the session holds the token - both must survive between requests
     */
    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    private function token(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', '/en/import/new');
        $this->assertResponseIsSuccessful();

        return (string) $crawler->filter('form[data-controller="data-import-upload"]')->attr('data-data-import-upload-csrf-token-value');
    }

    /**
     * What the browser does: start, PUT the file where the URL points, confirm
     */
    private function uploadedImport(KernelBrowser $browser, string $name, string $content): string
    {
        $token = $this->token($browser);
        $answer = $this->start($browser, $token, ['name' => $name, 'bytes' => strlen($content)]);
        $this->assertResponseIsSuccessful();
        self::assertIsString($answer['importId'] ?? null);
        self::assertIsString($answer['url'] ?? null);
        self::assertSame(1, preg_match('~/(' . ImportFileStorage::PREFIX . '/[^?]+)\?~', $answer['url'], $match));

        self::getContainer()->get(Filesystem::class)->write($match[1], $content);

        $this->confirm($browser, $token, $answer['importId']);
        $this->assertResponseIsSuccessful();

        return $answer['importId'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function start(KernelBrowser $browser, string $token, array $body): array
    {
        $browser->request('POST', '/en/import/upload', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($body));

        return $this->json($browser);
    }

    /**
     * @return array<mixed>
     */
    private function confirm(KernelBrowser $browser, string $token, string $importId): array
    {
        $browser->request('POST', '/en/import/' . $importId . '/uploaded', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: '{}');

        return $this->json($browser);
    }

    /**
     * @return array<mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{0: string, 1: string, 2: null|int, 3: null|string} status, file name, size, key
     */
    private function importRow(string $importId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchNumeric('SELECT status, file_name, file_size, file_key FROM data_import WHERE id = :id', ['id' => $importId]);
        self::assertIsArray($row);
        self::assertIsString($row[0]);
        self::assertIsString($row[1]);
        self::assertTrue($row[2] === null || is_int($row[2]));
        self::assertTrue($row[3] === null || is_string($row[3]));

        return [$row[0], $row[1], $row[2], $row[3]];
    }

    private function key(string $importId, string $kind, string $playerId = PlayerFixture::PLAYER_REGULAR): string
    {
        return 'tmp-imports/player-' . $playerId . '/' . $importId . '/file.' . $kind;
    }

    private function countImports(string $playerId): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM data_import WHERE player_id = :id', ['id' => $playerId]);
        self::assertIsInt($count);

        return $count;
    }

    private function uploadedFile(string $name, string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
