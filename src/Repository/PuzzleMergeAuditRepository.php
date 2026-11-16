<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PuzzleMergeAudit;

readonly final class PuzzleMergeAuditRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The audit the merge of this request wrote - null for merges approved before the audit existed (2026-09-16).
     */
    public function findLatestForMergeRequest(UuidInterface $mergeRequestId): null|PuzzleMergeAudit
    {
        return $this->entityManager->getRepository(PuzzleMergeAudit::class)->findOneBy(
            ['mergeRequestId' => $mergeRequestId],
            ['performedAt' => 'DESC'],
        );
    }
}
