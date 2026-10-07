<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

readonly final class PuzzleMergeRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleMergeRequestNotFound
     */
    public function get(string $mergeRequestId): PuzzleMergeRequest
    {
        if (!Uuid::isValid($mergeRequestId)) {
            throw new PuzzleMergeRequestNotFound();
        }

        $request = $this->entityManager->find(PuzzleMergeRequest::class, $mergeRequestId);

        return $request ?? throw new PuzzleMergeRequestNotFound();
    }

    /**
     * The pending requests naming any of the puzzles - the reported ids are a JSON list, so they are found by SQL.
     *
     * @param array<string> $puzzleIds
     *
     * @return list<PuzzleMergeRequest>
     */
    public function findPendingNamingAny(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            <<<SQL
SELECT pmr.id
FROM puzzle_merge_request pmr
WHERE pmr.status = :status
    AND EXISTS (
        SELECT 1 FROM jsonb_array_elements_text(pmr.reported_duplicate_puzzle_ids::jsonb) reported(puzzle_id)
        WHERE lower(reported.puzzle_id) IN (:puzzleIds)
    )
SQL,
            [
                'status' => PuzzleReportStatus::Pending->value,
                'puzzleIds' => array_values(array_map(strtolower(...), $puzzleIds)),
            ],
            ['puzzleIds' => ArrayParameterType::STRING],
        );

        if ($ids === []) {
            return [];
        }

        return $this->entityManager->getRepository(PuzzleMergeRequest::class)->findBy(['id' => $ids]);
    }

    /**
     * @return list<PuzzleMergeRequest>
     */
    public function findAllPending(): array
    {
        return $this->entityManager->getRepository(PuzzleMergeRequest::class)->findBy(
            ['status' => PuzzleReportStatus::Pending],
            ['submittedAt' => 'ASC'],
        );
    }
}
