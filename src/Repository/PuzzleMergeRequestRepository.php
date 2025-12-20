<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;

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
}
