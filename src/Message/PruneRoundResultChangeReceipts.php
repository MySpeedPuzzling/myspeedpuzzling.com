<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class PruneRoundResultChangeReceipts
{
    public function __construct(
        public int $retentionDays,
    ) {
    }
}
