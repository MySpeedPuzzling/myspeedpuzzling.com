<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Unlisted;

/**
 * Why a result or a mark of an unlisted puzzle stayed unlisted when the puzzle was paired with a catalogue puzzle -
 * beside the writer's own codes (ImportedResultRefusal) and the engine's (ImportRowRefusal), which are stored by
 * their value just like these (docs/features/data-import/unlisted-puzzles.md "What stays unlisted").
 */
enum UnlistedKeptReason: string
{
    /** A result known to the month or the year only, or without a date: a date we do not know is never stored as a day */
    case NeedsExactDay = 'needs_exact_day';
    /** The result it became was changed since - an Undo of the pairing leaves it where it is */
    case EditedSince = 'edited_since';
    /** The collection item it became was moved to another collection since - an Undo leaves it */
    case MovedSince = 'moved_since';
    /** The step threw - ours */
    case Error = 'error';
}
