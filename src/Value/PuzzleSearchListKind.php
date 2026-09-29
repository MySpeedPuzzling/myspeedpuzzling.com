<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The viewer's own puzzle lists the puzzle database can be narrowed to.
 */
enum PuzzleSearchListKind: string
{
    case Library = 'library';
    case Wishlist = 'wishlist';
    case Unsolved = 'unsolved';
    case Solved = 'solved';
    case Collection = 'collection';
    case Borrowed = 'borrowed';
    case Lent = 'lent';
    case SellSwap = 'sell-swap';

    /**
     * Custom collections, lending and sell/swap are member features -
     * the rest is there for every signed-in player.
     */
    public function isMembersOnly(): bool
    {
        return match ($this) {
            self::Collection, self::Borrowed, self::Lent, self::SellSwap => true,
            self::Library, self::Wishlist, self::Unsolved, self::Solved => false,
        };
    }
}
