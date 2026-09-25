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
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RejectPuzzleMergeRequestHandler
{
    public function __construct(
        private PuzzleMergeRequestRepository $puzzleMergeRequestRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
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

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::MergeRequestRejected,
            decidedBy: $reviewer,
            source: $message->decisionSource,
            puzzleId: $mergeRequest->sourcePuzzle?->id,
            puzzleName: $mergeRequest->sourcePuzzle?->name,
            mergeRequestId: $mergeRequest->id,
            note: $message->rejectionReason,
            details: ['reportedDuplicatePuzzleIds' => $mergeRequest->reportedDuplicatePuzzleIds],
        );

        // Create notification for reporter (if reporter still exists)
        if ($mergeRequest->reporter !== null) {
            $notification = new Notification(
                id: Uuid::uuid7(),
                player: $mergeRequest->reporter,
                type: NotificationType::PuzzleMergeRequestRejected,
                notifiedAt: $this->clock->now(),
                targetMergeRequest: $mergeRequest,
            );
            $this->entityManager->persist($notification);
        }
    }
}
