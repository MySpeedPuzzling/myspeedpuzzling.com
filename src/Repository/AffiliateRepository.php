<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Affiliate;
use SpeedPuzzling\Web\Exceptions\AffiliateNotFound;

readonly class AffiliateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws AffiliateNotFound
     */
    public function get(string $affiliateId): Affiliate
    {
        if (!Uuid::isValid($affiliateId)) {
            throw new AffiliateNotFound();
        }

        $affiliate = $this->entityManager->find(Affiliate::class, $affiliateId);

        if ($affiliate === null) {
            throw new AffiliateNotFound();
        }

        return $affiliate;
    }

    /**
     * @throws AffiliateNotFound
     */
    public function getByCode(string $code): Affiliate
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $affiliate = $queryBuilder->select('affiliate')
                ->from(Affiliate::class, 'affiliate')
                ->where('LOWER(affiliate.code) = LOWER(:code)')
                ->setParameter('code', $code)
                ->getQuery()
                ->getSingleResult();

            assert($affiliate instanceof Affiliate);
            return $affiliate;
        } catch (NoResultException) {
            throw new AffiliateNotFound();
        }
    }

    /**
     * @throws AffiliateNotFound
     */
    public function getByPlayerId(string $playerId): Affiliate
    {
        if (!Uuid::isValid($playerId)) {
            throw new AffiliateNotFound();
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $affiliate = $queryBuilder->select('affiliate')
                ->from(Affiliate::class, 'affiliate')
                ->where('affiliate.player = :playerId')
                ->setParameter('playerId', $playerId)
                ->getQuery()
                ->getSingleResult();

            assert($affiliate instanceof Affiliate);
            return $affiliate;
        } catch (NoResultException) {
            throw new AffiliateNotFound();
        }
    }

    public function save(Affiliate $affiliate): void
    {
        $this->entityManager->persist($affiliate);
    }
}
