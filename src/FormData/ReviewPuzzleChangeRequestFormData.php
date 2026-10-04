<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestImageChoice;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The admin review of a change request: the whole puzzle, every field editable.
 */
#[Callback('validate')]
final class ReviewPuzzleChangeRequestFormData
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

    public PuzzleChangeRequestImageChoice $image = PuzzleChangeRequestImageChoice::Keep;

    public null|UploadedFile $photo = null;

    /**
     * What the player proposed where they proposed something, the puzzle as it is now everywhere else.
     */
    public static function prefilled(PuzzleChangeRequestOverview $request): self
    {
        $data = new self();
        $data->name = $request->hasNameChange() ? $request->proposedName : $request->puzzleName;
        $data->alternativeName = $request->puzzleAlternativeName;
        $data->manufacturerId = $request->hasManufacturerChange() ? $request->proposedManufacturerId : $request->puzzleManufacturerId;
        $data->piecesCount = $request->hasPiecesCountChange() ? $request->proposedPiecesCount : $request->puzzlePiecesCount;
        $data->ean = $request->hasEanChange() ? $request->proposedEan : $request->puzzleEan;
        $data->currentEan = $request->puzzleEan;
        $data->identificationNumber = $request->hasIdentificationNumberChange() ? $request->proposedIdentificationNumber : $request->puzzleIdentificationNumber;
        $data->image = $request->hasImageChange() ? PuzzleChangeRequestImageChoice::Proposed : PuzzleChangeRequestImageChoice::Keep;

        return $data;
    }

    public function validate(ExecutionContextInterface $context): void
    {
        EanList::addViolations($context, 'ean', $this->ean, $this->currentEan);

        if ($this->image === PuzzleChangeRequestImageChoice::Upload && $this->photo === null) {
            $context->buildViolation('Choose the image to upload.')
                ->disableTranslation()
                ->atPath('photo')
                ->addViolation();
        }
    }
}
