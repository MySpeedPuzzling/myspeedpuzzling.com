<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "Add to the catalogue" of an unlisted puzzle (docs/features/data-import/unlisted-puzzles.md): what the add-result
 * form asks of a new puzzle, with the same rules - a name, a brand, the pieces (10 - 99,999), a photo of the box
 * (PuzzleBoxPhoto; what is required is said by the form type, in the page's language), and optionally a barcode and a brand code by the catalogue's one rule
 * for codes (EanList, BrandCodeList).
 */
final class UnlistedCatalogueProposalFormData
{
    #[Length(max: 200)]
    public null|string $name = null;

    #[Length(max: 100)]
    public null|string $brand = null;

    #[Range(min: 10, max: 99999)]
    public null|int $piecesCount = null;

    public null|UploadedFile $photo = null;

    #[Length(max: 100)]
    public null|string $ean = null;

    #[Length(max: 100)]
    public null|string $brandCode = null;

    #[Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        EanList::addInputViolations($context, 'ean', [$this->ean], null);
        BrandCodeList::addViolations($context, 'brandCode', [$this->brandCode]);
    }
}
