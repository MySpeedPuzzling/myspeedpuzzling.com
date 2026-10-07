<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCheck;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;

readonly final class SuspiciousTimeCheckRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $timeId): null|SuspiciousTimeCheck
    {
        return $this->entityManager->find(SuspiciousTimeCheck::class, $timeId);
    }

    /**
     * Native SQL on purpose - a genuine bulk operation: the scan checks every solo time once (~450k rows on the first
     * run) and writes the checks in batches as it streams, never holding them as objects. One statement per batch
     * with three arrays, whatever the batch size. A time deleted while the scan ran is skipped, not a broken FK.
     *
     * @param list<array{time_id: string, outcome: SuspicionCheckOutcome, fingerprint: string}> $checks
     */
    public function upsertMany(array $checks, int $version, DateTimeImmutable $now): void
    {
        if ($checks === []) {
            return;
        }

        $this->entityManager->getConnection()->executeStatement(
            <<<SQL
INSERT INTO suspicious_time_check (time_id, version, outcome, fingerprint, checked_at)
SELECT batch.time_id, :version, batch.outcome, batch.fingerprint, :checkedAt
FROM unnest(CAST(:timeIds AS uuid[]), CAST(:outcomes AS text[]), CAST(:fingerprints AS text[])) AS batch(time_id, outcome, fingerprint)
INNER JOIN puzzle_solving_time pst ON pst.id = batch.time_id
ON CONFLICT (time_id) DO UPDATE SET
    version = EXCLUDED.version,
    outcome = EXCLUDED.outcome,
    fingerprint = EXCLUDED.fingerprint,
    checked_at = EXCLUDED.checked_at
SQL,
            [
                'version' => $version,
                'checkedAt' => $now,
                'timeIds' => self::arrayLiteral(array_column($checks, 'time_id')),
                'outcomes' => self::arrayLiteral(array_map(
                    static fn (array $check): string => $check['outcome']->value,
                    $checks,
                )),
                'fingerprints' => self::arrayLiteral(array_column($checks, 'fingerprint')),
            ],
            [
                'checkedAt' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    /**
     * Uuids, enum values and md5 hashes only - nothing that needs quoting inside a Postgres array literal.
     *
     * @param array<string> $values
     */
    private static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }
}
