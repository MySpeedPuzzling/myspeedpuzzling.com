<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\XpLeaderboardRow;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * XP leaderboards (§1.9): weekly delta straight from the ledger (settlements and
 * backfilled achievements excluded via in_weekly_delta), all-time from the
 * denormalized player totals, and the members-only Achievement Points ladder.
 * Private profiles and experience-system-opted-out players never appear.
 */
readonly class GetXpLeaderboard
{
    private const string PUBLIC_ELIGIBILITY = <<<SQL
  p.is_private = false
  AND p.experience_system_opted_out = false
SQL;

    private const string ACTIVE_MEMBERSHIP = <<<SQL
  (
    (m.ends_at IS NULL AND m.billing_period_ends_at IS NOT NULL)
    OR GREATEST(
        COALESCE(m.ends_at, m.billing_period_ends_at, '1970-01-01'::timestamp),
        COALESCE(m.granted_until, '1970-01-01'::timestamp)
    ) > NOW()
  )
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function allTime(null|string $country, null|array $favoritePlayerIds, int $limit = 100): array
    {
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);

        $sql = <<<SQL
SELECT
    ROW_NUMBER() OVER (ORDER BY p.xp_total DESC, p.id ASC) AS rank,
    p.id AS player_id,
    p.name AS player_name,
    p.code,
    p.country,
    p.avatar,
    p.xp_total AS value,
    p.level,
    CASE WHEN p.level >= 50 AND EXISTS (
        SELECT 1 FROM membership m WHERE m.player_id = p.id AND {$this->activeMembership()}
    ) THEN p.achievement_points END AS achievement_points
FROM player p
WHERE p.xp_total > 0
  AND {$this->publicEligibility()}
  AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
  {$favoritesCondition}
ORDER BY p.xp_total DESC, p.id ASC
LIMIT :limit
SQL;

        return $this->hydrate($sql, ['country' => $country, 'limit' => $limit] + $params, $types);
    }

    /**
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function thisWeek(null|string $country, null|array $favoritePlayerIds, int $limit = 100): array
    {
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);
        [$weekStart, $weekEnd] = $this->currentWeekWindow();

        $sql = <<<SQL
SELECT
    ROW_NUMBER() OVER (ORDER BY delta.value DESC, p.id ASC) AS rank,
    p.id AS player_id,
    p.name AS player_name,
    p.code,
    p.country,
    p.avatar,
    delta.value,
    p.level,
    CASE WHEN p.level >= 50 AND EXISTS (
        SELECT 1 FROM membership m WHERE m.player_id = p.id AND {$this->activeMembership()}
    ) THEN p.achievement_points END AS achievement_points
FROM (
    SELECT e.player_id, SUM(e.amount) AS value
    FROM xp_entry e
    WHERE e.in_weekly_delta = true
      AND e.earned_at >= CAST(:weekStart AS TIMESTAMP)
      AND e.earned_at < CAST(:weekEnd AS TIMESTAMP)
    GROUP BY e.player_id
    HAVING SUM(e.amount) > 0
) delta
JOIN player p ON p.id = delta.player_id
WHERE {$this->publicEligibility()}
  AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
  {$favoritesCondition}
ORDER BY delta.value DESC, p.id ASC
LIMIT :limit
SQL;

        return $this->hydrate($sql, [
            'country' => $country,
            'limit' => $limit,
            'weekStart' => $weekStart->format('Y-m-d H:i:s'),
            'weekEnd' => $weekEnd->format('Y-m-d H:i:s'),
        ] + $params, $types);
    }

    /**
     * Achievement Points ladder — members ranked by AP; viewable by all logged-in
     * users (this is the read-only ladder free level-50 players are pointed to).
     *
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function achievementPoints(null|string $country, null|array $favoritePlayerIds, int $limit = 100): array
    {
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);

        $sql = <<<SQL
SELECT
    ROW_NUMBER() OVER (ORDER BY p.achievement_points DESC, p.id ASC) AS rank,
    p.id AS player_id,
    p.name AS player_name,
    p.code,
    p.country,
    p.avatar,
    p.achievement_points AS value,
    p.level,
    p.achievement_points
FROM player p
JOIN membership m ON m.player_id = p.id AND {$this->activeMembership()}
WHERE p.achievement_points > 0
  AND {$this->publicEligibility()}
  AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
  {$favoritesCondition}
ORDER BY p.achievement_points DESC, p.id ASC
LIMIT :limit
SQL;

        return $this->hydrate($sql, ['country' => $country, 'limit' => $limit] + $params, $types);
    }

    /**
     * The viewer's own standing for the pinned self-row: [rank, value] within the
     * unfiltered public set, or null when they have nothing on that board.
     *
     * @return array{rank: int, value: int}|null
     */
    public function selfRank(string $playerId, string $tab): null|array
    {
        [$weekStart, $weekEnd] = $this->currentWeekWindow();

        $sql = match ($tab) {
            'this-week' => <<<SQL
WITH deltas AS (
    SELECT e.player_id, SUM(e.amount) AS value
    FROM xp_entry e
    WHERE e.in_weekly_delta = true
      AND e.earned_at >= CAST(:weekStart AS TIMESTAMP)
      AND e.earned_at < CAST(:weekEnd AS TIMESTAMP)
    GROUP BY e.player_id
    HAVING SUM(e.amount) > 0
)
SELECT mine.value,
       1 + (
           SELECT COUNT(*) FROM deltas d
           JOIN player p ON p.id = d.player_id
           WHERE d.value > mine.value AND {$this->publicEligibility()}
       ) AS rank
FROM deltas mine
WHERE mine.player_id = :playerId
SQL,
            'achievement-points' => <<<SQL
SELECT mine.achievement_points AS value,
       1 + (
           SELECT COUNT(*) FROM player p
           JOIN membership m ON m.player_id = p.id AND {$this->activeMembership()}
           WHERE p.achievement_points > mine.achievement_points AND {$this->publicEligibility()}
       ) AS rank
FROM player mine
WHERE mine.id = :playerId AND mine.achievement_points > 0
SQL,
            default => <<<SQL
SELECT mine.xp_total AS value,
       1 + (
           SELECT COUNT(*) FROM player p
           WHERE p.xp_total > mine.xp_total AND {$this->publicEligibility()}
       ) AS rank
FROM player mine
WHERE mine.id = :playerId AND mine.xp_total > 0
SQL,
        };

        $params = ['playerId' => $playerId];

        if ($tab === 'this-week') {
            $params['weekStart'] = $weekStart->format('Y-m-d H:i:s');
            $params['weekEnd'] = $weekEnd->format('Y-m-d H:i:s');
        }

        /** @var array{value: int|string, rank: int|string}|false $row */
        $row = $this->database->executeQuery($sql, $params)->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'rank' => is_numeric($row['rank']) ? (int) $row['rank'] : 0,
            'value' => is_numeric($row['value']) ? (int) $row['value'] : 0,
        ];
    }

    /**
     * @return list<string>
     */
    public function countries(): array
    {
        $sql = <<<SQL
SELECT DISTINCT p.country
FROM player p
WHERE p.xp_total > 0
  AND p.country IS NOT NULL
  AND {$this->publicEligibility()}
ORDER BY p.country
SQL;

        /** @var list<string> $countries */
        $countries = $this->database->executeQuery($sql)->fetchFirstColumn();

        return $countries;
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function currentWeekWindow(): array
    {
        $now = $this->clock->now();
        $weekStart = $now
            ->setISODate((int) $now->format('o'), (int) $now->format('W'))
            ->setTime(0, 0);

        return [$weekStart, $weekStart->modify('+7 days')];
    }

    /**
     * @param null|list<string> $favoritePlayerIds
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function favoritesFilter(null|array $favoritePlayerIds): array
    {
        if ($favoritePlayerIds === null) {
            return ['', [], []];
        }

        if ($favoritePlayerIds === []) {
            // Favorites filter active with no favorites — force an empty board.
            return ['AND FALSE', [], []];
        }

        return [
            'AND p.id IN (:favoriteIds)',
            ['favoriteIds' => $favoritePlayerIds],
            ['favoriteIds' => ArrayParameterType::STRING],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     * @return list<XpLeaderboardRow>
     */
    private function hydrate(string $sql, array $params, array $types): array
    {
        /** @var list<array{rank: int|string, player_id: string, player_name: null|string, code: string, country: null|string, avatar: null|string, value: int|string, level: int, achievement_points: null|int|string}> $rows */
        $rows = $this->database->executeQuery($sql, $params, $types)->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $result[] = new XpLeaderboardRow(
                rank: is_numeric($row['rank']) ? (int) $row['rank'] : 0,
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['code'],
                countryCode: CountryCode::fromCode($row['country']),
                avatar: $row['avatar'],
                value: is_numeric($row['value']) ? (int) $row['value'] : 0,
                level: $row['level'],
                achievementPoints: is_numeric($row['achievement_points']) ? (int) $row['achievement_points'] : null,
            );
        }

        return $result;
    }

    private function publicEligibility(): string
    {
        return self::PUBLIC_ELIGIBILITY;
    }

    private function activeMembership(): string
    {
        return self::ACTIVE_MEMBERSHIP;
    }
}
