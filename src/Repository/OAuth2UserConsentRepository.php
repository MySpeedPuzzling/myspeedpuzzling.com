<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2UserConsent;

readonly final class OAuth2UserConsentRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPlayerAndClient(string $playerId, string $clientIdentifier): null|OAuth2UserConsent
    {
        /** @var null|OAuth2UserConsent $result */
        $result = $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(OAuth2UserConsent::class, 'c')
            ->where('c.player = :playerId')
            ->andWhere('c.clientIdentifier = :clientIdentifier')
            ->setParameter('playerId', $playerId)
            ->setParameter('clientIdentifier', $clientIdentifier)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function save(OAuth2UserConsent $consent): void
    {
        $this->entityManager->persist($consent);
        $this->entityManager->flush();
    }
}
