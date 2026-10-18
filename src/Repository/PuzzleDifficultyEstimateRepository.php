<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleDifficultyEstimate;

readonly final class PuzzleDifficultyEstimateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The stored estimates of the listed puzzles in one statement, keyed by the lower-case puzzle id - puzzles without
     * one (or unknown ids) are absent.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, PuzzleDifficultyEstimate>
     */
    public function findForPuzzles(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<PuzzleDifficultyEstimate> $estimates */
        $estimates = $this->entityManager->createQueryBuilder()
            ->select('estimate')
            ->from(PuzzleDifficultyEstimate::class, 'estimate')
            ->where('estimate.puzzle IN (:puzzleIds)')
            ->setParameter('puzzleIds', $puzzleIds)
            ->getQuery()
            ->getResult();

        $byPuzzle = [];

        foreach ($estimates as $estimate) {
            $byPuzzle[strtolower($estimate->puzzle->id->toString())] = $estimate;
        }

        return $byPuzzle;
    }

    public function save(PuzzleDifficultyEstimate $estimate): void
    {
        $this->entityManager->persist($estimate);
    }
}
