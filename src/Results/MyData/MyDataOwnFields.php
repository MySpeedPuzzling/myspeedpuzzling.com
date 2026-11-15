<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

/**
 * The signed-in player's own fields as columns of one page of "My data" (docs/features/my-data.md): which columns,
 * and the text of every filled cell - worded already, for the rows of that page only.
 */
readonly final class MyDataOwnFields
{
    /**
     * @param array<string, string> $columns column id (`f:<field id>`) => the field's name, in the player's order
     * @param array<string, array<string, string>> $cells row id => column id => the text, filled cells only
     */
    public function __construct(
        public array $columns = [],
        public array $cells = [],
    ) {
    }

    public function cell(string $rowId, string $column): null|string
    {
        return $this->cells[$rowId][$column] ?? null;
    }
}
