<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportPuzzle;

/**
 * The puzzles a results sheet links to, the way the import finds them (ApplyEventResultsTabHandler::puzzle()): the
 * puzzle itself, else the survivor it was merged into. One statement for a whole check.
 */
readonly final class GetResultsImportPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $puzzleIds as linked from the sheet
     * @return array<string, ResultsImportPuzzle> by the linked id (lower-case); a puzzle nobody can find is left out
     */
    public function byLinkedIds(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT linked.id AS linked_id, p.id, p.name, m.name AS manufacturer_name, p.pieces_count, p.image, p.image_ratio, p.approved
FROM unnest(CAST(:ids AS uuid[])) AS linked(id)
LEFT JOIN puzzle_redirect r ON r.old_puzzle_id = linked.id
    AND NOT EXISTS (SELECT 1 FROM puzzle direct WHERE direct.id = linked.id)
INNER JOIN puzzle p ON p.id = COALESCE(r.survivor_puzzle_id, linked.id)
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
SQL;

        /** @var list<array{linked_id: string, id: string, name: string, manufacturer_name: null|string, pieces_count: int|string, image: null|string, image_ratio: null|float|string, approved: bool}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['ids' => '{' . implode(',', $puzzleIds) . '}'],
        )->fetchAllAssociative();

        $puzzles = [];

        foreach ($rows as $row) {
            $puzzles[strtolower($row['linked_id'])] = new ResultsImportPuzzle(
                id: strtolower($row['id']),
                name: $row['name'],
                manufacturerName: $row['manufacturer_name'],
                piecesCount: (int) $row['pieces_count'],
                image: $row['image'],
                imageRatio: $row['image_ratio'] !== null ? (float) $row['image_ratio'] : null,
                approved: $row['approved'],
            );
        }

        return $puzzles;
    }
}
