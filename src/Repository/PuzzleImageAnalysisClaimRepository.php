<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleImageAnalysisClaim;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

readonly final class PuzzleImageAnalysisClaimRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The claims on the listed puzzles, in one statement, keyed by the lower-case puzzle id and the analyzer.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, PuzzleImageAnalysisClaim>
     */
    public function findForPuzzles(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<PuzzleImageAnalysisClaim> $claims */
        $claims = $this->entityManager->createQueryBuilder()
            ->select('claim')
            ->from(PuzzleImageAnalysisClaim::class, 'claim')
            ->where('claim.puzzle IN (:puzzleIds)')
            ->setParameter('puzzleIds', $puzzleIds)
            ->getQuery()
            ->getResult();

        $keyed = [];

        foreach ($claims as $claim) {
            $keyed[self::key($claim->puzzle->id->toString(), $claim->analyzer)] = $claim;
        }

        return $keyed;
    }

    /**
     * A worker's claims - all of them, or only those of an analyzer and/or of the listed puzzles.
     *
     * @param null|list<string> $puzzleIds
     *
     * @return list<PuzzleImageAnalysisClaim>
     */
    public function findOfWorker(string $worker, null|ImageAnalyzer $analyzer, null|array $puzzleIds): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('claim')
            ->from(PuzzleImageAnalysisClaim::class, 'claim')
            ->where('claim.worker = :worker')
            ->setParameter('worker', $worker);

        if ($analyzer !== null) {
            $query->andWhere('claim.analyzer = :analyzer')->setParameter('analyzer', $analyzer);
        }

        if ($puzzleIds !== null) {
            $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

            if ($puzzleIds === []) {
                return [];
            }

            $query->andWhere('claim.puzzle IN (:puzzleIds)')->setParameter('puzzleIds', $puzzleIds);
        }

        /** @var list<PuzzleImageAnalysisClaim> $claims */
        $claims = $query->getQuery()->getResult();

        return $claims;
    }

    public function delete(PuzzleImageAnalysisClaim $claim): void
    {
        $this->entityManager->remove($claim);
    }

    public static function key(string $puzzleId, ImageAnalyzer $analyzer): string
    {
        return strtolower($puzzleId) . '|' . $analyzer->value;
    }
}
