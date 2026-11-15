<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * Why a row of an "Add" was not added (docs/features/data-import/adding.md "Refusals") - beside the writer's own
 * codes (ImportedResultRefusal), which are stored by their value just like these. A refused row waits again with the
 * code in `data_import_row.failure`; `Failed` alone is ours: the row threw.
 */
enum ImportRowRefusal: string
{
    /** The row is not what "Add" may take any more: its puzzle is no longer decided, it has a problem, it is no data */
    case NotReady = 'not_ready';
    /** Its puzzle is kept as an unlisted puzzle - those arrive with a later release */
    case UnlistedNotYet = 'unlisted_not_yet';
    /** A result known to the month or the year only, or without a date - arrives with the date precision */
    case NeedsExactDay = 'needs_exact_day';
    /** The catalogue puzzle is hidden, secret or gone - the player chooses again */
    case PuzzleNotAvailable = 'puzzle_not_available';
    /** The profile has a result of this puzzle with the same time on another day, or the same day with another time */
    case SimilarResultExists = 'similar_result_exists';
    /** A pair's or team's row: somebody else already has a result of the puzzle with this day and time */
    case SomebodyElsesResult = 'somebody_elses_result';
    /** The file says the result is on MySpeedPuzzling already - and none was found */
    case SaidAlreadyOnProfile = 'said_already_on_profile';
    /** The row says pair or team and names nobody (or says solo and names people): the result would be written as another kind */
    case PeopleDoNotFitType = 'people_do_not_fit_type';
    /** The row it is the same result as was not added */
    case EqualRowNotAdded = 'equal_row_not_added';
    /** The comment of an owned puzzle is longer than a collection item takes */
    case CommentTooLong = 'comment_too_long';
    /** The row threw - ours, logged once per batch */
    case Error = 'error';
}
