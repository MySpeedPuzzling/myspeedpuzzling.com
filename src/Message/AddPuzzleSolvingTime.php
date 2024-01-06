<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzleSolvingTime
{
    public function __construct(
        public string $userId,
        public string $puzzleId,
        public string $time,
        public null|string $comment,
        public null|UploadedFile $finishedPuzzlesPhoto,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
    ) {
    }

    /**
     * @param array<string> $groupPlayers
     */
    public static function fromFormData(string $userId, array $groupPlayers, PuzzleSolvingTimeFormData $data): self
    {
        assert($data->puzzleId !== null);
        assert($data->time !== null);

        return new self(
            userId: $userId,
            puzzleId: $data->puzzleId,
            time: $data->time,
            comment: $data->comment,
            finishedPuzzlesPhoto: $data->finishedPuzzlesPhoto,
            groupPlayers: $groupPlayers,
            finishedAt: $data->finishedAt,
        );
    }
}
