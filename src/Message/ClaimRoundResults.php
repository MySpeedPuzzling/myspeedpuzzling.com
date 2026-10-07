<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Materializes organizer-entered round results as verified PuzzleSolvingTimes
 * on the claiming player's profile.
 *
 * @see docs/features/competitions-management/results.md
 */
readonly final class ClaimRoundResults
{
    /**
     * @param array<string> $resultIds
     */
    public function __construct(
        public string $playerId,
        public array $resultIds,
    ) {
    }
}
