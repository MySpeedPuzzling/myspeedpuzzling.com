<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PartnerRequest;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotFound;

readonly final class PartnerRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PartnerRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    /**
     * @throws PartnerRequestNotFound
     */
    public function get(string $requestId): PartnerRequest
    {
        $request = Uuid::isValid($requestId) ? $this->entityManager->find(PartnerRequest::class, $requestId) : null;

        if ($request === null) {
            throw new PartnerRequestNotFound();
        }

        return $request;
    }

    /**
     * The sender's request to the listing - at most one (partner_request_listing_sender_unique).
     */
    public function findFor(string $listingId, string $fromPlayerId): null|PartnerRequest
    {
        if (Uuid::isValid($listingId) === false || Uuid::isValid($fromPlayerId) === false) {
            return null;
        }

        return $this->entityManager->getRepository(PartnerRequest::class)->findOneBy([
            'listing' => $listingId,
            'fromPlayer' => $fromPlayerId,
        ]);
    }
}
