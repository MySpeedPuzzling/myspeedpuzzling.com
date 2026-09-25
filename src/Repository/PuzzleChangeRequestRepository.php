<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

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

    public function save(PuzzleChangeRequest $changeRequest): void
    {
        $this->entityManager->persist($changeRequest);
    }

    /**
     * A still-open proposal of exactly this code list for the puzzle (multiscan
     * linking is idempotent: a retry must not queue a second request).
     */
    public function findPendingEanProposal(Puzzle $puzzle, string $proposedEan): null|PuzzleChangeRequest
    {
        return $this->entityManager->getRepository(PuzzleChangeRequest::class)
            ->findOneBy([
                'puzzle' => $puzzle,
                'proposedEan' => $proposedEan,
                'status' => PuzzleReportStatus::Pending,
            ]);
    }

    /**
     * @return list<PuzzleChangeRequest>
     */
    public function findByProposedManufacturer(Manufacturer $manufacturer): array
    {
        return $this->entityManager->getRepository(PuzzleChangeRequest::class)
            ->findBy(['proposedManufacturer' => $manufacturer]);
    }
}
