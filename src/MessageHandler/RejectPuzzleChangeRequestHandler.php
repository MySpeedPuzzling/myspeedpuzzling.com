<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RejectPuzzleChangeRequestHandler
{
    public function __construct(
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
    ) {
    }

    /**
     * @throws PuzzleChangeRequestNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(RejectPuzzleChangeRequest $message): void
    {
        $changeRequest = $this->puzzleChangeRequestRepository->get($message->changeRequestId);
        $reviewer = $this->playerRepository->get($message->reviewerId);

        $changeRequest->reject($reviewer, $this->clock->now(), $message->rejectionReason);

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::ChangeRequestRejected,
            decidedBy: $reviewer,
            puzzleId: $changeRequest->puzzle->id,
            puzzleName: $changeRequest->puzzle->name,
            changeRequestId: $changeRequest->id,
            note: $message->rejectionReason,
        );

        // Delete proposal image if exists
        if ($changeRequest->proposedImage !== null && $this->filesystem->fileExists($changeRequest->proposedImage)) {
            $this->filesystem->delete($changeRequest->proposedImage);
        }

        // Create notification for reporter
        $notification = new Notification(
            id: Uuid::uuid7(),
            player: $changeRequest->reporter,
            type: NotificationType::PuzzleChangeRequestRejected,
            notifiedAt: $this->clock->now(),
            targetChangeRequest: $changeRequest,
        );
        $this->entityManager->persist($notification);
    }
}
