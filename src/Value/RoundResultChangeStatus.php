<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What became of one change of RecordRoundResults (three-way check of its `from` against the entry's current value):
 * - applied: the entry held `from`, it holds `to` now (on a dry run: would be applied)
 * - unchanged: the entry holds `to` already - a replay of a change that was applied before
 * - conflict: the entry holds something else - not applied, the outcome carries the current value
 * - rejected: invalid (reason key) - not applied
 */
enum RoundResultChangeStatus: string
{
    case Applied = 'applied';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
    case Rejected = 'rejected';
}
