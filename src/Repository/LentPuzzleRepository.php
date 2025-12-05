<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\LentPuzzleNotFound;

readonly final class LentPuzzleRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws LentPuzzleNotFound
     */
    public function get(string $lentPuzzleId): LentPuzzle
    {
        if (!Uuid::isValid($lentPuzzleId)) {
            throw new LentPuzzleNotFound();
        }

        $lentPuzzle = $this->entityManager->find(LentPuzzle::class, $lentPuzzleId);

        if ($lentPuzzle === null) {
            throw new LentPuzzleNotFound();
        }

        return $lentPuzzle;
    }

    public function findByOwnerAndPuzzle(Player $owner, Puzzle $puzzle): null|LentPuzzle
    {
        return $this->entityManager->getRepository(LentPuzzle::class)
            ->findOneBy([
                'ownerPlayer' => $owner,
                'puzzle' => $puzzle,
            ]);
    }

    public function findByOwnerNameAndPuzzle(string $ownerName, Puzzle $puzzle): null|LentPuzzle
    {
        return $this->entityManager->getRepository(LentPuzzle::class)
            ->findOneBy([
                'ownerName' => $ownerName,
                'puzzle' => $puzzle,
            ]);
    }

    public function countByOwner(string $ownerId): int
    {
        return $this->entityManager->getRepository(LentPuzzle::class)
            ->count([
                'ownerPlayer' => $ownerId,
            ]);
    }

    public function countByHolder(string $holderId): int
    {
        return $this->entityManager->getRepository(LentPuzzle::class)
            ->count([
                'currentHolderPlayer' => $holderId,
            ]);
    }

    public function save(LentPuzzle $lentPuzzle): void
    {
        $this->entityManager->persist($lentPuzzle);
    }

    public function delete(LentPuzzle $lentPuzzle): void
    {
        $this->entityManager->remove($lentPuzzle);
    }
}
