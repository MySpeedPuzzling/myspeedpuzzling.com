<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Value\OauthProvider;

readonly final class OauthIdentityRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(OauthIdentity $oauthIdentity): void
    {
        $this->entityManager->persist($oauthIdentity);
    }

    public function remove(OauthIdentity $oauthIdentity): void
    {
        $this->entityManager->remove($oauthIdentity);
    }

    public function findByProviderUserId(OauthProvider $provider, string $providerUserId): null|OauthIdentity
    {
        return $this->entityManager->getRepository(OauthIdentity::class)
            ->findOneBy([
                'provider' => $provider,
                'providerUserId' => $providerUserId,
            ]);
    }

    public function findForUserAccount(UserAccount $userAccount, OauthProvider $provider): null|OauthIdentity
    {
        return $this->entityManager->getRepository(OauthIdentity::class)
            ->findOneBy([
                'userAccount' => $userAccount,
                'provider' => $provider,
            ]);
    }

    public function countForUserAccount(UserAccount $userAccount): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(oauth_identity.id)')
            ->from(OauthIdentity::class, 'oauth_identity')
            ->where('oauth_identity.userAccount = :userAccount')
            ->setParameter('userAccount', $userAccount)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
