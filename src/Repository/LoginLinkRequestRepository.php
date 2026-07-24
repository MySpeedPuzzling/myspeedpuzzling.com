<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\LoginLinkRequest;

readonly final class LoginLinkRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(LoginLinkRequest $loginLinkRequest): void
    {
        $this->entityManager->persist($loginLinkRequest);
    }

    public function findByHashedToken(string $hashedToken): null|LoginLinkRequest
    {
        return $this->entityManager->getRepository(LoginLinkRequest::class)
            ->findOneBy([
                'hashedToken' => $hashedToken,
            ]);
    }

    /**
     * Atomic consumption: the UPDATE only matches while the row is still open, so two
     * parallel clicks on the same link can never both authenticate (a read-modify-write
     * would). Returns false when somebody else consumed it first.
     */
    public function consumeIfOpen(LoginLinkRequest $loginLinkRequest, DateTimeImmutable $now): bool
    {
        $affectedRows = $this->entityManager->createQueryBuilder()
            ->update(LoginLinkRequest::class, 'login_link_request')
            ->set('login_link_request.consumedAt', ':now')
            ->where('login_link_request.id = :id')
            ->andWhere('login_link_request.consumedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('id', $loginLinkRequest->id)
            ->getQuery()
            ->execute();

        if ($affectedRows !== 1) {
            return false;
        }

        // Keep the managed entity in sync with the row we just wrote
        $loginLinkRequest->consume($now);

        return true;
    }

    public function removeExpiredBefore(DateTimeImmutable $expiredBefore): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(LoginLinkRequest::class, 'login_link_request')
            ->where('login_link_request.expiresAt <= :expiredBefore')
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->execute();
    }
}
