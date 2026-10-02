<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DuplicateDetectedBy;

readonly final class DetectDuplicateResults
{
    public function __construct(
        public DuplicateDetectedBy $detectedBy = DuplicateDetectedBy::Cron,
    ) {
    }
}
