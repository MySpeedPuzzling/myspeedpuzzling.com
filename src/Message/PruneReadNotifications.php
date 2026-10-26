<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class PruneReadNotifications
{
    public function __construct(
        public int $retentionDays,
        public int $batchSize,
    ) {
    }
}
