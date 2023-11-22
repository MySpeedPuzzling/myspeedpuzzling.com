<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;

readonly final class PlayerRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getByUserIdCreateIfNotExists(string $userId): Player
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $player = $queryBuilder->select('player')
                ->from(Player::class, 'player')
                ->where('player.userId = :userId')
                ->setParameter('userId', $userId)
                ->getQuery()
                ->getSingleResult();

            assert($player instanceof Player);
            return $player;
        } catch (NoResultException) {
            $player = new Player(
                Uuid::uuid7(),
                $userId,
                null,
                null,
                null,
                null,
                new \DateTimeImmutable(),
            );

            $this->entityManager->persist($player);

            return $player;
        }
    }
}
