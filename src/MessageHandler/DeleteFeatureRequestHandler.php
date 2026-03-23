<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\FeatureRequestCanNotBeEdited;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Message\DeleteFeatureRequest;
use SpeedPuzzling\Web\Query\HasFeatureRequestExternalVotes;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteFeatureRequestHandler
{
    public function __construct(
        private FeatureRequestRepository $featureRequestRepository,
        private HasFeatureRequestExternalVotes $hasFeatureRequestExternalVotes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FeatureRequestNotFound
     * @throws FeatureRequestCanNotBeEdited
     */
    public function __invoke(DeleteFeatureRequest $message): void
    {
        $featureRequest = $this->featureRequestRepository->get($message->featureRequestId);

        if ($featureRequest->author->id->toString() !== $message->playerId) {
            throw new FeatureRequestNotFound();
        }

        if (($this->hasFeatureRequestExternalVotes)($message->featureRequestId)) {
            throw new FeatureRequestCanNotBeEdited();
        }

        // Delete related votes and comments first
        $this->entityManager->createQuery('DELETE FROM SpeedPuzzling\Web\Entity\FeatureRequestVote v WHERE v.featureRequest = :fr')
            ->setParameter('fr', $featureRequest)
            ->execute();

        $this->entityManager->createQuery('DELETE FROM SpeedPuzzling\Web\Entity\FeatureRequestComment c WHERE c.featureRequest = :fr')
            ->setParameter('fr', $featureRequest)
            ->execute();

        $this->entityManager->remove($featureRequest);
    }
}
