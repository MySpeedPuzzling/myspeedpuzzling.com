<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class RoundPuzzleFormData
{
    /**
     * @param array<int, null|string> $puzzleEans One input per barcode (CodeListType)
     * @param array<int, null|string> $puzzleBrandCodes One input per brand code
     */
    public function __construct(
        #[Assert\NotBlank]
        public null|string $brand = null,
        #[Assert\NotBlank]
        public null|string $puzzle = null,
        public null|string $puzzleName = null,
        #[Assert\Range(min: 1, max: 99999)]
        public null|int $piecesCount = null,
        public null|UploadedFile $puzzlePhoto = null,
        #[Assert\Count(max: EanList::FORM_MAX_CODES)]
        #[Assert\All([new Assert\Length(max: 30)])]
        public array $puzzleEans = [],
        #[Assert\Count(max: BrandCodeList::FORM_MAX_CODES)]
        #[Assert\All([new Assert\Length(max: 50)])]
        public array $puzzleBrandCodes = [],
        public bool $hideUntilRoundStarts = false,
        public PuzzleHideMode $hideMode = PuzzleHideMode::Entirely,
    ) {
    }

    /**
     * The codes of a new puzzle (a typed name, not a picked puzzle id) - the same rule as every other form
     * (EanList::invalidCodes()); a picked puzzle keeps its own codes, so nothing typed under it blocks the save.
     */
    #[Assert\Callback]
    public function validateCodes(ExecutionContextInterface $context): void
    {
        if ($this->puzzle === null || Uuid::isValid($this->puzzle)) {
            return;
        }

        EanList::addInputViolations($context, 'puzzleEans', $this->puzzleEans, null);
        BrandCodeList::addViolations($context, 'puzzleBrandCodes', $this->puzzleBrandCodes);
    }
}
