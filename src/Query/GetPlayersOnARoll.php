<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PlayerOnARoll;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\CommunityScope;

/**
 * "On a roll" on the Players page (docs/features/players-page/README.md): the people with the most results in the
 * last 7 days in the world or one country, with their moments of the same 7 days. One statement over the precomputed
 * tables (community_player_stats, player_moment) - never over puzzle_solving_time.
 *
 * Public profiles only, for everybody: nobody is ranked differently for different viewers. Players the viewer has
 * hidden are left out (HiddenPlayers).
 */
readonly final class GetPlayersOnARoll
{
    public const int DAYS = 7;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Most results first, then most pieces.
     *
     * @return list<PlayerOnARoll>
     */
    public function forScope(CommunityScope $scope, int $limit): array
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('p.id');
        $inScope = $scope->isWorld() ? '' : 'AND LOWER(p.country) = :country';

        // The people first (a top-N over one small table), then the moments of just those people
        $query = <<<SQL
WITH on_a_roll AS (
    SELECT
        p.id AS player_id,
        p.code AS player_code,
        p.name AS player_name,
        p.country AS player_country,
        p.avatar AS player_avatar,
        s.solves7d,
        s.pieces7d,
        s.last_solved_at
    FROM community_player_stats s
    JOIN player p ON p.id = s.player_id
    WHERE s.solves7d > 0
        AND p.is_private = false
        {$inScope}
        {$notHidden}
    ORDER BY s.solves7d DESC, s.pieces7d DESC, s.last_solved_at DESC, p.id
    LIMIT :limit
)
SELECT
    r.player_id,
    r.player_code,
    r.player_name,
    r.player_country,
    r.player_avatar,
    r.solves7d,
    r.pieces7d,
    COALESCE(m.moments, '[]'::json) AS moments
FROM on_a_roll r
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object(
        'type', pm.type,
        'pieces_count', pm.pieces_count,
        'value', pm.value,
        'occurred_at', pm.occurred_at
    ) ORDER BY pm.occurred_at DESC) AS moments
    FROM player_moment pm
    WHERE pm.player_id = r.player_id
        AND pm.occurred_at >= :since
) m ON true
ORDER BY r.solves7d DESC, r.pieces7d DESC, r.last_solved_at DESC, r.player_id
SQL;

        $parameters = [
            'limit' => $limit,
            'since' => $this->clock->now()->modify(sprintf('-%d days', self::DAYS))->format('Y-m-d H:i:s'),
        ];

        if ($scope->country !== null) {
            $parameters['country'] = $scope->country->name;
        }

        $rows = $this->database
            ->executeQuery($query, $parameters, ['limit' => ParameterType::INTEGER])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerOnARoll {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             *     solves7d: int|string,
             *     pieces7d: int|string,
             *     moments: string,
             * } $row
             */

            return PlayerOnARoll::fromDatabaseRow($row);
        }, $rows);
    }
}
