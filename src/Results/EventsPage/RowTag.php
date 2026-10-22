<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\EventType;

readonly final class RowTag
{
    public function __construct(
        public RowTagType $type,
        // RegistrationOpens: the day it opens (in the registration's zone); RunsUntil: the last day
        public null|DateTimeImmutable $date = null,
        // GoingCount, PartnerListings
        public null|int $count = null,
        // Eligibility: the "Who can enter" text
        public null|string $text = null,
        // EventType: the kind of event
        public null|EventType $eventType = null,
    ) {
    }
}
