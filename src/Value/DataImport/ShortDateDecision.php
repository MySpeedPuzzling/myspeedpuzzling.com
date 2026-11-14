<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What DateOrderResolver says about the short dates of one column ("Jan 23", "10/23"): is their last number a day or a
 * two-digit year.
 */
final readonly class ShortDateDecision
{
    /**
     * @param list<int> $exampleRows Indexes into the dates handed to the resolver: for a question, the first short
     *        dates; for a mixed column, the rows that cannot be read the way most can
     */
    public function __construct(
        public DateOrderState $state,
        /** Resolved, or answered by the player: how every short date of the column is read */
        public null|ShortDateMeaning $meaning = null,
        /** When asking: what to offer first - never applied by itself */
        public null|ShortDateMeaning $suggested = null,
        public array $exampleRows = [],
        /** How many values of the column are short dates */
        public int $rows = 0,
    ) {
    }
}
