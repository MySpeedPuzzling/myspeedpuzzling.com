<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * Deletes evidence objects past their retention (docs/features/online-competitions/README.md §8) - a daily cron.
 * One page per message, oldest first: the page after (`afterCreatedAt`, `afterId`) - the previous page's last row,
 * null for the first page (PruneOnlineMediaHandler answers the next one).
 */
readonly final class PruneOnlineMedia
{
    public function __construct(
        public int $olderThanDays,
        public null|DateTimeImmutable $afterCreatedAt = null,
        public null|string $afterId = null,
    ) {
    }
}
