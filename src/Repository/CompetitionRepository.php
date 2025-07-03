<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;

readonly final class CompetitionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function get(string $competitionId): Competition
    {
        if (!Uuid::isValid($competitionId)) {
            throw new CompetitionNotFound();
        }

        $competition = $this->entityManager->find(Competition::class, $competitionId);

        return $competition ?? throw new CompetitionNotFound();
    }

    public function save(Competition $competition): void
    {
        $this->entityManager->persist($competition);
    }

    public function delete(Competition $competition): void
    {
        $this->entityManager->remove($competition);
    }
}
