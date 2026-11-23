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
            'SELECT cr.unboxed OR crp.unboxed FROM competition_round_puzzle crp'
            . ' INNER JOIN competition_round cr ON cr.id = crp.round_id'
            . ' WHERE crp.round_id = :roundId AND crp.puzzle_id = :puzzleId'
            . ' ORDER BY crp.unboxed DESC LIMIT 1',
            ['roundId' => $roundId, 'puzzleId' => $puzzleId],
        );

        return $unboxed === true || $unboxed === 't' || $unboxed === 1 || $unboxed === '1';
    }
}
