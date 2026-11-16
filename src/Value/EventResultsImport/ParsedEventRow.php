<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

use LogicException;

readonly final class ParsedEventRow
{
    public function __construct(
        public int $line,
        public string $teamName,
        // The row's identity across runs: the name folded like a search (SearchText::fold()) - a renamed team is a
        // removed row and a new one
        public string $teamKey,
        public int $teamSize,
        // The team's time - or, when it did not finish within the round's limit, its pieces placed (never both)
        public null|int $seconds,
        public null|int $piecesPlaced = null,
    ) {
        if (($seconds === null) === ($piecesPlaced === null)) {
            throw new LogicException('A team of the sheet has either a time or pieces placed.');
        }
    }

    public function isUnfinished(): bool
    {
        return $this->piecesPlaced !== null;
    }
}
