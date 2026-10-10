<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpLeaderboardRow;
use DateTimeImmutable;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\Karma\KarmaDerivation;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\KarmaGroup;

/**
 * Two ladders, two disciplines (§1.9): XP ranks activity and is open to everyone
 * forever, Achievement Points rank completion and are a membership perk. They are
 * deliberately NOT merged — hunting XP and hunting AP are different games, and a
 * player may chase either or both.
 *
 * Both read the denormalized player columns (`xp_total`, `achievement_points`), so
 * neither aggregates anything at scale. Private profiles and experience-system
 * opt-outs never appear on either - global rankings stay closed to private players
 * for everybody, the allow list included (docs/features/private-profile-allow-list.md).
 * Players the viewer blocks are left out of the ranking itself, so positions close up
 * (docs/features/player-blocklist.md).
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
        private HiddenPlayers $hiddenPlayers,
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
        $eligibility = $this->eligibility();

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
        $eligibility = $this->eligibility();
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
     * Karma list (docs/features/karma.md) - who helps the database most. Everybody, free; private profiles never (global
     * rankings stay closed to them), admins never (they have no karma), players the viewer blocks are left out so
     * positions close up. The default board (every kind, all time) reads the stored player.karma; a kind or the last
     * 12 months come from the derivation itself (one aggregate statement).
     *
     * The viewer's own row comes along even when it is outside the top $limit (rank > $limit) - the pinned self-row,
     * within the same filters.
     *
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function karma(
        null|KarmaGroup $group,
        null|DateTimeImmutable $since,
        null|string $country,
        null|array $favoritePlayerIds,
        null|string $viewerPlayerId,
        int $limit = 100,
    ): array {
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);
        $hidden = $this->hiddenPlayers->sqlExclude('p.id');

        if ($group === null && $since === null) {
            $board = <<<SQL
WITH karma_board AS (
    SELECT p.id AS player_id, p.karma
    FROM player p
    WHERE p.karma > 0
)
SQL;
        } else {
            $items = KarmaDerivation::itemsCte(scoped: false);
            $board = <<<SQL
{$items},
karma_board AS (
    SELECT i.player_id, COUNT(*) AS karma
    FROM karma_items i
    WHERE i.counted
      AND (CAST(:karmaKinds AS TEXT) IS NULL OR i.kind = ANY(string_to_array(CAST(:karmaKinds AS TEXT), ',')))
      AND (CAST(:karmaSince AS TIMESTAMP) IS NULL OR i.at >= CAST(:karmaSince AS TIMESTAMP))
    GROUP BY i.player_id
)
SQL;
            $params['karmaKinds'] = $group !== null ? implode(',', $group->kindValues()) : null;
            $params['karmaSince'] = $since?->format('Y-m-d H:i:s');
        }

        $sql = <<<SQL
{$board}
SELECT * FROM (
    SELECT
        ROW_NUMBER() OVER (ORDER BY b.karma DESC, p.id ASC) AS rank,
        p.id AS player_id,
        p.name AS player_name,
        p.code,
        p.country,
        p.avatar,
        b.karma AS value,
        p.level,
        NULL::int AS achievement_points
    FROM karma_board b
    JOIN player p ON p.id = b.player_id
    WHERE p.is_private = false
      AND p.is_admin = false
      -- "Hide my karma" (docs/features/karma.md) - every filter
      AND p.karma_opted_out = false
      {$hidden}
      AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
      {$favoritesCondition}
) board
WHERE board.rank <= :limit OR board.player_id = CAST(:viewerPlayerId AS UUID)
ORDER BY board.rank
SQL;

        return $this->hydrate($sql, [
            'country' => $country,
            'limit' => $limit,
            'viewerPlayerId' => $viewerPlayerId,
        ] + $params, $types);
    }

    /**
     * The viewer's own standing for the pinned self-row, on the same board the list shows - the country and
     * favorites filters included, ties broken like the list (value, then id) - or null when the viewer is not on
     * that board (no XP/AP, private, opted out, another country, AP without membership).
     *
     * @param null|list<string> $favoritePlayerIds the favorites board (the viewer counts as on it)
     * @return array{rank: int, value: int, level: int}|null
     */
    public function selfRank(string $playerId, string $tab, null|string $country = null, null|array $favoritePlayerIds = null): null|array
    {
        $eligibility = $this->eligibility();
        $mineEligibility = str_replace('p.', 'mine.', self::PUBLIC_ELIGIBILITY);
        $membership = self::ACTIVE_MEMBERSHIP;
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);

        $column = $tab === 'achievement-points' ? 'achievement_points' : 'xp_total';
        $membersOnly = $tab === 'achievement-points'
            ? "JOIN membership m ON m.player_id = p.id AND {$membership}"
            : '';
        $mineMembersOnly = $tab === 'achievement-points'
            ? 'JOIN membership mm ON mm.player_id = mine.id AND ' . str_replace('m.', 'mm.', $membership)
            : '';

        $sql = <<<SQL
SELECT mine.{$column} AS value,
       mine.level,
       1 + (
           SELECT COUNT(*) FROM player p
           {$membersOnly}
           WHERE (p.{$column} > mine.{$column} OR (p.{$column} = mine.{$column} AND p.id < mine.id))
             AND {$eligibility}
             AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
             {$favoritesCondition}
       ) AS rank
FROM player mine
{$mineMembersOnly}
WHERE mine.id = :playerId
  AND mine.{$column} > 0
  AND {$mineEligibility}
  AND (CAST(:country AS TEXT) IS NULL OR mine.country = :country)
SQL;

        /** @var array{value: int|string, level: int|string, rank: int|string}|false $row */
        $row = $this->database->executeQuery($sql, ['playerId' => $playerId, 'country' => $country] + $params, $types)->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'rank' => is_numeric($row['rank']) ? (int) $row['rank'] : 0,
            'value' => is_numeric($row['value']) ? (int) $row['value'] : 0,
            'level' => is_numeric($row['level']) ? (int) $row['level'] : 1,
        ];
    }

    /**
     * @return list<string>
     */
    public function countries(): array
    {
        $eligibility = $this->eligibility();

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

    private function eligibility(): string
    {
        return self::PUBLIC_ELIGIBILITY . $this->hiddenPlayers->sqlExclude('p.id');
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
