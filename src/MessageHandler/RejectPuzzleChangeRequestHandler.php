<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Services\ChangeRequestCreatedBrandSettler;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Message\RecalculateKarma;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private ChangeRequestCreatedBrandSettler $changeRequestCreatedBrandSettler,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PuzzleChangeRequestNotFound
     * @throws PuzzleChangeRequestAlreadyReviewed
     * @throws PlayerNotFound
     */
    public function __invoke(RejectPuzzleChangeRequest $message): void
    {
        $changeRequest = $this->puzzleChangeRequestRepository->get($message->changeRequestId);

        // The message was locked on this puzzle - another one would not keep concurrent decisions apart
        if ($changeRequest->puzzle->id->toString() !== strtolower($message->puzzleId)) {
            throw new PuzzleChangeRequestNotFound();
        }

        // Only a pending request is decided - a second decision would flip it, log it and notify the player again
        if ($changeRequest->status !== PuzzleReportStatus::Pending) {
            throw new PuzzleChangeRequestAlreadyReviewed();
        }
        $reviewer = $this->playerRepository->get($message->reviewerId);

        $changeRequest->reject($reviewer, $this->clock->now(), $message->rejectionReason);

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::ChangeRequestRejected,
            decidedBy: $reviewer,
            source: $message->decisionSource,
            puzzleId: $changeRequest->puzzle->id,
            puzzleName: $changeRequest->puzzle->name,
            changeRequestId: $changeRequest->id,
            note: $message->rejectionReason,
            details: $message->decisionNote !== null ? ['decisionNote' => $message->decisionNote] : null,
        );

        // A brand the proposal created is deleted when nothing else uses it
        $this->changeRequestCreatedBrandSettler->settle($changeRequest, null, $reviewer, $message->decisionSource);

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

        // Karma (docs/features/karma.md): the moderator's decision counts, the declined proposal takes nothing away
        $this->messageBus->dispatch(RecalculateKarma::forPlayers($changeRequest->reporter, $reviewer));
    }
}
