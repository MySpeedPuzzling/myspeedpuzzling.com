<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Achievement Points (AP) — read from the denormalized player.achievement_points
 * column, which BadgeEvaluator maintains (and self-heals) as the absolute
 * SUM of BadgeTier::points() over every earned badge row (§1.6 locked values).
 */
readonly class GetAchievementPoints
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPlayer(string $playerId): int
    {
        $value = $this->database
            ->executeQuery('SELECT achievement_points FROM player WHERE id = :playerId', ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
