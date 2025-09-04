<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Results\CollectionPuzzle;

readonly final class GetCollectionPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<CollectionPuzzle>
     * @throws PuzzleCollectionNotFound
     */
    public function byCollection(string $collectionId): array
    {
        if (!Uuid::isValid($collectionId)) {
            throw new PuzzleCollectionNotFound();
        }

        $query = <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.alternative_name AS puzzle_alternative_name,
    p.pieces_count,
    p.image AS puzzle_image,
    p.ean,
    p.identification_number,
    m.name AS manufacturer_name,
    ci.id AS item_id,
    ci.added_at,
    ci.comment,
    ci.price,
    ci.condition,
    (SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.puzzle_id = p.id AND pst.player_id = ci.player_id) AS times_solved
FROM puzzle_collection_item ci
INNER JOIN puzzle p ON p.id = ci.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE ci.collection_id = :collectionId
ORDER BY ci.added_at DESC
SQL;

        /** @var array<array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     pieces_count: int,
         *     puzzle_image: null|string,
         *     ean: null|string,
         *     identification_number: null|string,
         *     manufacturer_name: null|string,
         *     item_id: string,
         *     added_at: string,
         *     comment: null|string,
         *     price: null|string,
         *     condition: null|string,
         *     times_solved: int,
         * }> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['collectionId' => $collectionId]);

        return array_map(static fn(array $row): CollectionPuzzle => CollectionPuzzle::fromDatabaseRow($row), $rows);
    }

    /**
     * @return array<CollectionPuzzle>
     * For root collection (collection_id IS NULL)
     */
    public function byPlayerRootCollection(string $playerId): array
    {
        if (!Uuid::isValid($playerId)) {
            return [];
        }

        $query = <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.alternative_name AS puzzle_alternative_name,
    p.pieces_count,
    p.image AS puzzle_image,
    p.ean,
    p.identification_number,
    m.name AS manufacturer_name,
    ci.id AS item_id,
    ci.added_at,
    ci.comment,
    ci.price,
    ci.condition,
    (SELECT COUNT(*) FROM puzzle_solving_time pst WHERE pst.puzzle_id = p.id AND pst.player_id = ci.player_id) AS times_solved
FROM puzzle_collection_item ci
INNER JOIN puzzle p ON p.id = ci.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE ci.player_id = :playerId AND ci.collection_id IS NULL
ORDER BY ci.added_at DESC
SQL;

        /** @var array<array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     pieces_count: int,
         *     puzzle_image: null|string,
         *     ean: null|string,
         *     identification_number: null|string,
         *     manufacturer_name: null|string,
         *     item_id: string,
         *     added_at: string,
         *     comment: null|string,
         *     price: null|string,
         *     condition: null|string,
         *     times_solved: int,
         * }> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['playerId' => $playerId]);

        return array_map(static fn(array $row): CollectionPuzzle => CollectionPuzzle::fromDatabaseRow($row), $rows);
    }

    /**
     * @return array<CollectionPuzzle>
     * For completed collection - gets puzzles from puzzle_solving_time
     */
    public function byPlayerCompletedPuzzles(string $playerId): array
    {
        if (!Uuid::isValid($playerId)) {
            return [];
        }

        $query = <<<SQL
SELECT DISTINCT
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.alternative_name AS puzzle_alternative_name,
    p.pieces_count,
    p.image AS puzzle_image,
    p.ean,
    p.identification_number,
    m.name AS manufacturer_name,
    NULL AS item_id,
    MAX(pst.finished_at) AS added_at,
    NULL AS comment,
    NULL AS price,
    NULL AS condition,
    COUNT(DISTINCT pst.id) AS times_solved
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE 
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS text)))
GROUP BY p.id, p.name, p.alternative_name, p.pieces_count, p.image, p.ean, p.identification_number, m.name
ORDER BY MAX(pst.finished_at) DESC
SQL;

        /** @var array<array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     pieces_count: int,
         *     puzzle_image: null|string,
         *     ean: null|string,
         *     identification_number: null|string,
         *     manufacturer_name: null|string,
         *     item_id: null|string,
         *     added_at: string,
         *     comment: null|string,
         *     price: null|string,
         *     condition: null|string,
         *     times_solved: int,
         * }> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['playerId' => $playerId]);

        return array_map(static fn(array $row): CollectionPuzzle => CollectionPuzzle::fromDatabaseRow($row), $rows);
    }

    /**
     * Check if puzzle is in any of player's collections
     * @return array<string> collection IDs or system types
     */
    public function getPuzzleCollections(string $playerId, string $puzzleId): array
    {
        $query = <<<SQL
SELECT DISTINCT
    COALESCE(c.system_type, CAST(c.id AS text)) AS collection_key
FROM puzzle_collection_item ci
LEFT JOIN puzzle_collection c ON c.id = ci.collection_id
WHERE ci.player_id = :playerId AND ci.puzzle_id = :puzzleId

UNION

SELECT DISTINCT 'completed' AS collection_key
FROM puzzle_solving_time pst
WHERE 
    pst.puzzle_id = :puzzleId
    AND (
        pst.player_id = :playerId
        OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS text)))
    )
SQL;

        /** @var array<string> */
        return $this->database->fetchFirstColumn($query, [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
        ]);
    }
}
