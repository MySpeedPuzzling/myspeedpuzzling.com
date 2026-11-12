<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

readonly final class ParsedEventRow
{
    public function __construct(
        public int $line,
        public string $teamName,
        // The row's identity across runs: the name folded like a search (SearchText::fold()) - a renamed team is a
        // removed row and a new one
        public string $teamKey,
        public int $teamSize,
        public int $seconds,
    ) {
    }
}
