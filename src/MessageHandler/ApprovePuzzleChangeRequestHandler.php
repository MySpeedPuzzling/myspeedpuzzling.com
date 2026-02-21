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

        $selectedFields = $message->selectedFields;

        // Apply proposed changes to puzzle (only selected fields)
        if (in_array('name', $selectedFields, true) && $changeRequest->proposedName !== null) {
            $puzzle->name = array_key_exists('name', $message->overrides) && is_string($message->overrides['name'])
                ? $message->overrides['name']
                : $changeRequest->proposedName;
        }

        if (in_array('manufacturer', $selectedFields, true) && $changeRequest->proposedManufacturer !== null) {
            $puzzle->manufacturer = $changeRequest->proposedManufacturer;
        }

        if (in_array('piecesCount', $selectedFields, true) && $changeRequest->proposedPiecesCount !== null) {
            $puzzle->piecesCount = array_key_exists('piecesCount', $message->overrides) && is_int($message->overrides['piecesCount'])
                ? $message->overrides['piecesCount']
                : $changeRequest->proposedPiecesCount;
        }

        $ean = $puzzle->ean;
        $identificationNumber = $puzzle->identificationNumber;

        if (in_array('ean', $selectedFields, true)) {
            $ean = array_key_exists('ean', $message->overrides) && is_string($message->overrides['ean'])
                ? $message->overrides['ean']
                : ($changeRequest->proposedEan ?? $puzzle->ean);
        }

        if (in_array('identificationNumber', $selectedFields, true)) {
            $identificationNumber = array_key_exists('identificationNumber', $message->overrides) && is_string($message->overrides['identificationNumber'])
                ? $message->overrides['identificationNumber']
                : ($changeRequest->proposedIdentificationNumber ?? $puzzle->identificationNumber);
        }

        $puzzle->updateProductIdentifiers(
            ean: $ean,
            identificationNumber: $identificationNumber,
        );

        if (in_array('image', $selectedFields, true) && $changeRequest->proposedImage !== null) {
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
