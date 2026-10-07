<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "This round doesn't use table numbers" (off = true) and back - the seating step and its reminders follow it, numbers
 * given already are kept.
 */
readonly final class ChangeRoundTableNumbersUsage
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        public bool $off,
    ) {
    }
}
