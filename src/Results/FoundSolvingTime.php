<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzlingType;

readonly final class FoundSolvingTime
{
    public function __construct(
        public string $timeId,
        // Changes when the puzzle is merged into another one - the time keeps its id
        public string $puzzleId,
        public PuzzlingType $type,
        public bool $trackedByMe,
    ) {
    }
}
