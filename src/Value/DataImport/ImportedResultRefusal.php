<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * Why ImportedResultWriter did not add a row's result - every one is a rule the add form's handlers hold too
 * (docs/features/data-import/writer.md "Refusals"). The value is the row's reason code.
 */
enum ImportedResultRefusal: string
{
    // The puzzle is gone (deleted, or merged without the row following puzzle_redirect)
    case PuzzleNotFound = 'puzzle_not_found';
    // A secret competition puzzle before its reveal takes nothing personal from anybody
    case PuzzleNotRevealed = 'puzzle_not_revealed';
    // People were named, and none of them is anybody but the player
    case EmptyGroup = 'empty_group';
    // Somebody of the result already holds a first try on the puzzle
    case FirstTryTaken = 'first_try_taken';
    // 100 pieces a minute per person or more
    case TooFast = 'too_fast';
    // Longer than the add form's largest time, 999:59:59
    case TimeTooLong = 'time_too_long';
    // The public comment is longer than the add form takes
    case CommentTooLong = 'comment_too_long';
}
