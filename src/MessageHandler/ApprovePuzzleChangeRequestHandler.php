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
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Approves a puzzle change request. The admin review sends the whole puzzle as the reviewer wants it
 * (proposed fields or not, image kept, proposed or uploaded); the internal API names the proposed fields
 * to apply as proposed. Both end up as PuzzleRecordValues, saved by PuzzleRecordUpdater. The other names are
 * always applied as a diff (docs/features/puzzle-names/README.md, "Writing names and codes"): the review's list
 * against the list it was loaded with, the proposal against the list when it was proposed.
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

        $values = $message->reviewed !== null
            ? self::reviewedValues($message->reviewed, $message->reviewedFrom, $puzzle)
            : self::proposedValues($changeRequest, $puzzle, $message);

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
            $overrides = [];

            if ($message->alternativeNamesOverride !== null) {
                $overrides['alternativeNames'] = $message->alternativeNamesOverride->toArray();
            }

            if ($message->nameLanguageOverride !== false) {
                $overrides['nameLanguage'] = $message->nameLanguageOverride;
            }

            $details = ['selectedFields' => $message->selectedFields]
                + ($overrides !== [] ? ['overrides' => $overrides] : [])
                + $details;
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
     * The admin review's values - its other names applied as a diff against the ones it was loaded with.
     */
    private static function reviewedValues(PuzzleRecordValues $reviewed, null|PuzzleNames $reviewedFrom, Puzzle $puzzle): PuzzleRecordValues
    {
        if ($reviewedFrom === null) {
            return $reviewed;
        }

        return new PuzzleRecordValues(
            name: $reviewed->name,
            nameLanguage: $reviewed->nameLanguage,
            alternativeNames: $reviewed->alternativeNames->diff($reviewedFrom)->applyTo($puzzle->alternativeNames()),
            manufacturerId: $reviewed->manufacturerId,
            piecesCount: $reviewed->piecesCount,
            ean: $reviewed->ean,
            identificationNumber: $reviewed->identificationNumber,
            image: $reviewed->image,
            uploadedImage: $reviewed->uploadedImage,
            recordVersion: $reviewed->recordVersion,
        );
    }

    /**
     * The internal API's selectedFields: those fields as proposed (or as overridden), everything else as the puzzle
     * has it now. The other names are applied as a diff against the list when proposed.
     */
    private static function proposedValues(
        PuzzleChangeRequest $changeRequest,
        Puzzle $puzzle,
        ApprovePuzzleChangeRequest $message,
    ): PuzzleRecordValues {
        $selected = static fn (string $field): bool => in_array($field, $message->selectedFields, true);

        $manufacturer = $selected('manufacturer')
            ? ($changeRequest->proposedManufacturer ?? $puzzle->manufacturer)
            : $puzzle->manufacturer;

        $alternativeNames = $puzzle->alternativeNames();

        if ($selected('alternativeNames')) {
            $proposedNames = $message->alternativeNamesOverride !== null
                ? $message->alternativeNamesOverride->diff(PuzzleNames::fromArray($changeRequest->originalAlternativeNames ?? []))
                : $changeRequest->proposedNamesDiff();

            $alternativeNames = $proposedNames->applyTo($alternativeNames);
        }

        $nameLanguage = $puzzle->nameLanguage;

        if ($selected('nameLanguage') && $message->nameLanguageOverride !== false) {
            $nameLanguage = $message->nameLanguageOverride;
        } elseif ($selected('nameLanguage') && $changeRequest->proposedAlternativeNames !== null) {
            $nameLanguage = $changeRequest->proposedNameLanguage;
        }

        return new PuzzleRecordValues(
            name: $selected('name') ? ($changeRequest->proposedName ?? $puzzle->name) : $puzzle->name,
            nameLanguage: $nameLanguage,
            alternativeNames: $alternativeNames,
            manufacturerId: $manufacturer?->id->toString(),
            piecesCount: $selected('piecesCount') ? ($changeRequest->proposedPiecesCount ?? $puzzle->piecesCount) : $puzzle->piecesCount,
            ean: $selected('ean') ? ($changeRequest->proposedEan ?? $puzzle->ean) : $puzzle->ean,
            identificationNumber: $selected('identificationNumber')
                ? ($changeRequest->proposedIdentificationNumber ?? $puzzle->identificationNumber)
                : $puzzle->identificationNumber,
            image: $selected('image') && $changeRequest->proposedImage !== null
                ? PuzzleImageChoice::Proposed
                : PuzzleImageChoice::Keep,
            recordVersion: $message->recordVersion,
        );
    }
}
