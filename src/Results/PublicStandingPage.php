<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\EventTitle;

/**
 * Everything the public standing page shows (competition toolkit A7, StandingPageBuilder).
 */
readonly final class PublicStandingPage
{
    /**
     * @param list<PublishedStandingRef> $standings every published standing of the event - the page's tabs
     * @param string $liveTopic the event's public standings topic (CompetitionStandingsLiveUpdates::topic(), v2 S4)
     */
    public function __construct(
        public CompetitionEvent $event,
        public EventTitle $eventTitle,
        public PublishedStandingRef $standing,
        public array $standings,
        public PublicStandingTable $table,
        public string $liveTopic,
    ) {
    }
}
