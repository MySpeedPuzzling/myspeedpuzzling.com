<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ImportPersonDecision;
use SpeedPuzzling\Web\Entity\Player;

readonly final class ImportPersonDecisionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ImportPersonDecision $decision): void
    {
        $this->entityManager->persist($decision);
    }

    /**
     * @param list<string> $personKeys
     *
     * @return array<string, ImportPersonDecision> by person key
     */
    public function ofPlayerByKeys(Player $player, array $personKeys): array
    {
        if ($personKeys === []) {
            return [];
        }

        /** @var list<ImportPersonDecision> $decisions */
        $decisions = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(ImportPersonDecision::class, 'd')
            ->where('d.player = :player')
            ->andWhere('d.personKey IN (:keys)')
            ->setParameter('player', $player)
            ->setParameter('keys', $personKeys)
            ->getQuery()
            ->getResult();

        $byKey = [];

        foreach ($decisions as $decision) {
            $byKey[$decision->personKey] = $decision;
        }

        return $byKey;
    }
}
