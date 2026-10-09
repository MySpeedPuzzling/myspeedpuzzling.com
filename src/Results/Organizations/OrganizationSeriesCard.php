<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Organizations;

use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Results\EventsPage\SeriesNext;
use SpeedPuzzling\Web\Value\FollowTarget;

/**
 * One series of the organization page's "What we run" (docs/features/organizations/README.md): name, place or Online,
 * "When it happens", "Who can enter", its editions, the next date (else the last one, else none), a follow star. Its
 * team also gets its drafts and the ones waiting for approval, tagged.
 */
readonly final class OrganizationSeriesCard
{
    public function __construct(
        public string $seriesId,
        public string $name,
        public null|string $url,
        public Place $place,
        public bool $isOnline,
        public null|string $schedule,
        public null|string $eligibility,
        // every edition shown on the page, date not set included
        public int $editionCount,
        public SeriesNext $next,
        // null unless the series is publicly visible
        public null|FollowTarget $followTarget,
        public bool $following,
        public ManageRef $manage,
        public bool $isDraft,
        // waiting for approval (or rejected later) - and not a draft
        public bool $isPending,
    ) {
    }
}
