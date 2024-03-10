<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class SearchPuzzle
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    public function countByUserInput(
        null|string $brandId = null,
        null|string $search = null,
        bool $onlyWithResults = false,
        null|PiecesFilter $pieces = null,
    ): int {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        $query = <<<SQL
SELECT
    COUNT(DISTINCT puzzle.id) AS count
FROM puzzle
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE
    manufacturer_id = COALESCE(:brandId, manufacturer_id)
    AND pieces_count >= COALESCE(:minPieces, pieces_count)
    AND pieces_count <= COALESCE(:maxPieces, pieces_count)
    AND (
        puzzle.approved = true
        -- OR puzzle.added_by_user_id = :playerId
    )
    AND (
        LOWER(puzzle.alternative_name) LIKE LOWER(:searchFullLikeQuery)
        OR LOWER(puzzle.name) LIKE LOWER(:searchFullLikeQuery)
        OR LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
        OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
        OR identification_number LIKE :searchFullLikeQuery
        OR ean LIKE :searchFullLikeQuery
   )
HAVING COUNT(puzzle_solving_time.id) >= :solvedCount
SQL;

        $count = $this->database
            ->executeQuery($query, [
                'searchQuery' => $search,
                'searchStartLikeQuery' => "%$search",
                'searchEndLikeQuery' => "$search%",
                'searchFullLikeQuery' => "%$search%",
                'brandId' => $brandId,
                'solvedCount' => $onlyWithResults ? 1 : 0,
                'minPieces' => 0,
                'maxPieces' => 15000,
            ])
            ->fetchOne();
        assert(is_int($count));

        return $count;
    }

    /**
     * @throws ManufacturerNotFound
     * @return list<PuzzleOverview>
     */
    public function byUserInput(
        null|string $brandId = null,
        null|string $search = null,
        bool $onlyWithResults = false,
        null|PiecesFilter $pieces = null,
        int $offset = 0,
        int $limit = 20,
    ): array
    {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.image AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.is_available,
    puzzle.approved AS puzzle_approved,
    manufacturer.name AS manufacturer_name,
    ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    COUNT(puzzle_solving_time.id) AS solved_times,
    AVG(CASE WHEN team IS NULL THEN seconds_to_solve END) AS average_time_solo,
    MIN(CASE WHEN team IS NULL THEN seconds_to_solve END) AS fastest_time_solo,
    AVG(CASE WHEN json_array_length(team->'puzzlers') = 2 THEN seconds_to_solve END) AS average_time_duo,
    MIN(CASE WHEN json_array_length(team->'puzzlers') = 2 THEN seconds_to_solve END) AS fastest_time_duo,
    AVG(CASE WHEN json_array_length(team->'puzzlers') > 2 THEN seconds_to_solve END) AS average_time_team,
    MIN(CASE WHEN json_array_length(team->'puzzlers') > 2 THEN seconds_to_solve END) AS fastest_time_team,
    CASE
        WHEN LOWER(puzzle.alternative_name) = LOWER(:searchQuery) OR LOWER(puzzle.name) = LOWER(:searchQuery) OR identification_number = :searchQuery OR ean = :searchQuery THEN 7 -- Exact match with diacritics
        WHEN LOWER(unaccent(puzzle.alternative_name)) = LOWER(unaccent(:searchQuery)) OR LOWER(unaccent(puzzle.name)) = LOWER(unaccent(:searchQuery)) THEN 6 -- Exact match without diacritics
        WHEN identification_number LIKE :searchEndLikeQuery OR identification_number LIKE :searchStartLikeQuery OR ean LIKE :searchEndLikeQuery OR ean LIKE :searchStartLikeQuery THEN 5 -- Starts or ends with the query - code + ean
        WHEN LOWER(puzzle.alternative_name) LIKE LOWER(:searchEndLikeQuery) OR LOWER(puzzle.alternative_name) LIKE LOWER(:searchStartLikeQuery) OR LOWER(puzzle.name) LIKE LOWER(:searchEndLikeQuery) OR LOWER(puzzle.name) LIKE LOWER(:searchStartLikeQuery) THEN 4 -- Starts or ends with the query with diacritics
        WHEN LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchStartLikeQuery)) OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchStartLikeQuery)) THEN 3 -- Starts or ends with the query without diacritics
        WHEN identification_number LIKE :searchFullLikeQuery OR ean LIKE :searchFullLikeQuery THEN 2 -- Partial match - ean + code
        WHEN LOWER(puzzle.alternative_name) LIKE LOWER(:searchFullLikeQuery) OR LOWER(puzzle.name) LIKE LOWER(:searchFullLikeQuery) THEN 1 -- Partial match with diacritics
        ELSE 0 -- Partial match without diacritics or any other case
    END as match_score
FROM puzzle
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE
    manufacturer_id = COALESCE(:brandId, manufacturer_id)
    AND pieces_count >= COALESCE(:minPieces, pieces_count)
    AND pieces_count <= COALESCE(:maxPieces, pieces_count)
    AND (
        puzzle.approved = true
        -- OR puzzle.added_by_user_id = :playerId
    )
    AND (
        LOWER(puzzle.alternative_name) LIKE LOWER(:searchFullLikeQuery)
        OR LOWER(puzzle.name) LIKE LOWER(:searchFullLikeQuery)
        OR LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
        OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
        OR identification_number LIKE :searchFullLikeQuery
        OR ean LIKE :searchFullLikeQuery
   )
GROUP BY puzzle.name, puzzle.pieces_count, manufacturer.name, puzzle.alternative_name, puzzle.id
HAVING COUNT(puzzle_solving_time.id) >= :solvedCount
ORDER BY match_score DESC, COALESCE(puzzle.alternative_name, puzzle.name), manufacturer_name, pieces_count
LIMIT :limit OFFSET :offset
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'searchQuery' => $search,
                'searchStartLikeQuery' => "%$search",
                'searchEndLikeQuery' => "$search%",
                'searchFullLikeQuery' => "%$search%",
                'brandId' => $brandId,
                'solvedCount' => $onlyWithResults ? 1 : 0,
                'limit' => $limit,
                'minPieces' => 0,
                'maxPieces' => 15000,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PuzzleOverview {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_approved: bool,
             *     manufacturer_name: string,
             *     pieces_count: int,
             *     average_time_solo: null|string,
             *     fastest_time_solo: null|int,
             *     average_time_duo: null|string,
             *     fastest_time_duo: null|int,
             *     average_time_team: null|string,
             *     fastest_time_team: null|int,
             *     solved_times: int,
             *     is_available: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }
}
