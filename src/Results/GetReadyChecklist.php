<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * The "Get ready" checklist of an event's edit page (EventGetReadyChecklist): its rows in order, "n of m done" over the
 * rows with a tick (tips are not counted).
 */
readonly final class GetReadyChecklist
{
    /**
     * @param list<GetReadyRow> $rows
     */
    public function __construct(
        public string $competitionId,
        public array $rows,
        public null|DateTimeImmutable $dateFrom,
        public int $roundsCount,
    ) {
    }

    public function doneCount(): int
    {
        return count(array_filter($this->rows, static fn (GetReadyRow $row): bool => $row->done === true));
    }

    public function totalCount(): int
    {
        return count(array_filter($this->rows, static fn (GetReadyRow $row): bool => $row->done !== null));
    }
}
