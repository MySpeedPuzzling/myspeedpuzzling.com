<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetSubscribedPlayers;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;

readonly final class PlayerRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
        private GetSubscribedPlayers $getSubscribedPlayers,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function get(string $playerId): Player
    {
        if (!Uuid::isValid($playerId)) {
            throw new PlayerNotFound();
        }

        $player = $this->entityManager->find(Player::class, $playerId);

        if ($player === null) {
            throw new PlayerNotFound();
        }

        return $player;
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     */
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
                $this->generateUniquePlayerCode->generate(),
                $userId,
                null,
                null,
                new \DateTimeImmutable(),
            );

            $this->entityManager->persist($player);

            return $player;
        }
    }

    /**
     * @throws PlayerNotFound
     */
    public function getByCode(string $code): Player
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $player = $queryBuilder->select('player')
                ->from(Player::class, 'player')
                ->where('LOWER(player.code) = :code')
                ->setParameter('code', strtolower($code))
                ->getQuery()
                ->getSingleResult();

            assert($player instanceof Player);
            return $player;
        } catch (NoResultException) {
            throw new PlayerNotFound();
        }
    }

    /**
     * @return array<Player>
     */
    public function findPlayersByFavoriteUuid(string $playerId): array
    {
        $subscribers = $this->getSubscribedPlayers->ofPlayer($playerId);

        if (empty($subscribers)) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('p')
            ->from(Player::class, 'p')
            ->where($qb->expr()->in('p.id', $subscribers));

        return $qb->getQuery()->getResult();
    }
}
