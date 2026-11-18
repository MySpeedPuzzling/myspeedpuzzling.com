<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\RoundChallengeTime;

/**
 * One entry of one round as StandingCalculator::sum() / stages() read it. `key` = the entrant across rounds: the participant id
 * (solo) or the line-up (CompetitionLineupId over the exact set of members - a substitute makes another entrant).
 */
final readonly class StandingEntryInput
{
    /**
     * @param list<string> $countries
     * @param list<StandingEntrant> $entrants
     * @param list<RoundChallengeTime> $challengeTimes a solve-all block's challenge times (real events W4) - shown in the
     *     round's cell, never a ranking input
     */
    public function __construct(
        public string $key,
        public RoundEntryResult $result,
        // The official place in its round (OfficialResultsRanking) - shown, never a ranking input of the standing
        public null|int $rank,
        // The pieces the entry places when it finishes the round (for the penalty per missing piece); null = unknown
        public null|int $piecesCount,
        public string $name,
        public array $countries = [],
        public array $entrants = [],
        // Finished with pieces missing (competition toolkit T6): how many - null = complete or not finished
        public null|int $missingPieces = null,
        public array $challengeTimes = [],
        // Marked qualified in its round - only the `stages` classification reads it (competition toolkit v2 S5: "Qualified
        // for <next stage>" while the next stage is not classified yet)
        public bool $qualified = false,
    ) {
    }
}
