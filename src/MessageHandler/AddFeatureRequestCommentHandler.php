<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequestComment;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddFeatureRequestComment;
use SpeedPuzzling\Web\Repository\FeatureRequestCommentRepository;
use SpeedPuzzling\Web\Repository\FeatureRequestRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddFeatureRequestCommentHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private FeatureRequestRepository $featureRequestRepository,
        private FeatureRequestCommentRepository $featureRequestCommentRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws FeatureRequestNotFound
     */
    public function __invoke(AddFeatureRequestComment $message): void
    {
        $player = $this->playerRepository->get($message->authorId);
        $featureRequest = $this->featureRequestRepository->get($message->featureRequestId);

        $comment = new FeatureRequestComment(
            id: Uuid::uuid7(),
            featureRequest: $featureRequest,
            author: $player,
            content: $message->content,
            createdAt: $this->clock->now(),
        );

        $this->featureRequestCommentRepository->save($comment);
    }
}
