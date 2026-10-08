<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Organizations;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\FollowTarget;

/**
 * One one-time event of the organization page's "What we run" that is not over yet (docs/features/organizations/
 * README.md): name, place or Online, its dates, "Who can enter", a follow star. Sessions of one event are one card.
 */
readonly final class OrganizationEventCard
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $url,
        public Place $place,
        public bool $isOnline,
        // the first day still to come (its first session not over yet); null without a date
        public null|DateTimeImmutable $from,
        // its last day, when it is not $from
        public null|DateTimeImmutable $to,
        // Live, Upcoming, Ongoing or Tba - of its first session not over yet
        public EventOccurrenceStatus $status,
        public null|string $eligibility,
        // null unless the event is publicly visible
        public null|FollowTarget $followTarget,
        public bool $following,
        public ManageRef $manage,
        public bool $isDraft,
        // waiting for approval - and not a draft
        public bool $isPending,
    ) {
    }

    public function isLive(): bool
    {
        return $this->status === EventOccurrenceStatus::Live;
    }
}
