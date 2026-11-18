<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the table part of the public standing page shows (competition toolkit v2 S4, `public_standing_table` -
 * StandingPageBuilder::buildById()): the standing, its table for the viewer, and how its round links are built (an
 * edition's rounds live under its series' URL).
 */
readonly final class PublicStandingTableView
{
    /**
     * @param array<string, string> $routeParams
     */
    public function __construct(
        public string $competitionId,
        public PublishedStandingRef $standing,
        public PublicStandingTable $table,
        public string $roundRoute,
        public array $routeParams,
    ) {
    }
}
