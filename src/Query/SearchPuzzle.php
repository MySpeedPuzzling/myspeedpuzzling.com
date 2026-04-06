<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Results\PiecesFilter;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class SearchPuzzle
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    /**
     * @param list<int> $difficultyTiers
     */
    public function countByUserInput(
        null|string $brandId,
        null|string $search,
        PiecesFilter $pieces,
        null|string $tag,
        array $difficultyTiers = [],
    ): int {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        $difficultyJoin = '';
        $difficultyCondition = '';

        if ($difficultyTiers !== []) {
            $difficultyJoin = 'LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = puzzle.id';
            $difficultyCondition = 'AND pd.difficulty_tier IN(:difficultyTiers)';
        }

        $query = <<<SQL
SELECT
    COUNT(DISTINCT puzzle.id) AS count
FROM puzzle
LEFT JOIN tag_puzzle ON tag_puzzle.puzzle_id = puzzle.id
{$difficultyJoin}
WHERE
    (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
    AND (:brandId::uuid IS NULL OR manufacturer_id = :brandId)
    AND (:minPieces::int IS NULL OR pieces_count >= :minPieces)
    AND (:maxPieces::int IS NULL OR pieces_count <= :maxPieces)
    AND (
        puzzle.alternative_name ILIKE :searchFullLikeQuery
        OR puzzle.name ILIKE :searchFullLikeQuery
        OR immutable_unaccent(puzzle.alternative_name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR immutable_unaccent(puzzle.name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR identification_number ILIKE :searchFullLikeQuery
        OR ean ILIKE :eanSearchFullLikeQuery
   )
    AND (:useTags = false OR tag_puzzle.tag_id IN(:tag))
    {$difficultyCondition}
SQL;

        $eanSearch = trim($search ?? '', '0');

        $params = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'searchFullLikeQuery' => "%$search%",
            'eanSearchFullLikeQuery' => "%$eanSearch%",
            'brandId' => $brandId,
            'minPieces' => $pieces->minPieces(),
            'maxPieces' => $pieces->maxPieces(),
            'useTags' => $tag !== null ? 1 : 0,
            'tag' => $tag ? [$tag] : [],
        ];

        $types = [
            'tag' => ArrayParameterType::STRING,
        ];

        if ($difficultyTiers !== []) {
            $params['difficultyTiers'] = $difficultyTiers;
            $types['difficultyTiers'] = ArrayParameterType::INTEGER;
        }

        $count = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchOne();
        assert(is_int($count));

        return $count;
    }

    /**
     * @param list<int> $difficultyTiers
     *
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
        array $difficultyTiers = [],
    ): array {
        if ($brandId !== null && Uuid::isValid($brandId) === false) {
            throw new ManufacturerNotFound();
        }

        if (in_array($sortBy, ['most-solved', 'least-solved', 'a-z', 'z-a', 'easiest', 'hardest'], true) === false) {
            $sortBy = 'most-solved';
        }

        $difficultyJoin = '';
        $difficultyCondition = '';

        if ($difficultyTiers !== []) {
            $difficultyJoin = 'LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = puzzle.id';
            $difficultyCondition = 'AND pd.difficulty_tier IN(:difficultyTiers)';
        }

        $query = <<<SQL
WITH puzzle_base AS (
    SELECT DISTINCT ON (puzzle.id)
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
        puzzle.alternative_name AS puzzle_alternative_name,
        puzzle.pieces_count,
        puzzle.is_available,
        puzzle.approved AS puzzle_approved,
        puzzle.manufacturer_id,
        puzzle.ean AS puzzle_ean,
        puzzle.identification_number AS puzzle_identification_number,
        puzzle.hide_image_until,
        CASE
            WHEN puzzle.alternative_name ILIKE :searchQuery
              OR puzzle.name ILIKE :searchQuery
              OR puzzle.identification_number = :searchQuery
              OR puzzle.ean = :eanSearchQuery THEN 7
            WHEN immutable_unaccent(puzzle.alternative_name) ILIKE immutable_unaccent(:searchQuery)
              OR immutable_unaccent(puzzle.name) ILIKE immutable_unaccent(:searchQuery) THEN 6
            WHEN puzzle.identification_number ILIKE :searchEndLikeQuery
              OR puzzle.identification_number ILIKE :searchStartLikeQuery
              OR puzzle.ean ILIKE :eanSearchEndLikeQuery
              OR puzzle.ean ILIKE :eanSearchStartLikeQuery THEN 5
            WHEN puzzle.alternative_name ILIKE :searchEndLikeQuery
              OR puzzle.alternative_name ILIKE :searchStartLikeQuery
              OR puzzle.name ILIKE :searchEndLikeQuery
              OR puzzle.name ILIKE :searchStartLikeQuery THEN 4
            WHEN immutable_unaccent(puzzle.alternative_name) ILIKE immutable_unaccent(:searchEndLikeQuery)
              OR immutable_unaccent(puzzle.alternative_name) ILIKE immutable_unaccent(:searchStartLikeQuery)
              OR immutable_unaccent(puzzle.name) ILIKE immutable_unaccent(:searchEndLikeQuery)
              OR immutable_unaccent(puzzle.name) ILIKE immutable_unaccent(:searchStartLikeQuery) THEN 3
            WHEN puzzle.identification_number ILIKE :searchFullLikeQuery
              OR puzzle.ean ILIKE :eanSearchFullLikeQuery THEN 2
            WHEN puzzle.alternative_name ILIKE :searchFullLikeQuery
              OR puzzle.name ILIKE :searchFullLikeQuery THEN 1
            ELSE 0
        END AS match_score
    FROM puzzle
    LEFT JOIN tag_puzzle ON tag_puzzle.puzzle_id = puzzle.id
    {$difficultyJoin}
    WHERE
        (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
        AND (:brandId::uuid IS NULL OR manufacturer_id = :brandId)
        AND (:minPieces::int IS NULL OR pieces_count >= :minPieces)
        AND (:maxPieces::int IS NULL OR pieces_count <= :maxPieces)
        AND (
            puzzle.alternative_name ILIKE :searchFullLikeQuery
            OR puzzle.name ILIKE :searchFullLikeQuery
            OR immutable_unaccent(puzzle.alternative_name) ILIKE immutable_unaccent(:searchFullLikeQuery)
            OR immutable_unaccent(puzzle.name) ILIKE immutable_unaccent(:searchFullLikeQuery)
            OR puzzle.identification_number ILIKE :searchFullLikeQuery
            OR puzzle.ean ILIKE :eanSearchFullLikeQuery
        )
        AND (:useTags = 0 OR tag_puzzle.tag_id IN(:tag))
        {$difficultyCondition}
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
    pb.hide_image_until,
    COALESCE(ps.solved_times_count, 0) AS solved_times,
    ps.average_time_solo,
    ps.fastest_time_solo,
    ps.average_time_duo,
    ps.fastest_time_duo,
    ps.average_time_team,
    ps.fastest_time_team
FROM puzzle_base pb
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = pb.puzzle_id
LEFT JOIN puzzle_difficulty pdi ON pdi.puzzle_id = pb.puzzle_id
INNER JOIN manufacturer m ON pb.manufacturer_id = m.id
SQL;
        if ($sortBy === 'most-solved') {
            $query .= ' ORDER BY solved_times DESC, pb.match_score DESC, pb.puzzle_name, m.name ';
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

        if ($sortBy === 'easiest') {
            $query .= ' ORDER BY pdi.difficulty_score ASC NULLS LAST, pb.match_score DESC, pb.puzzle_name ';
        }

        if ($sortBy === 'hardest') {
            $query .= ' ORDER BY pdi.difficulty_score DESC NULLS LAST, pb.match_score DESC, pb.puzzle_name ';
        }

         $query .= ' LIMIT :limit OFFSET :offset';

        $eanSearch = trim($search ?? '', '0');

        $params = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
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
        ];

        $types = [
            'tag' => ArrayParameterType::STRING,
        ];

        if ($difficultyTiers !== []) {
            $params['difficultyTiers'] = $difficultyTiers;
            $types['difficultyTiers'] = ArrayParameterType::INTEGER;
        }

        $data = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleOverview {
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
             *     hide_image_until: null|string,
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * Search for all puzzles by EAN code.
     * Strips leading zeros for flexible matching.
     *
     * @return array<array{puzzle_id: string, puzzle_name: string, puzzle_image: null|string, puzzle_ean: null|string, pieces_count: int, manufacturer_id: string, manufacturer_name: string}>
     */
    public function allByEan(string $ean): array
    {
        // Strip leading/trailing zeros for flexible matching
        $eanSearch = trim($ean, '0');

        if ($eanSearch === '') {
            return [];
        }

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    puzzle.ean AS puzzle_ean,
    puzzle.pieces_count,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name
FROM puzzle
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE puzzle.ean LIKE :eanPattern
SQL;

        /** @var array<array{puzzle_id: string, puzzle_name: string, puzzle_image: null|string, puzzle_ean: null|string, pieces_count: int, manufacturer_id: string, manufacturer_name: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'eanPattern' => '%' . $eanSearch . '%',
            ])
            ->fetchAllAssociative();

        return $rows;
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
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.approved AS puzzle_approved,
    manufacturer.name AS manufacturer_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE ean END AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number
FROM puzzle
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE
    manufacturer_id = :manufacturerId
ORDER BY COALESCE(puzzle.alternative_name, puzzle.name) ASC, manufacturer_name ASC, pieces_count ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'manufacturerId' => $brandId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): AutocompletePuzzle {
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
