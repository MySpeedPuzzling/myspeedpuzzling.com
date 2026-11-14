<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;

readonly final class DataImportSheetRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DataImportSheet $sheet): void
    {
        $this->entityManager->persist($sheet);
    }

    /**
     * @return list<DataImportSheet>
     */
    public function ofImport(DataImport $import): array
    {
        return $this->entityManager->getRepository(DataImportSheet::class)->findBy(['import' => $import], ['position' => 'ASC']);
    }

    /**
     * A discarded import's sheets. A bulk delete on purpose: up to a hundred sheets, none of them needs loading
     * (their rows go first - DataImportRowRepository::deleteOfImport()).
     */
    public function deleteOfImport(DataImport $import): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(DataImportSheet::class, 's')
            ->where('s.import = :import')
            ->setParameter('import', $import)
            ->getQuery()
            ->execute();
    }
}
