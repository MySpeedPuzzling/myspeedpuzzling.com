<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\EanList;
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
    // Every name - the main title, its language and the other names (the names editor)
    #[Valid]
    public PuzzleNamesFormData $names;

    // The record the form was loaded with (PuzzleRecordVersion) - a hidden field; the proposal is filed against it
    public null|string $recordVersion = null;

    public null|string $manufacturerId = null;

    #[NotBlank]
    #[Positive]
    #[Range(min: 10, max: 25000)]
    public int $piecesCount = 0;

    #[Length(max: 100)]
    public null|string $ean = null;

    /**
     * The puzzle's EAN list as it is now (not a form field) - its codes pass even when invalid
     */
    public null|string $currentEan = null;

    #[Length(max: 100)]
    public null|string $identificationNumber = null;

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

    public function validateEan(ExecutionContextInterface $context): void
    {
        EanList::addViolations($context, 'ean', $this->ean, $this->currentEan);
    }
}
