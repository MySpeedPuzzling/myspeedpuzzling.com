<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PendingPuzzleApproval;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The approval of a newly added puzzle: its record with the moderator's corrections (a new photo included) and
 * what happens to its brand (docs/features/puzzle-approvals.md).
 */
#[Callback('validate')]
final class ApprovePuzzleFormData
{
    #[NotBlank]
    #[Length(max: 255)]
    public null|string $name = null;

    #[Length(max: 255)]
    public null|string $alternativeName = null;

    #[NotBlank]
    #[Positive]
    public null|int $piecesCount = null;

    #[Length(max: 100)]
    public null|string $ean = null;

    /**
     * The puzzle's EAN list as added (not a form field) - its codes pass even when invalid
     */
    public null|string $currentEan = null;

    #[Length(max: 100)]
    public null|string $identificationNumber = null;

    // A photo of the box instead of the player's (the name FormPhotoStash knows)
    public null|UploadedFile $puzzlePhoto = null;

    // Why - kept in the puzzle's history
    #[Length(max: 2000)]
    public null|string $note = null;

    // Only asked for a new (unapproved) brand
    public null|PuzzleApprovalBrandChoice $brandChoice = null;

    public null|string $targetManufacturerId = null;

    // Not form fields: the brand the puzzle was added with
    public null|string $currentManufacturerId = null;

    public bool $newBrand = false;

    public static function fromPendingPuzzle(PendingPuzzleApproval $puzzle, null|string $suggestedBrandId): self
    {
        $data = new self();
        $data->name = $puzzle->puzzleName;
        $data->alternativeName = $puzzle->alternativeNames->legacyAlternativeName();
        $data->piecesCount = $puzzle->piecesCount;
        $data->ean = $puzzle->ean;
        $data->currentEan = $puzzle->ean;
        $data->identificationNumber = $puzzle->identificationNumber;
        $data->currentManufacturerId = $puzzle->manufacturerId;
        $data->newBrand = $puzzle->manufacturerId !== null && $puzzle->manufacturerApproved === false;
        // A new brand: the likeliest existing one is ready for a merge or move; otherwise the puzzle's own brand
        $data->targetManufacturerId = $suggestedBrandId ?? ($data->newBrand ? null : $puzzle->manufacturerId);

        return $data;
    }

    /**
     * What happens to the brand: picked for a new brand; otherwise picking another brand moves the puzzle there.
     */
    public function resolvedBrandChoice(): PuzzleApprovalBrandChoice
    {
        if ($this->newBrand) {
            return $this->brandChoice ?? PuzzleApprovalBrandChoice::Keep;
        }

        return $this->targetManufacturerId !== null && $this->targetManufacturerId !== $this->currentManufacturerId
            ? PuzzleApprovalBrandChoice::UseExisting
            : PuzzleApprovalBrandChoice::Keep;
    }

    public function validate(ExecutionContextInterface $context): void
    {
        EanList::addViolations($context, 'ean', $this->ean, $this->currentEan);

        if ($this->newBrand && $this->brandChoice === null) {
            $context->buildViolation('Decide what happens to the new brand.')
                ->atPath('brandChoice')
                ->addViolation();
        }

        $needsTarget = in_array($this->resolvedBrandChoice(), [PuzzleApprovalBrandChoice::MergeInto, PuzzleApprovalBrandChoice::UseExisting], true);

        if ($needsTarget && ($this->targetManufacturerId === null || $this->targetManufacturerId === '')) {
            $context->buildViolation('Pick the existing brand.')
                ->atPath('targetManufacturerId')
                ->addViolation();
        }
    }
}
