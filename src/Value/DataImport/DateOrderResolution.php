<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What DateOrderResolver says about the dates of one column that are written in numbers.
 */
final readonly class DateOrderResolution
{
    /**
     * @param list<DateOrder> $orders Resolved: the one order. Ambiguous: the orders to choose from (of several that read
     *        every value the same, the first). Mixed and not needed: none
     * @param array<string, int> $rowsByOrder For each order (DateOrder value) how many of the values are a date in it
     * @param list<int> $exampleRows Indexes into the dates handed to the resolver: for a question, rows the orders
     *        read differently; for a mixed column, rows that are no date in the order most rows follow
     */
    public function __construct(
        public DateOrderState $state,
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
     * A date of the column as the resolved order reads it; without a resolved order it stays as it is.
     */
    public function apply(ReadDate $read): ReadDate
    {
        $order = $this->order();

        return $order === null ? $read : $read->inOrder($order);
    }
}
