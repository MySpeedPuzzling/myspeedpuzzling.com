<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\DismissedHint;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\HintType;

readonly final class DismissedHintRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DismissedHint $dismissedHint): void
    {
        $this->entityManager->persist($dismissedHint);
    }

    public function findByPlayerAndType(Player $player, HintType $type): null|DismissedHint
    {
        return $this->entityManager->getRepository(DismissedHint::class)
            ->findOneBy([
                'player' => $player,
                'type' => $type,
            ]);
    }
}
