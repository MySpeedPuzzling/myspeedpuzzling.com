<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Referral;
use SpeedPuzzling\Web\Exceptions\ReferralNotFound;

readonly class ReferralRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ReferralNotFound
     */
    public function get(string $referralId): Referral
    {
        if (!Uuid::isValid($referralId)) {
            throw new ReferralNotFound();
        }

        $referral = $this->entityManager->find(Referral::class, $referralId);

        if ($referral === null) {
            throw new ReferralNotFound();
        }

        return $referral;
    }

    /**
     * @throws ReferralNotFound
     */
    public function getBySubscriberId(string $playerId): Referral
    {
        if (!Uuid::isValid($playerId)) {
            throw new ReferralNotFound();
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $referral = $queryBuilder->select('referral')
                ->from(Referral::class, 'referral')
                ->where('referral.subscriber = :playerId')
                ->setParameter('playerId', $playerId)
                ->getQuery()
                ->getSingleResult();

            assert($referral instanceof Referral);
            return $referral;
        } catch (NoResultException) {
            throw new ReferralNotFound();
        }
    }

    public function save(Referral $referral): void
    {
        $this->entityManager->persist($referral);
    }
}
