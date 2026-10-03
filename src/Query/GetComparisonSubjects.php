<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\PuzzlingTeamMemberView;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * Who is in a line-up, as the current (web) viewer may see them - re-checked on every read, never only when a subject
 * was added (docs/features/player-comparison.md "Visibility"). One statement for players and pairs/teams together.
 *
 * - Player: hidden by the viewer's blocklist → unavailable; private → only when revealed to the viewer (allow list) or
 *   when it is the viewer. Same rule as the player's profile page (GetPlayerProfile::byId()), so a block the *other*
 *   player made never shows here either - the blocked side must not be able to tell (docs/features/player-blocklist.md).
 * - Pair/team: any member hidden by the blocklist → unavailable, even when the viewer is in it (GetCoPuzzlers'
 *   `hidden_member`); every registered member private-and-unrevealed → unavailable unless the viewer is a member;
 *   otherwise private members are masked (name/country null, `isPrivate`) - the viewer never masks themselves.
 *
 * Uses the ambient HiddenPlayers / PrivateProfileAccess (security token), so it is for web requests. Handlers decide
 * with explicit ids instead.
 *
 * @phpstan-type SubjectRow array{
 *     subject_type: string,
 *     subject_id: string,
 *     available: bool,
 *     player_name: null|string,
 *     player_code: null|string,
 *     player_avatar: null|string,
 *     player_country: null|string,
 *     team_name: null|string,
 *     team_size: null|int,
 *     includes_viewer: bool,
 *     members: null|string,
 * }
 */
readonly final class GetComparisonSubjects
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @param list<ComparisonSubjectRef> $refs
     * @return list<ComparisonSubject> one per distinct ref, in the given order; refs that do not exist come back unavailable
     */
    public function byRefs(array $refs, null|string $viewerPlayerId): array
    {
        $unique = [];

        foreach ($refs as $ref) {
            $unique[$ref->toString()] ??= $ref;
        }

        if ($unique === []) {
            return [];
        }

        $playerIds = [];
        $teamIds = [];

        foreach ($unique as $ref) {
            if ($ref->isPlayer()) {
                $playerIds[] = $ref->id;
            } else {
                $teamIds[] = $ref->id;
            }
        }

        $viewerId = $viewerPlayerId !== null ? strtolower($viewerPlayerId) : null;
        $rows = $this->fetchRows($playerIds, $teamIds, $viewerId);
        $subjects = [];

        foreach ($unique as $key => $ref) {
            $row = $rows[$key] ?? null;

            if ($row === null) {
                $subjects[] = ComparisonSubject::unavailable($ref, $ref->isPlayer() ? ComparisonKind::Solo : null);

                continue;
            }

            $subjects[] = $ref->isPlayer() ? $this->playerSubject($ref, $row, $viewerId) : $this->teamSubject($ref, $row);
        }

        return $subjects;
    }

    /**
     * @param list<string> $playerIds
     * @param list<string> $teamIds
     * @return array<string, SubjectRow> keyed by ref string
     */
    private function fetchRows(array $playerIds, array $teamIds, null|string $viewerId): array
    {
        $notHiddenPlayer = $this->hiddenPlayers->sqlExclude('player.id');
        $playerIsPrivate = $this->privateProfileAccess->sqlIsPrivate('player');

        $hiddenMember = $this->hiddenPlayers->sqlExclude('hidden_member.player_id');
        $noHiddenMember = $hiddenMember === ''
            ? 'TRUE'
            : "NOT EXISTS (SELECT 1 FROM puzzling_team_member hidden_member WHERE hidden_member.team_id = team.id AND NOT (TRUE{$hiddenMember}))";
        $visibleIsPrivate = $this->privateProfileAccess->sqlIsPrivate('visible_player');
        // The viewer never masks themselves
        $memberMasked = "({$this->privateProfileAccess->sqlIsPrivate('member_player')} AND member_player.id IS DISTINCT FROM CAST(:viewerId AS UUID))";

        $query = <<<SQL
SELECT
    'p' AS subject_type,
    player.id AS subject_id,
    COALESCE((TRUE{$notHiddenPlayer}) AND (player.id = CAST(:viewerId AS UUID) OR NOT {$playerIsPrivate}), FALSE) AS available,
    player.name AS player_name,
    player.code AS player_code,
    player.avatar AS player_avatar,
    player.country AS player_country,
    NULL AS team_name,
    NULL::SMALLINT AS team_size,
    FALSE AS includes_viewer,
    NULL::JSON AS members
FROM player
WHERE player.id IN (:playerIds)
UNION ALL
SELECT
    't' AS subject_type,
    team.id AS subject_id,
    {$noHiddenMember}
        AND (
            EXISTS (SELECT 1 FROM puzzling_team_member own WHERE own.team_id = team.id AND own.player_id = CAST(:viewerId AS UUID))
            OR EXISTS (
                SELECT 1
                FROM puzzling_team_member visible_member
                INNER JOIN player visible_player ON visible_player.id = visible_member.player_id
                WHERE visible_member.team_id = team.id AND NOT {$visibleIsPrivate}
            )
        ) AS available,
    NULL AS player_name,
    NULL AS player_code,
    NULL AS player_avatar,
    NULL AS player_country,
    team.name AS team_name,
    team.size AS team_size,
    EXISTS (SELECT 1 FROM puzzling_team_member own WHERE own.team_id = team.id AND own.player_id = CAST(:viewerId AS UUID)) AS includes_viewer,
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
        WHERE member.team_id = team.id
    ) AS members
FROM puzzling_team team
WHERE team.id IN (:teamIds)
SQL;

        /** @var list<SubjectRow> $rows */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['playerIds' => $playerIds, 'teamIds' => $teamIds, 'viewerId' => $viewerId],
            ['playerIds' => ArrayParameterType::STRING, 'teamIds' => ArrayParameterType::STRING],
        );

        $byRef = [];

        foreach ($rows as $row) {
            $byRef[$row['subject_type'] . '-' . strtolower($row['subject_id'])] = $row;
        }

        return $byRef;
    }

    /**
     * @param SubjectRow $row
     */
    private function playerSubject(ComparisonSubjectRef $ref, array $row, null|string $viewerId): ComparisonSubject
    {
        if ($row['available'] === false) {
            return ComparisonSubject::unavailable($ref, ComparisonKind::Solo);
        }

        return new ComparisonSubject(
            ref: $ref,
            kind: ComparisonKind::Solo,
            isAvailable: true,
            isViewer: $viewerId !== null && $ref->id === $viewerId,
            playerName: $row['player_name'],
            playerCode: $row['player_code'] !== null ? strtoupper($row['player_code']) : null,
            playerAvatar: $row['player_avatar'],
            playerCountry: CountryCode::fromCode($row['player_country']),
        );
    }

    /**
     * @param SubjectRow $row
     */
    private function teamSubject(ComparisonSubjectRef $ref, array $row): ComparisonSubject
    {
        $size = (int) $row['team_size'];
        $kind = ComparisonKind::forTeamSize($size);

        if ($row['available'] === false) {
            return ComparisonSubject::unavailable($ref, $kind);
        }

        return new ComparisonSubject(
            ref: $ref,
            kind: $kind,
            isAvailable: true,
            teamName: $row['team_name'],
            teamSize: $size,
            members: PuzzlingTeamMemberView::listFromJson($row['members']),
            includesViewer: $row['includes_viewer'],
        );
    }
}
