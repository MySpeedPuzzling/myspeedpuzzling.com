<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\ResultsImportLog;

readonly final class ResultsImportLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $importId): null|ResultsImportLog
    {
        if (Uuid::isValid($importId) === false) {
            return null;
        }

        return $this->entityManager->find(ResultsImportLog::class, $importId);
    }

    /**
     * @return list<ResultsImportLog> newest first
     */
    public function latestOf(EventResultsSheet $source, int $limit): array
    {
        return $this->entityManager->getRepository(ResultsImportLog::class)->findBy(
            ['source' => $source],
            ['importedAt' => 'DESC', 'id' => 'DESC'],
            $limit,
        );
    }

    public function save(ResultsImportLog $log): void
    {
        $this->entityManager->persist($log);
    }
}
