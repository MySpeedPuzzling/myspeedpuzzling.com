<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;

/**
 * A series line's date: the next edition, one happening now, the last one, or none yet.
 */
readonly final class SeriesNext
{
    public const string NEXT = 'next';
    public const string LIVE = 'live';
    public const string LAST = 'last';
    public const string NONE = 'none';

    /**
     * @param 'next'|'live'|'last'|'none' $type
     */
    public function __construct(
        public string $type,
        public null|DateTimeImmutable $date,
    ) {
    }
}
