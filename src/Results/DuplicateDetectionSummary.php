<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class DuplicateDetectionSummary
{
    public function __construct(
        public int $candidates,
        public int $newCases,
        public int $goneCases,
    ) {
    }
}
