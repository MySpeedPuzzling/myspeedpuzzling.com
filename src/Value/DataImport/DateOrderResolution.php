<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What DateOrderResolver says about the dates of one column: what the last number of its short dates is
 * ($shortDates), and the order of day and month of its dates written in numbers (everything else here).
 */
final readonly class DateOrderResolution
{
    /**
     * @param list<DateOrder> $orders Resolved: the one order. Ambiguous: the orders to choose from (of several that read
     *        every value the same, the first). Typed dates in doubt: the order of the text dates. Mixed, not needed: none
     * @param array<string, int> $rowsByOrder For each order (DateOrder value) how many of the values are a date in it
     * @param list<int> $exampleRows Indexes into the dates handed to the resolver: for a question, rows the orders
     *        read differently; for a mixed column, rows that are no date in the order most rows follow; for typed
     *        dates in doubt, the typed rows
     */
    public function __construct(
        public DateOrderState $state,
        public ShortDateDecision $shortDates,
        public array $orders = [],
        /** When asking: the one order under which no date is in the future or before 1950 - offered first, never applied */
        public null|DateOrder $suggested = null,
        public array $rowsByOrder = [],
        public array $exampleRows = [],
        /** How many values of the column the order matters for */
        public int $rows = 0,
    ) {
    }

    public function order(): null|DateOrder
    {
        return $this->state === DateOrderState::Resolved ? $this->orders[0] ?? null : null;
    }

    /**
     * Whether anything about the column's dates waits for the player (or is a problem).
     */
    public function isSettled(): bool
    {
        $settled = [DateOrderState::NotNeeded, DateOrderState::Resolved];

        return in_array($this->state, $settled, true) && in_array($this->shortDates->state, $settled, true);
    }

    /**
     * A date of the column as what is settled reads it: a short date by the column's meaning, a date in numbers by
     * the column's order. What still waits for an answer stays as it is.
     */
    public function apply(ReadDate $read): ReadDate
    {
        if ($this->shortDates->meaning !== null) {
            $read = $read->asShort($this->shortDates->meaning);
        }

        $order = $this->order();

        return $order === null ? $read : $read->inOrder($order);
    }
}
