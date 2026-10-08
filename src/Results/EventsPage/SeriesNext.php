<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;

/**
 * A series line's date: the next edition (or session), one live now, one ongoing (a long span without rounds), the
 * last one, or none yet.
 */
readonly final class SeriesNext
{
    public const string NEXT = 'next';
    public const string LIVE = 'live';
    public const string ONGOING = 'ongoing';
    public const string LAST = 'last';
    public const string NONE = 'none';

    /**
     * @param 'next'|'live'|'ongoing'|'last'|'none' $type
     */
    public function __construct(
        public string $type,
        public null|DateTimeImmutable $date,
    ) {
    }
}
