<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * How many puzzles some players solved, from the precomputed community_player_stats (docs/features/players-page/
 * README.md) - the "123 puzzles" next to each instant search result on the Players page. Numbers only: the ids come
 * from a query that already decided who the viewer may see (SearchPlayers).
 */
readonly final class GetSolvedTotals
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $playerIds
     * @return array<string, int> player id => results solved; players without a stats row yet are missing
     */
    public function byPlayerIds(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $rows = $this->database
            ->executeQuery(
                'SELECT player_id, solved_total FROM community_player_stats WHERE player_id IN (:playerIds)',
                ['playerIds' => $playerIds],
                ['playerIds' => ArrayParameterType::STRING],
            )
            ->fetchAllAssociative();

        $totals = [];

        foreach ($rows as $row) {
            /** @var array{player_id: string, solved_total: int|string} $row */
            $totals[$row['player_id']] = (int) $row['solved_total'];
        }

        return $totals;
    }
}
