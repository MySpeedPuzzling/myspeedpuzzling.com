<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use DateTimeImmutable;
use SpeedPuzzling\Web\Results\ProgressBoard;
use SpeedPuzzling\Web\Results\ProgressBoardRow;
use SpeedPuzzling\Web\Results\XpLeaderboardRow;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\Karma\KarmaDerivation;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\KarmaGroup;
use SpeedPuzzling\Web\Value\LeaderboardPosition;
use SpeedPuzzling\Web\Value\LeaderboardWindow;
use SpeedPuzzling\Web\Value\ProgressLeaderboardTab;

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
     * One board as one window statement (docs/features/xp-levels/ux-polish/design-spec.md §4): the top rows, the viewer's
     * own row with its neighbours and the rows "Show more" asked for (LeaderboardWindow::rowsToLoad()), plus the rows at
     * the milestone positions for the position line. Ranks are shared on equal values (RANK()) and the order is stable
     * (value, then id); both count within the filtered list - country, favorites, karma kind and period.
     *
     * Who is on which board is unchanged: XP and AP leave out private profiles and experience-system opt-outs, AP holds
     * members only; karma leaves out private profiles, admins and "Hide my karma". Players the viewer blocks are left out
     * of every board, so positions close up.
     *
     * @param null|list<string> $favoritePlayerIds the favorites board (the viewer counts as on it)
     */
    public function board(
        ProgressLeaderboardTab $tab,
        LeaderboardWindow $window,
        null|string $country,
        null|array $favoritePlayerIds,
        null|string $viewerPlayerId,
        null|KarmaGroup $karmaGroup = null,
        null|DateTimeImmutable $karmaSince = null,
    ): ProgressBoard {
        [$favoritesCondition, $params, $types] = $this->favoritesFilter($favoritePlayerIds);
        $prelude = '';
        $secondary = 'NULL::int';

        if ($tab === ProgressLeaderboardTab::Karma) {
            $hidden = $this->hiddenPlayers->sqlExclude('p.id');

            if ($karmaGroup === null && $karmaSince === null) {
                $prelude = 'WITH karma_board AS (SELECT p.id AS player_id, p.karma FROM player p WHERE p.karma > 0),';
            } else {
                $prelude = KarmaDerivation::itemsCte(scoped: false) . <<<SQL
,
karma_board AS (
    SELECT i.player_id, COUNT(*) AS karma
    FROM karma_items i
    WHERE i.counted
      AND (CAST(:karmaKinds AS TEXT) IS NULL OR i.kind = ANY(string_to_array(CAST(:karmaKinds AS TEXT), ',')))
      AND (CAST(:karmaSince AS TIMESTAMP) IS NULL OR i.at >= CAST(:karmaSince AS TIMESTAMP))
    GROUP BY i.player_id
),
SQL;
                $params['karmaKinds'] = $karmaGroup !== null ? implode(',', $karmaGroup->kindValues()) : null;
                $params['karmaSince'] = $karmaSince?->format('Y-m-d H:i:s');
                // A filtered count is ranked - the karma in total is the secondary line
                $secondary = 'p.karma';
            }

            $value = 'b.karma';
            $from = <<<SQL
FROM karma_board b
JOIN player p ON p.id = b.player_id
WHERE p.is_private = false
  AND p.is_admin = false
  -- "Hide my karma" (docs/features/karma.md) - every filter
  AND p.karma_opted_out = false
  {$hidden}
SQL;
        } else {
            $eligibility = $this->eligibility();
            $prelude = 'WITH';

            if ($tab === ProgressLeaderboardTab::AchievementPoints) {
                $membership = self::ACTIVE_MEMBERSHIP;
                $value = 'p.achievement_points';
                $from = <<<SQL
FROM player p
JOIN membership m ON m.player_id = p.id AND {$membership}
WHERE p.achievement_points > 0
  AND {$eligibility}
SQL;
            } else {
                $value = 'p.xp_total';
                $from = <<<SQL
FROM player p
WHERE p.xp_total > 0
  AND {$eligibility}
SQL;
            }
        }

        // Achievements held, read for the returned rows only (a sub-select of this statement, not a query of its own)
        $achievements = $tab === ProgressLeaderboardTab::AchievementPoints
            ? '(SELECT COUNT(DISTINCT held.type) FROM badge held WHERE held.player_id = board.player_id)'
            : 'board.secondary';
        $milestones = implode(', ', LeaderboardPosition::RANK_MILESTONES);
        $rows = $window->rowsToLoad();

        $sql = <<<SQL
{$prelude}
board AS (
    SELECT
        ROW_NUMBER() OVER (ORDER BY {$value} DESC, p.id ASC) AS position,
        RANK() OVER (ORDER BY {$value} DESC) AS rank,
        COUNT(*) OVER () AS total,
        p.id AS player_id,
        p.name AS player_name,
        p.code,
        p.country,
        p.avatar,
        {$value} AS value,
        p.level,
        {$secondary} AS secondary
    {$from}
      AND (CAST(:country AS TEXT) IS NULL OR p.country = :country)
      {$favoritesCondition}
),
me AS (
    SELECT position FROM board WHERE player_id = CAST(:viewerPlayerId AS UUID)
)
SELECT
    board.position,
    board.rank,
    board.total,
    me.position AS own_position,
    board.player_id,
    board.player_name,
    board.code,
    board.country,
    board.avatar,
    board.value,
    board.level,
    {$achievements} AS secondary
FROM board
LEFT JOIN me ON true
WHERE board.position <= :limit
   OR (me.position IS NOT NULL AND board.position BETWEEN me.position - :above AND me.position + :below)
   OR board.position IN ({$milestones})
ORDER BY board.position
SQL;

        /** @var list<array{position: int|string, rank: int|string, total: int|string, own_position: null|int|string, player_id: string, player_name: null|string, code: string, country: null|string, avatar: null|string, value: int|string, level: int|string, secondary: null|int|string}> $result */
        $result = $this->database->executeQuery($sql, [
            'country' => $country,
            'viewerPlayerId' => $viewerPlayerId,
            'limit' => $rows['limit'],
            'above' => $rows['above'],
            'below' => $rows['below'],
        ] + $params, $types)->fetchAllAssociative();

        $boardRows = [];
        $total = 0;
        $ownPosition = null;

        foreach ($result as $row) {
            $position = (int) $row['position'] - 1;
            $total = (int) $row['total'];
            $ownPosition = $row['own_position'] !== null ? (int) $row['own_position'] - 1 : null;

            $boardRows[$position] = new ProgressBoardRow(
                position: $position,
                rank: (int) $row['rank'],
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['code'],
                countryCode: CountryCode::fromCode($row['country']),
                avatar: $row['avatar'],
                value: (int) $row['value'],
                level: (int) $row['level'],
                secondaryCount: $row['secondary'] !== null ? (int) $row['secondary'] : null,
            );
        }

        return new ProgressBoard($boardRows, $total, $ownPosition);
    }

    /**
     * XP ladder — all-time, everyone, ranked purely by total XP. The level cap does not
     * end this board: XP keeps accruing past Level 50 and keeps being shown.
     * The top $limit rows of board() (shared ranks).
     *
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function xp(null|string $country, null|array $favoritePlayerIds, int $limit = 100): array
    {
        return $this->topRows(ProgressLeaderboardTab::Xp, $country, $favoritePlayerIds, $limit);
    }

    /**
     * Achievement Points ladder — members ranked by AP; viewable by every logged-in
     * user (this is the read-only ladder free players are pointed to). The top $limit rows of board() (shared ranks).
     *
     * @param list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    public function achievementPoints(null|string $country, null|array $favoritePlayerIds, int $limit = 100): array
    {
        return $this->topRows(ProgressLeaderboardTab::AchievementPoints, $country, $favoritePlayerIds, $limit);
    }

    /**
     * @param null|list<string> $favoritePlayerIds
     * @return list<XpLeaderboardRow>
     */
    private function topRows(ProgressLeaderboardTab $tab, null|string $country, null|array $favoritePlayerIds, int $limit): array
    {
        $board = $this->board($tab, new LeaderboardWindow(0, null, $limit), $country, $favoritePlayerIds, null);
        $rows = [];

        foreach ($board->rows as $position => $row) {
            if ($position >= $limit) {
                break;
            }

            $rows[] = new XpLeaderboardRow(
                rank: $row->rank,
                playerId: $row->playerId,
                playerName: $row->playerName,
                playerCode: $row->playerCode,
                countryCode: $row->countryCode,
                avatar: $row->avatar,
                value: $row->value,
                level: $row->level,
                achievementPoints: $tab === ProgressLeaderboardTab::AchievementPoints ? $row->value : null,
            );
        }

        return $rows;
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
}
