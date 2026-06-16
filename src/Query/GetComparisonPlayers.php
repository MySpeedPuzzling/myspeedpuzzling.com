<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ComparisonPlayer;

readonly final class GetComparisonPlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $playerIds
     * @return array<string, ComparisonPlayer> keyed by player id
     */
    public function byIds(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    id AS player_id,
    code AS player_code,
    name AS player_name,
    country AS player_country,
    avatar AS player_avatar,
    is_private
FROM player
WHERE id IN (:ids)
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'ids' => $playerIds,
            ], [
                'ids' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();

        $results = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             *     is_private: bool,
             * } $row
             */

            $player = ComparisonPlayer::fromDatabaseRow($row);
            $results[$player->playerId] = $player;
        }

        return $results;
    }
}
