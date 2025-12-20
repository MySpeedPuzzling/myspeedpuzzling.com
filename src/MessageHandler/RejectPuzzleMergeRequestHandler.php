<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Message\RejectPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RejectPuzzleMergeRequestHandler
{
    public function __construct(
        private PuzzleMergeRequestRepository $puzzleMergeRequestRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleMergeRequestNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(RejectPuzzleMergeRequest $message): void
    {
        $mergeRequest = $this->puzzleMergeRequestRepository->get($message->mergeRequestId);
        $reviewer = $this->playerRepository->get($message->reviewerId);

        $mergeRequest->reject($reviewer, $this->clock->now(), $message->rejectionReason);

        // Create notification for reporter
        $notification = new Notification(
            id: Uuid::uuid7(),
            player: $mergeRequest->reporter,
            type: NotificationType::PuzzleMergeRequestRejected,
            notifiedAt: $this->clock->now(),
            targetMergeRequest: $mergeRequest,
        );
        $this->entityManager->persist($notification);

        $this->entityManager->flush();
    }
}
