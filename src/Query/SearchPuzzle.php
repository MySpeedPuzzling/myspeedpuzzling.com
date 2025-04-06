<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Results\AutocompletePuzzle;
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
        null|string $brandId,
        null|string $search,
        PiecesFilter $pieces,
        null|string $tag,
    ): int {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        $query = <<<SQL
SELECT
    COUNT(DISTINCT puzzle.id) AS count
FROM puzzle
LEFT JOIN tag_puzzle ON tag_puzzle.puzzle_id = puzzle.id
WHERE
    manufacturer_id = COALESCE(:brandId, manufacturer_id)
    AND pieces_count >= COALESCE(:minPieces, pieces_count)
    AND pieces_count <= COALESCE(:maxPieces, pieces_count)
    AND (
        LOWER(puzzle.alternative_name) LIKE LOWER(:searchFullLikeQuery)
        OR LOWER(puzzle.name) LIKE LOWER(:searchFullLikeQuery)
        OR LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
        OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
        OR identification_number LIKE :searchFullLikeQuery
        OR ean LIKE :eanSearchFullLikeQuery
   )
    AND (:useTags = false OR tag_puzzle.tag_id IN(:tag))
SQL;

        $eanSearch = trim($search ?? '', '0');

        $count = $this->database
            ->executeQuery($query, [
                'searchFullLikeQuery' => "%$search%",
                'eanSearchFullLikeQuery' => "%$eanSearch%",
                'brandId' => $brandId,
                'minPieces' => $pieces->minPieces(),
                'maxPieces' => $pieces->maxPieces(),
                'useTags' => $tag !== null ? 1 : 0,
                'tag' => $tag ? [$tag] : [],
            ], [
                'tag' => ArrayParameterType::STRING,
            ])
            ->fetchOne();
        assert(is_int($count));

        return $count;
    }

    /**
     * @return list<PuzzleOverview>
     *
     * @throws ManufacturerNotFound
     */
    public function byUserInput(
        null|string $brandId,
        null|string $search,
        PiecesFilter $pieces,
        null|string $tag,
        null|string $sortBy = null,
        int $offset = 0,
        int $limit = 20,
    ): array
    {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        if (in_array($sortBy, ['most-solved', 'least-solved', 'a-z', 'z-a'], true) === false) {
            $sortBy = 'most-solved';
        }

        $query = <<<SQL
WITH puzzle_base AS (
    SELECT
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        puzzle.image AS puzzle_image,
        puzzle.alternative_name AS puzzle_alternative_name,
        puzzle.pieces_count,
        puzzle.is_available,
        puzzle.approved AS puzzle_approved,
        puzzle.manufacturer_id,
        puzzle.ean AS puzzle_ean,
        puzzle.identification_number AS puzzle_identification_number,
        CASE
            WHEN LOWER(puzzle.alternative_name) = LOWER(:searchQuery)
              OR LOWER(puzzle.name) = LOWER(:searchQuery)
              OR puzzle.identification_number = :searchQuery
              OR puzzle.ean = :eanSearchQuery THEN 7
            WHEN LOWER(unaccent(puzzle.alternative_name)) = LOWER(unaccent(:searchQuery))
              OR LOWER(unaccent(puzzle.name)) = LOWER(unaccent(:searchQuery)) THEN 6
            WHEN puzzle.identification_number LIKE :searchEndLikeQuery
              OR puzzle.identification_number LIKE :searchStartLikeQuery
              OR puzzle.ean LIKE :eanSearchEndLikeQuery
              OR puzzle.ean LIKE :eanSearchStartLikeQuery THEN 5
            WHEN LOWER(puzzle.alternative_name) LIKE LOWER(:searchEndLikeQuery)
              OR LOWER(puzzle.alternative_name) LIKE LOWER(:searchStartLikeQuery)
              OR LOWER(puzzle.name) LIKE LOWER(:searchEndLikeQuery)
              OR LOWER(puzzle.name) LIKE LOWER(:searchStartLikeQuery) THEN 4
            WHEN LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchEndLikeQuery))
              OR LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchStartLikeQuery))
              OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchEndLikeQuery))
              OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchStartLikeQuery)) THEN 3
            WHEN puzzle.identification_number LIKE :searchFullLikeQuery
              OR puzzle.ean LIKE :eanSearchFullLikeQuery THEN 2
            WHEN LOWER(puzzle.alternative_name) LIKE LOWER(:searchFullLikeQuery)
              OR LOWER(puzzle.name) LIKE LOWER(:searchFullLikeQuery) THEN 1
            ELSE 0
        END AS match_score
    FROM puzzle
    LEFT JOIN tag_puzzle ON tag_puzzle.puzzle_id = puzzle.id
    WHERE
        manufacturer_id = COALESCE(:brandId, manufacturer_id)
        AND pieces_count >= COALESCE(:minPieces, pieces_count)
        AND pieces_count <= COALESCE(:maxPieces, pieces_count)
        AND (
            LOWER(puzzle.alternative_name) LIKE LOWER(:searchFullLikeQuery)
            OR LOWER(puzzle.name) LIKE LOWER(:searchFullLikeQuery)
            OR LOWER(unaccent(puzzle.alternative_name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
            OR LOWER(unaccent(puzzle.name)) LIKE LOWER(unaccent(:searchFullLikeQuery))
            OR puzzle.identification_number LIKE :searchFullLikeQuery
            OR puzzle.ean LIKE :eanSearchFullLikeQuery
        )
        AND (:useTags = 0 OR tag_puzzle.tag_id IN(:tag))
)
SELECT
    pb.puzzle_id,
    pb.puzzle_name,
    pb.puzzle_image,
    pb.puzzle_alternative_name,
    pb.pieces_count,
    pb.is_available,
    pb.puzzle_approved,
    m.name AS manufacturer_name,
    m.id AS manufacturer_id,
    pb.puzzle_ean,
    pb.puzzle_identification_number,
    COUNT(pst.id) AS solved_times,
    AVG(CASE WHEN pst.team IS NULL THEN pst.seconds_to_solve END) AS average_time_solo,
    MIN(CASE WHEN pst.team IS NULL THEN pst.seconds_to_solve END) AS fastest_time_solo,
    AVG(CASE WHEN json_array_length(pst.team->'puzzlers') = 2 THEN pst.seconds_to_solve END) AS average_time_duo,
    MIN(CASE WHEN json_array_length(pst.team->'puzzlers') = 2 THEN pst.seconds_to_solve END) AS fastest_time_duo,
    AVG(CASE WHEN json_array_length(pst.team->'puzzlers') > 2 THEN pst.seconds_to_solve END) AS average_time_team,
    MIN(CASE WHEN json_array_length(pst.team->'puzzlers') > 2 THEN pst.seconds_to_solve END) AS fastest_time_team
FROM puzzle_base pb
LEFT JOIN puzzle_solving_time pst ON pst.puzzle_id = pb.puzzle_id
INNER JOIN manufacturer m ON pb.manufacturer_id = m.id
GROUP BY pb.puzzle_id,
         pb.puzzle_name,
         pb.puzzle_image,
         pb.puzzle_alternative_name,
         pb.pieces_count,
         pb.is_available,
         pb.puzzle_approved,
         pb.puzzle_ean,
         pb.puzzle_identification_number,
         m.name,
         m.id,
         pb.match_score
SQL;
        if ($sortBy === 'most-solved') {
            $query .= ' ORDER BY solved_times DESC, pb.match_score DESC, pb.puzzle_namegs, m.name ';
        }

        if ($sortBy === 'least-solved') {
            $query .= ' ORDER BY solved_times ASC, pb.match_score DESC, pb.puzzle_name, m.name ';
        }

        if ($sortBy === 'a-z') {
            $query .= ' ORDER BY pb.puzzle_name, pb.match_score DESC, m.name, pb.pieces_count ';
        }

        if ($sortBy === 'z-a') {
            $query .= ' ORDER BY pb.puzzle_name DESC, pb.match_score DESC, m.name DESC, pb.pieces_count ';
        }

         $query .= ' LIMIT :limit OFFSET :offset';

        $eanSearch = trim($search ?? '', '0');

        $data = $this->database
            ->executeQuery($query, [
                'searchQuery' => $search,
                'searchStartLikeQuery' => "%$search",
                'searchEndLikeQuery' => "$search%",
                'searchFullLikeQuery' => "%$search%",
                'eanSearchQuery' => $eanSearch,
                'eanSearchStartLikeQuery' => "%$eanSearch",
                'eanSearchEndLikeQuery' => "$eanSearch%",
                'eanSearchFullLikeQuery' => "%$eanSearch%",
                'brandId' => $brandId,
                'limit' => $limit,
                'minPieces' => $pieces->minPieces(),
                'maxPieces' => $pieces->maxPieces(),
                'offset' => $offset,
                'useTags' => $tag !== null ? 1 : 0,
                'tag' => $tag ? [$tag] : [],
            ], [
                'tag' => ArrayParameterType::STRING,
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
             *     manufacturer_id: string,
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
             *     puzzle_identification_number: null|string,
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<AutocompletePuzzle>
     */
    public function byBrandId(string $brandId): array
    {
        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.image AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.approved AS puzzle_approved,
    manufacturer.name AS manufacturer_name,
    ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number
FROM puzzle
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE
    manufacturer_id = :manufacturerId
ORDER BY COALESCE(puzzle.alternative_name, puzzle.name) ASC, manufacturer_name ASC, pieces_count ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'manufacturerId' => $brandId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): AutocompletePuzzle {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_image: null|string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     pieces_count: int,
             *     puzzle_approved: bool,
             *     puzzle_ean: null|string,
             *     puzzle_identification_number: null|string
             * } $row
             */

            return AutocompletePuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
