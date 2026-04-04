<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class GenerateTableLayout
{
    public function __construct(
        public string $roundId,
        public int $numberOfRows,
        public int $tablesPerRow,
        public int $spotsPerTable,
    ) {
    }
}
