<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What POST /api/v1/me/solving-times/lookup knows about the ids it was given (docs/features/api/solving-time-sync.md).
 */
readonly final class SolvingTimeLookup
{
    /**
     * @param list<FoundSolvingTime> $found
     * @param list<DeletedSolvingTime> $deleted
     * @param list<string> $missing
     */
    public function __construct(
        public array $found,
        public array $deleted,
        public array $missing,
    ) {
    }
}
