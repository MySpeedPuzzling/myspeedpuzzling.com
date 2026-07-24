<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\Entity\UserAccount;

readonly final class ResetPasswordRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResetPasswordRequest $resetPasswordRequest): void
    {
        $this->entityManager->persist($resetPasswordRequest);
    }

    public function findBySelector(string $selector): null|ResetPasswordRequest
    {
        return $this->entityManager->getRepository(ResetPasswordRequest::class)
            ->findOneBy([
                'selector' => $selector,
            ]);
    }

    public function hasActiveRequestForUserAccount(UserAccount $userAccount, DateTimeImmutable $now): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(reset_password_request.id)')
            ->from(ResetPasswordRequest::class, 'reset_password_request')
            ->where('reset_password_request.userAccount = :userAccount')
            ->andWhere('reset_password_request.expiresAt > :now')
            ->setParameter('userAccount', $userAccount)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function removeAllForUserAccount(UserAccount $userAccount): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'reset_password_request')
            ->where('reset_password_request.userAccount = :userAccount')
            ->setParameter('userAccount', $userAccount)
            ->getQuery()
            ->execute();
    }

    public function removeExpiredBefore(DateTimeImmutable $expiredBefore): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'reset_password_request')
            ->where('reset_password_request.expiresAt <= :expiredBefore')
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->execute();
    }
}
