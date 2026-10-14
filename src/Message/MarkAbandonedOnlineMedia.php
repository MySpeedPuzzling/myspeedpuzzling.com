<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * Marks evidence uploads that never arrived as abandoned (docs/features/online-competitions/README.md §5.11) - with the
 * daily prune (myspeedpuzzling:prune-online-media). One page per message, oldest first: the page after
 * (`afterCreatedAt`, `afterId`) - the previous page's last row, null for the first page
 * (MarkAbandonedOnlineMediaHandler answers the next one).
 */
readonly final class MarkAbandonedOnlineMedia
{
    public function __construct(
        public null|DateTimeImmutable $afterCreatedAt = null,
        public null|string $afterId = null,
    ) {
    }
}
