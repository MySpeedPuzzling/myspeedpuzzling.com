<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Imagick;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Exceptions\OnlineReferenceImageUnreadable;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\DeleteOnlineReferenceImage;
use SpeedPuzzling\Web\Message\RemoveOnlineRoundReferenceImage;
use SpeedPuzzling\Web\Message\SetOnlineRoundReferenceImage;
use SpeedPuzzling\Web\MessageHandler\DeleteOnlineReferenceImageHandler;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReferenceImages;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\ImagesWithMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The organiser's reference image of an online round (docs/features/online-competitions/README.md §5.8,
 * OnlineReferenceImages): stored as a JPEG under a new unguessable key of the round, the replaced or removed one deleted
 * only after the commit (DeleteOnlineReferenceImage, async) - never one a round still holds; a damaged picture stores
 * nothing.
 */
final class OnlineRoundReferenceImageHandlersTest extends KernelTestCase
{
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAnUploadIsStoredAsAJpegUnderAKeyOfItsOwn(): void
    {
        $this->bus()->dispatch(new SetOnlineRoundReferenceImage(self::ROUND, $this->upload(ImagesWithMetadata::png(4_000, 2_000), 'motif.png')));

        $key = $this->referenceImage();
        self::assertNotNull($key);
        self::assertMatchesRegularExpression(
            '~^online/' . OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP . '/' . self::ROUND . '/reference/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$~',
            $key,
        );
        self::assertTrue(OnlineReferenceImages::isObjectKey($key));

        $stored = new Imagick();
        $stored->readImageBlob($this->filesystem()->read($key));
        self::assertSame(['JPEG', OnlineReferenceImages::MAX_DIMENSION, 1_500], [$stored->getImageFormat(), $stored->getImageWidth(), $stored->getImageHeight()]);
        self::assertSame([], $this->transport()->getSent(), 'nothing to delete');
    }

    public function testReplacingDeletesTheOldObjectOnlyAfterTheCommit(): void
    {
        $this->bus()->dispatch(new SetOnlineRoundReferenceImage(self::ROUND, $this->upload(ImagesWithMetadata::png(800, 600), 'first.png')));
        $first = $this->referenceImage();
        self::assertNotNull($first);

        $this->bus()->dispatch(new SetOnlineRoundReferenceImage(self::ROUND, $this->upload(ImagesWithMetadata::jpeg(800, 600), 'second.jpg')));
        $second = $this->referenceImage();
        self::assertNotNull($second);
        self::assertNotSame($first, $second, 'a new key for every upload');

        // Queued after the commit, not done in the handler: both objects are still there
        self::assertSame([$first], $this->queuedDeletions());
        self::assertTrue($this->filesystem()->fileExists($first));

        $this->deleteQueued();

        self::assertFalse($this->filesystem()->fileExists($first));
        self::assertTrue($this->filesystem()->fileExists($second));
    }

    public function testRemovingGoesBackToTheCatalogueImage(): void
    {
        $this->bus()->dispatch(new SetOnlineRoundReferenceImage(self::ROUND, $this->upload(ImagesWithMetadata::png(800, 600), 'motif.png')));
        $key = $this->referenceImage();
        self::assertNotNull($key);

        $this->bus()->dispatch(new RemoveOnlineRoundReferenceImage(self::ROUND));

        self::assertNull($this->referenceImage());
        self::assertSame([$key], $this->queuedDeletions());
        $this->deleteQueued();
        self::assertFalse($this->filesystem()->fileExists($key));

        // Nothing to remove: nothing happens
        $this->bus()->dispatch(new RemoveOnlineRoundReferenceImage(self::ROUND));
        self::assertSame([], $this->queuedDeletions());
    }

    public function testADamagedPictureStoresNothing(): void
    {
        $this->bus()->dispatch(new SetOnlineRoundReferenceImage(self::ROUND, $this->upload(ImagesWithMetadata::png(800, 600), 'motif.png')));
        $key = $this->referenceImage();

        // A JPEG's first bytes, then nothing a decoder can read
        $damaged = "\xFF\xD8\xFF\xE0" . str_repeat("\x13\x37", 2_000);

        try {
            $this->bus()->dispatch(new SetOnlineRoundReferenceImage(self::ROUND, $this->upload($damaged, 'broken.jpg')));
            self::fail('A damaged picture is refused');
        } catch (OnlineReferenceImageUnreadable) {
        }

        self::assertSame($key, $this->referenceImage(), 'the reference before stays');
        self::assertSame([$key], $this->storedReferences());
        self::assertSame([], $this->queuedDeletions());
    }

    public function testTheDeletionKeepsWhatARoundStillHolds(): void
    {
        $this->bus()->dispatch(new SetOnlineRoundReferenceImage(self::ROUND, $this->upload(ImagesWithMetadata::png(800, 600), 'motif.png')));
        $key = $this->referenceImage();
        self::assertNotNull($key);

        // A replacement that did not commit after all: the round still points at it
        $this->deleteHandler()(new DeleteOnlineReferenceImage($key));
        self::assertTrue($this->filesystem()->fileExists($key));

        // Never anything but a reference image
        $this->filesystem()->write('players/someone.jpg', 'a picture');
        $this->deleteHandler()(new DeleteOnlineReferenceImage('players/someone.jpg'));
        self::assertTrue($this->filesystem()->fileExists('players/someone.jpg'));
    }

    public function testARoundWithoutOnlineTimingHasNoReferenceImage(): void
    {
        $this->expectException(OnlineRoundNotFound::class);

        $this->bus()->dispatch(new SetOnlineRoundReferenceImage(CompetitionRoundFixture::ROUND_WJPC_FINAL, $this->upload(ImagesWithMetadata::png(800, 600), 'motif.png')));
    }

    private function upload(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'reference_');
        assert(is_string($path));
        file_put_contents($path, $content);
        $this->files[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function referenceImage(): null|string
    {
        $key = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT reference_image FROM online_round WHERE round_id = :round',
            ['round' => self::ROUND],
        );
        assert($key === null || is_string($key));

        return $key;
    }

    /**
     * @return list<string>
     */
    private function storedReferences(): array
    {
        $keys = [];

        foreach ($this->filesystem()->listContents('online/' . OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP . '/' . self::ROUND . '/reference', true) as $item) {
            $keys[] = $item->path();
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function queuedDeletions(): array
    {
        $keys = [];

        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof DeleteOnlineReferenceImage) {
                $keys[] = $message->objectKey;
            }
        }

        return $keys;
    }

    private function deleteQueued(): void
    {
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof DeleteOnlineReferenceImage) {
                $this->deleteHandler()($message);
            }
        }

        $this->transport()->reset();
    }

    private function deleteHandler(): DeleteOnlineReferenceImageHandler
    {
        return self::getContainer()->get(DeleteOnlineReferenceImageHandler::class);
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
