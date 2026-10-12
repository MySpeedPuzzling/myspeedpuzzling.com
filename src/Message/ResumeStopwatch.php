<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class ResumeStopwatch
{
    public function __construct(
        public UuidInterface $stopwatchId,
        public string $userId,
        // How long before this request the player tapped Continue (a request replayed from the offline queue)
        public int $tappedMillisecondsAgo = 0,
    ) {
    }
}
