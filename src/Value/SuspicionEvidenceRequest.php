<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Asks Query\GetSuspiciousTimeEvidence for the explanations' facts of one raised time - a stored one (timeId) or one
 * being entered in a form (no timeId: no comment, no confirmation).
 */
readonly final class SuspicionEvidenceRequest
{
    public function __construct(
        // What the answer is keyed by - the time id in the scan. Letters, digits and dashes only
        public string $key,
        public null|string $timeId,
        public string $playerId,
        public string $puzzleId,
        // The solved day, Y-m-d
        public string $solvedDay,
    ) {
    }
}
