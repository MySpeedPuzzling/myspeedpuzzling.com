<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EventsPage\DateLeaf;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\RoundStatus;

/**
 * One round of the timeline: `<li id="round-<id>">` - the anchor the events page and the series page link sessions to.
 */
readonly final class TimelineRound
{
    public function __construct(
        public EditionRoundDetail $round,
        // the round's day in its own zone; muted once over
        public DateLeaf $leaf,
        public RoundStatus $status,
        // Live and Next only: "Live", "Today", "Tomorrow", "This weekend", "In 13 days"
        public null|WhenLabel $when,
        public EventTime $time,
        // behind "Show N earlier rounds"
        public bool $folded,
        // the round results page - public pages, rounds with a slug and something to show
        public null|string $resultsUrl,
        // the organiser published the official results
        public bool $officialResults,
        // signed in, the page is public, the round started
        public null|string $addTimeUrl,
        // at least one puzzle is shown (secret ones are dropped by the read model)
        public bool $puzzlesAnnounced,
    ) {
    }

    public function isPast(): bool
    {
        return $this->status === RoundStatus::Past;
    }
}
