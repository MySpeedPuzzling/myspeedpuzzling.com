<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;

readonly final class GetExportableSolvingTimes
{
    public function __construct(
        private Connection $database,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return array<ExportableSolvingTime>
     * @throws PlayerNotFound
     */
    public function byPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    pst.id AS time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    manufacturer.name AS brand_name,
    puzzle.pieces_count,
    pst.seconds_to_solve,
    pst.finished_at,
    pst.tracked_at,
    pst.first_attempt,
    pst.finished_puzzle_photo,
    pst.comment,
    pst.puzzling_type AS solving_type,
    pst.puzzlers_count AS players_count,
    (
        SELECT string_agg(
            COALESCE(
                p.name,
                player_elem.player ->> 'player_name',
                CASE WHEN p.code IS NOT NULL THEN '#' || UPPER(p.code) ELSE NULL END
            ),
            ', '
            ORDER BY player_elem.ordinality
        )
        FROM json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
        LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
        WHERE COALESCE(p.name, player_elem.player ->> 'player_name', p.code) IS NOT NULL
    ) AS team_members
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
ORDER BY pst.finished_at DESC, tracked_at DESC
SQL;

        /**
         * @var array<array{
         *     time_id: string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     brand_name: string,
         *     pieces_count: int,
         *     seconds_to_solve: null|int,
         *     finished_at: string,
         *     tracked_at: string,
         *     first_attempt: bool,
         *     finished_puzzle_photo: null|string,
         *     comment: null|string,
         *     solving_type: string,
         *     players_count: int,
         *     team_members: null|string,
         * }> $data
         */
        $data = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(
            fn(array $row): ExportableSolvingTime => ExportableSolvingTime::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $data,
        );
    }
}
