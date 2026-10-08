<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;

readonly final class RowTag
{
    public function __construct(
        public RowTagType $type,
        // RegistrationOpens: the day it opens (in the registration's zone); RunsUntil: the last day
        public null|DateTimeImmutable $date = null,
        // GoingCount
        public null|int $count = null,
    ) {
    }
}
