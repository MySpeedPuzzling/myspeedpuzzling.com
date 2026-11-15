<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\DataImportSettings;

/**
 * The spreadsheet import's settings row - the safety switch (docs/features/data-import/adding.md "The switch").
 */
readonly final class DataImportSettingsRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The row, made when it is first written to - no row means "never paused".
     */
    public function getOrCreate(): DataImportSettings
    {
        $settings = $this->entityManager->find(DataImportSettings::class, DataImportSettings::ID);

        if ($settings === null) {
            $settings = new DataImportSettings();
            $this->entityManager->persist($settings);
        }

        return $settings;
    }

    public function find(): null|DataImportSettings
    {
        return $this->entityManager->find(DataImportSettings::class, DataImportSettings::ID);
    }

    /**
     * As the database says it now - asked by a long-running job between two rows, so never from a loaded object.
     */
    public function isAddingPaused(): bool
    {
        /** @var list<array{addingPaused: bool}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.addingPaused')
            ->from(DataImportSettings::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', DataImportSettings::ID)
            ->getQuery()
            ->getArrayResult();

        return ($rows[0]['addingPaused'] ?? false) === true;
    }
}
