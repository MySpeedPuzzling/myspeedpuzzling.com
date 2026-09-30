<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PhotoStash;

use Imagick;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PhotoStashTest extends TestCase
{
    private const string PLAYER = '018d0000-0000-0000-0000-000000000001';
    private const string OTHER_PLAYER = '018d0000-0000-0000-0000-000000000005';

    private Filesystem $filesystem;
    private MockClock $clock;
    private PhotoStash $stash;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->clock = new MockClock();
        $this->stash = new PhotoStash($this->filesystem, $this->clock, new NullLogger());
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
        $first = $this->stash->keep($this->photo(), self::PLAYER);
        self::assertNotNull($first);

        for ($i = 0; $i < 6; $i++) {
            $this->clock->modify('+1 second');
            $this->stash->keep($this->photo(), self::PLAYER);
        }

        self::assertCount(6, array_filter($this->files(), static fn(string $path): bool => str_ends_with($path, '.json')));
        self::assertNotNull($this->stash->keep($this->photo(), self::OTHER_PLAYER), 'Another player has their own room');
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
        $this->stash->keep($this->photo(), self::PLAYER);
        self::assertSame(0, $this->stash->prune());

        $this->clock->modify('+2 days');

        self::assertSame(3, $this->stash->prune());
        self::assertSame([], $this->files());
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
