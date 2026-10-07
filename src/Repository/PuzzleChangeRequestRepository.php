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

    /**
     * @return array<PuzzleChangeRequest>
     */
    public function findByOriginalManufacturer(Manufacturer $manufacturer): array
    {
        return $this->entityManager->getRepository(PuzzleChangeRequest::class)
            ->findBy(['originalManufacturerId' => $manufacturer->id]);
    }

    public function countByProposedManufacturer(Manufacturer $manufacturer): int
    {
        return $this->entityManager->getRepository(PuzzleChangeRequest::class)
            ->count(['proposedManufacturer' => $manufacturer]);
    }

    /**
     * Every request about the puzzle, decided or not - what a merge moves onto the survivor.
     *
     * @return list<PuzzleChangeRequest>
     */
    public function findByPuzzle(Puzzle $puzzle): array
    {
        return $this->entityManager->getRepository(PuzzleChangeRequest::class)->findBy(['puzzle' => $puzzle]);
    }

    /**
     * @param list<Puzzle> $puzzles
     *
     * @return list<PuzzleChangeRequest>
     */
    public function findPendingForPuzzles(array $puzzles): array
    {
        if ($puzzles === []) {
            return [];
        }

        return $this->entityManager->getRepository(PuzzleChangeRequest::class)->findBy([
            'puzzle' => $puzzles,
            'status' => PuzzleReportStatus::Pending,
        ]);
    }

    /**
     * @return list<PuzzleChangeRequest>
     */
    public function findAllPending(): array
    {
        return $this->entityManager->getRepository(PuzzleChangeRequest::class)->findBy(
            ['status' => PuzzleReportStatus::Pending],
            ['submittedAt' => 'ASC'],
        );
    }
}
