<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use SpeedPuzzling\Web\Entity\UserAccount;

readonly final class UserAccountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(UserAccount $userAccount): void
    {
        $this->entityManager->persist($userAccount);
    }

    public function findByUserId(string $userId): null|UserAccount
    {
        return $this->entityManager->getRepository(UserAccount::class)
            ->findOneBy([
                'userId' => $userId,
            ]);
    }

    public function findByEmail(string $email): null|UserAccount
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $userAccount = $queryBuilder->select('user_account')
                ->from(UserAccount::class, 'user_account')
                ->where('LOWER(user_account.email) = :email')
                ->setParameter('email', UserAccount::canonicalizeEmail($email))
                ->getQuery()
                ->getSingleResult();

            assert($userAccount instanceof UserAccount);
            return $userAccount;
        } catch (NoResultException) {
            return null;
        }
    }
}
