<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\FeatureRequestVote;

readonly final class FeatureRequestVoteRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(FeatureRequestVote $vote): void
    {
        $this->entityManager->persist($vote);
    }
}
