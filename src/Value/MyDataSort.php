<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a table of "My data" is sorted by (docs/features/my-data.md) - which ones a tab offers: MyDataTab::sorts().
 */
enum MyDataSort: string
{
    case Date = 'date';
    case Time = 'time';
    case Pieces = 'pieces';
    case Name = 'name';
    case Brand = 'brand';
    case Added = 'added';

    /**
     * The newest first; the fastest, the smallest and A first.
     */
    public function descendingByDefault(): bool
    {
        return $this === self::Date || $this === self::Added;
    }
}
