<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class RoundPuzzleFormData
{
    public function __construct(
        public null|string $brand = null,
        public null|string $puzzle = null,
        public null|string $puzzleName = null,
        #[Assert\Range(min: 1, max: 99999)]
        public null|int $piecesCount = null,
        public null|UploadedFile $puzzlePhoto = null,
        public null|string $puzzleEan = null,
        public null|string $puzzleIdentificationNumber = null,
        public bool $hideUntilRoundStarts = false,
    ) {
    }
}
