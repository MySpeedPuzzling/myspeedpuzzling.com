<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventsPage;

use SpeedPuzzling\Web\Results\OrganizationRef;
use SpeedPuzzling\Web\Value\FollowTarget;

readonly final class SeriesLine
{
    public function __construct(
        public int $indexId,
        public string $seriesId,
        public string $name,
        public null|string $url,
        public Place $place,
        public bool $isOnline,
        // every edition, date not set included
        public int $editionCount,
        public SeriesNext $next,
        public FollowTarget $followTarget,
        public bool $following,
        public null|ManageRef $manage,
        public bool $isPending,
        public string $scopeKey,
        public bool $visible,
        // only a publicly visible organization ("by …" under the line, docs/features/organizations/README.md)
        public null|OrganizationRef $organization = null,
    ) {
    }
}
