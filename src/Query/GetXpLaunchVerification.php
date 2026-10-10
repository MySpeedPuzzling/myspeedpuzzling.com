<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpLaunchVerification;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\LevelTable;

/**
 * Is the XP history complete enough to show it to everybody (docs/features/xp-levels/launch-runbook.md)? Every player
 * with a counted solving time has ledger entries, every denormalized total matches what it sums up - XP (and the
 * level for it) and Achievement Points. A launch-time check over the whole table, not for page views.
 */
readonly final class GetXpLaunchVerification
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function check(): XpLaunchVerification
    {
        $withSolves = <<<SQL
EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.player_id = p.id AND pst.suspicious = false)
SQL;

        $playersWithSolves = $this->count("SELECT COUNT(*) FROM player p WHERE {$withSolves}");

        $withoutXp = $this->count(<<<SQL
SELECT COUNT(*)
FROM player p
WHERE {$withSolves}
  AND NOT EXISTS (SELECT 1 FROM xp_entry e WHERE e.player_id = p.id)
SQL);

        $xpTotals = $this->count(<<<SQL
SELECT COUNT(*)
FROM player p
LEFT JOIN (SELECT player_id, SUM(amount) AS total FROM xp_entry GROUP BY player_id) ledger ON ledger.player_id = p.id
WHERE p.xp_total <> COALESCE(ledger.total, 0)
SQL);

        $pointsCase = 'CASE b.tier';
        foreach (BadgeTier::cases() as $tier) {
            $pointsCase .= sprintf(' WHEN %d THEN %d', $tier->value, $tier->points());
        }
        $pointsCase .= sprintf(' ELSE %d END', BadgeTier::SINGLE_TIER_POINTS);

        $achievementPoints = $this->count(<<<SQL
SELECT COUNT(*)
FROM player p
LEFT JOIN (SELECT b.player_id, SUM({$pointsCase}) AS points FROM badge b GROUP BY b.player_id) earned ON earned.player_id = p.id
WHERE p.achievement_points <> COALESCE(earned.points, 0)
SQL);

        /** @var list<array{xp_total: int|string, level: int|string, players: int|string}> $levels */
        $levels = $this->database
            ->executeQuery('SELECT xp_total, level, COUNT(*) AS players FROM player GROUP BY xp_total, level')
            ->fetchAllAssociative();

        $levelMismatches = 0;

        foreach ($levels as $row) {
            if (LevelTable::levelForXp((int) $row['xp_total']) !== (int) $row['level']) {
                $levelMismatches += (int) $row['players'];
            }
        }

        return new XpLaunchVerification(
            playersWithSolvesWithoutXp: $withoutXp,
            xpTotalsNotMatchingLedger: $xpTotals,
            levelsNotMatchingXp: $levelMismatches,
            achievementPointsNotMatchingAchievements: $achievementPoints,
            playersWithSolves: $playersWithSolves,
        );
    }

    private function count(string $sql): int
    {
        $value = $this->database->executeQuery($sql)->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
