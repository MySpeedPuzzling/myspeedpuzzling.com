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
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\ExifPhotos;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * docs/features/photo-uploads/README.md "A photo the phone cannot read" - the server's side of it. The page takes a
 * photo of 0 bytes out of the form itself (assets/photo_readability.js, tests/PhotoReadabilityScriptTest.php); here:
 *
 * - the upload start's `empty` answer the page relies on
 * - the floor under the page: a photo part that still arrives without a byte counts as no photo - the result is saved,
 *   a new puzzle is still told that it needs its photo
 * - what the page says it took out while saving (`photo_dropped[<field>]`) said on the page that comes next, once
 * - the telemetry: the new fields, cut and cleaned, never above info
 */
final class UnreadablePhotoTest extends WebTestCase
{
    // TIME_06: PLAYER_REGULAR on PUZZLE_500_02, 36:40
    private const string EDIT_URL = '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_06;
    private const string LEFT_OUT = 'A photo could not be read from your device and was left out';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * assets/photo_upload.js reads this reason: `empty` is the one refusal that does not send the photo with the form
     */
    public function testStartingAnUploadOfNoBytesIsRefusedAsEmpty(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $handler = $this->photoUploadLog();

        foreach ([0, -5] as $bytes) {
            $answer = $this->start($browser, ['bytes' => $bytes, 'type' => 'image/jpeg', 'name' => 'IMG_0001.jpg']);

            $this->assertResponseStatusCodeSame(422);
            self::assertSame(['error' => 'empty'], $answer);
            self::assertStringContainsString('application/json', (string) $browser->getResponse()->headers->get('Content-Type'));
        }

        $records = $handler->getRecords();
        self::assertCount(2, $records);
        self::assertSame('Background photo upload refused', $records[0]->message);
        self::assertSame('INFO', $records[0]->level->getName());
        self::assertSame(['reason' => 'empty', 'bytes' => 0, 'type' => 'image/jpeg'], $records[0]->context);
    }

    public function testAnEmptyPhotoPartDoesNotStopAnExistingPuzzlesResult(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $handler = $this->photoUploadLog();

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');

        $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => $this->existingPuzzleSubmission($crawler), 'time_id' => $timeId],
            ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->upload('')]],
        );

        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        $database = self::getContainer()->get(Connection::class);
        $saved = $database->fetchAssociative('SELECT seconds_to_solve, finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertSame(['seconds_to_solve' => 41 * 60 + 13, 'finished_puzzle_photo' => null], $saved);

        // Said on the page that comes next
        $browser->followRedirect();
        $this->assertSelectorTextContains('body', self::LEFT_OUT);

        // Routine, countable: info on the photo_upload channel
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('INFO', $records[0]->level->getName());
        self::assertSame(['photo_dropped', 'empty', 'server', 'finishedPuzzlesPhoto'], [
            $records[0]->context['event'],
            $records[0]->context['reason'],
            $records[0]->context['detected_on'],
            $records[0]->context['field'],
        ]);
    }

    public function testANewPuzzleWithAnEmptyBoxPhotoIsStillToldThatItNeedsAPhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $ids = $this->ids($crawler);

        $crawler = $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => $this->newPuzzleSubmission($crawler, 'Empty Box Photo Puzzle'), ...$ids],
            ['puzzle_add_form' => ['puzzlePhoto' => $this->upload('')]],
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', 'Photo of solved puzzle, motif or box is required');
        $this->assertSelectorTextContains('body', self::LEFT_OUT);
        // Nothing of the empty file is kept for the next try
        self::assertCount(0, $crawler->filter('input[name="photo_stash[puzzlePhoto]"]'));

        $database = self::getContainer()->get(Connection::class);
        self::assertSame(0, $database->fetchOne("SELECT COUNT(*) FROM puzzle WHERE name = 'Empty Box Photo Puzzle'"));
        self::assertSame(0, $database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => $ids['time_id']]));
    }

    /**
     * Speed/relax mode: a new puzzle takes the finish photo as its box photo - one real photo is enough, the empty one
     * next to it is no reason to refuse
     */
    public function testANewPuzzleIsSavedWithItsRealPhotoNextToAnEmptyOne(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $ids = $this->ids($crawler);

        $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => $this->newPuzzleSubmission($crawler, 'One Real Photo Puzzle'), ...$ids],
            ['puzzle_add_form' => ['puzzlePhoto' => $this->upload(''), 'finishedPuzzlesPhoto' => $this->upload(ExifPhotos::jpeg(40, 30, null))]],
        );

        $this->assertResponseRedirects('/en/time-added/' . $ids['time_id']);

        $database = self::getContainer()->get(Connection::class);
        self::assertIsString($database->fetchOne('SELECT image FROM puzzle WHERE id = :id', ['id' => $ids['new_puzzle_id']]));
        self::assertIsString($database->fetchOne('SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => $ids['time_id']]));
    }

    /**
     * The photo uploaded in the background stands in for an empty part of the same field
     */
    public function testAnEmptyPhotoPartGivesWayToTheFieldsUploadedPhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');
        $token = $this->uploadedPhoto($browser);

        $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => $this->existingPuzzleSubmission($crawler), 'time_id' => $timeId, 'photo_stash' => ['finishedPuzzlesPhoto' => $token]],
            ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->upload('')]],
        );

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertIsString(self::getContainer()->get(Connection::class)->fetchOne('SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    /**
     * The page took the photo out while saving (`photo_dropped[<field>]`): its own note is gone with the form, so the
     * page that comes next says it - once, however often the same save is sent
     */
    public function testAPhotoThePageTookOutWhileSavingIsSaidOnceOnTheRecap(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');
        $request = ['puzzle_add_form' => $this->existingPuzzleSubmission($crawler), 'time_id' => $timeId, 'photo_dropped' => ['finishedPuzzlesPhoto' => 'empty']];

        $browser->request('POST', '/en/puzzle-add', $request);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        // The answer got lost: the automatic resend, then one more tap - the same save, the same form
        $browser->request('POST', '/en/puzzle-add', $request);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        $browser->request('POST', '/en/puzzle-add', $request);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);

        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame(1, substr_count((string) $browser->getResponse()->getContent(), self::LEFT_OUT));

        // Shown once: gone on the next page
        $browser->request('GET', '/en/puzzle-add');
        self::assertStringNotContainsString(self::LEFT_OUT, (string) $browser->getResponse()->getContent());
    }

    public function testAPhotoThePageTookOutIsSaidAfterAnEditOnThePageAndInTheModal(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // The full page: "Save without the photo", then the redirect
        $crawler = $browser->request('GET', self::EDIT_URL);
        $browser->request('POST', self::EDIT_URL, ['edit_puzzle_solving_time_form' => $this->editSubmission($crawler), 'photo_dropped' => ['finishedPuzzlesPhoto' => 'not_sent']]);
        $this->assertResponseRedirects();
        $this->followRedirects($browser);
        $this->assertSelectorTextContains('body', self::LEFT_OUT);

        // The modal: the answer closes it and refreshes the page behind it - which shows the flash
        $crawler = $browser->request('GET', self::EDIT_URL);
        $browser->request(
            'POST',
            self::EDIT_URL,
            ['edit_puzzle_solving_time_form' => $this->editSubmission($crawler), 'photo_dropped' => ['finishedPuzzlesPhoto' => 'unreadable']],
            server: ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'],
        );
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('<turbo-stream action="refresh">', (string) $browser->getResponse()->getContent());

        $browser->request('GET', '/en/puzzle-add');
        $this->assertSelectorTextContains('body', self::LEFT_OUT);
    }

    /**
     * Read strictly: a field of the form, a known reason, and no photo for the field in the request after all
     */
    public function testWhatThePageSaysItTookOutIsIgnoredUnlessItIsWhatThePageSends(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $garbage = [
            'an unknown reason' => ['finishedPuzzlesPhoto' => 'because'],
            'markup' => ['finishedPuzzlesPhoto' => '<script>alert(1)</script>'],
            'an unknown field' => ['comment' => 'empty', 'logo' => 'empty', 'photo' => 'empty'],
            'a list' => ['finishedPuzzlesPhoto' => ['empty']],
            'no fields at all' => 'empty',
        ];

        $seconds = 0;

        foreach ($garbage as $label => $dropped) {
            $crawler = $browser->request('GET', '/en/puzzle-add');
            $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');

            $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => ['timeSeconds' => (string) ++$seconds] + $this->existingPuzzleSubmission($crawler), 'time_id' => $timeId, 'photo_dropped' => $dropped]);
            $this->assertResponseRedirects('/en/time-added/' . $timeId, null, $label);
            $browser->followRedirect();
            self::assertStringNotContainsString(self::LEFT_OUT, (string) $browser->getResponse()->getContent(), $label);
        }

        // The photo came after all (picked again, or uploaded): nothing was left out
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');
        $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => ['timeSeconds' => (string) ++$seconds] + $this->existingPuzzleSubmission($crawler), 'time_id' => $timeId, 'photo_dropped' => ['finishedPuzzlesPhoto' => 'empty']],
            ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->upload(ExifPhotos::jpeg(40, 30, null))]],
        );
        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        $browser->followRedirect();
        self::assertStringNotContainsString(self::LEFT_OUT, (string) $browser->getResponse()->getContent());

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');
        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => ['timeSeconds' => (string) ++$seconds] + $this->existingPuzzleSubmission($crawler),
            'time_id' => $timeId,
            'photo_dropped' => ['finishedPuzzlesPhoto' => 'empty'],
            'photo_stash' => ['finishedPuzzlesPhoto' => $this->uploadedPhoto($browser)],
        ]);
        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        $browser->followRedirect();
        self::assertStringNotContainsString(self::LEFT_OUT, (string) $browser->getResponse()->getContent());
    }

    /**
     * A new puzzle whose photo the page took out: the usual "photo required", and the reason on the same page
     */
    public function testANewPuzzleWhosePhotoThePageTookOutIsToldWhy(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->newPuzzleSubmission($crawler, 'Taken Out Box Photo Puzzle'),
            ...$this->ids($crawler),
            'photo_dropped' => ['puzzlePhoto' => 'unreadable'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', 'Photo of solved puzzle, motif or box is required');
        $this->assertSelectorTextContains('body', self::LEFT_OUT);
    }

    public function testAnEmptyPhotoPartDoesNotStopAnEdit(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $photoBefore = $database->fetchOne('SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_06]);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $browser->request(
            'POST',
            self::EDIT_URL,
            ['edit_puzzle_solving_time_form' => ['comment' => 'Saved although the photo was empty'] + $this->editSubmission($crawler)],
            ['edit_puzzle_solving_time_form' => ['finishedPuzzlesPhoto' => $this->upload('')]],
        );

        $this->assertResponseRedirects();

        $saved = $database->fetchAssociative('SELECT comment, finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_06]);
        self::assertSame(['comment' => 'Saved although the photo was empty', 'finished_puzzle_photo' => $photoBefore], $saved);
    }

    /**
     * Only a part without a byte is left out: any other photo the form refuses keeps its own message
     */
    public function testAPhotoThatIsNoPhotoIsStillRefusedByTheForm(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');

        $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => $this->existingPuzzleSubmission($crawler), 'time_id' => $timeId],
            ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->upload('not a photo at all')]],
        );

        $this->assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString(self::LEFT_OUT, (string) $browser->getResponse()->getContent());
        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testDroppedPhotosAndTheBrowsersErrorsAreReported(): void
    {
        $browser = $this->browser();
        $handler = $this->photoUploadLog();

        // The page took an empty photo out when it was picked
        $this->report($browser, ['event' => 'photo_dropped', 'reason' => 'empty', 'detectedOn' => 'pick', 'field' => 'finishedPuzzlesPhoto', 'bytes' => 0, 'page' => '/en/puzzle-add']);
        $this->assertResponseStatusCodeSame(204);
        // One it could not read, right before the request
        $this->report($browser, ['event' => 'photo_dropped', 'reason' => 'unreadable', 'detectedOn' => 'save', 'field' => 'puzzlePhoto', 'bytes' => 2_345_678, 'error' => 'NotReadableError: The requested file could not be read']);
        // A save that died in the browser, and the message the player got
        $this->report($browser, ['event' => 'save', 'outcome' => 'failed', 'status' => 0, 'durationMs' => 45, 'photosInForm' => 1, 'error' => 'TypeError: Failed to fetch', 'failure' => 'photo']);
        // The save without the photo that followed: saved, never "failed"
        $this->report($browser, ['event' => 'save', 'outcome' => 'saved', 'status' => 200, 'photosInForm' => 0, 'photosDropped' => 1, 'droppedOn' => 'save_without']);
        // An upload whose start never got through
        $this->report($browser, ['event' => 'upload', 'outcome' => 'failed', 'reason' => 'network', 'error' => 'TypeError: NetworkError when attempting to fetch resource.']);

        $records = $handler->getRecords();
        self::assertCount(5, $records);

        foreach ($records as $record) {
            self::assertSame('INFO', $record->level->getName(), 'A photo a phone cannot read is routine - never a Sentry issue');
        }

        self::assertSame('Photo the device could not read was taken out of the form', $records[0]->message);
        self::assertSame(['photo_dropped', 'empty', 'pick', 'finishedPuzzlesPhoto', 0, null], [
            $records[0]->context['event'],
            $records[0]->context['reason'],
            $records[0]->context['detected_on'],
            $records[0]->context['field'],
            $records[0]->context['bytes'],
            $records[0]->context['error'],
        ]);

        self::assertSame(['unreadable', 'save', 'NotReadableError: The requested file could not be read'], [
            $records[1]->context['reason'],
            $records[1]->context['detected_on'],
            $records[1]->context['error'],
        ]);

        self::assertSame('Add/edit time form save', $records[2]->message);
        self::assertSame(['failed', 0, 'TypeError: Failed to fetch', 'photo'], [
            $records[2]->context['outcome'],
            $records[2]->context['status'],
            $records[2]->context['error'],
            $records[2]->context['failure'],
        ]);

        self::assertSame(['saved', 1, 'save_without', null], [
            $records[3]->context['outcome'],
            $records[3]->context['photos_dropped'],
            $records[3]->context['dropped_on'],
            $records[3]->context['error'],
        ]);

        self::assertSame('Background photo upload', $records[4]->message);
        self::assertSame('TypeError: NetworkError when attempting to fetch resource.', $records[4]->context['error']);
    }

    public function testWhatTheBrowserSaysAboutAnErrorIsCutAndCleaned(): void
    {
        $browser = $this->browser();
        $handler = $this->photoUploadLog();

        $this->report($browser, ['event' => 'save', 'outcome' => 'failed', 'error' => 'TypeError: ' . str_repeat('ž', 300)]);
        $this->report($browser, ['event' => 'save', 'outcome' => 'failed', 'error' => "Error: could not read\n file:///storage/emulated/0/DCIM/Camera/IMG_1.jpg nor\tblob:https://example.com/018d0003-0000-0000-0000-000000000001 nor C:\\Users\\Someone\\Pictures\\b.jpg nor /data/user/0/cache/c.jpg of 018d0003-0000-0000-0000-000000000002"]);
        // Garbage in every new field
        $this->report($browser, ['event' => 'photo_dropped', 'reason' => ['empty'], 'detectedOn' => str_repeat('x', 500), 'error' => ['name' => 'TypeError'], 'failure' => 7, 'photosDropped' => 'many', 'droppedOn' => null, 'bytes' => 'none']);
        $this->report($browser, ['event' => 'save', 'outcome' => 'failed', 'error' => "  \n\t "]);
        $browser->request('POST', '/-/photo-upload-report', server: ['CONTENT_TYPE' => 'application/json'], content: '{"event":"save","outcome":"failed","error":"' . "\xB1\x31" . ' not UTF-8"}');
        $this->assertResponseStatusCodeSame(204);

        $records = $handler->getRecords();
        self::assertCount(4, $records, 'A body that is no JSON (invalid UTF-8) is no report at all');

        $longError = $records[0]->context['error'];
        self::assertIsString($longError);
        self::assertSame(200, mb_strlen($longError));
        self::assertStringStartsWith('TypeError: žžž', $longError);

        self::assertSame('Error: could not read [url] nor [url] nor [path] nor [path] of {id}', $records[1]->context['error']);

        self::assertSame('INFO', $records[2]->level->getName());
        self::assertSame([null, str_repeat('x', 20), null, null, null, null, null], [
            $records[2]->context['reason'],
            $records[2]->context['detected_on'],
            $records[2]->context['error'],
            $records[2]->context['failure'],
            $records[2]->context['photos_dropped'],
            $records[2]->context['dropped_on'],
            $records[2]->context['bytes'],
        ]);

        self::assertNull($records[3]->context['error']);
    }

    public function testTheFormsCarryTheTextsForAPhotoThatCannotBeRead(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['/en/puzzle-add', self::EDIT_URL] as $url) {
            $form = $browser->request('GET', $url)->filter('form[data-controller~="submit-prevention"]');
            self::assertCount(1, $form, $url);

            self::assertStringContainsString('could not be read from your device', (string) $form->attr('data-submit-prevention-photo-unreadable-text-value'), $url);
            self::assertStringContainsString('Save without it', (string) $form->attr('data-submit-prevention-photo-unreadable-text-value'), $url);
            // The box photo of a new puzzle is required - no "save without it" there
            self::assertStringNotContainsStringIgnoringCase('save without', (string) $form->attr('data-submit-prevention-photo-unreadable-required-text-value'), $url);
            // Short enough to read on a phone
            self::assertLessThanOrEqual(120, mb_strlen((string) $form->attr('data-submit-prevention-photo-unreadable-text-value')), $url);
            self::assertLessThanOrEqual(120, mb_strlen((string) $form->attr('data-submit-prevention-photo-unreadable-required-text-value')), $url);

            // The first failure at once names both; only the second one in a row the photo
            self::assertStringContainsString('It may be the connection, or the photo could not be sent', (string) $form->attr('data-submit-prevention-save-failed-either-text-value'), $url);
            self::assertStringContainsString('again - it looks like the photo could not be sent', (string) $form->attr('data-submit-prevention-save-failed-photo-text-value'), $url);
            self::assertSame('Try again', $form->attr('data-submit-prevention-try-again-text-value'), $url);
            self::assertSame('Save without the photo', $form->attr('data-submit-prevention-save-without-photo-text-value'), $url);
            // A new puzzle's photo cannot be left out: its own texts, which promise no save without it
            foreach (['photo-required', 'either-required'] as $text) {
                $required = (string) $form->attr('data-submit-prevention-save-failed-' . $text . '-text-value');
                self::assertStringContainsString('A new puzzle needs its photo', $required, $url);
                self::assertStringNotContainsStringIgnoringCase('save without', $required, $url);
            }
            // The message for what it was written for stays
            self::assertStringContainsString('the connection was too slow or was interrupted', (string) $form->attr('data-submit-prevention-save-failed-text-value'), $url);
        }
    }

    private function followRedirects(KernelBrowser $browser): void
    {
        for ($hops = 0; $hops < 5 && $browser->getResponse()->isRedirection(); $hops++) {
            $browser->followRedirect();
        }
    }

    private function photoUploadLog(): TestHandler
    {
        $handler = new TestHandler();
        /** @var Logger $logger */
        $logger = self::getContainer()->get('monolog.logger.photo_upload');
        $logger->pushHandler($handler);

        return $handler;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function report(KernelBrowser $browser, array $payload): void
    {
        $browser->request('POST', '/-/photo-upload-report', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Kept photos and log handlers live in the test kernel - it must survive between requests
     */
    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function start(KernelBrowser $browser, array $body): array
    {
        $browser->request('POST', '/en/photo-upload', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost/en/puzzle-add',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: (string) json_encode($body));

        $answer = json_decode((string) $browser->getResponse()->getContent(), true);

        return is_array($answer) ? $answer : [];
    }

    /**
     * What the browser does when a photo is picked: start the upload, then PUT the photo where the URL points
     */
    private function uploadedPhoto(KernelBrowser $browser): string
    {
        $photo = ExifPhotos::jpeg(40, 30, null);
        $answer = $this->start($browser, ['bytes' => strlen($photo), 'type' => 'image/jpeg', 'name' => 'finished.jpg']);
        $this->assertResponseIsSuccessful();

        $token = $answer['token'] ?? null;
        self::assertIsString($token);

        $container = self::getContainer();
        $container->get(Filesystem::class)->write($container->get(PhotoStash::class)->objectKey(PlayerFixture::PLAYER_REGULAR, $token), $photo);

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function existingPuzzleSubmission(Crawler $crawler): array
    {
        return [
            '_token' => (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '0',
            'timeMinutes' => '41',
            'timeSeconds' => '13',
            'finishedAt' => '12.07.2026',
            'collection' => '__system_collection__',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function editSubmission(Crawler $crawler): array
    {
        return [
            '_token' => (string) $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value'),
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_02,
            'timeHours' => '0',
            'timeMinutes' => '36',
            'timeSeconds' => '40',
            'finishedAt' => '12.07.2026',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function newPuzzleSubmission(Crawler $crawler, string $name): array
    {
        return ['puzzle' => $name, 'puzzlePiecesCount' => '500'] + $this->existingPuzzleSubmission($crawler);
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
     * A photo part as PHP hands it over - `''` is the phone's photo without a single byte
     */
    private function upload(string $content): UploadedFile
    {
        $path = ExifPhotos::file($content);
        $this->files[] = $path;

        return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
    }
}
