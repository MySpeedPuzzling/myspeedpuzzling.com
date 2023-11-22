<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeletePuzzleSolvingTime
{
    public function __construct(
        public string $currentUserId,
        public string $puzzleSolvingTimeId,
    ) {
    }
}
