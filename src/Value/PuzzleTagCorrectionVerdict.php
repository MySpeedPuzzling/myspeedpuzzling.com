<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What an admin or a moderator said about one tag of one puzzle (puzzle_tag_correction) - it wins over every analysis,
 * the next ones included, until the correction is removed.
 */
enum PuzzleTagCorrectionVerdict: string
{
    // The puzzle has the tag - whether the analysis assigned it or not (on an assigned tag: a confirmation)
    case Add = 'add';
    // The puzzle does not have the tag, whatever the analysis says
    case Remove = 'remove';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $verdict): string => $verdict->value, self::cases());
    }
}
