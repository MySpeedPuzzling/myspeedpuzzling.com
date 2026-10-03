<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which puzzles a comparison lists, by how many of the compared subjects solved them (docs/features/player-comparison.md,
 * D10). "Everyone" with two subjects reads "solved by both".
 */
enum ComparisonShow: string
{
    case Everyone = 'everyone';
    case TwoPlus = 'two_plus';
    case All = 'all';

    /**
     * D10: two subjects compare what both solved, a bigger line-up what at least two solved - "everyone" is near-empty
     * for big line-ups and "all" is mostly rows that compare nothing.
     */
    public static function defaultFor(int $subjectCount): self
    {
        return $subjectCount >= 3 ? self::TwoPlus : self::Everyone;
    }

    /**
     * How many subjects must have solved a puzzle for it to be listed.
     */
    public function minimumSolvers(int $subjectCount): int
    {
        return match ($this) {
            self::Everyone => max(1, $subjectCount),
            self::TwoPlus => min(2, max(1, $subjectCount)),
            self::All => 1,
        };
    }
}
