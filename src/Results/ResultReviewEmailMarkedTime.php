<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A mark of the "Your results" e-mail (docs/features/suspicious-time-review.md, "Where they see it"): one of the
 * player's results set aside until it is checked, still marked and not told by e-mail yet. One per notice - the
 * person told, never the whole pair/team.
 */
readonly final class ResultReviewEmailMarkedTime
{
    public function __construct(
        public string $noticeId,
        public string $puzzleName,
        public null|int $secondsToSolve,
        public DateTimeImmutable $solvedAt,
    ) {
    }
}
