<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditPuzzleSolvingTime
{
    public function __construct(
        public string $currentUserId,
        public string $puzzleSolvingTimeId,
        public null|string $competitionId,
        public null|string $time,
        public null|string $comment,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
        public null|UploadedFile $finishedPuzzlesPhoto,
        public bool $firstAttempt,
    ) {
    }

    /**
     * @param array<string> $groupPlayers
     */
    public static function fromFormData(string $userId, string $timeId, array $groupPlayers, EditPuzzleSolvingTimeFormData $formData): self
    {
        return new self(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            competitionId: $formData->competition,
            time: $formData->mode === PuzzleAddMode::Relax ? null : $formData->getTimeAsString(),
            comment: $formData->comment,
            groupPlayers: $groupPlayers,
            finishedAt: $formData->finishedAt,
            finishedPuzzlesPhoto: $formData->finishedPuzzlesPhoto,
            firstAttempt: $formData->firstAttempt,
        );
    }
}
