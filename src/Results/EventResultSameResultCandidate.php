<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * The viewer's own result of the puzzle near the event day - maybe the same result they are claiming
 * (docs/features/event-results-import/README.md "Claiming a spot", step 5).
 */
readonly final class EventResultSameResultCandidate
{
    public function __construct(
        public string $timeId,
        public null|int $seconds,
        public DateTimeImmutable $solvedAt,
        public int $puzzlersCount,
        public bool $firstAttempt,
    ) {
    }
}
