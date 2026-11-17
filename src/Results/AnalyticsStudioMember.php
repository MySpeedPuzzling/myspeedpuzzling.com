<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One person of an analytics studio entry (analytics-studio.md §2.4): the organiser's name, masked like the public
 * round page with no viewer (OfficialEntrantsView::entrant()), and whether MySpeedPuzzling history may be read for them.
 */
readonly final class AnalyticsStudioMember
{
    public function __construct(
        public string $participantId,
        public PublishedRoundEntrant $entrant,
        // Linked to a public profile (a private player is never analysable - and the JSON cannot tell them from an unlinked person)
        private null|string $publicPlayerId,
        public null|string $playerCode,
        public bool $timePredictionsOptedOut,
        public bool $rankingOptedOut,
    ) {
    }

    public function analysable(): bool
    {
        return $this->publicPlayerId !== null;
    }

    /**
     * The only player id of a member that ever goes into a history, insights or prediction statement.
     */
    public function analysablePlayerId(): null|string
    {
        return $this->publicPlayerId;
    }
}
