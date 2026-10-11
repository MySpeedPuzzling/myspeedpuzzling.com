<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ContentDigestRun;
use SpeedPuzzling\Web\Results\ContentDigestRunCounts;

/**
 * Runs of the activity digest. The counters are atomic `UPDATE .. SET n = n + 1` statements, not entity changes: many
 * messages of one run bump them, and a read-modify-write through the entity would lose counts between consumers.
 */
readonly final class ContentDigestRunRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $database,
    ) {
    }

    public function save(ContentDigestRun $run): void
    {
        $this->entityManager->persist($run);
    }

    public function isStopped(string $runId): bool
    {
        $stoppedAt = $this->database->fetchOne('SELECT stopped_at FROM content_digest_run WHERE id = :id', ['id' => $runId]);

        return is_string($stoppedAt);
    }

    /**
     * Counts one outcome ('sent', 'failed_permanently', 'deferred') and returns the run's counts after it.
     */
    public function count(string $runId, string $outcome): ContentDigestRunCounts
    {
        $column = match ($outcome) {
            'sent' => 'sent',
            'failed_permanently' => 'failed_permanently',
            'deferred' => 'deferred',
            default => throw new \InvalidArgumentException(sprintf('Unknown digest outcome "%s".', $outcome)),
        };

        /** @var false|array{sent: int|string, failed_permanently: int|string, deferred: int|string} $row */
        $row = $this->database->fetchAssociative(
            "UPDATE content_digest_run SET {$column} = {$column} + 1 WHERE id = :id RETURNING sent, failed_permanently, deferred",
            ['id' => $runId],
        );

        if ($row === false) {
            return new ContentDigestRunCounts(0, 0, 0);
        }

        return new ContentDigestRunCounts(
            sent: (int) $row['sent'],
            failedPermanently: (int) $row['failed_permanently'],
            deferred: (int) $row['deferred'],
        );
    }

    /**
     * True only for the caller that stopped the run - so exactly one warning is logged per stopped run.
     */
    public function stop(string $runId, DateTimeImmutable $now): bool
    {
        $affectedRows = $this->database->executeStatement(
            'UPDATE content_digest_run SET stopped_at = :now WHERE id = :id AND stopped_at IS NULL',
            ['id' => $runId, 'now' => $now->format(DATE_ATOM)],
        );

        return $affectedRows === 1;
    }
}
