<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PrivateProfileViewer;

readonly final class PrivateProfileViewerRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PrivateProfileViewer $privateProfileViewer): void
    {
        $this->entityManager->persist($privateProfileViewer);
    }

    public function remove(PrivateProfileViewer $privateProfileViewer): void
    {
        $this->entityManager->remove($privateProfileViewer);
    }

    public function findByOwnerAndViewer(Player $owner, Player $viewer): null|PrivateProfileViewer
    {
        return $this->entityManager->getRepository(PrivateProfileViewer::class)
            ->findOneBy([
                'owner' => $owner,
                'viewer' => $viewer,
            ]);
    }

    public function countByOwner(Player $owner): int
    {
        return $this->entityManager->getRepository(PrivateProfileViewer::class)
            ->count(['owner' => $owner]);
    }
}
