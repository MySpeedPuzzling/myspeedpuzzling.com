<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\FollowTarget;

/**
 * One row of the agenda (Live, months, TBA, Ongoing, Your events): one occurrence (or session), or a month roll-up
 * of several editions of one series (`isGroup`, `sessions`) - at most EventsPageBuilder::MAX_SESSION_CHIPS chips, the
 * rest is "+N more" (`moreSessionIds`; docs/features/events-page/high-frequency-series.md "Events page agenda").
 */
readonly final class AgendaRow
{
    /**
     * @param list<int> $indexIds the index entries the row shows (a group: every session, the ones behind "+N more" too)
     * @param list<RowTag> $tags
     * @param list<SessionChip> $sessions group only - the chips shown
     * @param list<int> $moreSessionIds group only - the index entries of the sessions behind "+N more"
     */
    public function __construct(
        public array $indexIds,
        public bool $isGroup,
        // the event name; an edition's and a group's: the series name
        public string $title,
        // the line under the name: an edition's own name and a session's label (EventOccurrence::subtitle())
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
        // Your events, and the series page's rows (`show_logo`)
        public null|string $logo = null,
        // the series page: the start of the occurrence's first round in its zone (EventRowFactory, RowContext::SeriesPage)
        public null|EventTime $time = null,
        public array $moreSessionIds = [],
    ) {
    }

    /**
     * The sessions of a group behind its "+N more" chip
     */
    public function moreSessionsCount(): int
    {
        return count($this->moreSessionIds);
    }

    public function moreIdsAttribute(): string
    {
        return implode(' ', $this->moreSessionIds);
    }

    public function idsAttribute(): string
    {
        return implode(' ', $this->indexIds);
    }

    /**
     * The number of dates the row stands for (a group: its sessions, with the ones behind "+N more")
     */
    public function datesCount(): int
    {
        return $this->isGroup ? count($this->sessions) + $this->moreSessionsCount() : 1;
    }
}
