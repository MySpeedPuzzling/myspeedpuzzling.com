<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ImportPartnerOptOut;

readonly final class ImportPartnerOptOutRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ImportPartnerOptOut $optOut): void
    {
        $this->entityManager->persist($optOut);
    }

    public function find(string $playerId, string $importerId): null|ImportPartnerOptOut
    {
        return $this->entityManager->getRepository(ImportPartnerOptOut::class)->findOneBy(['player' => $playerId, 'importer' => $importerId]);
    }

    /**
     * Of these players: those who took themselves off this importing player's imports.
     *
     * @param list<string> $playerIds
     *
     * @return list<string>
     */
    public function among(string $importerId, array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(o.player) AS id')
            ->from(ImportPartnerOptOut::class, 'o')
            ->where('o.importer = :importer')
            ->andWhere('o.player IN (:players)')
            ->setParameter('importer', $importerId)
            ->setParameter('players', $playerIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => strtolower((string) $row['id']), $rows);
    }
}
