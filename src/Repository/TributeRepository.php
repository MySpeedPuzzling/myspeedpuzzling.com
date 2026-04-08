<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Tribute;
use SpeedPuzzling\Web\Exceptions\TributeNotFound;

readonly class TributeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TributeNotFound
     */
    public function get(string $tributeId): Tribute
    {
        if (!Uuid::isValid($tributeId)) {
            throw new TributeNotFound();
        }

        $tribute = $this->entityManager->find(Tribute::class, $tributeId);

        if ($tribute === null) {
            throw new TributeNotFound();
        }

        return $tribute;
    }

    /**
     * @throws TributeNotFound
     */
    public function getBySubscriberId(string $playerId): Tribute
    {
        if (!Uuid::isValid($playerId)) {
            throw new TributeNotFound();
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $tribute = $queryBuilder->select('tribute')
                ->from(Tribute::class, 'tribute')
                ->where('tribute.subscriber = :playerId')
                ->setParameter('playerId', $playerId)
                ->getQuery()
                ->getSingleResult();

            assert($tribute instanceof Tribute);
            return $tribute;
        } catch (NoResultException) {
            throw new TributeNotFound();
        }
    }

    public function save(Tribute $tribute): void
    {
        $this->entityManager->persist($tribute);
    }
}
