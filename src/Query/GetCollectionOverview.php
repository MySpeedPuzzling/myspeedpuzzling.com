<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleCollectionNotFound;
use SpeedPuzzling\Web\Results\CollectionDetail;

readonly final class GetCollectionOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleCollectionNotFound
     */
    public function byId(string $collectionId): CollectionDetail
    {
        if (!Uuid::isValid($collectionId)) {
            throw new PuzzleCollectionNotFound();
        }

        $query = <<<SQL
SELECT
    c.id,
    c.name,
    c.description,
    c.is_public,
    c.system_type,
    c.created_at,
    c.updated_at,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    COUNT(DISTINCT ci.id) AS puzzles_count
FROM puzzle_collection c
INNER JOIN player p ON p.id = c.player_id
LEFT JOIN puzzle_collection_item ci ON ci.collection_id = c.id
WHERE c.id = :collectionId
GROUP BY c.id, c.name, c.description, c.is_public, c.system_type, c.created_at, c.updated_at, p.id, p.name, p.code
SQL;

        /** @var false|array{
         *     id: string,
         *     name: null|string,
         *     description: null|string,
         *     is_public: bool,
         *     system_type: null|string,
         *     created_at: string,
         *     updated_at: null|string,
         *     player_id: string,
         *     player_name: null|string,
         *     player_code: string,
         *     puzzles_count: int,
         * } $row */
        $row = $this->database->fetchAssociative($query, ['collectionId' => $collectionId]);

        if ($row === false) {
            throw new PuzzleCollectionNotFound();
        }

        return CollectionDetail::fromDatabaseRow($row);
    }

    /**
     * Get collection for completed puzzles (virtual collection)
     */
    public function getCompletedCollection(string $playerId): CollectionDetail
    {
        if (!Uuid::isValid($playerId)) {
            throw new PuzzleCollectionNotFound();
        }

        $query = <<<SQL
SELECT
    :collectionId AS id,
    NULL AS name,
    NULL AS description,
    true AS is_public,
    'completed' AS system_type,
    NOW() AS created_at,
    NULL AS updated_at,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    COUNT(DISTINCT pst.puzzle_id) AS puzzles_count
FROM player p
LEFT JOIN puzzle_solving_time pst ON (
    pst.player_id = p.id 
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(p.id AS text)))
)
WHERE p.id = :playerId
GROUP BY p.id, p.name, p.code
SQL;

        /** @var false|array{
         *     id: string,
         *     name: null|string,
         *     description: null|string,
         *     is_public: bool,
         *     system_type: string,
         *     created_at: string,
         *     updated_at: null|string,
         *     player_id: string,
         *     player_name: null|string,
         *     player_code: string,
         *     puzzles_count: int,
         * } $row */
        $row = $this->database->fetchAssociative($query, [
            'collectionId' => Uuid::uuid7()->toString(),
            'playerId' => $playerId,
        ]);

        if ($row === false) {
            throw new PuzzleCollectionNotFound();
        }

        return CollectionDetail::fromDatabaseRow($row);
    }

    /**
     * Get root collection
     */
    public function getRootCollection(string $playerId): CollectionDetail
    {
        if (!Uuid::isValid($playerId)) {
            throw new PuzzleCollectionNotFound();
        }

        $query = <<<SQL
SELECT
    COALESCE(c.id, :defaultId) AS id,
    c.name,
    c.description,
    COALESCE(c.is_public, true) AS is_public,
    c.system_type,
    COALESCE(c.created_at, NOW()) AS created_at,
    c.updated_at,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    COUNT(DISTINCT ci.id) AS puzzles_count
FROM player p
LEFT JOIN puzzle_collection c ON c.player_id = p.id AND c.system_type IS NULL AND c.name IS NULL
LEFT JOIN puzzle_collection_item ci ON ci.player_id = p.id AND ci.collection_id IS NULL
WHERE p.id = :playerId
GROUP BY c.id, c.name, c.description, c.is_public, c.system_type, c.created_at, c.updated_at, p.id, p.name, p.code
SQL;

        /** @var false|array{
         *     id: string,
         *     name: null|string,
         *     description: null|string,
         *     is_public: bool,
         *     system_type: null|string,
         *     created_at: string,
         *     updated_at: null|string,
         *     player_id: string,
         *     player_name: null|string,
         *     player_code: string,
         *     puzzles_count: int,
         * } $row */
        $row = $this->database->fetchAssociative($query, [
            'defaultId' => Uuid::uuid7()->toString(),
            'playerId' => $playerId,
        ]);

        if ($row === false) {
            throw new PuzzleCollectionNotFound();
        }

        return CollectionDetail::fromDatabaseRow($row);
    }
}
