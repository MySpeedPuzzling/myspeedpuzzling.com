<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\FollowTarget;

/**
 * One row of the agenda (happening now, months, TBA, ongoing online, Your events): one occurrence, or a month roll-up
 * of several editions of one series (`isGroup`, `sessions`).
 */
readonly final class AgendaRow
{
    /**
     * @param list<int> $indexIds the index entries the row shows (a group: every session)
     * @param list<RowTag> $tags
     * @param list<SessionChip> $sessions group only
     */
    public function __construct(
        public array $indexIds,
        public bool $isGroup,
        // the event name; an edition's and a group's: the series name
        public string $title,
        // an edition's own name (null when it equals the series name)
        public null|string $editionName,
        public null|string $url,
        public DateLeaf $leaf,
        public Place $place,
        public array $tags,
        public null|WhenLabel $when,
        public array $sessions,
        public EventOccurrenceStatus $status,
        // `online`, a country code, or '' (in person without a country)
        public string $scopeKey,
        // Y-m-d; long-running: `to` = `from`; group: the first and the last session
        public null|string $from,
        public null|string $to,
        public null|FollowTarget $followTarget,
        public string $followName,
        public bool $following,
        public null|ManageRef $manage,
        public bool $isPending,
        // in the request's scope
        public bool $visible,
        // Your events only
        public null|string $logo = null,
    ) {
    }

    public function idsAttribute(): string
    {
        return implode(' ', $this->indexIds);
    }

    /**
     * The number of dates the row stands for (a group: its sessions)
     */
    public function datesCount(): int
    {
        return $this->isGroup ? count($this->sessions) : 1;
    }
}
