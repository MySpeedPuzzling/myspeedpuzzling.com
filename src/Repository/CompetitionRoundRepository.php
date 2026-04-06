<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;

readonly final class CompetitionRoundRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function get(string $roundId): CompetitionRound
    {
        if (!Uuid::isValid($roundId)) {
            throw new CompetitionRoundNotFound();
        }

        $round = $this->entityManager->find(CompetitionRound::class, $roundId);

        return $round ?? throw new CompetitionRoundNotFound();
    }

    public function save(CompetitionRound $round): void
    {
        $this->entityManager->persist($round);
    }

    public function delete(CompetitionRound $round): void
    {
        $this->entityManager->remove($round);
    }
}
