<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One entry of one round as StandingCalculator::sum() reads it. `key` = the entrant across rounds: the participant id
 * (solo) or the line-up (CompetitionLineupId over the exact set of members - a substitute makes another entrant).
 */
final readonly class StandingEntryInput
{
    /**
     * @param list<string> $countries
     * @param list<StandingEntrant> $entrants
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
    ) {
    }
}
