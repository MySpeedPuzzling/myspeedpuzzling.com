<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Message\UpdateFeatureRequestStatus;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdateFeatureRequestStatusHandler
{
    public function __construct(
        private FeatureRequestRepository $featureRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws FeatureRequestNotFound
     */
    public function __invoke(UpdateFeatureRequestStatus $message): void
    {
        $featureRequest = $this->featureRequestRepository->get($message->featureRequestId);

        $featureRequest->updateStatus(
            $message->status,
            $this->clock->now(),
            $message->githubUrl,
            $message->adminComment,
        );
    }
}
