<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetFastestPairs
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function perPiecesCount(int $piecesCount, int $howManyPlayers, null|CountryCode $countryCode): array
    {
        $query = <<<SQL
WITH candidate_times AS (
    SELECT pst.id
    FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE puzzle.pieces_count = :piecesCount
        AND pst.puzzling_type = 'duo'
        AND pst.seconds_to_solve > 0
        AND pst.suspicious = false
    ORDER BY pst.seconds_to_solve ASC
    LIMIT 500
),
player_data AS (
    SELECT
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        puzzle.alternative_name AS puzzle_alternative_name,
        puzzle.image AS puzzle_image,
        pieces_count,
        comment,
        tracked_at,
        finished_at,
        finished_puzzle_photo,
        pst.seconds_to_solve AS time,
        player.name AS player_name,
        player.code AS player_code,
        player.country AS player_country,
        player.id AS player_id,
        manufacturer.name AS manufacturer_name,
        puzzle.identification_number AS puzzle_identification_number,
        pst.id AS time_id,
        pst.team ->> 'team_id' AS team_id,
        first_attempt,
        player.is_private,
        competition.id AS competition_id,
        competition.shortcut AS competition_shortcut,
        competition.name AS competition_name,
        competition.slug AS competition_slug,
        JSON_AGG(
            JSON_BUILD_OBJECT(
                'player_id', player_elem.player ->> 'player_id',
                'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                'player_code', p.code,
                'player_country', p.country,
                'is_private', p.is_private
            ) ORDER BY player_elem.ordinality
        ) AS players
    FROM candidate_times ct
    INNER JOIN puzzle_solving_time pst ON pst.id = ct.id
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    INNER JOIN player ON pst.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN competition ON pst.competition_id = competition.id,
    LATERAL json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
    GROUP BY puzzle.id, player.id, manufacturer.id, pst.id, competition.id
)
SELECT *
FROM player_data
SQL;

        if ($countryCode !== null) {
            $query .= <<<SQL
    WHERE EXISTS (
        SELECT 1
        FROM json_array_elements(player_data.players) AS filtered_player
        WHERE filtered_player->>'player_country' = :countryCode
    )
SQL;
        }

        $query .= <<<SQL
    ORDER BY time ASC
    LIMIT :howManyPlayers
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'piecesCount' => $piecesCount,
                'countryCode' => $countryCode?->name,
                'howManyPlayers' => $howManyPlayers,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): SolvedPuzzle {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_image: null|string,
             *     time: int,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     player_id: string,
             *     manufacturer_name: string,
             *     time_id: string,
             *     finished_puzzle_photo: null|string,
             *     tracked_at: string,
             *     pieces_count: int,
             *     comment: null|string,
             *     puzzle_identification_number: null|string,
             *     players: null|string,
             *     finished_at: string,
             *     first_attempt: bool,
             *     is_private: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
