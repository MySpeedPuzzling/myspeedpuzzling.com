<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the embeddable standing shows (real events L3, `standing_embed` - StandingPageBuilder::buildEmbed()): the table
 * part of the public standing page as a guest sees it, the event's name over it and the public page it links to
 * ("Results by MySpeedPuzzling"). An edition's pages live under its series' URL.
 */
readonly final class PublicStandingEmbed
{
    /**
     * @param array<string, string> $standingRouteParams the public standing page's (`event_standing` / `edition_standing`)
     */
    public function __construct(
        public PublicStandingTableView $view,
        public string $eventName,
        public string $standingRoute,
        public array $standingRouteParams,
    ) {
    }
}
