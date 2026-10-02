<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class DuplicatePuzzleSignalDetectionSummary
{
    public function __construct(
        public int $pairs,
        // Of them strong enough to be listed by default (DuplicatePuzzleSignalScoring)
        public int $strongPairs,
        public int $newSignals,
        public int $removedSignals,
    ) {
    }
}
