<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Storage;

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Storage\SpooledOperationType;
use SpeedPuzzling\Web\Services\Storage\UploadSpool;
use SpeedPuzzling\Web\Services\Storage\UploadSpoolProcessor;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class UploadSpoolProcessorTest extends TestCase
{
    private ToggleableFailingFilesystemAdapter $s3;
    private InMemoryFilesystemAdapter $spoolAdapter;
    private UploadSpool $spool;
    private MockClock $clock;
    private InMemoryLogger $logger;
    private UploadSpoolProcessor $processor;

    protected function setUp(): void
    {
        $this->s3 = new ToggleableFailingFilesystemAdapter();
        $this->spoolAdapter = new InMemoryFilesystemAdapter();
        $this->clock = new MockClock('2026-08-01 10:00:00');
        $this->logger = new InMemoryLogger();
        $this->spool = new UploadSpool($this->spoolAdapter, $this->clock, $this->logger);
        $this->processor = new UploadSpoolProcessor(
            $this->s3,
            $this->spool,
            new LockFactory(new InMemoryStore()),
            $this->clock,
            $this->logger,
        );
    }

    public function testDrainUploadsPayloadsAndClearsSpool(): void
    {
        $this->spool->spoolWrite('players/1/a.jpg', 'bytes-a', 'outage');
        $this->spool->spoolWrite('players/2/b.jpg', 'bytes-b', 'outage');

        $result = $this->processor->process();

        self::assertSame(2, $result['uploaded']);
        self::assertSame(0, $result['failed']);
        self::assertSame(0, $result['remaining']);
        self::assertFalse($result['skipped']);

        self::assertSame('bytes-a', $this->s3->read('players/1/a.jpg'));
        self::assertSame('bytes-b', $this->s3->read('players/2/b.jpg'));
        self::assertSame([], $this->spool->pendingOperations());
    }

    public function testStillFailingRetainsEntryAndIncrementsAttempts(): void
    {
        $this->spool->spoolWrite('players/1/a.jpg', 'bytes-a', 'outage');
        $this->s3->setFailing(true);

        $result = $this->processor->process();

        self::assertSame(0, $result['uploaded']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['remaining']);

        $pending = $this->spool->pendingOperations();
        self::assertCount(1, $pending);
        self::assertSame(2, $pending[0]->attempts);
        self::assertTrue($this->spool->hasPayload('players/1/a.jpg'));
    }

    public function testPendingDeleteIsExecuted(): void
    {
        $this->s3->write('players/1/old.jpg', 'old bytes', new Config());
        $this->spool->spoolDelete('players/1/old.jpg', 'outage');

        $result = $this->processor->process();

        self::assertSame(1, $result['deleted']);
        self::assertSame(0, $result['remaining']);
        self::assertFalse($this->s3->fileExists('players/1/old.jpg'));
    }

    public function testEmptySpoolIsNoop(): void
    {
        $result = $this->processor->process();

        self::assertSame(
            ['uploaded' => 0, 'deleted' => 0, 'failed' => 0, 'remaining' => 0, 'skipped' => false],
            $result,
        );
    }

    public function testStaleBacklogTriggersErrorLog(): void
    {
        $this->spool->spoolWrite('players/1/a.jpg', 'bytes-a', 'outage');
        $this->s3->setFailing(true);
        $this->clock->modify('+4 hours');

        $this->processor->process();

        self::assertTrue($this->logger->hasRecord('error', 'Upload spool backlog is not draining'));
    }

    public function testFreshBacklogDoesNotAlert(): void
    {
        $this->spool->spoolWrite('players/1/a.jpg', 'bytes-a', 'outage');
        $this->s3->setFailing(true);

        $this->processor->process();

        self::assertFalse($this->logger->hasRecord('error', 'Upload spool backlog is not draining'));
    }

    public function testCorruptMetaIsRebuiltFromPayloadAndDrained(): void
    {
        // Crash mid-meta-write leaves unparseable JSON next to a healthy payload
        $this->spoolAdapter->write('payload/players/1/a.jpg', 'bytes-a', new Config());
        $this->spoolAdapter->write('meta/players/1/a.jpg.json', 'not-json{', new Config());

        $result = $this->processor->process();

        self::assertSame(1, $result['uploaded']);
        self::assertSame('bytes-a', $this->s3->read('players/1/a.jpg'));
        self::assertTrue($this->logger->hasRecord('warning', 'Corrupt upload spool meta'));
    }

    public function testOrphanPayloadWithoutMetaIsDrained(): void
    {
        // Crash between payload write and meta write leaves a bare payload
        $this->spoolAdapter->write('payload/players/1/a.jpg', 'bytes-a', new Config());

        $result = $this->processor->process();

        self::assertSame(1, $result['uploaded']);
        self::assertSame('bytes-a', $this->s3->read('players/1/a.jpg'));
        self::assertSame([], $this->spool->pendingOperations());
    }

    public function testSpooledOperationTypeRoundTrips(): void
    {
        $this->spool->spoolWrite('players/1/a.jpg', 'bytes-a', 'outage');
        $this->spool->spoolDelete('players/2/b.jpg', 'outage');

        $ops = [];
        foreach ($this->spool->pendingOperations() as $operation) {
            $ops[$operation->key] = $operation->op;
        }

        self::assertSame(SpooledOperationType::Write, $ops['players/1/a.jpg']);
        self::assertSame(SpooledOperationType::Delete, $ops['players/2/b.jpg']);
    }
}
