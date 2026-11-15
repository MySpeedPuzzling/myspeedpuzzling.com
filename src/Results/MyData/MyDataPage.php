<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

use SpeedPuzzling\Web\Value\MyDataCriteria;

/**
 * One page of a table of "My data" and how many rows the filters leave in all.
 *
 * @template TRow of object
 */
readonly final class MyDataPage
{
    /**
     * @param list<TRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        // The page the rows are of - the first one when the asked page lies behind the last row
        public int $page,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / MyDataCriteria::PAGE_SIZE));
    }

    public function firstRow(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * MyDataCriteria::PAGE_SIZE + 1;
    }

    public function lastRow(): int
    {
        return ($this->page - 1) * MyDataCriteria::PAGE_SIZE + count($this->rows);
    }
}
