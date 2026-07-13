<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Digest;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\BadgeConditions\BadgeConditionInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Results\WeeklyDigestData;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Gathers the weekly digest content blocks for one player (content-digest README §9,
 * blocks 1–5 + most-solved; the rating-movement block is deferred with the daily digest).
 */
readonly class WeeklyDigestDataProvider
{
    /**
     * @param iterable<BadgeConditionInterface> $conditions
     */
    public function __construct(
        private Connection $database,
        #[AutowireIterator('badge.condition')]
        private iterable $conditions,
        private GetBadges $getBadges,
        private GetPlayerStatsSnapshot $getPlayerStatsSnapshot,
        private ActivityCalendarStreakCalculator $streakCalculator,
    ) {
    }

    public function forPlayer(Player $player, DigestPeriod $period): WeeklyDigestData
    {
        $playerId = $player->id->toString();

        [$xpGained, $levelsGained] = $this->xpBlock($player, $period);
        $achievements = $this->achievementsEarned($playerId, $period);
        [$solves, $pieces, $minutes] = $this->weekInNumbers($playerId, $period->weekStart->format('Y-m-d H:i:s'), $period->weekEnd->format('Y-m-d H:i:s'));
        [$previousSolves, $previousPieces] = $this->weekInNumbers(
            $playerId,
            $period->weekStart->modify('-7 days')->format('Y-m-d H:i:s'),
            $period->weekStart->format('Y-m-d H:i:s'),
        );
        [$mostSolvedName, $mostSolvedSolvers] = $this->mostSolvedOfWeek($period);

        return new WeeklyDigestData(
            xpGained: $xpGained,
            levelsGained: $levelsGained,
            currentLevel: $player->level,
            achievementsEarned: $achievements,
            solvesCount: $solves,
            piecesCount: $pieces,
            minutesSpent: $minutes,
            previousSolvesCount: $previousSolves,
            previousPiecesCount: $previousPieces,
            currentStreakDays: $player->streakOptedOut ? 0 : $this->currentStreak($playerId),
            favoritesActivity: $this->favoritesActivity($player, $period),
            nextAchievements: $this->nextAchievements($playerId),
            mostSolvedPuzzleName: $mostSolvedName,
            mostSolvedPuzzleSolvers: $mostSolvedSolvers,
        );
    }

    /**
     * @return array{int, int}
     */
    private function xpBlock(Player $player, DigestPeriod $period): array
    {
        $sql = <<<SQL
SELECT COALESCE(SUM(amount), 0)
FROM xp_entry
WHERE player_id = :playerId
  AND in_weekly_delta = true
  AND earned_at >= CAST(:weekStart AS TIMESTAMP)
  AND earned_at < CAST(:weekEnd AS TIMESTAMP)
SQL;

        $value = $this->database->executeQuery($sql, [
            'playerId' => $player->id->toString(),
            'weekStart' => $period->weekStart->format('Y-m-d H:i:s'),
            'weekEnd' => $period->weekEnd->format('Y-m-d H:i:s'),
        ])->fetchOne();

        $xpGained = is_numeric($value) ? (int) $value : 0;
        $levelsGained = max(0, LevelTable::levelForXp($player->xpTotal) - LevelTable::levelForXp($player->xpTotal - $xpGained));

        return [$xpGained, $levelsGained];
    }

    /**
     * @return list<array{type: BadgeType, tier: null|BadgeTier}>
     */
    private function achievementsEarned(string $playerId, DigestPeriod $period): array
    {
        $earned = [];

        foreach ($this->getBadges->allEarnedTiers($playerId) as $badge) {
            if ($badge->earnedAt >= $period->weekStart && $badge->earnedAt < $period->weekEnd) {
                $earned[] = ['type' => $badge->type, 'tier' => $badge->tier];
            }
        }

        return $earned;
    }

    /**
     * @return array{int, int, int}
     */
    private function weekInNumbers(string $playerId, string $windowStart, string $windowEnd): array
    {
        $sql = <<<SQL
SELECT
    COUNT(*) AS solves,
    COALESCE(SUM(COALESCE(pst.pieces_count_snapshot, p.pieces_count)), 0) AS pieces,
    COALESCE(SUM(pst.seconds_to_solve), 0) / 60 AS minutes
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.player_id = :playerId
  AND pst.suspicious = false
  AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:windowStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) < CAST(:windowEnd AS TIMESTAMP)
SQL;

        /** @var array{solves: int|string, pieces: int|string, minutes: int|string}|false $row */
        $row = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'windowStart' => $windowStart,
            'windowEnd' => $windowEnd,
        ])->fetchAssociative();

        if ($row === false) {
            return [0, 0, 0];
        }

        return [
            is_numeric($row['solves']) ? (int) $row['solves'] : 0,
            is_numeric($row['pieces']) ? (int) $row['pieces'] : 0,
            is_numeric($row['minutes']) ? (int) $row['minutes'] : 0,
        ];
    }

    private function currentStreak(string $playerId): int
    {
        $sql = <<<SQL
SELECT DISTINCT TO_CHAR(COALESCE(finished_at, tracked_at), 'YYYY-MM-DD') AS solve_day
FROM puzzle_solving_time
WHERE suspicious = false
  AND COALESCE(finished_at, tracked_at) >= '2000-01-01'
  AND (
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY solve_day
SQL;

        /** @var list<array{solve_day: string}> $rows */
        $rows = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative();

        return $this->streakCalculator->calculate(array_column($rows, 'solve_day'))->current;
    }

    /**
     * @return list<array{name: string, solves: int}>
     */
    private function favoritesActivity(Player $player, DigestPeriod $period): array
    {
        $favoriteIds = array_values($player->favoritePlayerIds());

        if ($favoriteIds === []) {
            return [];
        }

        $sql = <<<SQL
SELECT COALESCE(p.name, '#' || UPPER(p.code)) AS name, COUNT(*) AS solves
FROM puzzle_solving_time pst
JOIN player p ON p.id = pst.player_id
WHERE pst.player_id IN (:favoriteIds)
  AND pst.suspicious = false
  AND p.is_private = false
  AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:weekStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) < CAST(:weekEnd AS TIMESTAMP)
GROUP BY p.id, p.name, p.code
ORDER BY solves DESC
LIMIT 5
SQL;

        /** @var list<array{name: string, solves: int|string}> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            [
                'favoriteIds' => $favoriteIds,
                'weekStart' => $period->weekStart->format('Y-m-d H:i:s'),
                'weekEnd' => $period->weekEnd->format('Y-m-d H:i:s'),
            ],
            ['favoriteIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => ['name' => $row['name'], 'solves' => is_numeric($row['solves']) ? (int) $row['solves'] : 0],
            $rows,
        );
    }

    /**
     * Top progress toward the next achievement tiers — members-only block.
     *
     * @return list<array{type: BadgeType, progress: \SpeedPuzzling\Web\Results\BadgeProgress}>
     */
    private function nextAchievements(string $playerId): array
    {
        $snapshot = $this->getPlayerStatsSnapshot->forPlayer($playerId);

        $highestEarned = [];
        foreach ($this->getBadges->forPlayer($playerId) as $badge) {
            if ($badge->tier !== null) {
                $highestEarned[$badge->type->value] = $badge->tier;
            }
        }

        $candidates = [];

        foreach ($this->conditions as $condition) {
            $type = $condition->badgeType();
            $earned = $highestEarned[$type->value] ?? null;

            if ($earned === BadgeTier::Diamond) {
                continue;
            }

            $progress = $condition->progressToNextTier($snapshot, $earned);

            if ($progress === null || $progress->percent <= 0) {
                continue;
            }

            $candidates[] = ['type' => $type, 'progress' => $progress];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['progress']->percent <=> $a['progress']->percent);

        return array_slice($candidates, 0, 2);
    }

    /**
     * @return array{null|string, int}
     */
    private function mostSolvedOfWeek(DigestPeriod $period): array
    {
        $sql = <<<SQL
SELECT p.name, COUNT(DISTINCT pst.player_id) AS solvers
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.suspicious = false
  AND COALESCE(pst.finished_at, pst.tracked_at) >= CAST(:weekStart AS TIMESTAMP)
  AND COALESCE(pst.finished_at, pst.tracked_at) < CAST(:weekEnd AS TIMESTAMP)
GROUP BY p.id, p.name
HAVING COUNT(DISTINCT pst.player_id) >= 3
ORDER BY solvers DESC, p.name ASC
LIMIT 1
SQL;

        /** @var array{name: string, solvers: int|string}|false $row */
        $row = $this->database->executeQuery($sql, [
            'weekStart' => $period->weekStart->format('Y-m-d H:i:s'),
            'weekEnd' => $period->weekEnd->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        if ($row === false) {
            return [null, 0];
        }

        return [$row['name'], is_numeric($row['solvers']) ? (int) $row['solvers'] : 0];
    }
}
