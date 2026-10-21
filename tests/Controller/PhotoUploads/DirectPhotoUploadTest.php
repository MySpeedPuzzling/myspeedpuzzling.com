<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\PhotoUploads;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ExifPhotos;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * docs/features/photo-uploads/README.md - a photo uploaded in the background, straight to object storage, and the form
 * saved with its token. The browser's PUT is played by writing the photo where the presigned URL points.
 */
final class DirectPhotoUploadTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testANewPuzzleIsSavedWithItsBackgroundUploadedPhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        // The form asks for background uploads
        $form = $crawler->filter('form[data-submit-prevention-upload-url-value]');
        self::assertCount(1, $form);
        self::assertSame('/en/photo-upload', $form->attr('data-submit-prevention-upload-url-value'));
        self::assertSame('/-/photo-upload-report', $form->attr('data-submit-prevention-report-url-value'));

        $token = $this->uploadedPhoto($browser, 'box.jpg');

        $submission = $this->newPuzzleSubmission($crawler, 'Background Upload Puzzle');
        $ids = ['time_id' => (string) $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => (string) $crawler->filter('input[name="new_puzzle_id"]')->attr('value')];

        // No file in the request - only the token
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'photo_stash' => ['puzzlePhoto' => $token], ...$ids]);

        $this->assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        $database = self::getContainer()->get(Connection::class);
        $image = $database->fetchOne('SELECT image FROM puzzle WHERE id = :id', ['id' => $ids['new_puzzle_id']]);
        self::assertIsString($image, 'The new puzzle carries the uploaded box photo');
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($image));
    }

    /**
     * docs/features/upload-image-metadata.md "Read in the browser": the browser shrank the photo (no EXIF left in it) and
     * sent what it had read before with the upload's start
     */
    public function testWhatTheBrowserReadBeforeShrinkingIsStoredWithTheBackgroundUploadedPhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $token = $this->uploadedPhoto($browser, 'finished.jpg', self::browserSummary());

        $submission = $this->newPuzzleSubmission($crawler, 'Browser Metadata Puzzle');
        $ids = $this->ids($crawler);

        // Only the finish photo: the new puzzle takes it as its box photo too
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'photo_stash' => ['finishedPuzzlesPhoto' => $token], ...$ids]);
        $this->assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        $time = $this->jsonColumn('SELECT finished_puzzle_photo_upload_metadata FROM puzzle_solving_time WHERE id = :id', $ids['time_id']);
        self::assertSame('browser', $time['source']);
        self::assertSame('2026-10-21T14:03:12', $time['capturedAt']);
        self::assertSame('+02:00', $time['capturedAtOffset']);
        self::assertSame(['phone', 'Apple', 3_456_789, 4032], [$time['software'], $time['make'], $time['bytes'], $time['width']]);

        $puzzle = $this->jsonColumn('SELECT image_upload_metadata FROM puzzle WHERE id = :id', $ids['new_puzzle_id']);
        self::assertSame($time, $puzzle);
    }

    public function testAPhotoSentWithTheFormKeepsTheBrowsersSummaryThroughARefusedSubmit(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $submission = $this->newPuzzleSubmission($crawler, 'Form Metadata Puzzle');
        $refused = ['timeHours' => '', 'timeMinutes' => '', 'timeSeconds' => ''] + $submission;

        // The background upload could not work: the shrunk photo goes as a file, its summary as photo_metadata[...]
        $crawler = $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => $refused, 'photo_metadata' => ['finishedPuzzlesPhoto' => (string) json_encode(self::browserSummary())], ...$this->ids($crawler)],
            ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->uploadedFile(ExifPhotos::jpeg(40, 30, null))]],
        );
        $this->assertResponseStatusCodeSame(422);

        // The refused page shows the kept photo as a token - the browser no longer has the file nor its summary
        $token = (string) $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value');
        self::assertNotSame('', $token);
        $ids = $this->ids($crawler);

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'photo_stash' => ['finishedPuzzlesPhoto' => $token], ...$ids]);
        $this->assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        $time = $this->jsonColumn('SELECT finished_puzzle_photo_upload_metadata FROM puzzle_solving_time WHERE id = :id', $ids['time_id']);
        self::assertSame(['browser', '2026-10-21T14:03:12'], [$time['source'], $time['capturedAt']]);
    }

    public function testThePhotosOwnExifWinsOverWhatTheBrowserSays(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $claim = ['dateTimeOriginal' => '2020:01:01 00:00:00'] + self::browserSummary();
        $token = $this->uploadedPhoto($browser, 'finished.jpg', $claim, ExifPhotos::iphoneJpeg(40, 30));

        $ids = $this->ids($crawler);
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $this->newPuzzleSubmission($crawler, 'Server Metadata Puzzle'), 'photo_stash' => ['finishedPuzzlesPhoto' => $token], ...$ids]);
        $this->assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        $time = $this->jsonColumn('SELECT finished_puzzle_photo_upload_metadata FROM puzzle_solving_time WHERE id = :id', $ids['time_id']);
        self::assertSame(['server', '2026-10-21T14:03:12', 40], [$time['source'], $time['capturedAt'], $time['width']]);

        // The new puzzle's box photo is a copy of the finish photo: stripping one never empties the other's summary
        $puzzle = $this->jsonColumn('SELECT image_upload_metadata FROM puzzle WHERE id = :id', $ids['new_puzzle_id']);
        self::assertSame($time, $puzzle);
    }

    public function testABrokenSummaryNeverStopsTheUpload(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $answer = $this->start($browser, ['bytes' => 1000, 'type' => 'image/jpeg', 'metadata' => ['bytes' => 'a lot', 'gps' => [50.08, 14.42]]]);

        $this->assertResponseIsSuccessful();
        self::assertIsString($answer['token'] ?? null);
        self::assertNull(self::getContainer()->get(PhotoStash::class)->browserMetadata($answer['token'], PlayerFixture::PLAYER_REGULAR));
    }

    public function testTheSameFormSentAgainAfterItSavedIsAnsweredAsTheSavedResult(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $token = $this->uploadedPhoto($browser, 'box.jpg');
        $submission = $this->newPuzzleSubmission($crawler, 'Resent Upload Puzzle');
        $ids = ['time_id' => (string) $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => (string) $crawler->filter('input[name="new_puzzle_id"]')->attr('value')];

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'photo_stash' => ['puzzlePhoto' => $token], ...$ids]);
        $this->assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        // The answer got lost on the way: the browser sends exactly the same form again (the automatic retry). The photo is
        // still found, so this is no "photo lost" error but the result saved a moment ago
        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'photo_stash' => ['puzzlePhoto' => $token], ...$ids]);

        $this->assertResponseRedirects('/en/time-added/' . $ids['time_id']);
        $browser->followRedirect();
        self::assertStringContainsString('This result was already saved', (string) $browser->getResponse()->getContent());

        $database = self::getContainer()->get(Connection::class);
        self::assertSame(1, $database->fetchOne("SELECT COUNT(*) FROM puzzle WHERE name = 'Resent Upload Puzzle'"));
        self::assertSame(1, $database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => $ids['time_id']]));
    }

    public function testARefusedFormShowsTheUploadedPhotoAndKeepsItsToken(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $token = $this->uploadedPhoto($browser, 'finished.jpg');

        $submission = $this->newPuzzleSubmission($crawler, 'Refused Upload Puzzle');
        $submission['timeMinutes'] = '';
        $submission['timeHours'] = '';
        $submission['timeSeconds'] = '';
        unset($submission['puzzle']);

        $crawler = $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'photo_stash' => ['finishedPuzzlesPhoto' => $token]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame($token, $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value'));
        // Its preview was made from the uploaded photo
        self::assertCount(1, $crawler->filter('img[src="/en/photo-stash/' . $token . '"]'));
        $browser->request('GET', '/en/photo-stash/' . $token);
        $this->assertResponseIsSuccessful();
    }

    public function testAnotherPlayersUploadedPhotoDoesNotWork(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $token = $this->uploadedPhoto($browser, 'box.jpg');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $submission = $this->newPuzzleSubmission($crawler, 'Stolen Upload Puzzle');

        $crawler = $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, 'photo_stash' => ['puzzlePhoto' => $token]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('could not be kept', $crawler->filter('form')->text());
    }

    public function testStartingAnUploadAnswersWhereToPutThePhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $answer = $this->start($browser, ['bytes' => 512_000, 'type' => 'image/jpeg', 'name' => 'box.jpg']);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertIsString($answer['token'] ?? null);
        self::assertIsString($answer['url'] ?? null);
        self::assertStringContainsString('/tmp-uploads/' . PlayerFixture::PLAYER_REGULAR . '/' . $answer['token'] . '?', $answer['url']);
        self::assertSame(['Content-Type' => 'image/jpeg', 'Content-Length' => '512000'], $answer['headers'] ?? null);
        self::assertSame(512_000, self::getContainer()->get(PhotoStash::class)->reservedBytes($answer['token'], PlayerFixture::PLAYER_REGULAR));
    }

    public function testStartingAnUploadIsRefusedWithoutAReasonToTrustIt(): void
    {
        $browser = $this->browser();

        // Signed out: the sign-in page, never a URL
        $browser->request('POST', '/en/photo-upload', server: ['CONTENT_TYPE' => 'application/json'], content: '{"bytes": 1000, "type": "image/jpeg"}');
        self::assertFalse($browser->getResponse()->isSuccessful());

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Another site's page
        $answer = $this->start($browser, ['bytes' => 1000, 'type' => 'image/jpeg'], origin: 'https://evil.example');
        $this->assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf_token', $answer['error'] ?? null);

        // What the form would refuse anyway
        $answer = $this->start($browser, ['bytes' => 100_000_000, 'type' => 'image/jpeg']);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('too_large', $answer['error'] ?? null);

        $answer = $this->start($browser, ['bytes' => 1000, 'type' => 'text/html']);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('type', $answer['error'] ?? null);

        $answer = $this->start($browser, ['type' => 'image/jpeg']);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testTheKillSwitchSendsPhotosWithTheFormAgain(): void
    {
        $this->overrideFeatureFlagEnv('DIRECT_PHOTO_UPLOADS', false);

        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        self::assertCount(0, $crawler->filter('form[data-submit-prevention-upload-url-value]'));

        $this->start($browser, ['bytes' => 1000, 'type' => 'image/jpeg']);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTheBrowsersReportsAreCounted(): void
    {
        $browser = $this->browser();
        $handler = new TestHandler();
        /** @var Logger $logger */
        $logger = self::getContainer()->get('monolog.logger.photo_upload');
        $logger->pushHandler($handler);

        $this->report($browser, ['event' => 'upload', 'outcome' => 'done', 'bytes' => 812345, 'durationMs' => 98000, 'attempts' => 2, 'page' => '/en/puzzle-add/018d0003-0000-0000-0000-000000000001']);
        $this->assertResponseStatusCodeSame(204);
        $this->report($browser, ['event' => 'save', 'outcome' => 'failed', 'status' => 504, 'photosInForm' => 2]);
        $this->report($browser, ['event' => 'upload', 'outcome' => 'failed', 'reason' => 'store_refused', 'status' => 400]);
        $this->report($browser, ['event' => 'something-else']);
        $this->assertResponseStatusCodeSame(204);

        $records = $handler->getRecords();
        self::assertCount(3, $records);
        self::assertSame('Background photo upload', $records[0]->message);
        self::assertSame('/en/puzzle-add/{id}', $records[0]->context['page']);
        self::assertSame(812345, $records[0]->context['bytes']);
        self::assertSame('INFO', $records[1]->level->getName());
        self::assertSame(504, $records[1]->context['status']);
        self::assertSame('WARNING', $records[2]->level->getName(), 'Object storage refusing a signed upload is ours to look at');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function report(KernelBrowser $browser, array $payload): void
    {
        $browser->request('POST', '/-/photo-upload-report', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
    }

    /**
     * Kept photos live in the in-memory storage of the test kernel - it must survive between requests
     */
    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    /**
     * What the browser does when a photo is picked: start the upload, then PUT the photo where the URL points
     */
    /**
     * @param null|array<string, mixed> $metadata what the browser read from the photo before shrinking it
     */
    private function uploadedPhoto(KernelBrowser $browser, string $name, null|array $metadata = null, null|string $photo = null): string
    {
        $photo ??= $this->jpeg();
        $answer = $this->start($browser, ['bytes' => strlen($photo), 'type' => 'image/jpeg', 'name' => $name, ...($metadata !== null ? ['metadata' => $metadata] : [])]);
        $this->assertResponseIsSuccessful();

        $token = $answer['token'] ?? null;
        self::assertIsString($token);

        $container = self::getContainer();
        $playerId = $this->playerIdOfToken($answer);
        $container->get(Filesystem::class)->write($container->get(PhotoStash::class)->objectKey($playerId, $token), $photo);

        return $token;
    }

    /**
     * @param array<mixed> $answer
     */
    private function playerIdOfToken(array $answer): string
    {
        self::assertIsString($answer['url'] ?? null);
        self::assertSame(1, preg_match('~/tmp-uploads/([0-9a-f-]{36})/~', $answer['url'], $match));

        return $match[1];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function start(KernelBrowser $browser, array $body, string $origin = 'http://localhost'): array
    {
        $browser->request('POST', '/en/photo-upload', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_REFERER' => $origin . '/en/puzzle-add',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: (string) json_encode($body));

        $answer = json_decode((string) $browser->getResponse()->getContent(), true);

        return is_array($answer) ? $answer : [];
    }

    /**
     * @return array<string, string>
     */
    private function newPuzzleSubmission(Crawler $crawler, string $name): array
    {
        return [
            '_token' => (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => $name,
            'puzzlePiecesCount' => '500',
            'timeHours' => '0',
            'timeMinutes' => '41',
            'timeSeconds' => '13',
            'finishedAt' => '12.07.2026',
            'collection' => '__system_collection__',
        ];
    }

    /**
     * What assets/photo_metadata.js sends for a phone photo it is about to shrink
     *
     * @return array<string, mixed>
     */
    private static function browserSummary(): array
    {
        return [
            'bytes' => 3_456_789,
            'format' => 'JPEG',
            'width' => 4032,
            'height' => 3024,
            'exif' => true,
            'cameraExif' => true,
            'dateTimeOriginal' => '2026:10:21 14:03:12',
            'offsetTimeOriginal' => '+02:00',
            'software' => '18.0.1',
            'make' => 'Apple',
            'orientation' => 6,
        ];
    }

    /**
     * @return array{time_id: string, new_puzzle_id: string}
     */
    private function ids(Crawler $crawler): array
    {
        return [
            'time_id' => (string) $crawler->filter('input[name="time_id"]')->attr('value'),
            'new_puzzle_id' => (string) $crawler->filter('input[name="new_puzzle_id"]')->attr('value'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonColumn(string $sql, string $id): array
    {
        $json = self::getContainer()->get(Connection::class)->fetchOne($sql, ['id' => $id]);
        self::assertIsString($json);
        $data = json_decode($json, true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function uploadedFile(string $content): UploadedFile
    {
        return new UploadedFile(ExifPhotos::file($content), 'finished.jpg', 'image/jpeg', null, true);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(40, 30);
        assert($image !== false);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
