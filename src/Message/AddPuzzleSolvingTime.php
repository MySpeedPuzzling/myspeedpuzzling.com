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
        public bool $firstAttempt,
    ) {
    }

    /**
     * @param array<string> $groupPlayers
     */
    public static function fromFormData(string $userId, array $groupPlayers, PuzzleSolvingTimeFormData $data): self
    {
        assert($data->puzzle !== null);
        assert($data->time !== null);

        return new self(
            userId: $userId,
            puzzleId: $data->puzzle,
            time: $data->time,
            comment: $data->comment,
            finishedPuzzlesPhoto: $data->finishedPuzzlesPhoto,
            groupPlayers: $groupPlayers,
            finishedAt: $data->finishedAt,
            firstAttempt: $data->firstAttempt,
        );
    }
}
