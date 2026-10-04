<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\PuzzleRecordUpdater;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Approves a puzzle change request. The admin review sends the whole puzzle as the reviewer wants it
 * (proposed fields or not, image kept, proposed or uploaded); the internal API names the proposed fields
 * to apply as proposed. Both end up as PuzzleRecordValues, saved by PuzzleRecordUpdater.
 *
 * Everything is validated before the first change: a handler that throws after
 * mutating still has its changes flushed by a later flush in the same request.
 */
#[AsMessageHandler]
readonly final class ApprovePuzzleChangeRequestHandler
{
    public function __construct(
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private PuzzleRecordUpdater $puzzleRecordUpdater,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
    ) {
    }

    /**
     * @throws PuzzleChangeRequestNotFound
     * @throws PuzzleChangeRequestAlreadyReviewed
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerNotFound
     * @throws InvalidPuzzleValues
     */
    public function __invoke(ApprovePuzzleChangeRequest $message): void
    {
        $changeRequest = $this->puzzleChangeRequestRepository->get($message->changeRequestId);

        // The message was locked on this puzzle - another one would not keep concurrent saves apart
        if ($changeRequest->puzzle->id->toString() !== strtolower($message->puzzleId)) {
            throw new PuzzleChangeRequestNotFound();
        }

        if ($changeRequest->status !== PuzzleReportStatus::Pending) {
            throw new PuzzleChangeRequestAlreadyReviewed();
        }

        $puzzle = $this->puzzleRepository->get($changeRequest->puzzle->id->toString());
        $reviewer = $this->playerRepository->get($message->reviewerId);

        $values = $message->reviewed ?? self::proposedValues($changeRequest, $puzzle, $message->selectedFields);

        // Validates every value before it changes anything
        $change = $this->puzzleRecordUpdater->update(
            $puzzle,
            $values,
            $changeRequest->proposedImage,
            $changeRequest->proposedImageRatio,
        );

        $changeRequest->approve($reviewer, $this->clock->now());

        $details = ['image' => $values->image->value] + $change;

        if ($message->reviewed === null) {
            $details = ['selectedFields' => $message->selectedFields] + $details;
        }

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::ChangeRequestApproved,
            decidedBy: $reviewer,
            source: $message->decisionSource,
            puzzleId: $puzzle->id,
            puzzleName: $puzzle->name,
            changeRequestId: $changeRequest->id,
            note: $message->decisionNote,
            details: $details,
        );

        $notification = new Notification(
            id: Uuid::uuid7(),
            player: $changeRequest->reporter,
            type: NotificationType::PuzzleChangeRequestApproved,
            notifiedAt: $this->clock->now(),
            targetChangeRequest: $changeRequest,
        );
        $this->entityManager->persist($notification);
    }

    /**
     * The internal API's selectedFields: those fields as proposed, everything else as the puzzle has it now.
     *
     * @param list<string> $selectedFields
     */
    private static function proposedValues(
        PuzzleChangeRequest $changeRequest,
        Puzzle $puzzle,
        array $selectedFields,
    ): PuzzleRecordValues {
        $selected = static fn (string $field): bool => in_array($field, $selectedFields, true);

        $manufacturer = $selected('manufacturer')
            ? ($changeRequest->proposedManufacturer ?? $puzzle->manufacturer)
            : $puzzle->manufacturer;

        return new PuzzleRecordValues(
            name: $selected('name') ? ($changeRequest->proposedName ?? $puzzle->name) : $puzzle->name,
            nameLanguage: $puzzle->nameLanguage,
            alternativeNames: $puzzle->alternativeNames(),
            manufacturerId: $manufacturer?->id->toString(),
            piecesCount: $selected('piecesCount') ? ($changeRequest->proposedPiecesCount ?? $puzzle->piecesCount) : $puzzle->piecesCount,
            ean: $selected('ean') ? ($changeRequest->proposedEan ?? $puzzle->ean) : $puzzle->ean,
            identificationNumber: $selected('identificationNumber')
                ? ($changeRequest->proposedIdentificationNumber ?? $puzzle->identificationNumber)
                : $puzzle->identificationNumber,
            image: $selected('image') && $changeRequest->proposedImage !== null
                ? PuzzleImageChoice::Proposed
                : PuzzleImageChoice::Keep,
        );
    }
}
