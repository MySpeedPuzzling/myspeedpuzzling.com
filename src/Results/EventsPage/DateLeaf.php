<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;

/**
 * The date block at the start of a row: weekday band, day(s), month(s). `to` is null for one day, a long-running
 * occurrence (only the first day shows) and a month roll-up (the first date shows).
 */
readonly final class DateLeaf
{
    public const string TONE_IN_PERSON = 'in_person';
    public const string TONE_ONLINE = 'online';
    // past, or no date ("TBA")
    public const string TONE_MUTED = 'muted';

    /**
     * @param 'in_person'|'online'|'muted' $tone
     */
    public function __construct(
        public null|DateTimeImmutable $from,
        public null|DateTimeImmutable $to,
        public string $tone,
    ) {
    }
}
