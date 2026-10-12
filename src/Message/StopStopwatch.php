<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class StopStopwatch
{
    public function __construct(
        public UuidInterface $stopwatchId,
        public string $userId,
        // What the player's stopwatch showed at Stop (null = an older page that does not send it)
        public null|int $shownTotalMilliseconds = null,
    ) {
    }
}
