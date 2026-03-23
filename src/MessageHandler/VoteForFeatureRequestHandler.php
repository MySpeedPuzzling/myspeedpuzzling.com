<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequestVote;
use SpeedPuzzling\Web\Exceptions\AlreadyVotedForFeatureRequest;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\VoteLimitReached;
use SpeedPuzzling\Web\Message\VoteForFeatureRequest;
use SpeedPuzzling\Web\Query\GetPlayerVoteCountThisMonth;
use SpeedPuzzling\Web\Query\HasPlayerVotedForFeatureRequest;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use SpeedPuzzling\Web\Repository\FeatureRequestVoteRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class VoteForFeatureRequestHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private FeatureRequestRepository $featureRequestRepository,
        private FeatureRequestVoteRepository $featureRequestVoteRepository,
        private HasPlayerVotedForFeatureRequest $hasPlayerVotedForFeatureRequest,
        private GetPlayerVoteCountThisMonth $getPlayerVoteCountThisMonth,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws FeatureRequestNotFound
     * @throws AlreadyVotedForFeatureRequest
     * @throws VoteLimitReached
     */
    public function __invoke(VoteForFeatureRequest $message): void
    {
        $player = $this->playerRepository->get($message->voterId);
        $featureRequest = $this->featureRequestRepository->get($message->featureRequestId);

        if (($this->hasPlayerVotedForFeatureRequest)($message->voterId, $message->featureRequestId)) {
            throw new AlreadyVotedForFeatureRequest();
        }

        $voteCount = ($this->getPlayerVoteCountThisMonth)($message->voterId);
        if ($voteCount >= 1) {
            throw new VoteLimitReached();
        }

        $vote = new FeatureRequestVote(
            id: Uuid::uuid7(),
            featureRequest: $featureRequest,
            voter: $player,
            votedAt: $this->clock->now(),
        );

        $featureRequest->incrementVoteCount();

        $this->featureRequestVoteRepository->save($vote);
    }
}
