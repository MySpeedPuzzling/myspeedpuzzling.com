<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Exceptions\EventResultsSheetNotFound;

readonly final class EventResultsSheetRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws EventResultsSheetNotFound
     */
    public function get(string $sheetId): EventResultsSheet
    {
        if (Uuid::isValid($sheetId) === false) {
            throw new EventResultsSheetNotFound();
        }

        return $this->entityManager->find(EventResultsSheet::class, $sheetId) ?? throw new EventResultsSheetNotFound();
    }

    public function findBySpreadsheetId(string $spreadsheetId): null|EventResultsSheet
    {
        return $this->entityManager->getRepository(EventResultsSheet::class)->findOneBy(['spreadsheetId' => $spreadsheetId]);
    }

    /**
     * @return list<EventResultsSheet>
     */
    public function all(): array
    {
        return $this->entityManager->getRepository(EventResultsSheet::class)->findBy([], ['addedAt' => 'ASC']);
    }

    public function save(EventResultsSheet $sheet): void
    {
        $this->entityManager->persist($sheet);
    }
}
