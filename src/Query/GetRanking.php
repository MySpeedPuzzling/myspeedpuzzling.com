<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerRanking;

readonly final class GetRanking
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<string, PlayerRanking>
     */
    public function allForPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
WITH BestTimes AS (
    SELECT
        puzzle_id,
        player_id,
        MIN(seconds_to_solve) as best_time
    FROM
        puzzle_solving_time
    WHERE team IS NULL
    GROUP BY
        puzzle_id, player_id
),
RankedTimes AS (
    SELECT
        puzzle_id,
        player_id,
        best_time,
        RANK() OVER (PARTITION BY puzzle_id ORDER BY best_time ASC) as rank
    FROM
        BestTimes
)
SELECT
    player_id,
    rank,
    best_time AS time,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.pieces_count,
    puzzle.image AS puzzle_image,
    manufacturer.name AS manufacturer_name
FROM
    RankedTimes
INNER JOIN puzzle ON puzzle.id = RankedTimes.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    player_id = :playerId;

SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        $ranking = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     player_id: string,
             *     rank: int,
             *     time: int,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     pieces_count: int,
             *     puzzle_image: null|string,
             *     manufacturer_name: string,
             * } $row
             */

            $ranking[$row['puzzle_id']] = PlayerRanking::fromDatabaseRow($row);
        }

        return $ranking;
    }
}
