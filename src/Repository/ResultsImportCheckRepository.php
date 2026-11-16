<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\ResultsImportCheck;
use SpeedPuzzling\Web\Exceptions\ResultsImportCheckNotFound;

readonly final class ResultsImportCheckRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ResultsImportCheckNotFound
     */
    public function get(string $checkId): ResultsImportCheck
    {
        if (Uuid::isValid($checkId) === false) {
            throw new ResultsImportCheckNotFound();
        }

        return $this->entityManager->find(ResultsImportCheck::class, $checkId) ?? throw new ResultsImportCheckNotFound();
    }

    public function latestOf(EventResultsSheet $source): null|ResultsImportCheck
    {
        return $this->entityManager->getRepository(ResultsImportCheck::class)->findOneBy(
            ['source' => $source],
            ['checkedAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    /**
     * Every check of the source but the newest $keep - each holds the whole sheet, older ones are of no use.
     *
     * @return list<ResultsImportCheck>
     */
    public function olderThanLatest(EventResultsSheet $source, int $keep): array
    {
        return array_slice(
            $this->entityManager->getRepository(ResultsImportCheck::class)->findBy(
                ['source' => $source],
                ['checkedAt' => 'DESC', 'id' => 'DESC'],
            ),
            $keep,
        );
    }

    public function save(ResultsImportCheck $check): void
    {
        $this->entityManager->persist($check);
    }

    public function delete(ResultsImportCheck $check): void
    {
        $this->entityManager->remove($check);
    }
}
