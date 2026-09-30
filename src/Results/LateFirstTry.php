<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A result marked as a first try although the player had solved the puzzle on an earlier day already.
 */
readonly final class LateFirstTry
{
    public function __construct(
        public FirstTryPuzzle $puzzle,
        public FirstTryTime $time,
        public DateTimeImmutable $earlierSolvedAt,
    ) {
    }
}
