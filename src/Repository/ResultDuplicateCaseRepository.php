<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\ResultDuplicateCase;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseNotFound;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;

readonly final class ResultDuplicateCaseRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResultDuplicateCase $case): void
    {
        $this->entityManager->persist($case);
    }

    /**
     * Native SQL on purpose, for the detection right after a save (DetectDuplicateResultsOnSave): the row is written
     * at once, inside that detection's savepoint, and a pair somebody stored meanwhile is skipped instead of failing.
     * Persisted, the case would be written by the flush that follows the save's own - a unique-key failure there
     * would cost the player the save.
     */
    public function insertIfAbsent(ResultDuplicateCase $case): void
    {
        $this->entityManager->getConnection()->executeStatement(
            <<<SQL
INSERT INTO result_duplicate_case (id, player_id, time_a_id, time_b_id, tier, kind, detected_at, detected_by, snapshot, status, resolved_at, resolved_via)
VALUES (:id, :playerId, :timeAId, :timeBId, :tier, :kind, :detectedAt, :detectedBy, :snapshot, :status, :resolvedAt, :resolvedVia)
ON CONFLICT (player_id, time_a_id, time_b_id) DO NOTHING
SQL,
            [
                'id' => $case->id->toString(),
                'playerId' => $case->player->id->toString(),
                'timeAId' => $case->timeAId->toString(),
                'timeBId' => $case->timeBId->toString(),
                'tier' => $case->tier->value,
                'kind' => $case->kind->value,
                'detectedAt' => $case->detectedAt,
                'detectedBy' => $case->detectedBy->value,
                'snapshot' => $case->snapshot,
                'status' => $case->status->value,
                'resolvedAt' => $case->resolvedAt,
                'resolvedVia' => $case->resolvedVia?->value,
            ],
            [
                'detectedAt' => Types::DATETIME_IMMUTABLE,
                'snapshot' => Types::JSON,
                'resolvedAt' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    /**
     * @throws DuplicateCaseNotFound
     */
    public function get(string $caseId): ResultDuplicateCase
    {
        if (!Uuid::isValid($caseId)) {
            throw new DuplicateCaseNotFound();
        }

        return $this->entityManager->find(ResultDuplicateCase::class, $caseId) ?? throw new DuplicateCaseNotFound();
    }

    /**
     * Open cases of anybody that have one of the results as a copy.
     *
     * @param list<string> $timeIds
     * @return list<ResultDuplicateCase>
     */
    public function findOpenReferencing(array $timeIds): array
    {
        if ($timeIds === []) {
            return [];
        }

        /** @var list<ResultDuplicateCase> $cases */
        $cases = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ResultDuplicateCase::class, 'c')
            ->where('c.status = :open')
            ->andWhere('c.timeAId IN (:timeIds) OR c.timeBId IN (:timeIds)')
            ->setParameter('open', DuplicateCaseStatus::Open)
            ->setParameter('timeIds', $timeIds)
            ->getQuery()
            ->getResult();

        return $cases;
    }

    /**
     * Every person's case of the pair (a teammate copy is a case for each member).
     *
     * @return list<ResultDuplicateCase>
     */
    public function findOfPair(UuidInterface $timeAId, UuidInterface $timeBId): array
    {
        /** @var list<ResultDuplicateCase> $cases */
        $cases = $this->entityManager->getRepository(ResultDuplicateCase::class)
            ->findBy(['timeAId' => $timeAId, 'timeBId' => $timeBId]);

        return $cases;
    }

    /**
     * @return list<ResultDuplicateCase>
     */
    public function findOpen(): array
    {
        /** @var list<ResultDuplicateCase> $cases */
        $cases = $this->entityManager->getRepository(ResultDuplicateCase::class)
            ->findBy(['status' => DuplicateCaseStatus::Open]);

        return $cases;
    }

    /**
     * Every pair that ever had a case, in any status - such a pair is never raised again.
     *
     * @return array<string, true> keyed by ResultDuplicateCase::key()
     */
    public function allKeys(): array
    {
        return $this->keysOfCasesReferencing(null);
    }

    /**
     * Like allKeys(), limited to the pairs with the result as a copy - what the detection at save time needs.
     *
     * @return array<string, true> keyed by ResultDuplicateCase::key()
     */
    public function keysReferencing(string $timeId): array
    {
        return $this->keysOfCasesReferencing($timeId);
    }

    /**
     * @return array<string, true>
     */
    private function keysOfCasesReferencing(null|string $timeId): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.player) AS playerId', 'c.timeAId AS timeAId', 'c.timeBId AS timeBId')
            ->from(ResultDuplicateCase::class, 'c');

        if ($timeId !== null) {
            $queryBuilder
                ->where('c.timeAId = :timeId OR c.timeBId = :timeId')
                ->setParameter('timeId', $timeId);
        }

        /** @var list<array{playerId: string, timeAId: string|UuidInterface, timeBId: string|UuidInterface}> $rows */
        $rows = $queryBuilder->getQuery()->getScalarResult();

        $keys = [];

        foreach ($rows as $row) {
            $keys[ResultDuplicateCase::key($row['playerId'], (string) $row['timeAId'], (string) $row['timeBId'])] = true;
        }

        return $keys;
    }
}
