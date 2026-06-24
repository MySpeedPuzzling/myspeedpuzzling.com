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

        // Skill is computed only for the 500-piece category (PuzzleIntelligenceRecalculator::SKILL_PIECES_COUNTS).
        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.code AS player_code,
    player.name AS player_name,
    player.country AS player_country,
    player.avatar AS player_avatar,
    player.is_private,
    player.ranking_opted_out,
    ps.skill_tier
FROM player
LEFT JOIN player_skill ps ON ps.player_id = player.id AND ps.pieces_count = 500
WHERE player.id IN (:ids)
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
             *     ranking_opted_out: null|bool,
             *     skill_tier: null|int|string,
             * } $row
             */

            $player = ComparisonPlayer::fromDatabaseRow($row);
            $results[$player->playerId] = $player;
        }

        return $results;
    }
}
