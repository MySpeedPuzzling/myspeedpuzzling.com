<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PuzzleRecord;

/**
 * The puzzle's catalogue record for moderators - the direct edit and the puzzle's history.
 */
readonly final class GetPuzzleRecord
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Null when there is no such puzzle (any more - a merge deletes puzzles, their history stays).
     */
    public function byId(string $puzzleId): null|PuzzleRecord
    {
        if (Uuid::isValid($puzzleId) === false) {
            return null;
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name,
    puzzle.name_language,
    puzzle.alternative_names,
    puzzle.pieces_count,
    puzzle.ean,
    puzzle.identification_number,
    puzzle.image,
    puzzle.approved,
    puzzle.added_at,
    puzzle.hide_until,
    puzzle.hide_image_until,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.approved AS manufacturer_approved,
    added_by.id AS added_by_id,
    added_by.name AS added_by_name,
    added_by.code AS added_by_code
FROM puzzle
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN player added_by ON added_by.id = puzzle.added_by_user_id
WHERE puzzle.id = :puzzleId
SQL;

        $row = $this->database->executeQuery($query, ['puzzleId' => $puzzleId])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return PuzzleRecord::fromDatabaseRow($row);
    }
}
