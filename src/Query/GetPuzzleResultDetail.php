<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleResultNotFound;
use SpeedPuzzling\Web\Results\PuzzleResultAttempt;
use SpeedPuzzling\Web\Results\PuzzleResultDetail;
use SpeedPuzzling\Web\Results\PuzzleResultStanding;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\Puzzler;

/**
 * One player's - or one exact pair's / team's - results on one puzzle, opened from one of its times
 * (docs/features/puzzle-result-detail.md). Two statements: the attempts (with the puzzle and the subject)
 * and the standing on the leaderboard.
 */
readonly final class GetPuzzleResultDetail
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleResultNotFound
     */
    public function byTimeId(string $timeId, null|string $viewerPlayerId): PuzzleResultDetail
    {
        if (Uuid::isValid($timeId) === false) {
            throw new PuzzleResultNotFound();
        }

        // Solo: hidden with its player. Group: by its members alone (the viewer's own group times stay)
        $notHidden = $this->hiddenPlayers->sqlExclude('(CASE WHEN pst.team IS NULL THEN pst.player_id END)')
            . $this->hiddenPlayers->sqlExcludeTeam('pst.team');

        // The subject is the opened time's solo player, or its exact pair/team (`puzzling_team_id`) - never the URL
        $query = <<<SQL
WITH focus AS (
    SELECT id, puzzle_id, player_id, puzzling_type, puzzling_team_id, team
    FROM puzzle_solving_time
    WHERE id = :timeId
),
focus_members AS (
    SELECT JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', member.player ->> 'player_id',
            'player_name', COALESCE(p.name, member.player ->> 'player_name'),
            'player_code', p.code,
            'player_country', p.country,
            'player_avatar', p.avatar,
            'is_private', {$this->privateProfileAccess->sqlIsPrivate('p')},
            'skill_tier', ps_member.skill_tier,
            'ranking_opted_out', COALESCE(p.ranking_opted_out, false)
        ) ORDER BY member.ordinality
    ) AS players
    FROM focus
        CROSS JOIN LATERAL json_array_elements(focus.team -> 'puzzlers') WITH ORDINALITY AS member(player, ordinality)
        LEFT JOIN player p ON p.id = (member.player ->> 'player_id')::UUID
        LEFT JOIN player_skill ps_member ON ps_member.player_id = p.id
    WHERE focus.team IS NOT NULL
),
-- Split by kind, so each branch walks its own index instead of every time of the puzzle
subject_times AS (
    SELECT focus.id FROM focus
    UNION
    SELECT pst.id
    FROM focus
        INNER JOIN puzzle_solving_time pst ON pst.player_id = focus.player_id
            AND pst.puzzle_id = focus.puzzle_id
            AND pst.puzzling_type = focus.puzzling_type
            AND pst.team IS NULL
    WHERE focus.team IS NULL
    UNION
    SELECT pst.id
    FROM focus
        INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = focus.puzzling_team_id
            AND pst.puzzle_id = focus.puzzle_id
            AND pst.puzzling_type = focus.puzzling_type
    WHERE focus.team IS NOT NULL
)
SELECT
    pst.id AS time_id,
    pst.player_id AS tracked_by_id,
    pst.seconds_to_solve AS time,
    pst.finished_at,
    pst.tracked_at,
    pst.first_attempt,
    pst.unboxed,
    pst.suspicious,
    pst.comment,
    pst.finished_puzzle_photo,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    focus.puzzling_type,
    focus.puzzling_team_id::varchar AS team_id,
    puzzling_team.name AS team_name,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name,
    focus_player.id AS player_id,
    focus_player.name AS player_name,
    focus_player.code AS player_code,
    focus_player.country AS player_country,
    focus_player.avatar AS player_avatar,
    {$this->privateProfileAccess->sqlIsPrivate('focus_player')} AS is_private,
    ps.skill_tier,
    focus_player.ranking_opted_out,
    focus_members.players
FROM focus
    INNER JOIN subject_times ON true
    INNER JOIN puzzle_solving_time pst ON pst.id = subject_times.id
    INNER JOIN puzzle ON puzzle.id = focus.puzzle_id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    INNER JOIN player focus_player ON focus_player.id = focus.player_id
    LEFT JOIN player_skill ps ON ps.player_id = focus_player.id
    LEFT JOIN puzzling_team ON puzzling_team.id = focus.puzzling_team_id
    LEFT JOIN competition ON competition.id = pst.competition_id
    LEFT JOIN competition_series cs ON cs.id = competition.series_id
    LEFT JOIN focus_members ON true
WHERE true
    {$notHidden}
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.tracked_at DESC
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'timeId' => $timeId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        /**
         * @var list<array{
         *     time_id: string,
         *     tracked_by_id: string,
         *     time: null|int,
         *     finished_at: null|string,
         *     tracked_at: string,
         *     first_attempt: bool,
         *     unboxed: bool,
         *     suspicious: bool,
         *     comment: null|string,
         *     finished_puzzle_photo: null|string,
         *     competition_id: null|string,
         *     competition_shortcut: null|string,
         *     competition_name: null|string,
         *     competition_slug: null|string,
         *     competition_series_name: null|string,
         *     competition_series_shortcut: null|string,
         *     competition_series_slug: null|string,
         *     puzzling_type: string,
         *     team_id: null|string,
         *     team_name: null|string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_image: null|string,
         *     puzzle_image_ratio: null|string,
         *     pieces_count: int,
         *     manufacturer_name: string,
         *     player_id: string,
         *     player_name: null|string,
         *     player_code: null|string,
         *     player_country: null|string,
         *     player_avatar: null|string,
         *     is_private: null|bool,
         *     skill_tier: null|int,
         *     ranking_opted_out: bool,
         *     players: null|string,
         * }> $rows
         */

        $focusRow = null;

        foreach ($rows as $row) {
            if ($row['time_id'] === $timeId) {
                $focusRow = $row;
                break;
            }
        }

        // Unknown time, or the viewer has hidden its player / a member
        if ($focusRow === null) {
            throw new PuzzleResultNotFound();
        }

        $player = Puzzler::fromDatabaseRow($focusRow);
        $players = $focusRow['players'] !== null ? array_values(Puzzler::createPuzzlersFromJson($focusRow['players'])) : null;

        $memberPlayerIds = [];
        foreach ($players ?? [] as $member) {
            if ($member->playerId !== null) {
                $memberPlayerIds[] = $member->playerId;
            }
        }

        $viewerIsSubject = $viewerPlayerId !== null && (
            $players === null ? $viewerPlayerId === $player->playerId : in_array($viewerPlayerId, $memberPlayerIds, true)
        );

        if ($viewerIsSubject === false && $this->isPrivateForViewer($player, $players) === true) {
            throw new PuzzleResultNotFound();
        }

        $attempts = [];

        foreach ($rows as $row) {
            $attempts[] = new PuzzleResultAttempt(
                timeId: $row['time_id'],
                trackedByPlayerId: $row['tracked_by_id'],
                time: $row['time'],
                finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
                trackedAt: new DateTimeImmutable($row['tracked_at']),
                firstAttempt: $row['first_attempt'],
                unboxed: $row['unboxed'],
                suspicious: $row['suspicious'],
                comment: $row['comment'],
                finishedPuzzlePhoto: $row['finished_puzzle_photo'],
                competitionId: $row['competition_id'],
                competitionShortcut: $row['competition_shortcut'],
                competitionName: $row['competition_name'],
                competitionSlug: $row['competition_slug'],
                competitionSeriesName: $row['competition_series_name'],
                competitionSeriesShortcut: $row['competition_series_shortcut'],
                competitionSeriesSlug: $row['competition_series_slug'],
                memberPlayerIds: $memberPlayerIds,
            );
        }

        if ($attempts === []) {
            throw new PuzzleResultNotFound();
        }

        [$attempts, $bestAttempt] = self::compareAttempts($attempts);

        $detail = new PuzzleResultDetail(
            focusTimeId: $timeId,
            puzzleId: $focusRow['puzzle_id'],
            puzzleName: $focusRow['puzzle_name'],
            puzzleImage: $focusRow['puzzle_image'],
            puzzleImageRatio: $focusRow['puzzle_image_ratio'] !== null ? (float) $focusRow['puzzle_image_ratio'] : null,
            piecesCount: $focusRow['pieces_count'],
            manufacturerName: $focusRow['manufacturer_name'],
            puzzlingType: $focusRow['puzzling_type'],
            player: $player,
            teamId: $players !== null ? $focusRow['team_id'] : null,
            teamName: $players !== null ? $focusRow['team_name'] : null,
            players: $players,
            attempts: $attempts,
            bestAttempt: $bestAttempt,
        );

        $hasLeaderboardTime = false;
        foreach ($attempts as $attempt) {
            if ($attempt->time !== null && $attempt->suspicious === false) {
                $hasLeaderboardTime = true;
                break;
            }
        }

        if ($hasLeaderboardTime === false) {
            return $detail;
        }

        $subjectId = $players === null ? $player->playerId : $focusRow['team_id'];

        if ($subjectId === null) {
            return $detail;
        }

        return $detail->withStanding(
            $this->standing($focusRow['puzzle_id'], $focusRow['puzzling_type'], $subjectId, $viewerPlayerId),
        );
    }

    /**
     * Rank of the subject's best time on the unfiltered leaderboard of the puzzle + category, computed in one
     * aggregate without loading the leaderboard. Mirrors the PuzzleTimes component: best non-suspicious timed
     * result per subject, hidden players left out, private subjects left out like
     * PuzzlesSorter::filterOutPrivateProfiles() (solo: private and not the viewer; pair/team: only when no member
     * is visible to the viewer - a guest counts as visible - and the viewer is not a member).
     *
     * @param string $subjectId the player id (solo) or the puzzling_team id (pair / team)
     */
    public function standing(string $puzzleId, string $puzzlingType, string $subjectId, null|string $viewerPlayerId): null|PuzzleResultStanding
    {
        if ($puzzlingType === 'solo') {
            $notHidden = $this->hiddenPlayers->sqlExclude('player.id');

            $query = <<<SQL
WITH best AS (
    SELECT pst.player_id AS subject_id, MIN(pst.seconds_to_solve) AS best_time
    FROM puzzle_solving_time pst
        INNER JOIN player ON player.id = pst.player_id
    WHERE pst.puzzle_id = :puzzleId
        AND pst.puzzling_type = 'solo'
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND ({$this->privateProfileAccess->sqlIsPrivate('player')} = false OR player.id = :viewerId)
        {$notHidden}
    GROUP BY pst.player_id
),
subject AS (
    SELECT best_time FROM best WHERE subject_id = :subjectId
)
SELECT
    (SELECT best_time FROM subject) AS subject_time,
    COUNT(*) AS total,
    MIN(best.best_time) AS leader_time,
    COUNT(*) FILTER (WHERE best.best_time < (SELECT best_time FROM subject)) AS faster_count,
    MAX(best.best_time) FILTER (WHERE best.best_time < (SELECT best_time FROM subject)) AS closest_faster_time,
    -- Same median as the leaderboard: the middle best time, the two middle ones averaged and truncated
    FLOOR(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY best.best_time))::int AS median_time
FROM best
SQL;
        } else {
            $notHidden = $this->hiddenPlayers->sqlExcludeTeam('pst.team');

            $query = <<<SQL
WITH best AS (
    SELECT pst.puzzling_team_id AS subject_id, MIN(pst.seconds_to_solve) AS best_time
    FROM puzzle_solving_time pst
    WHERE pst.puzzle_id = :puzzleId
        AND pst.puzzling_type = :puzzlingType
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND pst.puzzling_team_id IS NOT NULL
        {$notHidden}
    GROUP BY pst.puzzling_team_id
),
visible AS (
    SELECT best.subject_id, best.best_time
    FROM best
    WHERE EXISTS (
        SELECT 1
        FROM puzzling_team_member member
            LEFT JOIN player mp ON mp.id = member.player_id
        WHERE member.team_id = best.subject_id
            AND (
                mp.id IS NULL
                OR {$this->privateProfileAccess->sqlIsPrivate('mp')} = false
                OR mp.id = :viewerId
            )
    )
),
subject AS (
    SELECT best_time FROM visible WHERE subject_id = :subjectId
)
SELECT
    (SELECT best_time FROM subject) AS subject_time,
    COUNT(*) AS total,
    MIN(visible.best_time) AS leader_time,
    COUNT(*) FILTER (WHERE visible.best_time < (SELECT best_time FROM subject)) AS faster_count,
    MAX(visible.best_time) FILTER (WHERE visible.best_time < (SELECT best_time FROM subject)) AS closest_faster_time,
    -- Same median as the leaderboard: the middle best time, the two middle ones averaged and truncated
    FLOOR(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY visible.best_time))::int AS median_time
FROM visible
SQL;
        }

        /**
         * @var false|array{
         *     subject_time: null|int,
         *     total: int,
         *     leader_time: null|int,
         *     faster_count: int,
         *     closest_faster_time: null|int,
         *     median_time: null|int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
                'puzzlingType' => $puzzlingType,
                'subjectId' => $subjectId,
                'viewerId' => $viewerPlayerId,
            ])
            ->fetchAssociative();

        if ($row === false || $row['subject_time'] === null || $row['leader_time'] === null) {
            return null;
        }

        return new PuzzleResultStanding(
            rank: 1 + (int) $row['faster_count'],
            total: (int) $row['total'],
            subjectTime: (int) $row['subject_time'],
            leaderTime: (int) $row['leader_time'],
            closestFasterTime: $row['closest_faster_time'] !== null ? (int) $row['closest_faster_time'] : null,
            medianTime: (int) $row['median_time'],
        );
    }

    /**
     * @param null|list<Puzzler> $players
     */
    private function isPrivateForViewer(Puzzler $player, null|array $players): bool
    {
        if ($players === null) {
            return $player->isPrivate;
        }

        // A pair/team is private only when every member is private to the viewer - a guest is never private,
        // like on the leaderboard (PuzzlesSorter::filterOutPrivateProfiles)
        foreach ($players as $member) {
            if ($member->playerId === null || $member->isPrivate === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Marks the best attempt and computes each timed attempt's change against the chronologically previous timed
     * attempt and its gap to the best. Attempts come newest first.
     *
     * @param non-empty-list<PuzzleResultAttempt> $attempts
     * @return array{list<PuzzleResultAttempt>, null|PuzzleResultAttempt}
     */
    private static function compareAttempts(array $attempts): array
    {
        $best = null;

        // A suspicious time (listed, with its badge) never beats a clean one
        foreach ([false, true] as $includeSuspicious) {
            foreach ($attempts as $attempt) {
                if ($attempt->time === null || ($attempt->suspicious === true && $includeSuspicious === false)) {
                    continue;
                }

                if ($best === null || $attempt->time < $best->time) {
                    $best = $attempt;
                }
            }

            if ($best !== null) {
                break;
            }
        }

        $compared = [];
        $count = count($attempts);

        for ($index = 0; $index < $count; $index++) {
            $attempt = $attempts[$index];

            if ($attempt->time === null) {
                $compared[] = $attempt;
                continue;
            }

            $previousTime = null;
            for ($older = $index + 1; $older < $count; $older++) {
                if ($attempts[$older]->time !== null) {
                    $previousTime = $attempts[$older]->time;
                    break;
                }
            }

            $isBest = $best !== null && $attempt->timeId === $best->timeId;

            $compared[] = $attempt->withComparison(
                isBest: $isBest,
                deltaToPrevious: $previousTime !== null ? $attempt->time - $previousTime : null,
                gapToBest: ($best !== null && $best->time !== null && $isBest === false) ? $attempt->time - $best->time : null,
            );
        }

        $bestCompared = null;
        foreach ($compared as $attempt) {
            if ($attempt->isBest === true) {
                $bestCompared = $attempt;
            }
        }

        return [$compared, $bestCompared];
    }
}
