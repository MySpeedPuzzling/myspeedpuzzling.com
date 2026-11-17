<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One round of a standing as StandingCalculator::sum() reads it, in the standing's order.
 */
final readonly class StandingRoundInput
{
    /**
     * @param list<StandingEntryInput> $entries
     */
    public function __construct(
        public string $roundId,
        // null = no time limit: an unfinished result of the round cannot score
        public null|int $minutesLimit,
        // A round that does not count yet scores nobody and makes nobody "missing" (A §3.4)
        public bool $counted,
        public array $entries,
    ) {
    }
}
