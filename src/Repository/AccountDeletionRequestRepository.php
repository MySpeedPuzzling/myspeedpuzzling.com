<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\AccountDeletionRequest;
use SpeedPuzzling\Web\Entity\UserAccount;

readonly final class AccountDeletionRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(AccountDeletionRequest $accountDeletionRequest): void
    {
        $this->entityManager->persist($accountDeletionRequest);
    }

    public function findBySelector(string $selector): null|AccountDeletionRequest
    {
        return $this->entityManager->getRepository(AccountDeletionRequest::class)
            ->findOneBy([
                'selector' => $selector,
            ]);
    }

    public function removeAllForUserAccount(UserAccount $userAccount): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(AccountDeletionRequest::class, 'account_deletion_request')
            ->where('account_deletion_request.userAccount = :userAccount')
            ->setParameter('userAccount', $userAccount)
            ->getQuery()
            ->execute();
    }

    public function removeExpiredBefore(DateTimeImmutable $expiredBefore): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(AccountDeletionRequest::class, 'account_deletion_request')
            ->where('account_deletion_request.expiresAt <= :expiredBefore')
            ->setParameter('expiredBefore', $expiredBefore)
            ->getQuery()
            ->execute();
    }
}
