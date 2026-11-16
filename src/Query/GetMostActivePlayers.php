<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\MostActivePlayer;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

readonly final class GetMostActivePlayers
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    public function mostActivePlayersQuery(): string
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('p.id');
        // An unfinished result is not a solve (CountsAsSolve)
        $countsAsSolve = CountsAsSolve::condition('pst');

        return <<<SQL
SELECT
    p.id AS player_id,
    p.name AS player_name,
    p.country AS player_country,
    p.avatar AS player_avatar,
    p.code AS player_code,
    {$this->privateProfileAccess->sqlIsPrivate('p')} AS is_private,
    COUNT(DISTINCT (subquery.puzzle_id, subquery.player_id)) as solved_puzzles_count
FROM (
    SELECT
        pst.id as puzzle_id,
        pst.player_id
    FROM
        puzzle_solving_time pst
    WHERE
        pst.suspicious = false
        AND {$countsAsSolve}

    UNION ALL

    SELECT
        pst.id as puzzle_id,
        (elem.player ->> 'player_id')::uuid as player_id
    FROM
        puzzle_solving_time pst
        CROSS JOIN LATERAL json_array_elements(pst.team -> 'puzzlers') AS elem(player)
    WHERE
        pst.team IS NOT NULL
        AND pst.suspicious = false
        AND {$countsAsSolve}
) as subquery
JOIN player p ON subquery.player_id = p.id{$notHidden}
GROUP BY p.id, p.name, p.country
ORDER BY solved_puzzles_count DESC
LIMIT :limit
SQL;
    }

    /**
     * @return array<MostActivePlayer>
     */
    public function mostActiveSoloPlayers(int $limit): array
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('player.id');
        // An unfinished result is not a solve, but its pieces placed and time spent were puzzled (CountsAsSolve)
        $countsAsSolve = CountsAsSolve::condition('puzzle_solving_time');
        $piecesPuzzled = CountsAsSolve::piecesPuzzled('puzzle_solving_time', 'puzzle.pieces_count');
        $secondsPuzzled = CountsAsSolve::secondsPuzzled('puzzle_solving_time');

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    player.country AS player_country,
    player.avatar AS player_avatar,
    player.code AS player_code,
    COUNT(puzzle_solving_time.id) FILTER (WHERE {$countsAsSolve}) as solved_puzzles_count,
    SUM({$piecesPuzzled}) as total_pieces_count,
    SUM({$secondsPuzzled}) as total_seconds,
    {$this->privateProfileAccess->sqlIsPrivate('player')} AS is_private
FROM puzzle_solving_time
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN puzzle ON puzzle_solving_time.puzzle_id = puzzle.id
WHERE puzzle_solving_time.puzzling_type = 'solo'
    AND puzzle_solving_time.suspicious = false
    {$notHidden}
GROUP BY player.id
HAVING COUNT(puzzle_solving_time.id) FILTER (WHERE {$countsAsSolve}) > 0
ORDER BY solved_puzzles_count DESC, total_pieces_count DESC, total_seconds DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): MostActivePlayer {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     player_avatar?: null|string,
             *     solved_puzzles_count: int,
             *     total_pieces_count: int,
             *     total_seconds: int,
             *     is_private: bool,
             * } $row
             */

            return MostActivePlayer::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<MostActivePlayer>
     */
    public function mostActiveSoloPlayersInMonth(int $limit, int $month, int $year): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = $month === 12
            ? sprintf('%04d-01-01', $year + 1)
            : sprintf('%04d-%02d-01', $year, $month + 1);

        $notHidden = $this->hiddenPlayers->sqlExclude('player.id');
        // An unfinished result is not a solve, but its pieces placed and time spent were puzzled (CountsAsSolve)
        $countsAsSolve = CountsAsSolve::condition('puzzle_solving_time');
        $piecesPuzzled = CountsAsSolve::piecesPuzzled('puzzle_solving_time', 'puzzle.pieces_count');
        $secondsPuzzled = CountsAsSolve::secondsPuzzled('puzzle_solving_time');

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    player.country AS player_country,
    player.avatar AS player_avatar,
    player.code AS player_code,
    COUNT(puzzle_solving_time.id) FILTER (WHERE {$countsAsSolve}) as solved_puzzles_count,
    SUM({$piecesPuzzled}) as total_pieces_count,
    SUM({$secondsPuzzled}) as total_seconds,
    {$this->privateProfileAccess->sqlIsPrivate('player')} AS is_private
FROM puzzle_solving_time
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN puzzle ON puzzle_solving_time.puzzle_id = puzzle.id
WHERE puzzle_solving_time.puzzling_type = 'solo'
    AND puzzle_solving_time.suspicious = false
    AND puzzle_solving_time.finished_at >= :startDate
    AND puzzle_solving_time.finished_at < :endDate
    -- "Most active of the month": a result known to its month is of that month; one known to its year only is of no
    -- month (it stands on 1 January) - docs/features/data-import/date-precision.md
    AND puzzle_solving_time.finished_at_precision IS DISTINCT FROM 'year'
    {$notHidden}
GROUP BY player.id
HAVING COUNT(puzzle_solving_time.id) FILTER (WHERE {$countsAsSolve}) > 0
ORDER BY solved_puzzles_count DESC, total_pieces_count DESC, total_seconds DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): MostActivePlayer {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     player_avatar?: null|string,
             *     solved_puzzles_count: int,
             *     total_pieces_count: int,
             *     total_seconds: int,
             *     is_private: bool,
             * } $row
             */

            return MostActivePlayer::fromDatabaseRow($row);
        }, $data);
    }
}
