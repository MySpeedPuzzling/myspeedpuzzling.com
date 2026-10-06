<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleRecord;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Callback('validateEan')]
final class ProposePuzzleChangesFormData
{
    use PuzzleCodesFields;

    // Every name - the main title, its language and the other names (the names editor)
    #[Valid]
    public PuzzleNamesFormData $names;

    // The record the form was loaded with (PuzzleRecordVersion) - a hidden field; the proposal is filed against it
    public null|string $recordVersion = null;

    // A brand id, or a typed brand name (ManufacturerResolver) - '' = no brand
    #[Length(max: 255)]
    public string $brand = '';

    #[NotBlank]
    #[Positive]
    #[Range(min: 10, max: 25000)]
    public int $piecesCount = 0;

    #[Image(
        maxSize: '20M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Please upload a valid image (JPEG, PNG, or WebP, up to 20 MB).'
    )]
    public null|UploadedFile $photo = null;

    public function __construct()
    {
        $this->names = new PuzzleNamesFormData();
    }

    /**
     * The form as it opens: the puzzle as it is - every name with its language (the record), brand, pieces, codes.
     */
    public static function forPuzzle(PuzzleRecord $record, PuzzleOverview $puzzle): self
    {
        $data = new self();
        $data->names = PuzzleNamesFormData::fromNames($record->name, $record->nameLanguage, $record->alternativeNames);
        $data->recordVersion = $record->recordVersion();
        $data->brand = $puzzle->manufacturerId;
        $data->piecesCount = $puzzle->piecesCount;
        $data->loadPuzzleCodes($puzzle->puzzleEan, $puzzle->puzzleIdentificationNumber);

        return $data;
    }

    public function validateEan(ExecutionContextInterface $context): void
    {
        $this->validateCodes($context);
    }

    /**
     * The puzzle's codes as it has them now, in the inputs.
     */
    public function loadPuzzleCodes(null|string $ean, null|string $identificationNumber): void
    {
        $this->loadCodes($ean, $identificationNumber, currentEan: $ean);
    }
}
