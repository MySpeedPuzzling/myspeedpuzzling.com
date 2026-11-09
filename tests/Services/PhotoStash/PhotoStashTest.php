<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PhotoStash;

use SpeedPuzzling\Web\Tests\TestClock;
use Imagick;
use League\Flysystem\Filesystem;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\BrowserImageSummary;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\ClockedInMemoryFilesystemAdapter;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * docs/features/first-try-integrity.md "Photos survive a refused form" + docs/features/photo-uploads/README.md.
 * Asserts what a player gets back (restore, describe, preview, reservations), never how the stash stores it.
 */
final class PhotoStashTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_STRIPE;

    private Filesystem $filesystem;
    private MockClock $clock;
    private PhotoStash $stash;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->clock = new MockClock(TestClock::now());
        $this->filesystem = new Filesystem(new ClockedInMemoryFilesystemAdapter($this->clock));
        $this->stash = $this->newStash($this->filesystem);
    }

    private function newStash(Filesystem $filesystem): PhotoStash
    {
        return new PhotoStash($filesystem, $this->clock, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->stash->reset();
    }

    public function testAKeptPhotoComesBackAsAnUpload(): void
    {
        $photo = $this->stash->keep($this->photo(), self::PLAYER);

        self::assertNotNull($photo);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $photo->token);
        self::assertSame('finished.jpg', $photo->fileName);
        self::assertTrue($photo->hasPreview);

        $restored = $this->stash->restore($photo->token, self::PLAYER);

        self::assertNotNull($restored);
        self::assertTrue($restored->isValid());
        self::assertSame('finished.jpg', $restored->getClientOriginalName());
        self::assertSame('image/jpeg', $restored->getMimeType());

        $preview = $this->stash->previewStream($photo->token, self::PLAYER);
        self::assertIsResource($preview);
        self::assertSame("\xFF\xD8", (string) fread($preview, 2), 'The preview is a JPEG');
    }

    public function testATokenOnlyWorksForItsPlayer(): void
    {
        $photo = $this->stash->keep($this->photo(), self::PLAYER);
        self::assertNotNull($photo);

        self::assertNull($this->stash->restore($photo->token, self::OTHER_PLAYER));
        self::assertNull($this->stash->describe($photo->token, self::OTHER_PLAYER));
        self::assertNull($this->stash->previewStream($photo->token, self::OTHER_PLAYER));
    }

    public function testAnythingButATokenIsRefused(): void
    {
        $this->stash->keep($this->photo(), self::PLAYER);

        self::assertNull($this->stash->restore('../' . self::OTHER_PLAYER . '/x', self::PLAYER));
        self::assertNull($this->stash->describe('ABC', self::PLAYER));
    }

    public function testAKeptPhotoExpires(): void
    {
        $photo = $this->stash->keep($this->photo(), self::PLAYER);
        self::assertNotNull($photo);

        $this->clock->modify('+' . (PhotoStash::KEEP_HOURS + 1) . ' hours');

        self::assertNull($this->stash->restore($photo->token, self::PLAYER));
    }

    public function testDiscardRemovesEverything(): void
    {
        $photo = $this->stash->keep($this->photo(), self::PLAYER);
        self::assertNotNull($photo);

        $this->stash->discard($photo->token, self::PLAYER);

        self::assertSame([], $this->files());
    }

    public function testOnlyAFewPhotosPerPlayerAreKept(): void
    {
        $theirs = $this->stash->keep($this->photo(), self::OTHER_PLAYER);
        self::assertNotNull($theirs);

        $first = $this->stash->keep($this->photo(), self::PLAYER);
        self::assertNotNull($first);
        $later = [];

        for ($i = 0; $i < 20; $i++) {
            $this->clock->modify('+1 second');
            $photo = $this->stash->keep($this->photo(), self::PLAYER);
            self::assertNotNull($photo);
            $later[] = $photo->token;
        }

        // The oldest made way for the 21st
        self::assertNull($this->stash->restore($first->token, self::PLAYER));
        self::assertNull($this->stash->describe($first->token, self::PLAYER));
        self::assertNull($this->stash->previewStream($first->token, self::PLAYER));

        foreach ($later as $token) {
            self::assertNotNull($this->stash->restore($token, self::PLAYER), 'The 20 newest are all kept');
        }

        self::assertNotNull($this->stash->restore($theirs->token, self::OTHER_PLAYER), 'Another player has their own room');
    }

    public function testReservationsAndKeptPhotosShareTheCap(): void
    {
        $kept = [];

        for ($i = 0; $i < 10; $i++) {
            $photo = $this->stash->keep($this->photo(), self::PLAYER);
            self::assertNotNull($photo);
            $kept[] = $photo->token;
            $this->clock->modify('+1 second');
        }

        $reserved = [];

        for ($i = 0; $i < 11; $i++) {
            $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 1000 + $i);
            self::assertNotNull($token);
            $reserved[] = $token;
            $this->clock->modify('+1 second');
        }

        self::assertNull($this->stash->describe($kept[0], self::PLAYER), 'The oldest kept photo made way for the 11th reservation');

        foreach (array_slice($kept, 1) as $token) {
            self::assertNotNull($this->stash->describe($token, self::PLAYER));
        }

        foreach ($reserved as $i => $token) {
            self::assertSame(1000 + $i, $this->stash->reservedBytes($token, self::PLAYER));
        }
    }

    public function testAPreviewMakesAPhotoRecentAgain(): void
    {
        $first = $this->stash->reserve(self::PLAYER, 'first.jpg', 'image/jpeg', 1000);
        self::assertNotNull($first);
        $others = [];

        for ($i = 0; $i < 19; $i++) {
            $this->clock->modify('+1 second');
            $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 2000);
            self::assertNotNull($token);
            $others[] = $token;
        }

        $this->clock->modify('+1 second');
        self::assertNotNull($this->stash->addPreview($first, self::PLAYER, $this->photo()));

        $this->clock->modify('+1 second');
        self::assertNotNull($this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 3000));

        self::assertSame(1000, $this->stash->reservedBytes($first, self::PLAYER), 'Shown again a moment ago - kept');
        self::assertNull($this->stash->reservedBytes($others[0], self::PLAYER), 'Now the oldest - made way');

        foreach (array_slice($others, 1) as $token) {
            self::assertSame(2000, $this->stash->reservedBytes($token, self::PLAYER));
        }
    }

    public function testAPreviewDoesNotExtendTheExpiry(): void
    {
        $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);
        self::assertNotNull($token);
        $this->filesystem->write($this->stash->objectKey(self::PLAYER, $token), (string) file_get_contents($this->photo()->getPathname()));

        $this->clock->modify('+23 hours');
        self::assertNotNull($this->stash->addPreview($token, self::PLAYER, $this->photo()));
        self::assertNotNull($this->stash->restore($token, self::PLAYER));

        $this->clock->modify('+2 hours');
        self::assertNull($this->stash->restore($token, self::PLAYER), 'Expires KEEP_HOURS after it was made');
        self::assertNull($this->stash->describe($token, self::PLAYER));
    }

    public function testEveryReadRefusesAnotherPlayersToken(): void
    {
        $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 1000, $this->summary('2026:10:21 14:03:12'));
        self::assertNotNull($token);
        $this->filesystem->write($this->stash->objectKey(self::PLAYER, $token), (string) file_get_contents($this->photo()->getPathname()));
        self::assertNotNull($this->stash->addPreview($token, self::PLAYER, $this->photo()));

        self::assertNull($this->stash->describe($token, self::OTHER_PLAYER));
        self::assertNull($this->stash->restore($token, self::OTHER_PLAYER));
        self::assertNull($this->stash->previewStream($token, self::OTHER_PLAYER));
        self::assertNull($this->stash->browserMetadata($token, self::OTHER_PLAYER));
        self::assertNull($this->stash->reservedBytes($token, self::OTHER_PLAYER));
        self::assertNull($this->stash->addPreview($token, self::OTHER_PLAYER, $this->photo()));

        // ... and all of it still works for its player
        self::assertNotNull($this->stash->describe($token, self::PLAYER));
        self::assertNotNull($this->stash->restore($token, self::PLAYER));
        self::assertIsResource($this->stash->previewStream($token, self::PLAYER));
        self::assertNotNull($this->stash->browserMetadata($token, self::PLAYER));
        self::assertSame(1000, $this->stash->reservedBytes($token, self::PLAYER));
    }

    public function testTheReservationsBrowserSummaryWinsOverALaterOne(): void
    {
        $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 1000, $this->summary('2026:10:21 14:03:12'));
        self::assertNotNull($token);

        self::assertNotNull($this->stash->addPreview($token, self::PLAYER, $this->photo(), $this->summary('2020:01:01 00:00:00')));

        self::assertSame('2026-10-21T14:03:12', $this->stash->browserMetadata($token, self::PLAYER)?->capturedAt);
    }

    public function testALaterBrowserSummaryIsKeptWhenTheReservationHadNone(): void
    {
        $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);
        self::assertNotNull($token);
        self::assertNull($this->stash->browserMetadata($token, self::PLAYER));

        self::assertNotNull($this->stash->addPreview($token, self::PLAYER, $this->photo(), $this->summary('2020:01:01 00:00:00')));

        self::assertSame('2020-01-01T00:00:00', $this->stash->browserMetadata($token, self::PLAYER)?->capturedAt);
    }

    public function testAKeptPhotoKeepsItsBrowserSummary(): void
    {
        $photo = $this->stash->keep($this->photo(), self::PLAYER, $this->summary('2026:10:21 14:03:12'));
        self::assertNotNull($photo);

        self::assertSame('2026-10-21T14:03:12', $this->stash->browserMetadata($photo->token, self::PLAYER)?->capturedAt);
        self::assertSame(['finished.jpg', true], [$this->stash->describe($photo->token, self::PLAYER)?->fileName, $this->stash->describe($photo->token, self::PLAYER)?->hasPreview]);
    }

    public function testADiscardedReservationIsGone(): void
    {
        $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);
        self::assertNotNull($token);
        $this->filesystem->write($this->stash->objectKey(self::PLAYER, $token), (string) file_get_contents($this->photo()->getPathname()));

        $this->stash->discard($token, self::PLAYER);

        self::assertNull($this->stash->reservedBytes($token, self::PLAYER));
        self::assertNull($this->stash->restore($token, self::PLAYER));
        self::assertSame([], $this->files(), 'An explicit discard lets the photo go at once');
    }

    public function testNotAnImageIsKeptWithoutPreview(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'stash-text-');
        file_put_contents($path, 'not an image');

        $photo = $this->stash->keep(new UploadedFile($path, 'notes.txt', 'text/plain', null, true), self::PLAYER);

        self::assertNotNull($photo);
        self::assertFalse($photo->hasPreview);
        self::assertNull($this->stash->previewStream($photo->token, self::PLAYER));
    }

    public function testPruneRemovesWhatNobodyCameBackFor(): void
    {
        $photo = $this->stash->keep($this->photo(), self::PLAYER);
        self::assertNotNull($photo);
        self::assertSame(0, $this->stash->prune());
        self::assertNotNull($this->stash->restore($photo->token, self::PLAYER), 'A fresh photo survives the prune');

        $this->clock->modify('+2 days');

        self::assertGreaterThan(0, $this->stash->prune());
        self::assertSame([], $this->files(), 'Everything of the photo is gone');
        self::assertNull($this->stash->describe($photo->token, self::PLAYER));
    }

    public function testADirectUploadIsRestoredLikeAKeptPhoto(): void
    {
        $token = $this->stash->reserve(self::PLAYER, 'box.jpg', 'image/jpeg', 1234);
        self::assertNotNull($token);
        self::assertSame(1234, $this->stash->reservedBytes($token, self::PLAYER));
        self::assertNull($this->stash->reservedBytes($token, self::OTHER_PLAYER), 'A reservation is only its player\'s');

        // Nothing uploaded yet - nothing to restore
        self::assertNull($this->stash->restore($token, self::PLAYER));

        // The browser's PUT lands under the object key
        self::assertSame('tmp-uploads/' . self::PLAYER . '/' . $token, $this->stash->objectKey(self::PLAYER, $token));
        $this->filesystem->write($this->stash->objectKey(self::PLAYER, $token), (string) file_get_contents($this->photo()->getPathname()));

        $restored = $this->stash->restore($token, self::PLAYER);
        self::assertNotNull($restored);
        self::assertSame('box.jpg', $restored->getClientOriginalName());
        self::assertSame('image/jpeg', $restored->getMimeType());

        // No preview until a refused form shows it again
        self::assertNull($this->stash->previewStream($token, self::PLAYER));
        $described = $this->stash->addPreview($token, self::PLAYER, $restored);
        self::assertNotNull($described);
        self::assertTrue($described->hasPreview);
        self::assertIsResource($this->stash->previewStream($token, self::PLAYER));
        self::assertSame(1234, $this->stash->reservedBytes($token, self::PLAYER), 'The preview keeps the reservation');
    }

    public function testAKeptPhotoIsNoReservation(): void
    {
        $photo = $this->stash->keep($this->photo(), self::PLAYER);
        self::assertNotNull($photo);

        self::assertNull($this->stash->reservedBytes($photo->token, self::PLAYER));
    }

    private function summary(string $dateTimeOriginal): UploadedImageMetadata
    {
        $summary = BrowserImageSummary::fromInput([
            'bytes' => 3_456_789,
            'format' => 'JPEG',
            'width' => 4032,
            'height' => 3024,
            'exif' => true,
            'cameraExif' => true,
            'dateTimeOriginal' => $dateTimeOriginal,
            'make' => 'Apple',
        ]);
        self::assertNotNull($summary);

        return $summary;
    }

    private function photo(): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'stash-photo-');
        $image = new Imagick();
        $image->newImage(800, 600, 'teal');
        $image->setImageFormat('jpeg');
        $image->writeImage($path);
        $image->destroy();

        return new UploadedFile($path, 'finished.jpg', 'image/jpeg', null, true);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $paths = [];

        foreach ($this->filesystem->listContents('tmp-uploads', true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }

        return $paths;
    }
}
