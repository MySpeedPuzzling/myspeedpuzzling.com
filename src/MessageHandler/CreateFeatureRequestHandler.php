<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequest;
use SpeedPuzzling\Web\Entity\FeatureRequestVote;
use SpeedPuzzling\Web\Exceptions\FeatureRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\CreateFeatureRequest;
use SpeedPuzzling\Web\Query\CountPlayerFeatureRequestsThisMonth;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use SpeedPuzzling\Web\Repository\FeatureRequestVoteRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreateFeatureRequestHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private FeatureRequestRepository $featureRequestRepository,
        private FeatureRequestVoteRepository $featureRequestVoteRepository,
        private CountPlayerFeatureRequestsThisMonth $countPlayerFeatureRequestsThisMonth,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws FeatureRequestLimitReached
     */
    public function __invoke(CreateFeatureRequest $message): string
    {
        $player = $this->playerRepository->get($message->authorId);

        $count = ($this->countPlayerFeatureRequestsThisMonth)($message->authorId);
        if ($count >= 3) {
            throw new FeatureRequestLimitReached();
        }

        $now = $this->clock->now();

        $featureRequest = new FeatureRequest(
            id: Uuid::uuid7(),
            author: $player,
            title: $message->title,
            description: $message->description,
            createdAt: $now,
        );

        $this->featureRequestRepository->save($featureRequest);

        $vote = new FeatureRequestVote(
            id: Uuid::uuid7(),
            featureRequest: $featureRequest,
            voter: $player,
            votedAt: $now,
        );

        $this->featureRequestVoteRepository->save($vote);

        $featureRequestId = $featureRequest->id->toString();
        $this->featureRequestRepository->recalculateVoteCount($featureRequestId);

        return $featureRequestId;
    }
}
