<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class IsCompetitionSeriesMaintainer
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function check(string $seriesId, string $playerId): bool
    {
        $query = <<<SQL
SELECT 1 FROM (
    SELECT added_by_player_id AS player_id FROM competition_series WHERE id = :seriesId AND added_by_player_id = :playerId
    UNION
    SELECT player_id FROM competition_series_maintainer WHERE competition_series_id = :seriesId AND player_id = :playerId
) sub
LIMIT 1
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'seriesId' => $seriesId,
                'playerId' => $playerId,
            ])
            ->fetchOne();

        return $result !== false;
    }
}
