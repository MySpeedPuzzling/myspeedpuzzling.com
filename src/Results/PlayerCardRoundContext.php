<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The player card's event-round context (docs/features/navigation CARD-02): the player's result in the round the card
 * was opened from - the official place and result when the organiser published them (ranked like the round page,
 * OfficialResultsRanking), else the time they added themselves (the earliest linked one, as the round page lists it).
 */
readonly final class PlayerCardRoundContext
{
    public function __construct(
        public string $roundName,
        public string $competitionName,
        public bool $official,
        // The official place, null for an unofficial time
        public null|int $rank,
        public RoundEntryResult $result,
        public bool $qualified,
        // The round's piece count when it has one puzzle - "479 / 500 pcs"
        public null|int $piecesCount,
    ) {
    }
}
