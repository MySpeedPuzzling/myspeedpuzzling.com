<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class BoxTextDetectionOutcome
{
    public function __construct(
        public int $puzzlesChecked,
        public int $mismatches,
        public int $written,
        public int $removed,
    ) {
    }
}
