<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class BackfillRoundTimezones
{
    public function __construct(
        public bool $dryRun = true,
    ) {
    }
}
