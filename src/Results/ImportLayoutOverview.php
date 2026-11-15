<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One saved layout of the signed-in player, as their list shows it (GetImportLayouts::ofPlayer()).
 */
readonly final class ImportLayoutOverview
{
    public function __construct(
        public string $layoutId,
        public string $name,
        public int $columns,
        public int $timesUsed,
        public DateTimeImmutable $lastUsedAt,
    ) {
    }
}
