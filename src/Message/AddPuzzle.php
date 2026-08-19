<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzle
{
    public function __construct(
        public UuidInterface $puzzleId,
        public string $userId,
        public string $puzzleName,
        public string $brand,
        public int $piecesCount,
        public null|UploadedFile $puzzlePhoto,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
    ) {
    }
}
