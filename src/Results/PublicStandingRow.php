<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One entrant of a public standing as one viewer may see it (competition toolkit A7, GetStandingResults::publicView()):
 * the computed row plus its people as the public round page shows them (PublishedRoundEntrant - the organiser's names,
 * a profile only for a player the viewer may see). The row's `key` (participant / line-up id) is never rendered.
 */
readonly final class PublicStandingRow
{
    /**
     * @param list<PublishedRoundEntrant> $entrants the person, or the members of the pair/team (the latest round's)
     */
    public function __construct(
        public StandingRow $row,
        public array $entrants,
        public bool $isTeam,
        public null|string $teamName,
        // The viewer is the person, or one of the members
        public bool $isViewers,
    ) {
    }
}
