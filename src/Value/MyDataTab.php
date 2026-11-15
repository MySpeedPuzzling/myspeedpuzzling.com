<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The tabs of "My data" (docs/features/my-data.md) - the player's own results, the puzzles of their collections and
 * their wish list.
 */
enum MyDataTab: string
{
    case Results = 'results';
    case Puzzles = 'puzzles';
    case WishList = 'wishlist';

    /**
     * @return list<MyDataSort> the first one is the tab's default
     */
    public function sorts(): array
    {
        return match ($this) {
            self::Results => [MyDataSort::Date, MyDataSort::Time, MyDataSort::Pieces, MyDataSort::Name, MyDataSort::Brand],
            self::Puzzles, self::WishList => [MyDataSort::Added, MyDataSort::Name, MyDataSort::Pieces, MyDataSort::Brand],
        };
    }

    /**
     * The built-in columns beside the puzzle itself, in the table's order (the player's own fields follow them).
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Results => ['brand', 'pieces', 'time', 'date', 'group', 'first_try', 'comment', 'photo', 'source', 'ppm'],
            self::Puzzles => ['brand', 'pieces', 'collection', 'added', 'comment', 'solved', 'lent'],
            self::WishList => ['brand', 'pieces', 'added', 'solved'],
        };
    }

    /**
     * The columns a player who never opened the column chooser does not see.
     *
     * @return list<string>
     */
    public function hiddenByDefault(): array
    {
        return match ($this) {
            self::Results => ['photo', 'ppm'],
            self::Puzzles, self::WishList => [],
        };
    }

    public function hasOwnFields(): bool
    {
        return $this !== self::WishList;
    }
}
