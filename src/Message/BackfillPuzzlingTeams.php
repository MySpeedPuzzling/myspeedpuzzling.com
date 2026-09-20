<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class BackfillPuzzlingTeams
{
    public function __construct(
        public int $batchSize = 1000,
        // Keyset cursor: only times with a greater id are looked at, so a row that cannot be converted
        // is passed by instead of being served again forever
        public null|string $afterTimeId = null,
    ) {
    }
}
