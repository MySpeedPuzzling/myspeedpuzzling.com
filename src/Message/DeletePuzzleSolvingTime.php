<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;

readonly final class DeletePuzzleSolvingTime
{
    public function __construct(
        public string $currentUserId,
        public string $puzzleSolvingTimeId,
        public SolvingTimeDeletedVia $via = SolvingTimeDeletedVia::Web,
    ) {
    }
}
