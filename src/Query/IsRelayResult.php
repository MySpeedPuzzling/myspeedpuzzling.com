<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * A relay team's result of one of its puzzles (docs/features/event-results-import/solo-and-relays.md §2): the team
 * solved two puzzles at the same time and only the total is known - so the row has no time of its own and no pieces
 * placed, which would read as a relax solve. Jan (2026-11-17): the total "is not a correct real time that should be
 * presented on the puzzle detail - it is something special". So **the puzzle's own pages leave it out**: the tabs and
 * their relax / unfinished counts, the puzzle's statistics ("Completed N×", averages), the result detail of the puzzle.
 * It counts everywhere else like any team result (players' profiles and statistics, global statistics) and is ranked
 * by the relay's total on the event's round page (GetRoundResults::relaysForRound()).
 *
 * Every reader of the puzzle page family uses it or is listed in IsRelayResultCoverageTest; timed readers
 * (`seconds_to_solve IS NOT NULL` - leaderboards, ranks, medians, difficulty) never see such a row anyway.
 */
readonly final class IsRelayResult
{
    /**
     * notRelay() for the usual alias `pst` = puzzle_solving_time, for SQL built in constants.
     */
    public const string SQL_NOT_RELAY = '(pst.relay_result_id IS NULL)';

    /**
     * WHERE / FILTER fragment: the row of `$alias` (a puzzle_solving_time alias) is no relay result. Wrapped in
     * parentheses, safe behind NOT or next to OR.
     */
    public static function notRelay(string $alias): string
    {
        return "({$alias}.relay_result_id IS NULL)";
    }
}
