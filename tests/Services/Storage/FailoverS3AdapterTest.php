<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Storage;

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Storage\FailoverS3Adapter;
use SpeedPuzzling\Web\Services\Storage\SpooledOperationType;
use SpeedPuzzling\Web\Services\Storage\UploadSpool;
use SpeedPuzzling\Web\Services\UploadFailureCollector;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter;
use Symfony\Component\Clock\MockClock;

final class FailoverS3AdapterTest extends TestCase
{
    private ToggleableFailingFilesystemAdapter $s3;
    private UploadSpool $spool;
    private UploadFailureCollector $collector;
    private InMemoryLogger $logger;
    private FailoverS3Adapter $adapter;

    protected function setUp(): void
    {
        $this->s3 = new ToggleableFailingFilesystemAdapter();
        $this->spool = new UploadSpool(new InMemoryFilesystemAdapter(), new MockClock(), new InMemoryLogger());
        $this->collector = new UploadFailureCollector();
        $this->logger = new InMemoryLogger();
        $this->adapter = new FailoverS3Adapter($this->s3, $this->spool, $this->collector, $this->logger);
    }

    public function testHappyPathWritesThroughToS3(): void
    {
        $this->adapter->write('players/1/photo.jpg', 'image bytes', new Config());

        self::assertSame('image bytes', $this->s3->read('players/1/photo.jpg'));
        self::assertSame([], $this->spool->pendingOperations());
        self::assertFalse($this->collector->hasFailures());
    }

    public function testFailedWriteStreamSpoolsByteIdenticalPayload(): void
    {
        $this->s3->setFailing(true);

        $stream = fopen('php://temp', 'r+b');
        assert(is_resource($stream));
        fwrite($stream, 'image bytes');
        rewind($stream);

        // The double consumes part of the stream before failing, like real S3
        $this->adapter->writeStream('players/1/photo.jpg', $stream, new Config());
        fclose($stream);

        self::assertSame('image bytes', $this->spool->readPayload('players/1/photo.jpg'));

        $pending = $this->spool->pendingOperations();
        self::assertCount(1, $pending);
        self::assertSame('players/1/photo.jpg', $pending[0]->key);
        self::assertSame(SpooledOperationType::Write, $pending[0]->op);
        self::assertSame(1, $pending[0]->attempts);
        self::assertStringContainsString('Simulated object storage outage', $pending[0]->lastError);

        self::assertTrue($this->collector->hasFailures());
        self::assertTrue($this->logger->hasRecord('error', 'file spooled for automatic retry'));
    }

    public function testFailedWriteSpoolsStringPayload(): void
    {
        $this->s3->setFailing(true);

        $this->adapter->write('players/1/photo.jpg', 'image bytes', new Config());

        self::assertSame('image bytes', $this->spool->readPayload('players/1/photo.jpg'));
        self::assertTrue($this->collector->hasFailures());
    }

    public function testSuccessfulWritePurgesStalePendingWrite(): void
    {
        $this->spool->spoolWrite('players/1/photo.jpg', 'stale bytes', 'earlier outage');

        $this->adapter->write('players/1/photo.jpg', 'fresh bytes', new Config());

        // The cron must never overwrite the fresh object with the stale payload
        self::assertSame([], $this->spool->pendingOperations());
        self::assertSame('fresh bytes', $this->s3->read('players/1/photo.jpg'));
    }

    public function testReadFallsBackToSpooledPayload(): void
    {
        $this->spool->spoolWrite('players/1/photo.jpg', 'spooled bytes', 'outage');
        $this->s3->setFailing(true);

        self::assertSame('spooled bytes', $this->adapter->read('players/1/photo.jpg'));

        $stream = $this->adapter->readStream('players/1/photo.jpg');
        self::assertSame('spooled bytes', stream_get_contents($stream));
        fclose($stream);
    }

    public function testReadRethrowsWhenNotSpooled(): void
    {
        $this->s3->setFailing(true);

        $this->expectException(UnableToReadFile::class);

        $this->adapter->read('players/1/unknown.jpg');
    }

    public function testFileExistsFallsBackOnRawNetworkException(): void
    {
        $this->s3->setFailing(true);

        // The raw AsyncAws exception is not a FilesystemException - must not bubble
        self::assertFalse($this->adapter->fileExists('players/1/unknown.jpg'));

        $this->spool->spoolWrite('players/1/photo.jpg', 'spooled bytes', 'outage');
        self::assertTrue($this->adapter->fileExists('players/1/photo.jpg'));
    }

    public function testFileExistsSeesPendingWriteEvenWhenS3IsHealthy(): void
    {
        $this->spool->spoolWrite('players/1/photo.jpg', 'spooled bytes', 'outage');

        self::assertTrue($this->adapter->fileExists('players/1/photo.jpg'));
    }

    public function testMetadataFallsBackToSpooledPayload(): void
    {
        $this->spool->spoolWrite('players/1/photo.jpg', 'spooled bytes', 'outage');
        $this->s3->setFailing(true);

        self::assertSame(strlen('spooled bytes'), $this->adapter->fileSize('players/1/photo.jpg')->fileSize());
        self::assertNotNull($this->adapter->lastModified('players/1/photo.jpg')->lastModified());
        self::assertSame('image/jpeg', $this->adapter->mimeType('players/1/photo.jpg')->mimeType());
    }

    public function testFailedDeleteQueuesPendingDeleteAndDropsPayload(): void
    {
        $this->spool->spoolWrite('players/1/photo.jpg', 'spooled bytes', 'outage');
        $this->s3->setFailing(true);

        $this->adapter->delete('players/1/photo.jpg');

        self::assertFalse($this->spool->hasPayload('players/1/photo.jpg'));

        $pending = $this->spool->pendingOperations();
        self::assertCount(1, $pending);
        self::assertSame(SpooledOperationType::Delete, $pending[0]->op);
        self::assertTrue($this->logger->hasRecord('error', 'queued for automatic retry'));
    }

    public function testSuccessfulDeletePurgesPendingWrite(): void
    {
        $this->spool->spoolWrite('players/1/photo.jpg', 'spooled bytes', 'outage');

        $this->adapter->delete('players/1/photo.jpg');

        // The cron must not resurrect the deleted file from the spool
        self::assertSame([], $this->spool->pendingOperations());
    }

    public function testCopyFallsBackToSpooledSourceWhenS3IsHealthy(): void
    {
        // Source never reached S3 (pending write), S3 itself is healthy again
        $this->spool->spoolWrite('proposal-123.jpg', 'proposal bytes', 'outage');

        $this->adapter->copy('proposal-123.jpg', 'brand-name-500.jpg', new Config());

        self::assertSame('proposal bytes', $this->s3->read('brand-name-500.jpg'));
    }

    public function testCopyDuringOutageSpoolsDestination(): void
    {
        $this->spool->spoolWrite('proposal-123.jpg', 'proposal bytes', 'outage');
        $this->s3->setFailing(true);

        $this->adapter->copy('proposal-123.jpg', 'brand-name-500.jpg', new Config());

        self::assertSame('proposal bytes', $this->spool->readPayload('brand-name-500.jpg'));
    }

    public function testOriginalExceptionRethrownWhenSpoolAlsoFails(): void
    {
        $failingSpoolAdapter = new ToggleableFailingFilesystemAdapter();
        $failingSpoolAdapter->setFailing(true);
        $adapter = new FailoverS3Adapter(
            $this->s3,
            new UploadSpool($failingSpoolAdapter, new MockClock(), new InMemoryLogger()),
            $this->collector,
            $this->logger,
        );
        $this->s3->setFailing(true);

        try {
            $adapter->write('players/1/photo.jpg', 'image bytes', new Config());
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            self::assertStringContainsString('Simulated object storage outage', $exception->getMessage());
        }

        self::assertTrue($this->logger->hasRecord('error', 'upload is lost'));
        self::assertFalse($this->collector->hasFailures());
    }
}
