<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

readonly final class ResetPasswordRequestRepository implements ResetPasswordRequestRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function createResetPasswordRequest(
        object $user,
        DateTimeInterface $expiresAt,
        string $selector,
        string $hashedToken,
    ): ResetPasswordRequestInterface {
        assert($user instanceof UserAccount);

        return new ResetPasswordRequest(Uuid::uuid7(), $user, $expiresAt, $selector, $hashedToken);
    }

    public function getUserIdentifier(object $user): string
    {
        assert($user instanceof UserAccount);

        return $user->id->toString();
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->entityManager->persist($resetPasswordRequest);
        // Bundle contract requires the request to be stored here - the ResetPasswordHelper
        // runs in the HTTP layer, outside any Messenger handler transaction
        $this->entityManager->flush();
    }

    public function findResetPasswordRequest(string $selector): null|ResetPasswordRequestInterface
    {
        return $this->entityManager->getRepository(ResetPasswordRequest::class)
            ->findOneBy([
                'selector' => $selector,
            ]);
    }

    public function getMostRecentNonExpiredRequestDate(object $user): null|DateTimeInterface
    {
        $resetPasswordRequest = $this->entityManager->createQueryBuilder()
            ->select('reset_password_request')
            ->from(ResetPasswordRequest::class, 'reset_password_request')
            ->where('reset_password_request.user = :user')
            ->setParameter('user', $user)
            ->orderBy('reset_password_request.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($resetPasswordRequest instanceof ResetPasswordRequestInterface && !$resetPasswordRequest->isExpired()) {
            return $resetPasswordRequest->getRequestedAt();
        }

        return null;
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->removeRequests($resetPasswordRequest->getUser());
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        $removed = $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'reset_password_request')
            ->where('reset_password_request.expiresAt <= :time')
            ->setParameter('time', $this->clock->now()->modify('-1 week'))
            ->getQuery()
            ->execute();

        return (int) $removed;
    }

    public function removeRequests(object $user): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ResetPasswordRequest::class, 'reset_password_request')
            ->where('reset_password_request.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
