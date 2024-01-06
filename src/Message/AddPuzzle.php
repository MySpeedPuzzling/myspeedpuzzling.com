<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzle
{
    public function __construct(
        public UuidInterface $puzzleId,
        public string $userId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public null|UploadedFile $puzzlePhoto,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
    ) {
    }

    public static function fromFormData(
        UuidInterface $newPuzzleId,
        string $userId,
        PuzzleSolvingTimeFormData $data,
    ): self
    {
        assert($data->puzzleName !== null);
        assert($data->puzzlePiecesCount !== null);

        return new self(
            puzzleId: $newPuzzleId,
            userId: $userId,
            puzzleName: $data->puzzleName,
            piecesCount: $data->puzzlePiecesCount,
            manufacturerId: $data->puzzleManufacturerId,
            manufacturerName: $data->puzzleManufacturerName,
            puzzlePhoto: $data->puzzlePhoto,
            puzzleEan: $data->puzzleEan,
            puzzleIdentificationNumber: $data->puzzleIdentificationNumber,
        );
    }
}
