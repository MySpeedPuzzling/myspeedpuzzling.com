<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ApprovePuzzleChangeRequestHandler
{
    public function __construct(
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleChangeRequestNotFound
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(ApprovePuzzleChangeRequest $message): void
    {
        $changeRequest = $this->puzzleChangeRequestRepository->get($message->changeRequestId);
        $puzzle = $this->puzzleRepository->get($changeRequest->puzzle->id->toString());
        $reviewer = $this->playerRepository->get($message->reviewerId);

        // Apply proposed changes to puzzle
        if ($changeRequest->proposedName !== null) {
            $puzzle->name = $changeRequest->proposedName;
        }

        if ($changeRequest->proposedManufacturer !== null) {
            $puzzle->manufacturer = $changeRequest->proposedManufacturer;
        }

        if ($changeRequest->proposedPiecesCount !== null) {
            $puzzle->piecesCount = $changeRequest->proposedPiecesCount;
        }

        $puzzle->updateProductIdentifiers(
            ean: $changeRequest->proposedEan ?? $puzzle->ean,
            identificationNumber: $changeRequest->proposedIdentificationNumber ?? $puzzle->identificationNumber,
        );

        if ($changeRequest->proposedImage !== null) {
            $puzzle->image = $changeRequest->proposedImage;
        }

        // Mark request as approved
        $changeRequest->approve($reviewer, $this->clock->now());

        // Create notification for reporter
        $notification = new Notification(
            id: Uuid::uuid7(),
            player: $changeRequest->reporter,
            type: NotificationType::PuzzleChangeRequestApproved,
            notifiedAt: $this->clock->now(),
            targetChangeRequest: $changeRequest,
        );
        $this->entityManager->persist($notification);
    }
}
