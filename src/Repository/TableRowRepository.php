<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\TableRow;
use SpeedPuzzling\Web\Exceptions\TableRowNotFound;

readonly final class TableRowRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TableRowNotFound
     */
    public function get(string $rowId): TableRow
    {
        if (!Uuid::isValid($rowId)) {
            throw new TableRowNotFound();
        }

        $row = $this->entityManager->find(TableRow::class, $rowId);

        return $row ?? throw new TableRowNotFound();
    }

    public function save(TableRow $row): void
    {
        $this->entityManager->persist($row);
    }

    public function delete(TableRow $row): void
    {
        $this->entityManager->remove($row);
    }
}
