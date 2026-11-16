<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\EventResultsSheetTab;

readonly final class EventResultsSheetTabRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findOfSheet(EventResultsSheet $sheet, string $gid): null|EventResultsSheetTab
    {
        return $this->entityManager->getRepository(EventResultsSheetTab::class)->findOneBy([
            'sheet' => $sheet,
            'gid' => $gid,
        ]);
    }

    /**
     * @return list<EventResultsSheetTab>
     */
    public function ofSheet(EventResultsSheet $sheet): array
    {
        return $this->entityManager->getRepository(EventResultsSheetTab::class)->findBy(['sheet' => $sheet], ['id' => 'ASC']);
    }

    public function save(EventResultsSheetTab $tab): void
    {
        $this->entityManager->persist($tab);
    }

    public function delete(EventResultsSheetTab $tab): void
    {
        $this->entityManager->remove($tab);
    }
}
