<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\DeletePlayerStoredFiles;
use SpeedPuzzling\Web\Message\DiscardImport;
use SpeedPuzzling\Web\Message\PruneDataImports;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/data-import/upload.md "Prune": uploads never confirmed, files kept long enough, discarded imports.
 */
final class PruneDataImportsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $bus;
    private Connection $database;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->filesystem = self::getContainer()->get(Filesystem::class);
    }

    public function testAnUploadNeverConfirmedGoesAfterADay(): void
    {
        // Something arrived for the first, nothing for the second
        $old = $this->started('old.xlsx', hoursAgo: DataImportLimits::UNCONFIRMED_UPLOAD_HOURS + 1);
        $this->filesystem->write($this->key($old, DataImportFileKind::Xlsx), 'half of it');
        $oldEmpty = $this->started('old-empty.csv', hoursAgo: DataImportLimits::UNCONFIRMED_UPLOAD_HOURS + 1, playerId: PlayerFixture::PLAYER_WITH_STRIPE);
        $fresh = $this->started('fresh.xlsx', hoursAgo: DataImportLimits::UNCONFIRMED_UPLOAD_HOURS - 1);
        // Confirmed long ago, but young enough to keep its file
        $confirmed = $this->uploaded('confirmed.xlsx', daysAgo: 2);

        self::assertSame(['unconfirmed' => 2, 'files' => 0, 'discarded' => 0], $this->prune());

        self::assertEqualsCanonicalizing([$fresh, $confirmed], $this->importIds());
        self::assertFalse($this->filesystem->fileExists($this->key($old, DataImportFileKind::Xlsx)));
        self::assertNotContains($oldEmpty, $this->importIds());
        self::assertTrue($this->filesystem->fileExists($this->key($confirmed, DataImportFileKind::Xlsx)));
    }

    public function testAFileGoesAfterThirtyDaysAndTheImportStays(): void
    {
        $old = $this->uploaded('old.xlsx', daysAgo: DataImportLimits::KEEP_FILE_DAYS + 1);
        $young = $this->uploaded('young.xlsx', daysAgo: DataImportLimits::KEEP_FILE_DAYS - 1);

        self::assertSame(['unconfirmed' => 0, 'files' => 1, 'discarded' => 0], $this->prune());

        self::assertEqualsCanonicalizing([$old, $young], $this->importIds());
        self::assertFalse($this->filesystem->fileExists($this->key($old, DataImportFileKind::Xlsx)));
        self::assertTrue($this->filesystem->fileExists($this->key($young, DataImportFileKind::Xlsx)));

        $row = $this->database->fetchAssociative('SELECT status, file_key, file_removed_at FROM data_import WHERE id = :id', ['id' => $old]);
        self::assertSame(['status' => 'uploaded', 'file_key' => null, 'file_removed_at' => TestClock::FROZEN_NOW], $row);

        // The next run finds nothing to do
        self::assertSame(['unconfirmed' => 0, 'files' => 0, 'discarded' => 0], $this->prune());
    }

    /**
     * An upload URL signed before the discard stays valid for half an hour: the object can come back after the
     * discard deleted it. The row keeps the key, and the prune deletes the object once more before the row goes -
     * but only once no signature can be valid any more.
     */
    public function testAnObjectPutBackAfterTheDiscardIsGoneAfterThePrune(): void
    {
        $discarded = $this->uploaded('discarded.xlsx', daysAgo: 0);
        $kept = $this->uploaded('kept.xlsx', daysAgo: 0);
        $key = $this->key($discarded, DataImportFileKind::Xlsx);
        $this->bus->dispatch(new DiscardImport($discarded, PlayerFixture::PLAYER_REGULAR));

        self::assertFalse($this->filesystem->fileExists($key), 'Discarding deletes the file at once');
        self::assertSame($key, $this->database->fetchOne('SELECT file_key FROM data_import WHERE id = :id', ['id' => $discarded]));

        // The browser's upload was still running (or somebody kept the signed URL)
        $this->filesystem->write($key, '0123456789');

        // Discarded a moment ago: the URL may still be valid - the row that knows the key stays
        self::assertSame(['unconfirmed' => 0, 'files' => 0, 'discarded' => 0], $this->prune());
        self::assertEqualsCanonicalizing([$discarded, $kept], $this->importIds());

        $this->database->executeStatement('UPDATE data_import SET discarded_at = :at WHERE id = :id', [
            'at' => TestClock::now()->modify('-' . DataImportLimits::SIGNATURE_GRACE_HOURS . ' hours -1 minute')->format('Y-m-d H:i:s'),
            'id' => $discarded,
        ]);

        self::assertSame(['unconfirmed' => 0, 'files' => 0, 'discarded' => 1], $this->prune());

        self::assertSame([$kept], $this->importIds());
        self::assertFalse($this->filesystem->fileExists($key), 'The object that came back is gone with the row');
        self::assertTrue($this->filesystem->fileExists($this->key($kept, DataImportFileKind::Xlsx)));
    }

    /**
     * An upload discarded while it was running: whatever arrives afterwards goes with the prune too
     */
    public function testAnUploadDiscardedWhileRunningLeavesNothingBehind(): void
    {
        $importId = $this->started('running.xlsx', hoursAgo: 0);
        $key = $this->key($importId, DataImportFileKind::Xlsx);
        $this->bus->dispatch(new DiscardImport($importId, PlayerFixture::PLAYER_REGULAR));
        $this->filesystem->write($key, '0123456789');

        $this->database->executeStatement('UPDATE data_import SET discarded_at = :at WHERE id = :id', [
            'at' => TestClock::now()->modify('-2 hours')->format('Y-m-d H:i:s'),
            'id' => $importId,
        ]);

        self::assertSame(['unconfirmed' => 0, 'files' => 0, 'discarded' => 1], $this->prune());
        self::assertSame([], $this->importIds());
        self::assertFalse($this->filesystem->fileExists($key));
    }

    /**
     * The storage layer queues a delete object storage refused and retries it by itself (FailoverS3Adapter) - the
     * prune goes on as if it was deleted
     */
    public function testObjectStorageBeingDownDoesNotStopThePrune(): void
    {
        $this->started('old.xlsx', hoursAgo: DataImportLimits::UNCONFIRMED_UPLOAD_HOURS + 1);
        $old = $this->uploaded('old.xlsx', daysAgo: DataImportLimits::KEEP_FILE_DAYS + 1);

        /** @var ToggleableFailingFilesystemAdapter $storage */
        $storage = self::getContainer()->get('app.storage.s3_adapter');
        $storage->setFailing(true);

        try {
            $removed = $this->prune();
        } finally {
            $storage->setFailing(false);
        }

        self::assertSame(['unconfirmed' => 1, 'files' => 1, 'discarded' => 0], $removed);
        self::assertSame([$old], $this->importIds());
    }

    public function testAnAccountsDeletionTakesItsImportFilesAlong(): void
    {
        $importId = $this->uploaded('mine.xlsx', daysAgo: 1);
        $key = $this->key($importId, DataImportFileKind::Xlsx);
        self::assertTrue($this->filesystem->fileExists($key));

        $this->bus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertSame([], $this->importIds());

        // The files go once the deletion committed, off the request (DeletePlayerStoredFilesHandler)
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $paths = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof DeletePlayerStoredFiles) {
                $paths = [...$paths, ...$message->paths];
            }
        }

        self::assertContains($key, $paths);
    }

    /**
     * @return array{unconfirmed: int, files: int, discarded: int}
     */
    private function prune(): array
    {
        // Like the daily run: nothing of the test's own writes in memory
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        /** @var array{unconfirmed: int, files: int, discarded: int} $removed */
        $removed = $this->bus->dispatch(new PruneDataImports())->last(HandledStamp::class)?->getResult();

        return $removed;
    }

    private function started(string $name, int $hoursAgo, string $playerId = PlayerFixture::PLAYER_REGULAR): string
    {
        $importId = Uuid::uuid7()->toString();
        $this->bus->dispatch(new StartImport($importId, $playerId, $name, 10));
        $this->createdAt($importId, TestClock::now()->modify('-' . $hoursAgo . ' hours'));

        return $importId;
    }

    private function uploaded(string $name, int $daysAgo): string
    {
        $importId = Uuid::uuid7()->toString();
        $this->bus->dispatch(new StartImport($importId, PlayerFixture::PLAYER_REGULAR, $name, 10));
        $this->filesystem->write($this->key($importId, DataImportFileKind::Xlsx), '0123456789');
        $this->bus->dispatch(new ConfirmImportUploaded($importId, PlayerFixture::PLAYER_REGULAR));
        $this->createdAt($importId, TestClock::now()->modify('-' . $daysAgo . ' days'));

        return $importId;
    }

    private function createdAt(string $importId, \DateTimeImmutable $at): void
    {
        $this->database->executeStatement('UPDATE data_import SET created_at = :at WHERE id = :id', ['at' => $at->format('Y-m-d H:i:s'), 'id' => $importId]);
    }

    private function key(string $importId, DataImportFileKind $kind): string
    {
        return ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, $kind);
    }

    /**
     * @return list<string>
     */
    private function importIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn('SELECT id FROM data_import ORDER BY id');

        return $ids;
    }
}
