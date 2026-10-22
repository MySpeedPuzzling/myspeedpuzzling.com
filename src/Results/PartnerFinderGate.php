<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Every event with a partner finder right now, nearest first (GetPartnerFinderEvents) - the gate page's picker, its
 * "Events with people looking" cards and its `?event=` redirect all read this one list.
 */
readonly final class PartnerFinderGate
{
    /**
     * @param list<PartnerFinderEvent> $events
     */
    public function __construct(
        public array $events,
    ) {
    }

    public function find(string $competitionId): null|PartnerFinderEvent
    {
        $competitionId = strtolower(trim($competitionId));

        foreach ($this->events as $event) {
            if ($event->event->competitionId === $competitionId) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The events with the most open listings (nearest first on a tie), only those with somebody listed
     *
     * @return list<PartnerFinderEvent>
     */
    public function mostLooking(int $limit): array
    {
        $looking = array_values(array_filter($this->events, static fn (PartnerFinderEvent $event): bool => $event->listingCount > 0));

        // usort is stable: a tie keeps the list's order, nearest first
        usort($looking, static fn (PartnerFinderEvent $a, PartnerFinderEvent $b): int => $b->listingCount <=> $a->listingCount);

        return array_slice($looking, 0, $limit);
    }
}
