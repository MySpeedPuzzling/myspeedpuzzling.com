<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2UserConsent;
use SpeedPuzzling\Web\Message\RevokeOAuth2Consent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RevokeOAuth2ConsentHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RevokeOAuth2Consent $message): void
    {
        $consent = $this->entityManager->find(OAuth2UserConsent::class, $message->consentId);

        if ($consent === null || $consent->player->id->toString() !== $message->playerId) {
            return;
        }

        $clientIdentifier = $consent->clientIdentifier;
        $userIdentifier = $message->playerId;

        // Revoke all access tokens for this user+client
        $this->entityManager->createQueryBuilder()
            ->update(AccessToken::class, 'at')
            ->set('at.revoked', ':revoked')
            ->where('at.userIdentifier = :userIdentifier')
            ->andWhere('at.client = :client')
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('client', $clientIdentifier, 'string')
            ->getQuery()
            ->execute();

        // Revoke all refresh tokens linked to those access tokens
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder
            ->update(RefreshToken::class, 'rt')
            ->set('rt.revoked', ':revoked')
            ->where($queryBuilder->expr()->in(
                'rt.accessToken',
                $this->entityManager->createQueryBuilder()
                    ->select('at.identifier')
                    ->from(AccessToken::class, 'at')
                    ->where('at.userIdentifier = :userIdentifier')
                    ->andWhere('at.client = :client')
                    ->getDQL()
            ))
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('client', $clientIdentifier, 'string')
            ->getQuery()
            ->execute();

        // Revoke all authorization codes for this user+client
        $this->entityManager->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->where('ac.userIdentifier = :userIdentifier')
            ->andWhere('ac.client = :client')
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('client', $clientIdentifier, 'string')
            ->getQuery()
            ->execute();

        // Remove the consent record
        $this->entityManager->remove($consent);
        $this->entityManager->flush();
    }
}
