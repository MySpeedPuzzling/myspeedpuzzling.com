<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;

readonly final class AgendaMonth
{
    /**
     * @param list<AgendaRow> $rows
     */
    public function __construct(
        public int $year,
        public int $month,
        public DateTimeImmutable $firstDay,
        public array $rows,
        // dates (a group counts each session) of the public rows in the request's scope
        public int $visibleCount,
    ) {
    }
}
