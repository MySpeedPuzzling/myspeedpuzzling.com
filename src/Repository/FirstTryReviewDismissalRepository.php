<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\FirstTryReviewDismissal;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;

readonly final class FirstTryReviewDismissalRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(FirstTryReviewDismissal $dismissal): void
    {
        $this->entityManager->persist($dismissal);
    }

    public function find(Player $player, PuzzleSolvingTime $solvingTime): null|FirstTryReviewDismissal
    {
        return $this->entityManager->getRepository(FirstTryReviewDismissal::class)
            ->findOneBy([
                'player' => $player,
                'solvingTime' => $solvingTime,
            ]);
    }
}
