<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetFastestPlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function perPiecesCount(int $piecesCount, int $limit, null|CountryCode $countryCode): array
    {
        $query = <<<SQL
WITH FastestTimes AS (
    SELECT puzzle_solving_time_id
    FROM (
        SELECT DISTINCT ON (pst.player_id)
            pst.id AS puzzle_solving_time_id,
            pst.seconds_to_solve
        FROM puzzle_solving_time pst
        INNER JOIN puzzle p ON p.id = pst.puzzle_id
        INNER JOIN player pl ON pl.id = pst.player_id
        WHERE pst.team IS NULL
          AND p.pieces_count = :piecesCount
          AND pl.name IS NOT NULL
          AND pst.seconds_to_solve > 0
SQL;

        if ($countryCode != null) {
            $query .= <<<SQL
    AND pl.country = :countryCode
SQL;
        }

        $query .= <<<SQL
        ORDER BY pst.player_id, pst.seconds_to_solve ASC
    )
    ORDER BY seconds_to_solve ASC
    LIMIT :limit
)
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    puzzle_solving_time.tracked_at,
    puzzle_solving_time.finished_at,
    puzzle_solving_time.finished_puzzle_photo,
    puzzle_solving_time.seconds_to_solve AS time,
    player.name AS player_name,
    player.country AS player_country,
    player.id AS player_id,
    COUNT(puzzle_solving_time.puzzle_id) AS solved_times,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.id AS time_id,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.first_attempt,
    is_private,
    competition.id AS competition_id,
    competition.name AS competition_name,
    competition.slug AS competition_slug
FROM FastestTimes
INNER JOIN puzzle_solving_time ON puzzle_solving_time.id = FastestTimes.puzzle_solving_time_id
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON player.id = puzzle_solving_time.player_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
GROUP BY player.id, puzzle.id, manufacturer.id, puzzle_solving_time.id
ORDER BY puzzle_solving_time.seconds_to_solve
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCount' => $piecesCount,
                'limit' => $limit,
                'countryCode' => $countryCode?->name,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SolvedPuzzle {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_image: null|string,
             *     time: int,
             *     player_name: string,
             *     player_country: null|string,
             *     player_id: string,
             *     solved_times: int,
             *     manufacturer_name: string,
             *     time_id: string,
             *     solved_times: int,
             *     finished_puzzle_photo: null|string,
             *     tracked_at: string,
             *     pieces_count: int,
             *     comment: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             *     is_private: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_slug: null|string,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
