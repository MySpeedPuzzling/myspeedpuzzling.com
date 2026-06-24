<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ComparisonView
{
    public function __construct(
        /** @var list<ComparisonSubjectView> */
        public array $subjects,
        /** @var list<ComparisonPuzzleRow> */
        public array $rows,
        /** @var list<array{id: string, name: string}> */
        public array $availableManufacturers,
        /** @var list<int> */
        public array $availablePieces,
        public int $matchedRows,
        public int $totalRows,
    ) {
    }
}
