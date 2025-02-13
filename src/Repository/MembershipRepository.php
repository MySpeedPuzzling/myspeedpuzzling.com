<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;

readonly final class MembershipRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws MembershipNotFound
     */
    public function get(string $membershipId): Membership
    {
        if (!Uuid::isValid($membershipId)) {
            throw new MembershipNotFound();
        }

        $membership = $this->entityManager->find(Membership::class, $membershipId);

        if ($membership === null) {
            throw new MembershipNotFound();
        }

        return $membership;
    }

    /**
     * @throws MembershipNotFound
     */
    public function getByPlayerId(string $playerId): Membership
    {
        if (!Uuid::isValid($playerId)) {
            throw new MembershipNotFound();
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $membership = $queryBuilder->select('membership')
                ->from(Membership::class, 'membership')
                ->where('membership.player = :playerId')
                ->setParameter('playerId', $playerId)
                ->getQuery()
                ->getSingleResult();

            assert($membership instanceof Membership);
            return $membership;
        } catch (NoResultException) {
            throw new MembershipNotFound();
        }
    }

    /**
     * @throws MembershipNotFound
     */
    public function getByStripeSubscriptionId(string $subscriptionId): Membership
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $membership = $queryBuilder->select('membership')
                ->from(Membership::class, 'membership')
                ->where('membership.stripeSubscriptionId = :subscriptionId')
                ->setParameter('subscriptionId', $subscriptionId)
                ->getQuery()
                ->getSingleResult();

            assert($membership instanceof Membership);
            return $membership;
        } catch (NoResultException) {
            throw new MembershipNotFound();
        }
    }

    public function save(Membership $membership): void
    {
        $this->entityManager->persist($membership);
    }
}
