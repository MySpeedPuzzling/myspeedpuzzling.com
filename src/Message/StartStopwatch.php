<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class StartStopwatch
{
    public function __construct(
        public UuidInterface $stopwatchId,
        public string $userId,
        public null|string $puzzleId,
    ) {
    }
}
