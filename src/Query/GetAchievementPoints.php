<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Achievement Points (AP) — sum of tier values over every earned badge row.
 * Values are locked (§1.6) and mirrored by BadgeTier::points() /
 * BadgeTier::SINGLE_TIER_POINTS; a unit test guards the two sources against drift.
 */
readonly class GetAchievementPoints
{
    private const string POINTS_CASE_SQL = <<<SQL
CASE tier
    WHEN 1 THEN 5
    WHEN 2 THEN 10
    WHEN 3 THEN 25
    WHEN 4 THEN 50
    WHEN 5 THEN 100
    ELSE 25
END
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPlayer(string $playerId): int
    {
        $sql = sprintf(
            'SELECT COALESCE(SUM(%s), 0) FROM badge WHERE player_id = :playerId',
            self::POINTS_CASE_SQL,
        );

        $value = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
