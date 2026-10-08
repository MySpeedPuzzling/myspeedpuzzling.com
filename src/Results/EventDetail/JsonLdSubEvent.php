<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use DateTimeImmutable;

/**
 * One `subEvent` of the series page's EventSeries JSON-LD: a public dated session (the template makes the URL absolute)
 */
readonly final class JsonLdSubEvent
{
    public function __construct(
        public string $name,
        // the occurrence's page, `#round-<id>` for a session of several
        public string $path,
        public DateTimeImmutable $startDate,
        public null|DateTimeImmutable $endDate,
        // the edition's logo (a stored path - `thumbnail` it)
        public null|string $image,
        public bool $isOnline,
    ) {
    }
}
