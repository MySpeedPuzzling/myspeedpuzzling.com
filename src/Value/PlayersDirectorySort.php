<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How the directory of puzzlers is ordered (docs/features/players-page/README.md, "Browse all"). The value is the
 * `?sort=` parameter; Active is the default and never in the URL.
 */
enum PlayersDirectorySort: string
{
    // Most puzzles solved this month, ties by pieces this month
    case Active = 'active';
    // Most recent result first, nobody without a result before anyone with one
    case Recent = 'recent';
    // Latest registration first
    case Newest = 'newest';
    // Most players have them in favorites
    case Followed = 'followed';
    // A-Z by name (the code for players without one)
    case Name = 'name';

    public static function fromQuery(mixed $value): self
    {
        if (!is_string($value)) {
            return self::Active;
        }

        return self::tryFrom($value) ?? self::Active;
    }

    public function isDefault(): bool
    {
        return $this === self::Active;
    }
}
