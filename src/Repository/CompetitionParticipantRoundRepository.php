<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;

readonly final class CompetitionParticipantRoundRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(CompetitionParticipantRound $participantRound): void
    {
        $this->entityManager->persist($participantRound);
    }
}
