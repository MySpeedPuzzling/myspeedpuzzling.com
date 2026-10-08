<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\OccurrenceDates;

/**
 * The rounds of an edition or a one-time event as the timeline shows them (RoundsTimelineBuilder,
 * docs/features/events-page/detail-pages.md "Rounds timeline"), and the dates of the page's header and JSON-LD.
 */
readonly final class RoundsTimeline
{
    /**
     * @param list<TimelineRound> $rounds in schedule order
     * @param non-empty-list<OccurrenceDates> $sessions OccurrenceDates::sessions() over every round
     */
    public function __construct(
        public array $rounds,
        // the first round not over (a running one counts); null when every round is over, or none
        public null|string $nextRoundId,
        // past rounds folded behind "Show N earlier rounds"
        public int $foldedCount,
        public array $sessions,
        // the first session's start, the last session's end ?? start; without rounds date_from/date_to as sessions() gives them
        public null|DateTimeImmutable $start,
        public null|DateTimeImmutable $end,
        // a round (or, without rounds, the event's span) runs now
        public bool $isLive,
        public int $roundsWithResults,
        // of the first round
        public null|string $zone,
        public bool $zoneAssumed,
        // without rounds, a span of more than 31 days that has started and not ended: the header says "Runs until …"
        public bool $runsUntilEnd = false,
        // the rounds name more than one zone - the side column's single zone note would be wrong
        public bool $mixedZones = false,
    ) {
    }

    public function hasRounds(): bool
    {
        return $this->rounds !== [];
    }

    /**
     * The past rounds behind "Show N earlier rounds"
     *
     * @return list<TimelineRound>
     */
    public function foldedRounds(): array
    {
        return array_values(array_filter($this->rounds, static fn (TimelineRound $round): bool => $round->folded));
    }

    /**
     * @return list<TimelineRound>
     */
    public function shownRounds(): array
    {
        return array_values(array_filter($this->rounds, static fn (TimelineRound $round): bool => $round->folded === false));
    }
}
