<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class TableLayoutTable
{
    /**
     * @param array<TableLayoutSpot> $spots
     */
    public function __construct(
        public string $id,
        public int $position,
        public string $label,
        public array $spots,
    ) {
    }
}
