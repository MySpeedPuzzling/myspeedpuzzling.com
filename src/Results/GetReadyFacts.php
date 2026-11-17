<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\QrTagMode;

/**
 * Everything the "Get ready" checklist derives its rows from (GetEventGetReadyFacts, a fixed number of statements) -
 * data only, nothing stored for the checklist. A new checklist item (W6) that needs a fact adds it here and to the query.
 */
readonly final class GetReadyFacts
{
    /**
     * @param list<GetReadyRoundFacts> $rounds by start, then name
     */
    public function __construct(
        public string $competitionId,
        public bool $isDraft,
        public bool $isOnline,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public bool $registrationManaged,
        public null|int $capacity,
        // Participants holding a spot (CompetitionParticipantGoing)
        public int $participantsGoing,
        public int $refereesCount,
        // Timer screens (DisplayKind::Screen) - an OBS overlay is not for the hall's TV
        public int $screenDisplaysCount,
        public array $rounds,
        // Whom the competition toolkit's tips are for (COMPETITION_TOOLKIT_MANAGE)
        public bool $toolkitAvailable,
        public DateTimeImmutable $now,
        // Stream overlays (DisplayKind::Overlay) - the Extras step's "Overlay for a live stream" (W6)
        public int $overlayDisplaysCount = 0,
        // The chosen QR tag mode (competitor pages & QR) - null = name tags as before
        public null|QrTagMode $qrTagMode = null,
    ) {
    }

    public function hasEntries(): bool
    {
        foreach ($this->rounds as $round) {
            if ($round->entriesTotal > 0) {
                return true;
            }
        }

        return false;
    }
}
