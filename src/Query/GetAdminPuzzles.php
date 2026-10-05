<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\AdminPuzzle;

/**
 * Puzzles by id as the internal API shows them to an admin - approved or not, secret (hide_until) or not.
 */
readonly final class GetAdminPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return array<string, AdminPuzzle> keyed by the (lower-case) id, unknown ids left out
     */
    public function byIds(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_filter($puzzleIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($puzzleIds === []) {
            return [];
        }

        /**
         * @var list<array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     manufacturer_id: null|string,
         *     manufacturer_name: null|string,
         *     puzzle_ean: null|string,
         *     puzzle_identification_number: null|string,
         *     puzzle_approved: bool,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    p.ean AS puzzle_ean,
    p.identification_number AS puzzle_identification_number,
    p.approved AS puzzle_approved
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE p.id IN (:puzzleIds)
SQL, ['puzzleIds' => $puzzleIds], ['puzzleIds' => ArrayParameterType::STRING]);

        $puzzles = [];

        foreach ($rows as $row) {
            $puzzles[$row['puzzle_id']] = AdminPuzzle::fromDatabaseRow($row);
        }

        return $puzzles;
    }
}
