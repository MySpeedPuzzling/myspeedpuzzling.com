<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;

readonly final class PuzzleChangeRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleChangeRequestNotFound
     */
    public function get(string $changeRequestId): PuzzleChangeRequest
    {
        if (!Uuid::isValid($changeRequestId)) {
            throw new PuzzleChangeRequestNotFound();
        }

        $request = $this->entityManager->find(PuzzleChangeRequest::class, $changeRequestId);

        return $request ?? throw new PuzzleChangeRequestNotFound();
    }
}
