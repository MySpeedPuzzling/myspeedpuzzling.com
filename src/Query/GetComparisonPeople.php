<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ComparisonPeople;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\SkillTier;

/**
 * The people the Solo add sheet of the compare page offers (docs/features/player-comparison.md "Add sheet"), fetched by
 * comparison_add_controller.js only once the sheet opens.
 *
 * @phpstan-type PersonRow array{
 *     player_id: string,
 *     player_code: string,
 *     player_name: null|string,
 *     player_country: null|string,
 *     player_avatar: null|string,
 *     is_favorite: bool,
 *     times_count: int,
 *     last_together_at: null|string,
 * }
 */
readonly final class GetComparisonPeople
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * Every favorite of the viewer (by name, like the favorite puzzlers page) and the registered players they share
     * the most pair/team results with who are not favorites. One statement, whatever the number of favorites.
     *
     * Only who an add would accept is listed - nobody the viewer blocked, no private profile hidden from them (an
     * allow list reveals it), never the viewer, never a guest. Like GetCoPuzzlers, a pair/team with a blocked member and
     * one the viewer archived count for nobody.
     */
    public function forViewer(string $viewerId, int $coPuzzlersLimit): ComparisonPeople
    {
        if (Uuid::isValid($viewerId) === false) {
            return new ComparisonPeople([], []);
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('player.id');
        $isPublic = $this->privateProfileAccess->sqlIsPublic('player');
        $memberNotHidden = $this->hiddenPlayers->sqlExclude('hidden_member.player_id');
        $noHiddenMember = $memberNotHidden === ''
            ? ''
            : " AND NOT EXISTS (SELECT 1 FROM puzzling_team_member hidden_member WHERE hidden_member.team_id = me.team_id AND NOT (TRUE{$memberNotHidden}))";

        $query = <<<SQL
WITH favorite AS (
    SELECT DISTINCT CAST(favorite_id AS UUID) AS player_id
    FROM player viewer
    CROSS JOIN LATERAL json_array_elements_text(viewer.favorite_players::json) AS favorite_id
    WHERE viewer.id = :viewerId
),
co_puzzler AS (
    SELECT
        member.player_id,
        COUNT(*) AS times_count,
        MAX(COALESCE(pst.finished_at, pst.tracked_at)) AS last_together_at
    FROM puzzling_team_member me
    INNER JOIN puzzling_team_member member ON member.team_id = me.team_id
        AND member.player_id IS NOT NULL
        AND member.player_id <> me.player_id
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = me.team_id
    WHERE me.player_id = :viewerId
        AND NOT EXISTS (
            SELECT 1 FROM puzzling_team_archive archive WHERE archive.team_id = me.team_id AND archive.player_id = me.player_id
        ){$noHiddenMember}
    GROUP BY member.player_id
)
SELECT
    player.id AS player_id,
    player.code AS player_code,
    player.name AS player_name,
    player.country AS player_country,
    player.avatar AS player_avatar,
    (favorite.player_id IS NOT NULL) AS is_favorite,
    COALESCE(co_puzzler.times_count, 0) AS times_count,
    co_puzzler.last_together_at
FROM (SELECT player_id FROM favorite UNION SELECT player_id FROM co_puzzler) AS candidate
INNER JOIN player ON player.id = candidate.player_id
LEFT JOIN favorite ON favorite.player_id = player.id
LEFT JOIN co_puzzler ON co_puzzler.player_id = player.id
WHERE player.id <> :viewerId
    AND {$isPublic}{$notHidden}
ORDER BY LOWER(player.name) NULLS LAST, player.code
SQL;

        /** @var list<PersonRow> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['viewerId' => $viewerId]);

        $favorites = [];
        $coPuzzlers = [];

        foreach ($rows as $row) {
            if ($row['is_favorite']) {
                $favorites[] = self::identification($row);
            } else {
                $coPuzzlers[] = $row;
            }
        }

        // Most results together first, the latest of them breaks a tie - the name order above breaks the rest
        usort($coPuzzlers, static fn (array $a, array $b): int => [$b['times_count'], $b['last_together_at']] <=> [$a['times_count'], $a['last_together_at']]);

        return new ComparisonPeople(
            favorites: $favorites,
            coPuzzlers: array_map(self::identification(...), array_slice($coPuzzlers, 0, max(0, $coPuzzlersLimit))),
        );
    }

    /**
     * @param PersonRow $row
     */
    private static function identification(array $row): PlayerIdentification
    {
        return new PlayerIdentification(
            playerId: $row['player_id'],
            playerCode: $row['player_code'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            playerAvatar: $row['player_avatar'],
        );
    }

    /**
     * The skill tier icon a member sees next to each of these players on a leaderboard (_leaderboard_player.html.twig,
     * skill_icon()): the tier at the piece count skill is computed for, "unknown" without one, "locked" for a player who
     * opted out of rankings. One statement for the whole list. The caller decides who may see it (members).
     *
     * @param list<string> $playerIds
     * @return array<string, string> player id => skill_icon() name
     */
    public function skillTierIcons(array $playerIds): array
    {
        $playerIds = array_values(array_unique(array_filter($playerIds, static fn (string $id): bool => Uuid::isValid($id))));

        if ($playerIds === []) {
            return [];
        }

        /** @var list<array{player_id: string, ranking_opted_out: bool, skill_tier: null|int}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT player.id AS player_id, player.ranking_opted_out, skill.skill_tier
FROM player
LEFT JOIN player_skill skill ON skill.player_id = player.id AND skill.pieces_count = :piecesCount
WHERE player.id IN (:playerIds)
SQL,
            ['playerIds' => $playerIds, 'piecesCount' => FindSimilarSpeedPuzzler::SKILL_PIECES_COUNT],
            ['playerIds' => ArrayParameterType::STRING, 'piecesCount' => ParameterType::INTEGER],
        );

        $icons = [];

        foreach ($rows as $row) {
            $tier = $row['skill_tier'] !== null ? SkillTier::tryFrom((int) $row['skill_tier']) : null;

            $icons[$row['player_id']] = match (true) {
                $row['ranking_opted_out'] => 'locked',
                $tier === null => 'unknown',
                default => strtolower($tier->name),
            };
        }

        return $icons;
    }
}
