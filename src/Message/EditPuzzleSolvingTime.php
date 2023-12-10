<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;

readonly final class EditPuzzleSolvingTime
{
    public function __construct(
        public string $currentUserId,
        public string $puzzleSolvingTimeId,
        public string $time,
        public null|string $comment,
    ) {
    }

    public static function fromFormData(string $userId, string $timeId, EditPuzzleSolvingTimeFormData $formData): self
    {
        assert($formData->time !== null);

        return new self(
            $userId,
            $timeId,
            $formData->time,
            $formData->comment,
        );
    }
}
