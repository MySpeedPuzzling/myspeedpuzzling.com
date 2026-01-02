<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\TagNotFound;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class GetPuzzleOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function byEan(string $ean): PuzzleOverview
    {
        // TODO: proper validation of ean
        if (strlen($ean) > 15 || strlen($ean) < 7) {
            throw new PuzzleNotFound();
        }

        $ean = ltrim($ean, '0');

        $query = <<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.image AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.is_available,
    puzzle.approved AS puzzle_approved,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times,
    puzzle_statistics.average_time_solo,
    puzzle_statistics.fastest_time_solo,
    puzzle_statistics.average_time_duo,
    puzzle_statistics.fastest_time_duo,
    puzzle_statistics.average_time_team,
    puzzle_statistics.fastest_time_team
FROM puzzle
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE puzzle.ean LIKE :ean
SQL;

        /**
         * @var false|array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_image: null|string,
         *     puzzle_alternative_name: null|string,
         *     puzzle_approved: bool,
         *     manufacturer_id: string,
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
         *     puzzle_identification_number: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'ean' => '%' . $ean,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PuzzleNotFound();
        }

        return PuzzleOverview::fromDatabaseRow($row);
    }

    /**
     * @throws PuzzleNotFound
     */
    public function byId(string $puzzleId): PuzzleOverview
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
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
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times,
    puzzle_statistics.average_time_solo,
    puzzle_statistics.fastest_time_solo,
    puzzle_statistics.average_time_duo,
    puzzle_statistics.fastest_time_duo,
    puzzle_statistics.average_time_team,
    puzzle_statistics.fastest_time_team
FROM puzzle
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
WHERE puzzle.id = :puzzleId
SQL;

        /**
         * @var false|array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_image: null|string,
         *     puzzle_alternative_name: null|string,
         *     puzzle_approved: bool,
         *     manufacturer_id: string,
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
         *     puzzle_identification_number: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PuzzleNotFound();
        }

        return PuzzleOverview::fromDatabaseRow($row);
    }

    /**
     * @return array<PuzzleOverview>
     * @throws TagNotFound
     */
    public function byTagId(string $tagId): array
    {
        if (Uuid::isValid($tagId) === false) {
            throw new TagNotFound();
        }

        $query = <<<SQL
WITH tagged_puzzles AS (
    SELECT puzzle_id
    FROM tag_puzzle
    WHERE tag_id = :tagId
)
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.image AS puzzle_image,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.is_available,
    puzzle.approved AS puzzle_approved,
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    ean AS puzzle_ean,
    puzzle.identification_number AS puzzle_identification_number,
    COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times,
    puzzle_statistics.average_time_solo,
    puzzle_statistics.fastest_time_solo,
    puzzle_statistics.average_time_duo,
    puzzle_statistics.fastest_time_duo,
    puzzle_statistics.average_time_team,
    puzzle_statistics.fastest_time_team
FROM puzzle
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
INNER JOIN tagged_puzzles ON tagged_puzzles.puzzle_id = puzzle.id
ORDER BY solved_times DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'tagId' => $tagId,
            ])
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
             * } $row
             */

            return PuzzleOverview::fromDatabaseRow($row);
        }, $data);
    }
}
