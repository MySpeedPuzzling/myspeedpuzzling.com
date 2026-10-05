<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

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
        public PuzzleHideMode $hideMode = PuzzleHideMode::ImageOnly,
    ) {
    }
}
