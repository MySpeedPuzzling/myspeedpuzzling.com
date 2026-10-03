<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Order of the compared puzzles. Lead / lag are the highlighted A minus B (biggest lead = A furthest ahead first);
 * difficulty (hardest first, unrated last) is members-only.
 */
enum ComparisonSort: string
{
    case Recent = 'recent';
    case Lead = 'lead';
    case Lag = 'lag';
    case Name = 'name';
    case Pieces = 'pieces';
    case Difficulty = 'difficulty';

    public function isMembersOnly(): bool
    {
        return $this === self::Difficulty;
    }

    public function needsHighlightPair(): bool
    {
        return $this === self::Lead || $this === self::Lag;
    }
}
