<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpLeaderboardRow;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * Two ladders, two disciplines (§1.9): XP ranks activity and is open to everyone
 * forever, Achievement Points rank completion and are a membership perk. They are
 * deliberately NOT merged — hunting XP and hunting AP are different games, and a
 * player may chase either or both.
 *
 * Both read the denormalized player columns (`xp_total`, `achievement_points`), so
 * neither aggregates anything at scale. Private profiles and experience-system
 * opt-outs never appear on either.
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
    ) {
    }

    /**
     * XP ladder — all-time, everyone, ranked purely by total XP. The level cap does not
     * end this board: XP keeps accruing past Level 50 and keeps being shown.
     *
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function xp(null|string $country, null|array $favoritePlayerIds, int $limit = 100): array
    {
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);
        $eligibility = self::PUBLIC_ELIGIBILITY;

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
    NULL::int AS achievement_points
FROM player p
WHERE p.xp_total > 0
  AND {$eligibility}
  AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
  {$favoritesCondition}
ORDER BY p.xp_total DESC, p.id ASC
LIMIT :limit
SQL;

        return $this->hydrate($sql, ['country' => $country, 'limit' => $limit] + $params, $types);
    }

    /**
     * Achievement Points ladder — members ranked by AP; viewable by every logged-in
     * user (this is the read-only ladder free players are pointed to).
     *
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function achievementPoints(null|string $country, null|array $favoritePlayerIds, int $limit = 100): array
    {
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);
        $eligibility = self::PUBLIC_ELIGIBILITY;
        $membership = self::ACTIVE_MEMBERSHIP;

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
JOIN membership m ON m.player_id = p.id AND {$membership}
WHERE p.achievement_points > 0
  AND {$eligibility}
  AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
  {$favoritesCondition}
ORDER BY p.achievement_points DESC, p.id ASC
LIMIT :limit
SQL;

        return $this->hydrate($sql, ['country' => $country, 'limit' => $limit] + $params, $types);
    }

    /**
     * The viewer's own standing for the pinned self-row: [rank, value] within the
     * unfiltered public board of that discipline, or null when they are not on it.
     *
     * @return array{rank: int, value: int}|null
     */
    public function selfRank(string $playerId, string $tab): null|array
    {
        $eligibility = self::PUBLIC_ELIGIBILITY;
        $membership = self::ACTIVE_MEMBERSHIP;

        $sql = match ($tab) {
            'achievement-points' => <<<SQL
SELECT mine.achievement_points AS value,
       1 + (
           SELECT COUNT(*) FROM player p
           JOIN membership m ON m.player_id = p.id AND {$membership}
           WHERE p.achievement_points > mine.achievement_points AND {$eligibility}
       ) AS rank
FROM player mine
WHERE mine.id = :playerId AND mine.achievement_points > 0
SQL,
            default => <<<SQL
SELECT mine.xp_total AS value,
       1 + (
           SELECT COUNT(*) FROM player p
           WHERE p.xp_total > mine.xp_total AND {$eligibility}
       ) AS rank
FROM player mine
WHERE mine.id = :playerId AND mine.xp_total > 0
SQL,
        };

        /** @var array{value: int|string, rank: int|string}|false $row */
        $row = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAssociative();

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
        $eligibility = self::PUBLIC_ELIGIBILITY;

        $sql = <<<SQL
SELECT DISTINCT p.country
FROM player p
WHERE p.xp_total > 0
  AND p.country IS NOT NULL
  AND {$eligibility}
ORDER BY p.country
SQL;

        /** @var list<string> $countries */
        $countries = $this->database->executeQuery($sql)->fetchFirstColumn();

        return $countries;
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
}
