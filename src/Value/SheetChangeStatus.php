<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How the server answered one change of a sheet change set - and, without `Skipped`, a whole group of changes.
 *
 * - applied: the value was the change's `from` and is its `to` now
 * - unchanged: the value is the change's `to` already (a replay, or two organisers typing the same)
 * - conflict: the value is neither - somebody changed it meanwhile (`current` says what it is)
 * - refused: a rule refuses the change (`reason`)
 * - skipped: the change would have gone through, but another change of its group did not - a group is all or nothing
 */
enum SheetChangeStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
    case Refused = 'refused';
    case Skipped = 'skipped';
}
