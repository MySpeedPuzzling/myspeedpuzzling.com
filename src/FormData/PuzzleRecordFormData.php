<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Results\PuzzleRecord;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A puzzle's whole catalogue record, every field editable - the review of a change request and a
 * moderator's direct edit.
 */
#[Callback('validate')]
final class PuzzleRecordFormData
{
    #[NotBlank]
    #[Length(max: 255)]
    public null|string $name = null;

    #[Length(max: 255)]
    public null|string $alternativeName = null;

    #[NotBlank]
    public null|string $manufacturerId = null;

    #[NotBlank]
    #[Positive]
    public null|int $piecesCount = null;

    #[Length(max: 100)]
    public null|string $ean = null;

    /**
     * The puzzle's EAN list as it is now (not a form field) - its codes pass even when invalid
     */
    public null|string $currentEan = null;

    #[Length(max: 100)]
    public null|string $identificationNumber = null;

    // Keep or Proposed - an uploaded photo is used instead of either (the name FormPhotoStash knows)
    public PuzzleImageChoice $image = PuzzleImageChoice::Keep;

    public null|UploadedFile $puzzlePhoto = null;

    // Why - kept in the puzzle's history
    #[Length(max: 2000)]
    public null|string $note = null;

    public static function fromPuzzle(PuzzleRecord $puzzle): self
    {
        $data = new self();
        $data->name = $puzzle->name;
        $data->alternativeName = $puzzle->alternativeName;
        $data->manufacturerId = $puzzle->manufacturerId;
        $data->piecesCount = $puzzle->piecesCount;
        $data->ean = $puzzle->ean;
        $data->currentEan = $puzzle->ean;
        $data->identificationNumber = $puzzle->identificationNumber;

        return $data;
    }

    /**
     * What the player proposed where they proposed something, the puzzle as it is now everywhere else.
     */
    public static function fromChangeRequest(PuzzleChangeRequestOverview $request): self
    {
        $data = new self();
        $data->name = $request->hasNameChange() ? $request->proposedName : $request->puzzleName;
        $data->alternativeName = $request->puzzleAlternativeName;
        $data->manufacturerId = $request->hasManufacturerChange() ? $request->proposedManufacturerId : $request->puzzleManufacturerId;
        $data->piecesCount = $request->hasPiecesCountChange() ? $request->proposedPiecesCount : $request->puzzlePiecesCount;
        $data->ean = $request->hasEanChange() ? $request->proposedEan : $request->puzzleEan;
        $data->currentEan = $request->puzzleEan;
        $data->identificationNumber = $request->hasIdentificationNumberChange() ? $request->proposedIdentificationNumber : $request->puzzleIdentificationNumber;
        $data->image = $request->hasImageChange() ? PuzzleImageChoice::Proposed : PuzzleImageChoice::Keep;

        return $data;
    }

    /**
     * The validated form as the values to save - an uploaded photo always wins over the keep / proposed choice.
     */
    public function toValues(): PuzzleRecordValues
    {
        assert($this->name !== null && $this->piecesCount !== null);

        return new PuzzleRecordValues(
            name: $this->name,
            alternativeName: $this->alternativeName,
            manufacturerId: $this->manufacturerId,
            piecesCount: $this->piecesCount,
            ean: $this->ean,
            identificationNumber: $this->identificationNumber,
            image: $this->puzzlePhoto !== null ? PuzzleImageChoice::Upload : $this->image,
            uploadedImage: $this->puzzlePhoto,
        );
    }

    public function validate(ExecutionContextInterface $context): void
    {
        EanList::addViolations($context, 'ean', $this->ean, $this->currentEan);
    }
}
