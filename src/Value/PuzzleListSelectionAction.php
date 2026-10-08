<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the selection bar of a list page does with the selected puzzles, besides "Add to collection…" and "Lend to…",
 * which have forms of their own (docs/features/collections/bulk-actions.md "Other lists").
 */
enum PuzzleListSelectionAction: string
{
    case Remove = 'remove';
    case Sold = 'sold';
    case Reserve = 'reserve';
    case Unreserve = 'unreserve';
    case Return = 'return';

    public const string ROUTE_REQUIREMENT = 'remove|sold|reserve|unreserve|return';

    public function isAvailableOn(PuzzleList $list): bool
    {
        return match ($this) {
            self::Remove => $list !== PuzzleList::LendBorrow,
            self::Sold, self::Reserve, self::Unreserve => $list === PuzzleList::SellSwap,
            self::Return => $list === PuzzleList::LendBorrow,
        };
    }

    // A reservation is undone in one tap - everything else asks first
    public function needsConfirmation(): bool
    {
        return $this !== self::Reserve && $this !== self::Unreserve;
    }

    // The cards leave the page; otherwise the page is loaded again (a badge changed, a count on another tab)
    public function removesCards(PuzzleList $list): bool
    {
        return $list->streamTargets() !== null && ($this === self::Remove || $this === self::Sold);
    }

    // The translation keys of this action on that list: puzzle_selection.<list>.<action>.*
    public function translationPrefix(PuzzleList $list): string
    {
        return 'puzzle_selection.' . str_replace('-', '_', $list->value) . '.' . $this->value;
    }
}
