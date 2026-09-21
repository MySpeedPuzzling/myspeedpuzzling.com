<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\GuestLinkRequest;
use SpeedPuzzling\Web\Exceptions\GuestLinkRequestNotFound;

readonly final class GuestLinkRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(GuestLinkRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    /**
     * @throws GuestLinkRequestNotFound
     */
    public function get(string $requestId): GuestLinkRequest
    {
        $request = Uuid::isValid($requestId) ? $this->entityManager->find(GuestLinkRequest::class, $requestId) : null;

        if ($request === null) {
            throw new GuestLinkRequestNotFound();
        }

        return $request;
    }

    /**
     * Asking again replaces the question that is still open - one guest, one open question.
     */
    public function removePending(string $requesterId, string $guestKey): void
    {
        $this->entityManager->createQuery(
            'DELETE FROM ' . GuestLinkRequest::class . ' r WHERE r.requester = :requester AND r.guestKey = :guestKey AND r.resolvedAt IS NULL',
        )->setParameter('requester', $requesterId)->setParameter('guestKey', $guestKey)->execute();
    }
}
