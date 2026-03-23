<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequest;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;

readonly final class FeatureRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $database,
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

    public function recalculateVoteCount(string $featureRequestId): void
    {
        $this->database->executeStatement(
            'UPDATE feature_request SET vote_count = (SELECT COUNT(*) FROM feature_request_vote WHERE feature_request_id = :id) WHERE id = :id',
            ['id' => $featureRequestId],
        );
    }
}
