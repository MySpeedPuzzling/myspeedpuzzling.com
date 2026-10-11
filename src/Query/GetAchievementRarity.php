<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * How rare each achievement tier is: the share of puzzlers holding it, for the catalogue and the info modal
 * (docs/features/xp-levels/launch-2026-10.md, "Achievements catalogue"). One aggregate statement for every
 * type and tier; read through AchievementRarityProvider, which caches it.
 *
 * Puzzlers = players with any XP, i.e. with at least one logged puzzle (XP is fed by logged puzzles only).
 * Holder counts include everybody, like the holders page's counts.
 */
readonly class GetAchievementRarity
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Single-tier achievements (Early Adopter) are counted under tier 0.
     *
     * @return array{puzzlers: int, holders: array<string, array<int, int>>}
     */
    public function counts(): array
    {
        $sql = <<<SQL
SELECT
    b.type,
    COALESCE(b.tier, 0) AS tier,
    COUNT(*) AS holders,
    (SELECT COUNT(*) FROM player WHERE xp_total > 0) AS puzzlers
FROM badge b
GROUP BY b.type, COALESCE(b.tier, 0)
SQL;

        /** @var list<array{type: string, tier: int|string, holders: int|string, puzzlers: int|string}> $rows */
        $rows = $this->database->executeQuery($sql)->fetchAllAssociative();

        $holders = [];
        $puzzlers = 0;

        foreach ($rows as $row) {
            $holders[$row['type']][(int) $row['tier']] = (int) $row['holders'];
            $puzzlers = (int) $row['puzzlers'];
        }

        return ['puzzlers' => $puzzlers, 'holders' => $holders];
    }
}
