<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class FinishStopwatch
{
    public function __construct(
        public string $stopwatchId,
        public string $currentUserId,
        public string $puzzleId,
    ) {
    }
}
