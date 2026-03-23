<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\FeatureRequestCanNotBeEdited;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Message\EditFeatureRequest;
use SpeedPuzzling\Web\Query\HasFeatureRequestExternalVotes;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditFeatureRequestHandler
{
    public function __construct(
        private FeatureRequestRepository $featureRequestRepository,
        private HasFeatureRequestExternalVotes $hasFeatureRequestExternalVotes,
    ) {
    }

    /**
     * @throws FeatureRequestNotFound
     * @throws FeatureRequestCanNotBeEdited
     */
    public function __invoke(EditFeatureRequest $message): void
    {
        $featureRequest = $this->featureRequestRepository->get($message->featureRequestId);

        if ($featureRequest->author->id->toString() !== $message->playerId) {
            throw new FeatureRequestNotFound();
        }

        if (($this->hasFeatureRequestExternalVotes)($message->featureRequestId)) {
            throw new FeatureRequestCanNotBeEdited();
        }

        $featureRequest->edit($message->title, $message->description);
    }
}
