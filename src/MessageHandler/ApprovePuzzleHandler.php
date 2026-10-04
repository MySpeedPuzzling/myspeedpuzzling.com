<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleApproval;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyApproved;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ManufacturerMerger;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\PuzzleRecordUpdater;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Approves a newly added puzzle from the approval queue, with the reviewer's
 * corrections, and settles its brand (docs/features/puzzle-approvals.md).
 *
 * Everything is validated before the first change: a handler that throws after
 * mutating still has its changes flushed by a later flush in the same request.
 */
#[AsMessageHandler]
readonly final class ApprovePuzzleHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private ManufacturerRepository $manufacturerRepository,
        private ManufacturerMerger $manufacturerMerger,
        private PuzzleRecordUpdater $puzzleRecordUpdater,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws ManufacturerNotFound
     * @throws PuzzleAlreadyApproved
     * @throws InvalidPuzzleApproval
     * @throws InvalidPuzzleValues
     */
    public function __invoke(ApprovePuzzle $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $reviewer = $this->playerRepository->get($message->reviewerId);

        if ($puzzle->approved) {
            throw new PuzzleAlreadyApproved();
        }

        $currentBrand = $puzzle->manufacturer;
        $targetBrand = $this->resolveTargetBrand($message, $currentBrand);

        // Validates every value before it changes anything - the record, the image included, gets the final brand
        $change = $this->puzzleRecordUpdater->update($puzzle, new PuzzleRecordValues(
            name: $message->name,
            nameLanguage: $puzzle->nameLanguage,
            // The form's single alternative name field edits one name of the list
            alternativeNames: $puzzle->alternativeNames()->withLegacyAlternativeName($message->alternativeName),
            manufacturerId: ($targetBrand ?? $currentBrand)?->id->toString(),
            piecesCount: $message->piecesCount,
            ean: $message->ean,
            identificationNumber: $message->identificationNumber,
            image: $message->uploadedImage !== null ? PuzzleImageChoice::Upload : PuzzleImageChoice::Keep,
            uploadedImage: $message->uploadedImage,
        ));
        $name = $puzzle->name;

        if ($message->brandChoice === PuzzleApprovalBrandChoice::Approve && $currentBrand !== null) {
            $currentBrand->approved = true;

            $this->puzzleModerationDecisionRecorder->record(
                action: PuzzleModerationAction::BrandApproved,
                decidedBy: $reviewer,
                puzzleId: $puzzle->id,
                puzzleName: $name,
                manufacturerId: $currentBrand->id,
                details: ['manufacturerName' => $currentBrand->name],
            );
        }

        if ($message->brandChoice === PuzzleApprovalBrandChoice::MergeInto && $currentBrand !== null && $targetBrand !== null) {
            $merged = $this->manufacturerMerger->merge($currentBrand, $targetBrand);

            $this->puzzleModerationDecisionRecorder->record(
                action: PuzzleModerationAction::BrandMerged,
                decidedBy: $reviewer,
                puzzleId: $puzzle->id,
                puzzleName: $name,
                manufacturerId: $targetBrand->id,
                details: [
                    'mergedManufacturerId' => $currentBrand->id->toString(),
                    'mergedManufacturerName' => $currentBrand->name,
                    'intoManufacturerName' => $targetBrand->name,
                    'movedPuzzles' => $merged['movedPuzzles'],
                    'redirectedSlugs' => $merged['redirectedSlugs'],
                ],
            );
        }

        $puzzle->approve($reviewer, $this->clock->now());

        $note = $message->note !== null ? trim($message->note) : '';

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::PuzzleApproved,
            decidedBy: $reviewer,
            puzzleId: $puzzle->id,
            puzzleName: $name,
            manufacturerId: $puzzle->manufacturer?->id,
            note: $note !== '' ? $note : null,
            details: [
                'brandChoice' => $message->brandChoice->value,
                'image' => $message->uploadedImage !== null ? PuzzleImageChoice::Upload->value : PuzzleImageChoice::Keep->value,
            ] + $change,
        );
    }

    /**
     * @throws ManufacturerNotFound
     * @throws InvalidPuzzleApproval
     */
    private function resolveTargetBrand(ApprovePuzzle $message, null|Manufacturer $currentBrand): null|Manufacturer
    {
        switch ($message->brandChoice) {
            case PuzzleApprovalBrandChoice::Keep:
                return null;

            case PuzzleApprovalBrandChoice::Approve:
                if ($currentBrand === null) {
                    throw new InvalidPuzzleApproval('The puzzle has no brand to approve.');
                }

                return null;

            case PuzzleApprovalBrandChoice::UseExisting:
            case PuzzleApprovalBrandChoice::MergeInto:
                if ($message->targetManufacturerId === null || $message->targetManufacturerId === '') {
                    throw new InvalidPuzzleApproval('Pick the brand to use.');
                }

                $target = $this->manufacturerRepository->get($message->targetManufacturerId);

                if ($target->approved === false) {
                    throw new InvalidPuzzleApproval('The target brand must be an approved brand.');
                }

                if ($currentBrand !== null && $target->id->equals($currentBrand->id)) {
                    throw new InvalidPuzzleApproval('The target brand is the puzzle\'s current brand.');
                }

                // A merge deletes the merged brand - only ever a new, unapproved one
                if (
                    $message->brandChoice === PuzzleApprovalBrandChoice::MergeInto
                    && ($currentBrand === null || $currentBrand->approved)
                ) {
                    throw new InvalidPuzzleApproval('Only a new, unapproved brand can be merged away.');
                }

                return $target;
        }
    }
}
