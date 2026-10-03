<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ComparisonTeamSearchResult;
use SpeedPuzzling\Web\Results\PuzzlingTeamMemberView;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\ComparisonKind;

/**
 * The add sheet of the Pairs / Teams line-ups (docs/features/player-comparison.md D12): any pair/team the viewer may
 * see, by team name or by a member - the viewer's own pairs/teams first, marked "You're in it".
 *
 * Only pairs/teams with at least one valid time (nothing to compare otherwise), and only those GetComparisonSubjects
 * would show: no member hidden by the blocklist (even when the viewer is in it), and a registered member the viewer
 * may see unless the viewer is a member. Members match by name or code when the viewer may see them: public players,
 * private ones who revealed themselves to the viewer (allow list) and the viewer. Unlike SearchPlayers, a private
 * player hidden from the viewer is never found - not even by the exact #code, which would tie the code to every
 * pair/team they are in. At most MATCHED_PLAYERS best-matching players are followed - a very common name narrows down
 * by typing more. Hidden players never match.
 *
 * Measured on the production copy (2026-10-03): 3+ characters < 4 ms, 1-2 characters ~16 ms (the player name scan
 * without trigrams, like SearchPlayers). Following every matching player instead took 380 ms, almost all of it JIT
 * compiling a plan estimated far above jit_above_cost - keep the estimate small when changing the shape.
 */
readonly final class SearchComparisonTeams
{
    public const int MATCHED_PLAYERS = 30;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @return list<ComparisonTeamSearchResult> the viewer's own first, then the most times
     */
    public function search(string $query, ComparisonKind $kind, string $viewerPlayerId, int $limit = 20): array
    {
        $term = ltrim(trim($query), '#');

        if ($term === '' || $kind === ComparisonKind::Solo || Uuid::isValid($viewerPlayerId) === false) {
            return [];
        }

        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('player');
        $notHidden = $this->hiddenPlayers->sqlExclude('player.id');
        // 3+ characters are answered by the trigram index custom_player_search_trgm (holds immutable_unaccent()),
        // 1-2 characters scan the table, where plain unaccent() is faster - see SearchPlayers
        $unaccent = mb_strlen($term) >= 3 ? 'immutable_unaccent' : 'unaccent';

        $candidates = <<<SQL
matched_players AS MATERIALIZED (
    SELECT player.id
    FROM player
    WHERE (
            LOWER(player.name) LIKE LOWER(:like) OR LOWER(player.code) LIKE LOWER(:like)
            OR LOWER({$unaccent}(player.name)) LIKE LOWER(unaccent(:like)) OR LOWER({$unaccent}(player.code)) LIKE LOWER(unaccent(:like))
        )
        AND ({$isPrivate} = false OR player.id = CAST(:viewerId AS UUID))
        {$notHidden}
    ORDER BY
        (LOWER(player.code) = LOWER(:term)) DESC,
        (LOWER(unaccent(player.name)) = LOWER(unaccent(:term))) DESC,
        (LOWER(unaccent(player.name)) LIKE LOWER(unaccent(:term)) || '%') DESC,
        player.id
    LIMIT :matchedPlayers
),
candidates AS MATERIALIZED (
    SELECT member.team_id AS id FROM puzzling_team_member member WHERE member.player_id IN (SELECT id FROM matched_players)
    UNION
    SELECT team.id FROM puzzling_team team WHERE team.name IS NOT NULL AND LOWER(unaccent(team.name)) LIKE LOWER(unaccent(:like))
)
SQL;

        return $this->fetch(
            $candidates,
            'candidates.id',
            $kind,
            $viewerPlayerId,
            $limit,
            'includes_viewer DESC, stats.times_count DESC, stats.last_solved_at DESC, team.id',
            [
                'term' => $term,
                'like' => '%' . $term . '%',
                'matchedPlayers' => self::MATCHED_PLAYERS,
            ],
            ['matchedPlayers' => ParameterType::INTEGER],
        );
    }

    /**
     * The viewer's own pairs (or teams) with results, most recently solved first - the suggestions of the add sheet
     * before anything is typed. (GetCoPuzzlers::forPlayer() is the add-time form's picker: it also lists named teams
     * without results and returns member keys rather than masked members, so it does not fit here.)
     *
     * @return list<ComparisonTeamSearchResult>
     */
    public function forViewer(string $viewerPlayerId, ComparisonKind $kind, int $limit = 20): array
    {
        if ($kind === ComparisonKind::Solo || Uuid::isValid($viewerPlayerId) === false) {
            return [];
        }

        $candidates = <<<SQL
candidates AS MATERIALIZED (
    SELECT own.team_id AS id FROM puzzling_team_member own WHERE own.player_id = :viewerId
)
SQL;

        return $this->fetch(
            $candidates,
            'candidates.id',
            $kind,
            $viewerPlayerId,
            $limit,
            'stats.last_solved_at DESC, stats.times_count DESC, team.id',
            [],
            [],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, ParameterType> $types
     * @return list<ComparisonTeamSearchResult>
     */
    private function fetch(
        string $candidates,
        string $candidateId,
        ComparisonKind $kind,
        string $viewerPlayerId,
        int $limit,
        string $orderBy,
        array $parameters,
        array $types,
    ): array {
        $sizeCondition = $kind === ComparisonKind::Pairs ? 'team.size = 2' : 'team.size >= 3';

        $hiddenMember = $this->hiddenPlayers->sqlExclude('hidden_member.player_id');
        $noHiddenMember = $hiddenMember === ''
            ? ''
            : "AND NOT EXISTS (SELECT 1 FROM puzzling_team_member hidden_member WHERE hidden_member.team_id = team.id AND NOT (TRUE{$hiddenMember}))";
        $visibleIsPrivate = $this->privateProfileAccess->sqlIsPrivate('visible_player');
        $memberMasked = "({$this->privateProfileAccess->sqlIsPrivate('member_player')} AND member_player.id IS DISTINCT FROM CAST(:viewerId AS UUID))";
        $outerOrderBy = str_replace(['stats.', 'team.'], '', $orderBy);

        $query = <<<SQL
WITH {$candidates},
limited AS (
    SELECT
        team.id,
        team.name,
        team.size,
        stats.times_count,
        stats.last_solved_at,
        EXISTS (SELECT 1 FROM puzzling_team_member own WHERE own.team_id = team.id AND own.player_id = CAST(:viewerId AS UUID)) AS includes_viewer
    FROM (SELECT DISTINCT {$candidateId} AS id FROM candidates) candidate
    INNER JOIN puzzling_team team ON team.id = candidate.id
    INNER JOIN LATERAL (
        SELECT COUNT(*) AS times_count, MAX(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at
        FROM puzzle_solving_time pst
        WHERE pst.puzzling_team_id = team.id AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL
    ) stats ON stats.times_count > 0
    WHERE {$sizeCondition}
        {$noHiddenMember}
        AND (
            EXISTS (SELECT 1 FROM puzzling_team_member own WHERE own.team_id = team.id AND own.player_id = CAST(:viewerId AS UUID))
            OR EXISTS (
                SELECT 1
                FROM puzzling_team_member visible_member
                INNER JOIN player visible_player ON visible_player.id = visible_member.player_id
                WHERE visible_member.team_id = team.id AND NOT {$visibleIsPrivate}
            )
        )
    ORDER BY {$orderBy}
    LIMIT :limit
)
SELECT
    limited.*,
    (
        SELECT json_agg(json_build_object(
            'player_id', member.player_id,
            'guest_name', member.guest_name,
            'player_code', member_player.code,
            'player_name', CASE WHEN {$memberMasked} THEN NULL ELSE member_player.name END,
            'player_country', CASE WHEN {$memberMasked} THEN NULL ELSE member_player.country END,
            'is_private', COALESCE({$memberMasked}, FALSE)
        ) ORDER BY member.position)
        FROM puzzling_team_member member
        LEFT JOIN player member_player ON member_player.id = member.player_id
        WHERE member.team_id = limited.id
    ) AS members
FROM limited
ORDER BY {$outerOrderBy}
SQL;

        /**
         * @var list<array{
         *     id: string,
         *     name: null|string,
         *     size: int,
         *     times_count: int,
         *     last_solved_at: string,
         *     includes_viewer: bool,
         *     members: null|string,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative(
            $query,
            [...$parameters, 'viewerId' => strtolower($viewerPlayerId), 'limit' => max(1, min(100, $limit))],
            [...$types, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn(array $row): ComparisonTeamSearchResult => new ComparisonTeamSearchResult(
            teamId: $row['id'],
            name: $row['name'],
            size: (int) $row['size'],
            members: PuzzlingTeamMemberView::listFromJson($row['members']),
            timesCount: (int) $row['times_count'],
            lastSolvedAt: new DateTimeImmutable($row['last_solved_at']),
            includesViewer: $row['includes_viewer'],
        ), $rows);
    }
}
