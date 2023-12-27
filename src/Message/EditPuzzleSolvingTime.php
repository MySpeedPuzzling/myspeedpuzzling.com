<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;

readonly final class EditPuzzleSolvingTime
{
    public function __construct(
        public string $currentUserId,
        public string $puzzleSolvingTimeId,
        public string $time,
        public null|string $comment,
        /** @var array<string> */
        public array $groupPlayers,
        public null|DateTimeImmutable $finishedAt,
    ) {
    }

    /**
     * @param array<string> $groupPlayers
     */
    public static function fromFormData(string $userId, string $timeId, array $groupPlayers, EditPuzzleSolvingTimeFormData $formData): self
    {
        assert($formData->time !== null);

        return new self(
            $userId,
            $timeId,
            $formData->time,
            $formData->comment,
            groupPlayers: $groupPlayers,
            finishedAt: $formData->finishedAt,
        );
    }
}
