<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PuzzleRecord;

/**
 * The puzzle's catalogue record for moderators - the direct edit, the reviews and the puzzle's history.
 */
readonly final class GetPuzzleRecord
{
    private const string COLUMNS = <<<SQL
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
SQL;

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

        $query = 'SELECT ' . self::COLUMNS . ' WHERE puzzle.id = :puzzleId';

        $row = $this->database->executeQuery($query, ['puzzleId' => $puzzleId])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return PuzzleRecord::fromDatabaseRow($row);
    }

    /**
     * Every listed puzzle's record in one statement, keyed by id; ids of no puzzle (any more) are simply absent.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, PuzzleRecord>
     */
    public function byIds(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        $rows = $this->database->executeQuery(
            'SELECT ' . self::COLUMNS . ' WHERE puzzle.id IN (:puzzleIds)',
            ['puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $records = [];

        foreach ($rows as $row) {
            $record = PuzzleRecord::fromDatabaseRow($row);
            $records[$record->puzzleId] = $record;
        }

        return $records;
    }
}
