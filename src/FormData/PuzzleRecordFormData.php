<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Results\PuzzleRecord;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A puzzle's whole catalogue record, every field editable - the review of a change request and a
 * moderator's direct edit. Every name in the names editor.
 */
#[Callback('validate')]
final class PuzzleRecordFormData
{
    use PuzzleCodesFields;

    #[Valid]
    public PuzzleNamesFormData $names;

    // The record the form was loaded with (PuzzleRecordVersion) - a hidden field
    public null|string $recordVersion = null;

    #[NotBlank]
    public null|string $manufacturerId = null;

    #[NotBlank]
    #[Positive]
    public null|int $piecesCount = null;

    // Keep or Proposed - an uploaded photo is used instead of either (the name FormPhotoStash knows)
    public PuzzleImageChoice $image = PuzzleImageChoice::Keep;

    public null|UploadedFile $puzzlePhoto = null;

    // Why - kept in the puzzle's history
    #[Length(max: 2000)]
    public null|string $note = null;

    public function __construct()
    {
        $this->names = new PuzzleNamesFormData();
    }

    /**
     * The puzzle as it is now, in the names editor.
     */
    public static function fromPuzzle(PuzzleRecord $puzzle): self
    {
        $data = new self();
        $data->names = PuzzleNamesFormData::fromNames($puzzle->name, $puzzle->nameLanguage, $puzzle->alternativeNames);
        $data->recordVersion = $puzzle->recordVersion();
        $data->manufacturerId = $puzzle->manufacturerId;
        $data->piecesCount = $puzzle->piecesCount;
        $data->loadCodes($puzzle->ean, $puzzle->identificationNumber, currentEan: $puzzle->ean);

        return $data;
    }

    /**
     * What the player proposed where they proposed something, the puzzle as it is now everywhere else - the other
     * names are the puzzle's now with the proposal applied as a diff (PuzzleChangeRequestOverview::reviewAlternativeNames()).
     */
    public static function fromChangeRequest(PuzzleChangeRequestOverview $request): self
    {
        $data = new self();
        $data->names = PuzzleNamesFormData::fromNames(
            $request->hasNameChange() && $request->proposedName !== null ? $request->proposedName : $request->puzzleName,
            $request->reviewNameLanguage(),
            $request->reviewAlternativeNames(),
        );
        // The proposal may add names: the cap counts the names the puzzle has
        $data->names->loadedAlternativeNamesCount = count($request->puzzleAlternativeNames);
        $data->recordVersion = $request->puzzleRecordVersion;
        $data->manufacturerId = $request->hasManufacturerChange() ? $request->proposedManufacturerId : $request->puzzleManufacturerId;
        $data->piecesCount = $request->hasPiecesCountChange() ? $request->proposedPiecesCount : $request->puzzlePiecesCount;
        $data->loadCodes(
            $request->hasEanChange() ? $request->proposedEan : $request->puzzleEan,
            $request->hasIdentificationNumberChange() ? $request->proposedIdentificationNumber : $request->puzzleIdentificationNumber,
            currentEan: $request->puzzleEan,
        );
        $data->image = $request->hasImageChange() ? PuzzleImageChoice::Proposed : PuzzleImageChoice::Keep;

        return $data;
    }

    /**
     * The validated form as the values to save - an uploaded photo always wins over the keep / proposed choice.
     */
    public function toValues(): PuzzleRecordValues
    {
        assert($this->piecesCount !== null);

        return new PuzzleRecordValues(
            name: $this->names->mainTitle(),
            nameLanguage: $this->names->nameLanguage,
            alternativeNames: $this->names->toPuzzleNames(),
            manufacturerId: $this->manufacturerId,
            piecesCount: $this->piecesCount,
            eans: $this->eanList(),
            brandCodes: $this->brandCodeList(),
            image: $this->puzzlePhoto !== null ? PuzzleImageChoice::Upload : $this->image,
            uploadedImage: $this->puzzlePhoto,
            recordVersion: $this->recordVersion,
        );
    }

    public function validate(ExecutionContextInterface $context): void
    {
        $this->validateCodes($context);
    }
}
