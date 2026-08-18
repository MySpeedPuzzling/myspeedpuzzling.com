<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class PrunePlayerActivity
{
    public function __construct(
        public int $retentionMonths,
    ) {
    }
}
