<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One person on the public competitor list (GetCompetitorList) - the organiser's name, masked like the public round
 * page (OfficialEntrantsView::entrant(), without an avatar): `entrant.playerId` and the #code only for a profile the
 * viewer may see. Their row links their person page (`p`).
 */
readonly final class CompetitorListPerson
{
    public function __construct(
        public string $participantId,
        public PublishedRoundEntrant $entrant,
        // The viewer is this person - "You"
        public bool $isViewer,
        // SearchText::fold() of the name and the #code (only when it is shown) - the list's filter reads it
        public string $search,
    ) {
    }

    public function code(): null|string
    {
        return $this->entrant->playerId !== null ? $this->entrant->linkedPlayerCode : null;
    }
}
