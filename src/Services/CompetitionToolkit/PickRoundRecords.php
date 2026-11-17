<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;

/**
 * The write side of `pick` rounds' per-puzzle records (`result_puzzles`, competition toolkit A3, A-round-formats.md
 * §2.4-2.5): the locks RecordRoundResults takes while it checks a record against the round, and the round-side guards
 * that keep every stored record valid.
 *
 * Locks, always in the order round, then its puzzles (the order of SecretPuzzleHides):
 * - recording: the round FOR SHARE (its format cannot change meanwhile - EditCompetitionRound and SetCompetitionRoundPuzzles
 *   take it FOR NO KEY UPDATE) and its puzzles FOR KEY SHARE (none of them can be deleted meanwhile);
 * - removing a round puzzle: its row FOR UPDATE first (lockForRemoval()), then the check - so a record naming it is
 *   either committed before the check sees it, or waits and no longer finds the row.
 */
readonly final class PickRoundRecords
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * For RecordRoundResults: the round's format as it is under the lock - null for a `total` round (or a `pick` round
     * without K) - with the piece count of each of its puzzles.
     *
     * @return null|array{toSolve: int, pieces: array<string, int>}
     */
    public function lockForRecording(string $roundId): null|array
    {
        /** @var false|array{puzzle_format: string, puzzles_to_solve: null|int} $round */
        $round = $this->database->fetchAssociative(
            'SELECT puzzle_format, puzzles_to_solve FROM competition_round WHERE id = :roundId FOR SHARE',
            ['roundId' => $roundId],
        );

        // A `total` round takes no record - nothing of its puzzles is needed
        if ($round === false || $round['puzzle_format'] !== RoundPuzzleFormat::Pick->value || $round['puzzles_to_solve'] === null) {
            return null;
        }

        /** @var list<array{id: string, pieces_count: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT crp.id, p.pieces_count
FROM competition_round_puzzle crp
INNER JOIN puzzle p ON p.id = crp.puzzle_id
WHERE crp.round_id = :roundId
ORDER BY crp.id
FOR KEY SHARE OF crp
SQL,
            ['roundId' => $roundId],
        );

        $pieces = [];
        foreach ($rows as $row) {
            $pieces[$row['id']] = (int) $row['pieces_count'];
        }

        return ['toSolve' => $round['puzzles_to_solve'], 'pieces' => $pieces];
    }

    /**
     * Before round puzzles are removed: their rows locked FOR UPDATE, so no record can name them meanwhile.
     *
     * @param list<string> $roundPuzzleIds
     */
    public function lockForRemoval(array $roundPuzzleIds): void
    {
        if ($roundPuzzleIds === []) {
            return;
        }

        $this->database->executeQuery(
            'SELECT id FROM competition_round_puzzle WHERE id IN (:ids) ORDER BY id FOR UPDATE',
            ['ids' => array_map(strtolower(...), $roundPuzzleIds)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * Entries of the round whose record names one of the round puzzles - removing such a puzzle is refused.
     *
     * @param list<string> $roundPuzzleIds
     */
    public function countEntriesNaming(string $roundId, array $roundPuzzleIds): int
    {
        if ($roundPuzzleIds === []) {
            return 0;
        }

        $count = $this->database->executeQuery(
            <<<SQL
SELECT
    (SELECT COUNT(*) FROM competition_participant_round cpr
        WHERE cpr.round_id = :roundId AND cpr.result_puzzles IS NOT NULL
            AND EXISTS (SELECT 1 FROM jsonb_array_elements(cpr.result_puzzles) item WHERE item->>'roundPuzzleId' IN (:ids)))
    + (SELECT COUNT(*) FROM competition_team ct
        WHERE ct.round_id = :roundId AND ct.result_puzzles IS NOT NULL
            AND EXISTS (SELECT 1 FROM jsonb_array_elements(ct.result_puzzles) item WHERE item->>'roundPuzzleId' IN (:ids)))
SQL,
            ['roundId' => $roundId, 'ids' => array_map(strtolower(...), $roundPuzzleIds)],
            ['ids' => ArrayParameterType::STRING],
        )->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Entries of the round whose record would break the rules under a new format or K (A §2.5) - the change is
     * refused while there is one. `total` takes no record at all.
     *
     * @param array<string, int> $piecesByRoundPuzzleId the round's puzzles as they stay
     */
    public function countRecordsBrokenBy(string $roundId, RoundPuzzleFormat $format, null|int $toSolve, array $piecesByRoundPuzzleId): int
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT result_seconds, result_pieces_placed, result_did_not_start, result_puzzles
FROM competition_participant_round
WHERE round_id = :roundId AND result_puzzles IS NOT NULL
UNION ALL
SELECT result_seconds, result_pieces_placed, result_did_not_start, result_puzzles
FROM competition_team
WHERE round_id = :roundId AND result_puzzles IS NOT NULL
SQL,
            ['roundId' => $roundId],
        );

        $broken = 0;
        foreach ($rows as $row) {
            /** @var array{result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_puzzles: string} $row */
            $puzzles = RoundEntryPuzzles::fromStored($row['result_puzzles']);

            if ($puzzles === null) {
                continue;
            }

            if ($format !== RoundPuzzleFormat::Pick || $toSolve === null) {
                $broken++;

                continue;
            }

            $result = RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']);

            if (
                $puzzles->itemsRefusal($toSolve, $piecesByRoundPuzzleId) !== null
                || RoundEntryPuzzles::consistencyRefusal($result, $puzzles, $toSolve, $piecesByRoundPuzzleId) !== null
            ) {
                $broken++;
            }
        }

        return $broken;
    }
}
