<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class TableLayoutRow
{
    /**
     * @param array<TableLayoutTable> $tables
     */
    public function __construct(
        public string $id,
        public int $position,
        public null|string $label,
        public array $tables,
    ) {
    }
}
