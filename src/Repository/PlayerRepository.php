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

readonly class PlayerRepository
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

    public function save(Player $player): void
    {
        $this->entityManager->persist($player);
    }

    public function findByUserId(string $userId): null|Player
    {
        return $this->entityManager->getRepository(Player::class)
            ->findOneBy([
                'userId' => $userId,
            ]);
    }

    /**
     * player.email is NOT unique - production carries 7 known duplicate-email pairs
     * (deleted-and-re-registered Auth0 accounts, README §Current state) - so this
     * answers "is this address taken at all", never "which row is the right one".
     * When the answer has to exclude the asker, use emailBelongsToAnotherPlayer().
     */
    public function findByEmail(string $email): null|Player
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $player = $queryBuilder->select('player')
            ->from(Player::class, 'player')
            ->where('LOWER(player.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        assert($player === null || $player instanceof Player);

        return $player;
    }

    /**
     * "Is this address held by any player other than me?" - the question the
     * change-email flow actually has to ask. Doing it as findByEmail() plus an
     * ownership comparison would be non-deterministic: player.email is not unique
     * (7 known duplicate pairs) and an unordered LIMIT 1 could return either row,
     * so an address the caller shares with a stale duplicate would be accepted or
     * refused depending on what Postgres felt like returning.
     */
    public function emailBelongsToAnotherPlayer(string $email, string $exceptUserId): bool
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $count = $queryBuilder->select('COUNT(player.id)')
            ->from(Player::class, 'player')
            ->where('LOWER(player.email) = :email')
            ->andWhere('player.userId IS NULL OR player.userId != :userId')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('userId', $exceptUserId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
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
