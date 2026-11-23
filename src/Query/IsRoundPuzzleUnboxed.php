<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Whether a puzzle was solved unboxed in a round - the round's "Unboxed" switch or the round puzzle's own flag
 * (world-series-rules.md §7, spws-unboxed-per-puzzle.md). "Add to my profile" of an official entry opens the add form with
 * "Solved without looking at the box image" ticked then (OfficialEntryTimePrefill).
 */
readonly final class IsRoundPuzzleUnboxed
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function of(string $roundId, string $puzzleId): bool
    {
        $unboxed = $this->database->fetchOne(
            'SELECT cr.unboxed OR COALESCE(BOOL_OR(crp.unboxed), false) FROM competition_round cr'
            . ' LEFT JOIN competition_round_puzzle crp ON crp.round_id = cr.id AND crp.puzzle_id = :puzzleId'
            . ' WHERE cr.id = :roundId GROUP BY cr.id',
            ['roundId' => $roundId, 'puzzleId' => $puzzleId],
        );

        return $unboxed === true || $unboxed === 't' || $unboxed === 1 || $unboxed === '1';
    }
}
