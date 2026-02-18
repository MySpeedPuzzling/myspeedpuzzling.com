<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserBlock;
use SpeedPuzzling\Web\Exceptions\UserBlockNotFound;

readonly final class UserBlockRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(UserBlock $userBlock): void
    {
        $this->entityManager->persist($userBlock);
    }

    public function remove(UserBlock $userBlock): void
    {
        $this->entityManager->remove($userBlock);
    }

    public function findByBlockerAndBlocked(Player $blocker, Player $blocked): null|UserBlock
    {
        return $this->entityManager->getRepository(UserBlock::class)
            ->findOneBy([
                'blocker' => $blocker,
                'blocked' => $blocked,
            ]);
    }

    /**
     * @throws UserBlockNotFound
     */
    public function getByBlockerAndBlocked(Player $blocker, Player $blocked): UserBlock
    {
        $userBlock = $this->findByBlockerAndBlocked($blocker, $blocked);

        if ($userBlock === null) {
            throw new UserBlockNotFound();
        }

        return $userBlock;
    }
}
