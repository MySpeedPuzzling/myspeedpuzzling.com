<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\EmailSuppression;

readonly class EmailSuppressionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByEmail(string $email): null|EmailSuppression
    {
        return $this->entityManager->getRepository(EmailSuppression::class)
            ->findOneBy(['email' => EmailSuppression::canonicalize($email)]);
    }

    public function isSuppressed(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function save(EmailSuppression $suppression): void
    {
        $this->entityManager->persist($suppression);
    }
}
