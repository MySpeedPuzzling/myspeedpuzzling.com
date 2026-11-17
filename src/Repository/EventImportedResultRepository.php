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
     * Every team's rows - one, or a relay team's one per puzzle (solo-and-relays.md §2), in the order of its puzzles.
     *
     * @return array<string, non-empty-list<EventImportedResult>> by team key
     */
    public function ofTab(EventResultsSheetTab $tab): array
    {
        $byKey = [];

        foreach ($this->entityManager->getRepository(EventImportedResult::class)->findBy(['tab' => $tab], ['leg' => 'ASC']) as $result) {
            $byKey[$result->teamKey][] = $result;
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
