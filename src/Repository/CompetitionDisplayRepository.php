<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;

readonly final class CompetitionDisplayRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionDisplayNotFound
     */
    public function get(string $displayId): CompetitionDisplay
    {
        if (!Uuid::isValid($displayId)) {
            throw new CompetitionDisplayNotFound();
        }

        $display = $this->entityManager->find(CompetitionDisplay::class, $displayId);

        return $display ?? throw new CompetitionDisplayNotFound();
    }

    public function countOf(Competition $competition): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(display.id)')
            ->from(CompetitionDisplay::class, 'display')
            ->where('display.competition = :competition')
            ->setParameter('competition', $competition)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function save(CompetitionDisplay $display): void
    {
        $this->entityManager->persist($display);
    }

    public function delete(CompetitionDisplay $display): void
    {
        $this->entityManager->remove($display);
    }
}
