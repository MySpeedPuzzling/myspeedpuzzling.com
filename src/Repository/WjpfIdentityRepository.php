<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\WjpfIdentity;

readonly final class WjpfIdentityRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPlayerId(string $playerId): null|WjpfIdentity
    {
        if (Uuid::isValid($playerId) === false) {
            return null;
        }

        $identity = $this->entityManager->createQueryBuilder()
            ->select('identity')
            ->from(WjpfIdentity::class, 'identity')
            ->where('identity.player = :playerId')
            ->setParameter('playerId', $playerId)
            ->getQuery()
            ->getOneOrNullResult();

        assert($identity === null || $identity instanceof WjpfIdentity);

        return $identity;
    }

    /**
     * Duplicate WJPF ids are allowed by the schema on purpose - this is how the sync spots
     * one so it can be logged rather than crashing a multi-hour run.
     */
    public function findOtherPlayerHoldingWjpfId(string $wjpfId, string $exceptPlayerId): null|WjpfIdentity
    {
        if (Uuid::isValid($exceptPlayerId) === false) {
            return null;
        }

        $identity = $this->entityManager->createQueryBuilder()
            ->select('identity')
            ->from(WjpfIdentity::class, 'identity')
            ->where('identity.wjpfId = :wjpfId')
            ->andWhere('identity.player != :exceptPlayerId')
            ->setParameter('wjpfId', $wjpfId)
            ->setParameter('exceptPlayerId', $exceptPlayerId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        assert($identity === null || $identity instanceof WjpfIdentity);

        return $identity;
    }

    public function save(WjpfIdentity $identity): void
    {
        $this->entityManager->persist($identity);
    }
}
