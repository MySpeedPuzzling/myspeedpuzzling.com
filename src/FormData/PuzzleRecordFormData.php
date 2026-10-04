<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Results\PuzzleRecord;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleNames;
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
 * moderator's direct edit.
 *
 * The names come from the names editor (`names`, PuzzleRecordFormType option `names_editor`) or - until every page
 * uses it - from the single `name` and `alternativeName` fields.
 */
#[Callback('validate')]
final class PuzzleRecordFormData
{
    #[Valid]
    public null|PuzzleNamesFormData $names = null;

    // The record the form was loaded with (PuzzleRecordVersion) - a hidden field
    public null|string $recordVersion = null;

    // Without the names editor: the main title...
    #[Length(max: 255)]
    public null|string $name = null;

    // ...and the one other name it edits
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

    // Not form fields, without the names editor: the names the form was loaded with - the single field edits one of them
    public null|string $loadedNameLanguage = null;

    public PuzzleNames $loadedAlternativeNames;

    public function __construct()
    {
        $this->loadedAlternativeNames = new PuzzleNames();
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
        $data->alternativeName = $request->puzzleAlternativeNames->legacyAlternativeName();
        $data->loadedNameLanguage = $request->puzzleNameLanguage;
        $data->loadedAlternativeNames = $request->puzzleAlternativeNames;
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
        assert($this->piecesCount !== null);

        return new PuzzleRecordValues(
            name: $this->names !== null ? $this->names->mainTitle() : $this->name ?? '',
            nameLanguage: $this->names !== null ? $this->names->nameLanguage : $this->loadedNameLanguage,
            alternativeNames: $this->names !== null
                ? $this->names->toPuzzleNames()
                : $this->loadedAlternativeNames->withLegacyAlternativeName($this->alternativeName),
            manufacturerId: $this->manufacturerId,
            piecesCount: $this->piecesCount,
            ean: $this->ean,
            identificationNumber: $this->identificationNumber,
            image: $this->puzzlePhoto !== null ? PuzzleImageChoice::Upload : $this->image,
            uploadedImage: $this->puzzlePhoto,
            recordVersion: $this->recordVersion,
        );
    }

    public function validate(ExecutionContextInterface $context): void
    {
        EanList::addViolations($context, 'ean', $this->ean, $this->currentEan);

        // The names editor checks its main title itself (PuzzleNamesFormData)
        if ($this->names === null && trim($this->name ?? '') === '') {
            $context->buildViolation((new NotBlank())->message)
                ->atPath('name')
                ->addViolation();
        }
    }
}
