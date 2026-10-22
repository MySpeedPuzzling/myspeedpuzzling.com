<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;

readonly class PlayerRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
        private ClockInterface $clock,
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
                $this->clock->now(),
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
     * Muted players whose mute ended before the given moment.
     *
     * @return list<Player>
     */
    public function findWithMessagingMuteEndedBefore(DateTimeImmutable $moment): array
    {
        /** @var list<Player> $players */
        $players = $this->entityManager->createQueryBuilder()
            ->select('player')
            ->from(Player::class, 'player')
            ->where('player.messagingMuted = true')
            ->andWhere('player.messagingMutedUntil < :moment')
            ->setParameter('moment', $moment)
            ->getQuery()
            ->getResult();

        return $players;
    }
}
