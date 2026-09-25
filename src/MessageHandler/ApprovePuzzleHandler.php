<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleApproval;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyApproved;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
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
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
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
     */
    public function __invoke(ApprovePuzzle $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $reviewer = $this->playerRepository->get($message->reviewerId);

        if ($puzzle->approved) {
            throw new PuzzleAlreadyApproved();
        }

        $name = trim($message->name);

        if ($name === '' || $message->piecesCount <= 0) {
            throw new InvalidPuzzleApproval('Name and pieces count are required.');
        }

        $currentBrand = $puzzle->manufacturer;
        $targetBrand = $this->resolveTargetBrand($message, $currentBrand);

        // --- validated, now apply ---

        $before = [
            'name' => $puzzle->name,
            'piecesCount' => $puzzle->piecesCount,
            'ean' => $puzzle->ean,
            'identificationNumber' => $puzzle->identificationNumber,
            'manufacturerId' => $currentBrand?->id->toString(),
            'manufacturerName' => $currentBrand?->name,
        ];

        $puzzle->name = $name;
        $puzzle->piecesCount = $message->piecesCount;
        $puzzle->updateProductIdentifiers(
            ean: self::nullIfBlank($message->ean),
            identificationNumber: self::nullIfBlank($message->identificationNumber),
        );

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

        if ($targetBrand !== null) {
            $puzzle->manufacturer = $targetBrand;
        }

        if ($message->brandChoice === PuzzleApprovalBrandChoice::MergeInto && $currentBrand !== null && $targetBrand !== null) {
            $movedPuzzles = $this->mergeBrand($currentBrand, $targetBrand);

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
                    'movedPuzzles' => $movedPuzzles,
                ],
            );
        }

        $puzzle->approve($reviewer, $this->clock->now());

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::PuzzleApproved,
            decidedBy: $reviewer,
            puzzleId: $puzzle->id,
            puzzleName: $name,
            manufacturerId: $puzzle->manufacturer?->id,
            details: [
                'brandChoice' => $message->brandChoice->value,
                'before' => $before,
                'after' => [
                    'name' => $puzzle->name,
                    'piecesCount' => $puzzle->piecesCount,
                    'ean' => $puzzle->ean,
                    'identificationNumber' => $puzzle->identificationNumber,
                    'manufacturerId' => $puzzle->manufacturer?->id->toString(),
                    'manufacturerName' => $puzzle->manufacturer?->name,
                ],
            ],
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

    /**
     * Folds a duplicate brand into an approved one: its puzzles and the change
     * requests proposing it move over, what only the duplicate knew is kept, and
     * the duplicate is deleted. A puzzle and a change request proposal are the
     * only things that reference a brand - a new reference must be moved here too.
     *
     * @return int number of puzzles moved
     */
    private function mergeBrand(Manufacturer $duplicate, Manufacturer $into): int
    {
        $puzzles = $this->puzzleRepository->findByManufacturer($duplicate);

        foreach ($puzzles as $puzzle) {
            $puzzle->manufacturer = $into;
        }

        foreach ($this->puzzleChangeRequestRepository->findByProposedManufacturer($duplicate) as $changeRequest) {
            $changeRequest->proposedManufacturerMergedInto($into);
        }

        if ($into->logo === null && $duplicate->logo !== null) {
            $into->logo = $duplicate->logo;
        }

        if ($into->eanPrefix === null && $duplicate->eanPrefix !== null) {
            $into->eanPrefix = $duplicate->eanPrefix;
        }

        $this->manufacturerRepository->delete($duplicate);

        return count($puzzles);
    }

    private static function nullIfBlank(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
