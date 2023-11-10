<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzleSolvingTime
{
    public function __construct(
        public string $userId,
        public string $puzzleId,
        public null|string $time,
        public int $playersCount,
        public null|string $comment,
        public null|UploadedFile $solvedPuzzlesPhoto,
    ) {
    }

    public static function fromFormData(string $userId, AddPuzzleSolvingTimeFormData $data): self
    {
        assert($data->puzzleId !== null);
        assert($data->playersCount !== null);

        return new self(
            userId: $userId,
            puzzleId: $data->puzzleId,
            time: $data->time,
            playersCount: $data->playersCount,
            comment: $data->comment,
            solvedPuzzlesPhoto: $data->solvedPuzzlesPhoto,
        );
    }
}
