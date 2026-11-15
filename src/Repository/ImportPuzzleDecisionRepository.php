<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ImportPuzzleDecision;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;

readonly final class ImportPuzzleDecisionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ImportPuzzleDecision $decision): void
    {
        $this->entityManager->persist($decision);
    }

    public function delete(ImportPuzzleDecision $decision): void
    {
        $this->entityManager->remove($decision);
    }

    /**
     * @param list<string> $puzzleKeys
     *
     * @return array<string, ImportPuzzleDecision> by puzzle key
     */
    public function ofPlayerByKeys(Player $player, array $puzzleKeys): array
    {
        if ($puzzleKeys === []) {
            return [];
        }

        /** @var list<ImportPuzzleDecision> $decisions */
        $decisions = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(ImportPuzzleDecision::class, 'd')
            ->where('d.player = :player')
            ->andWhere('d.puzzleKey IN (:keys)')
            ->setParameter('player', $player)
            ->setParameter('keys', $puzzleKeys)
            ->getQuery()
            ->getResult();

        $byKey = [];

        foreach ($decisions as $decision) {
            $byKey[$decision->puzzleKey] = $decision;
        }

        return $byKey;
    }

    /**
     * Every remembered decision for this catalogue puzzle - for a puzzle merge.
     *
     * @return list<ImportPuzzleDecision>
     */
    public function forPuzzle(Puzzle $puzzle): array
    {
        return $this->entityManager->getRepository(ImportPuzzleDecision::class)->findBy(['puzzle' => $puzzle]);
    }
}
