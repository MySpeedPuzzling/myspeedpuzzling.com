<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Deletes evidence objects past their retention (docs/features/online-competitions/README.md §8) - a daily cron.
 */
readonly final class PruneOnlineMedia
{
    public function __construct(
        public int $olderThanDays,
    ) {
    }
}
