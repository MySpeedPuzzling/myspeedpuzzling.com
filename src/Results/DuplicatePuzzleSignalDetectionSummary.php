<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class DuplicatePuzzleSignalDetectionSummary
{
    public function __construct(
        public int $pairs,
        public int $newSignals,
        public int $removedSignals,
    ) {
    }
}
