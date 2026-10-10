<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ProgressionSafetyNetRun
{
    /**
     * @param list<string> $failed player ids whose repair failed
     */
    public function __construct(
        public ProgressionDrift $drift,
        public int $repaired,
        public array $failed,
    ) {
    }
}
