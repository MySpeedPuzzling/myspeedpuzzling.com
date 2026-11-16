<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

/**
 * A team's result imported from a results source - what the import would update or remove.
 */
readonly final class StoredTeamResult
{
    public function __construct(
        public string $teamKey,
        public null|string $teamName,
        public null|int $seconds,
        public null|int $piecesPlaced,
        public int $teamSize,
        // Somebody holds a spot of it ("This was my team") - the import never removes it nor changes its people
        public bool $claimed,
    ) {
    }
}
