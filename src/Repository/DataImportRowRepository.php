<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;

readonly final class DataImportRowRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DataImportRow $row): void
    {
        $this->entityManager->persist($row);
    }

    /**
     * A discarded import's rows. A bulk delete on purpose: an import holds up to 50,000 rows
     * (DataImportLimits::MAX_ROWS) that must not be loaded one by one to be removed.
     */
    public function deleteOfImport(DataImport $import): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(DataImportRow::class, 'r')
            ->where('r.import = :import')
            ->setParameter('import', $import)
            ->getQuery()
            ->execute();
    }
}
