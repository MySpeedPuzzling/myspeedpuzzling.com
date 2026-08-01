<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Storage;

use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Drains the UploadSpool back to S3. Runs from the
 * myspeedpuzzling:upload-spooled-files cron; intentionally NOT a Messenger
 * handler - the doctrine_transaction middleware would hold an idle DB
 * transaction open for the whole (potentially long) S3 drain.
 *
 * Talks to the INNER S3 adapter directly, bypassing FailoverS3Adapter -
 * a failed retry must not re-spool in a loop.
 */
final readonly class UploadSpoolProcessor
{
    private const int LOCK_TTL_SECONDS = 3600;
    private const int BACKLOG_ALERT_AGE_HOURS = 3;

    public function __construct(
        private FilesystemAdapter $s3Adapter,
        private UploadSpool $spool,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{uploaded: int, deleted: int, failed: int, remaining: int, skipped: bool}
     */
    public function process(): array
    {
        $lock = $this->lockFactory->createLock('upload-spool-retry', ttl: self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            return ['uploaded' => 0, 'deleted' => 0, 'failed' => 0, 'remaining' => 0, 'skipped' => true];
        }

        try {
            $uploaded = 0;
            $deleted = 0;
            $failed = 0;

            foreach ($this->spool->pendingOperations() as $operation) {
                try {
                    if ($operation->op === SpooledOperationType::Write) {
                        $uploaded += $this->processWrite($operation) ? 1 : 0;
                    } else {
                        $deleted += $this->processDelete($operation) ? 1 : 0;
                    }
                } catch (\Throwable $exception) {
                    $this->spool->markAttemptFailed($operation, $exception::class . ': ' . $exception->getMessage());
                    $failed++;
                }
            }

            $remaining = $this->spool->pendingOperations();
            $this->alertOnStaleBacklog($remaining);

            return [
                'uploaded' => $uploaded,
                'deleted' => $deleted,
                'failed' => $failed,
                'remaining' => count($remaining),
                'skipped' => false,
            ];
        } finally {
            $lock->release();
        }
    }

    private function processWrite(SpooledOperation $operation): bool
    {
        $key = $operation->key;
        $fingerprintBefore = $this->spool->payloadFingerprint($key);

        if ($fingerprintBefore === null) {
            // Payload vanished - resolved by a successful live operation since
            // the directory listing. Purge only when the current meta is not a
            // pending delete: a live delete that failed AFTER our listing left
            // an op=delete meta that must survive for the next run.
            $current = $this->spool->pendingOperationFor($key);

            if ($current === null || $current->op === SpooledOperationType::Write) {
                $this->spool->resolve($key);
            }

            return false;
        }

        $stream = $this->spool->readPayloadStream($key);

        try {
            // Empty Config: no per-object ACL, matching the live upload path
            // (see the visibility comment in config/packages/oneup_flysystem.php)
            $this->s3Adapter->writeStream($key, $stream, new Config());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $fingerprintAfter = $this->spool->payloadFingerprint($key);

        if ($fingerprintAfter === $fingerprintBefore) {
            $this->spool->resolve($key);

            return true;
        }

        if ($fingerprintAfter === null) {
            // A live operation for this key resolved the entry DURING our
            // upload - the stale payload we just PUT may have clobbered a
            // fresh write or resurrected a deleted file. Cannot be rolled
            // back from here; needs eyes.
            $this->logger->error('Upload spool drain raced a live operation - stale payload was uploaded', [
                'key' => $key,
            ]);

            return false;
        }

        // Re-spooled with new content while uploading - the next run uploads
        // the fresh payload over whatever we just wrote. Self-healing.
        return false;
    }

    private function processDelete(SpooledOperation $operation): bool
    {
        // Freshness check: a live operation since the directory listing may
        // have superseded the delete (successful re-upload of the same key,
        // or the delete already went through). Deleting anyway would remove
        // a fresh object.
        $current = $this->spool->pendingOperationFor($operation->key);

        if ($current === null || $current->op !== SpooledOperationType::Delete) {
            return false;
        }

        $this->s3Adapter->delete($operation->key);
        $this->spool->resolve($operation->key);

        return true;
    }

    /**
     * @param list<SpooledOperation> $remaining
     */
    private function alertOnStaleBacklog(array $remaining): void
    {
        if ($remaining === []) {
            return;
        }

        $oldest = $remaining[0];
        $alertThreshold = $this->clock->now()->modify(sprintf('-%d hours', self::BACKLOG_ALERT_AGE_HOURS));

        if ($oldest->firstFailedAt <= $alertThreshold) {
            $this->logger->error('Upload spool backlog is not draining', [
                'count' => count($remaining),
                'oldest_key' => $oldest->key,
                'oldest_first_failed_at' => $oldest->firstFailedAt->format(\DateTimeInterface::ATOM),
                'oldest_attempts' => $oldest->attempts,
                'oldest_last_error' => $oldest->lastError,
            ]);
        }
    }
}
