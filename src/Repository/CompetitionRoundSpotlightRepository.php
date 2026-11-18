<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundSpotlight;

readonly final class CompetitionRoundSpotlightRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The round's spotlight - null while nobody is in it.
     */
    public function find(string $roundId): null|CompetitionRoundSpotlight
    {
        if (!Uuid::isValid($roundId)) {
            return null;
        }

        return $this->entityManager->find(CompetitionRoundSpotlight::class, ['round' => strtolower($roundId)]);
    }

    public function save(CompetitionRoundSpotlight $spotlight): void
    {
        $this->entityManager->persist($spotlight);
    }

    public function delete(CompetitionRoundSpotlight $spotlight): void
    {
        $this->entityManager->remove($spotlight);
    }
}
