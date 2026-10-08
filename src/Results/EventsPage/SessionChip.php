<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;

/**
 * One edition of a month roll-up row - the chip opens the edition page.
 */
readonly final class SessionChip
{
    public function __construct(
        public int $indexId,
        public null|string $url,
        public DateTimeImmutable $date,
        // the edition's name (the series name when it has none of its own)
        public string $title,
    ) {
    }
}
