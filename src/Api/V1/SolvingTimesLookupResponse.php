<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class SolvingTimesLookupResponse
{
    /**
     * @param list<FoundSolvingTimeResponse> $found
     * @param list<DeletedSolvingTimeResponse> $deleted
     * @param list<string> $missing
     */
    public function __construct(
        public array $found,
        public array $deleted,
        public array $missing,
    ) {
    }
}
