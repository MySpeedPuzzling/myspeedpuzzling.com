<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\EventImportedResult;
use SpeedPuzzling\Web\Entity\EventResultsSheetTab;

readonly final class EventImportedResultRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, EventImportedResult> by team key
     */
    public function ofTab(EventResultsSheetTab $tab): array
    {
        $byKey = [];

        foreach ($this->entityManager->getRepository(EventImportedResult::class)->findBy(['tab' => $tab]) as $result) {
            $byKey[$result->teamKey] = $result;
        }

        return $byKey;
    }

    public function save(EventImportedResult $result): void
    {
        $this->entityManager->persist($result);
    }

    public function delete(EventImportedResult $result): void
    {
        $this->entityManager->remove($result);
    }
}
