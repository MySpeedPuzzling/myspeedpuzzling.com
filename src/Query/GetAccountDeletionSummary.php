<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AccountDeletionSummary;

readonly final class GetAccountDeletionSummary
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byPlayerId(string $playerId): AccountDeletionSummary
    {
        // Every result the player owns, whatever the puzzling type - solo, duo and
        // team times all vanish (or lose this player) with the account
        $timesQuery = <<<SQL
SELECT
    COUNT(puzzle_solving_time.id) AS solving_times_count,
    COALESCE(SUM(puzzle.pieces_count), 0) AS total_pieces,
    COALESCE(SUM(puzzle_solving_time.seconds_to_solve), 0) AS total_seconds
FROM puzzle_solving_time
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE puzzle_solving_time.player_id = :playerId
SQL;

        /**
         * @var false|array{
         *     solving_times_count: int|string,
         *     total_pieces: int|string,
         *     total_seconds: int|string,
         * } $timesRow
         */
        $timesRow = $this->database
            ->executeQuery($timesQuery, ['playerId' => $playerId])
            ->fetchAssociative();

        // Distinct puzzles across the system collection and every custom one: a
        // puzzle filed in two collections is still one puzzle the user catalogued
        $collectionsQuery = <<<SQL
SELECT COUNT(DISTINCT collection_item.puzzle_id)
FROM collection_item
WHERE collection_item.player_id = :playerId
SQL;

        $collectionPuzzles = $this->database
            ->executeQuery($collectionsQuery, ['playerId' => $playerId])
            ->fetchOne();

        return new AccountDeletionSummary(
            solvingTimesCount: is_array($timesRow) ? (int) $timesRow['solving_times_count'] : 0,
            totalPieces: is_array($timesRow) ? (int) $timesRow['total_pieces'] : 0,
            totalSeconds: is_array($timesRow) ? (int) $timesRow['total_seconds'] : 0,
            collectionPuzzlesCount: is_numeric($collectionPuzzles) ? (int) $collectionPuzzles : 0,
        );
    }
}
