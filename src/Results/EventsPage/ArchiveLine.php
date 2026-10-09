<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use DateTimeImmutable;

/**
 * One line of the archive: a past occurrence, or a roll-up of a series' editions of one year
 * ("Harbor Jigsaw Nights · 5 editions in 2026", `editionCount` > 1, linking the series page).
 */
readonly final class ArchiveLine
{
    /**
     * @param list<int> $indexIds
     */
    public function __construct(
        public array $indexIds,
        // the event name; an edition's and a roll-up's: the series name
        public string $title,
        public null|string $url,
        // a single occurrence: its start; a roll-up: the first edition of the year
        public DateTimeImmutable $from,
        // a single occurrence: its last day when several; a roll-up: the last edition of the year
        public null|DateTimeImmutable $to,
        // 1 = a single occurrence
        public int $editionCount,
        public int $monthFrom,
        public int $monthTo,
        public bool $hasResults,
        public Place $place,
        public string $scopeKey,
        public bool $visible,
        // a single occurrence's line under the name (EventOccurrence::subtitle())
        public null|string $editionName = null,
        public int $year = 0,
        // Draft / Waiting for approval - only on the series and organization pages, which list those for their team only
        // (docs/features/organizations/README.md, P6)
        public null|RowTagType $stateTag = null,
    ) {
    }

    public function isRollUp(): bool
    {
        return $this->editionCount > 1;
    }

    public function idsAttribute(): string
    {
        return implode(' ', $this->indexIds);
    }
}
