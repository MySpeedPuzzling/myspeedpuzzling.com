<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleImageAnalysis;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

readonly final class PuzzleImageAnalysisRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findOne(string $puzzleId, ImageAnalyzer $analyzer): null|PuzzleImageAnalysis
    {
        if (Uuid::isValid($puzzleId) === false) {
            return null;
        }

        $analysis = $this->entityManager->getRepository(PuzzleImageAnalysis::class)->findOneBy([
            'puzzle' => strtolower($puzzleId),
            'analyzer' => $analyzer,
        ]);

        return $analysis instanceof PuzzleImageAnalysis ? $analysis : null;
    }

    /**
     * Every stored analysis of the listed puzzles, in one statement - unknown ids are simply absent.
     *
     * @param list<string> $puzzleIds
     *
     * @return list<PuzzleImageAnalysis>
     */
    public function findForPuzzles(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<PuzzleImageAnalysis> $analyses */
        $analyses = $this->entityManager->createQueryBuilder()
            ->select('analysis')
            ->from(PuzzleImageAnalysis::class, 'analysis')
            ->where('analysis.puzzle IN (:puzzleIds)')
            ->setParameter('puzzleIds', $puzzleIds)
            ->getQuery()
            ->getResult();

        return $analyses;
    }

    public function save(PuzzleImageAnalysis $analysis): void
    {
        $this->entityManager->persist($analysis);
    }
}
