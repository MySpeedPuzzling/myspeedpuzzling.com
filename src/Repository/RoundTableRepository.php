<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\RoundTable;
use SpeedPuzzling\Web\Exceptions\RoundTableNotFound;

readonly final class RoundTableRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws RoundTableNotFound
     */
    public function get(string $tableId): RoundTable
    {
        if (!Uuid::isValid($tableId)) {
            throw new RoundTableNotFound();
        }

        $table = $this->entityManager->find(RoundTable::class, $tableId);

        return $table ?? throw new RoundTableNotFound();
    }

    public function save(RoundTable $table): void
    {
        $this->entityManager->persist($table);
    }

    public function delete(RoundTable $table): void
    {
        $this->entityManager->remove($table);
    }
}
