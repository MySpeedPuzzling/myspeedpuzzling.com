<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;

readonly final class AddPuzzle
{
    public function __construct(
        public UuidInterface $puzzleId,
        public string $userId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
    ) {
    }

    public static function fromFormData(
        UuidInterface $newPuzzleId,
        string $userId,
        AddPuzzleSolvingTimeFormData $data,
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
        );
    }
}
