<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequest;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;

readonly final class FeatureRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FeatureRequestNotFound
     */
    public function get(string $featureRequestId): FeatureRequest
    {
        if (!Uuid::isValid($featureRequestId)) {
            throw new FeatureRequestNotFound();
        }

        $featureRequest = $this->entityManager->find(FeatureRequest::class, $featureRequestId);

        if ($featureRequest === null) {
            throw new FeatureRequestNotFound();
        }

        return $featureRequest;
    }

    public function save(FeatureRequest $featureRequest): void
    {
        $this->entityManager->persist($featureRequest);
    }
}
