<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PlayerCardBoardContext;
use SpeedPuzzling\Web\Results\PlayerCardPuzzleContext;
use SpeedPuzzling\Web\Results\PlayerCardResultContext;
use SpeedPuzzling\Web\Results\PlayerCardRoundContext;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\PlayerCardBoard;
use SpeedPuzzling\Web\Value\PlayerCardContext;
use SpeedPuzzling\Web\Value\PlayerCardContextKind;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * The context block of the player card (docs/features/navigation README §4.8, CARD-02): what the player did on the page
 * the card was opened from - their standing on the puzzle, the feed or notification result, their result in the event
 * round, their place on the leaderboard - next to the viewer's own where it makes sense.
 *
 * Exactly one statement per kind (CARD-06: profile + card + context + the direct-messages check ≤ 4), each reusing the
 * SQL of the surface it explains, so a rank on the card is the rank there (risk 12): the puzzle's leaderboard and the
 * result detail (GetPuzzleResultDetail::soloBestCte() / groupBestCte()), the round page's official ranking
 * (OfficialResultsRanking over the same entries as GetPublishedRoundResults) and each board's positionsOf().
 *
 * Runs only after the card's gate (GetPlayerProfile::byId(), private players): the player is one the viewer may see.
 * Nothing here ever changes the response's status - a context that does not apply (unknown id, a secret puzzle, a
 * result awaiting verification, a draft event, a board the viewer may not open, a result of somebody else) is no
 * block. Every statement leaves out players hidden from the viewer inside the ranked sets, results awaiting
 * verification and secret puzzles; private players stay off what the surface leaves them off.
 */
readonly final class GetPlayerCardContext
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
        private GetPuzzleResultDetail $getPuzzleResultDetail,
        private GetFastestPlayers $getFastestPlayers,
        private GetPlayerRatingRanking $getPlayerRatingRanking,
        private GetXpLeaderboard $getXpLeaderboard,
        private XpFeatureGate $xpFeatureGate,
    ) {
    }

    public function forCard(
        PlayerCardContext $context,
        PlayerProfile $player,
        null|PlayerProfile $viewer,
    ): null|PlayerCardPuzzleContext|PlayerCardResultContext|PlayerCardRoundContext|PlayerCardBoardContext {
        $playerId = strtolower($player->playerId);
        $viewerId = $viewer !== null ? strtolower($viewer->playerId) : null;

        return match ($context->kind) {
            PlayerCardContextKind::Puzzle => $this->puzzle($context->subject, $playerId, $viewerId),
            PlayerCardContextKind::Feed, PlayerCardContextKind::Notification => $this->result($context->subject, $playerId, $viewerId),
            PlayerCardContextKind::Round => $this->round($context->subject, $playerId, $viewerId),
            PlayerCardContextKind::Leaderboard => $this->board($context->subject, $playerId, $viewer),
        };
    }

    /**
     * The player's standing on the puzzle's solo leaderboard (the same board as the puzzle page and the result detail),
     * their attempts there, the viewer's best and - on their own card - one of their results to open
     */
    private function puzzle(string $puzzleId, string $playerId, null|string $viewerId): null|PlayerCardPuzzleContext
    {
        $soloBest = $this->getPuzzleResultDetail->soloBestCte('best', ':puzzleId');
        $groupNotHidden = $this->hiddenPlayers->sqlExcludeTeam('pst.team');

        $query = <<<SQL
WITH {$soloBest}
SELECT
    (SELECT best.best_time FROM best WHERE best.subject_id = :playerId) AS subject_time,
    (SELECT COUNT(*) FROM best) AS total,
    (SELECT COUNT(*) FROM best WHERE best.best_time < (SELECT own.best_time FROM best own WHERE own.subject_id = :playerId)) AS faster_count,
    (SELECT best.best_time FROM best WHERE best.subject_id = CAST(:viewerId AS UUID)) AS viewer_time,
    (
        SELECT COUNT(*)
        FROM puzzle_solving_time pst
        WHERE pst.puzzle_id = :puzzleId
            AND pst.player_id = :playerId
            AND pst.puzzling_type = 'solo'
            AND pst.suspicious = false
    ) AS solo_attempts,
    (
        SELECT COUNT(*)
        FROM puzzling_team_member ptm
            INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = ptm.team_id
        WHERE ptm.player_id = :playerId
            AND pst.puzzle_id = :puzzleId
            AND pst.suspicious = false
            {$groupNotHidden}
    ) AS group_attempts,
    -- The owner's own card: their best solo result, else their latest one - the result detail lists the rest
    CASE WHEN CAST(:viewerId AS UUID) = CAST(:playerId AS UUID) THEN (
        SELECT own.id
        FROM (
            SELECT pst.id, true AS solo, pst.suspicious, pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
            FROM puzzle_solving_time pst
            WHERE pst.puzzle_id = :puzzleId AND pst.player_id = :playerId AND pst.puzzling_type = 'solo'
            UNION ALL
            SELECT pst.id, false AS solo, pst.suspicious, pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
            FROM puzzling_team_member ptm
                INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = ptm.team_id
            WHERE ptm.player_id = :playerId AND pst.puzzle_id = :puzzleId
        ) own
        ORDER BY own.solo DESC, own.suspicious, own.seconds_to_solve NULLS LAST, own.solved_at DESC, own.id
        LIMIT 1
    ) END AS own_time_id
FROM puzzle
WHERE puzzle.id = :puzzleId
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
SQL;

        /** @var false|array{subject_time: null|int|string, total: int|string, faster_count: int|string, viewer_time: null|int|string, solo_attempts: int|string, group_attempts: int|string, own_time_id: null|string} $row */
        $row = $this->database->executeQuery($query, [
            'puzzleId' => $puzzleId,
            'playerId' => $playerId,
            'viewerId' => $viewerId,
            'now' => $this->now(),
        ])->fetchAssociative();

        // An unknown or secret puzzle
        if ($row === false) {
            return null;
        }

        $attempts = (int) $row['solo_attempts'] + (int) $row['group_attempts'];

        if ($row['subject_time'] === null && $attempts === 0 && $row['own_time_id'] === null) {
            return null;
        }

        $isOwn = $viewerId === $playerId;

        return new PlayerCardPuzzleContext(
            puzzleId: $puzzleId,
            rank: $row['subject_time'] !== null ? 1 + (int) $row['faster_count'] : null,
            total: (int) $row['total'],
            bestTime: $row['subject_time'] !== null ? (int) $row['subject_time'] : null,
            attempts: $attempts,
            groupAttempts: (int) $row['group_attempts'],
            viewerTime: $isOwn === false && $row['viewer_time'] !== null ? (int) $row['viewer_time'] : null,
            viewerSignedIn: $viewerId !== null,
            ownTimeId: $row['own_time_id'],
        );
    }

    /**
     * One result (a feed row, a notification): the player's own (solo, or a pair/team they are in), never one awaiting
     * verification unless it is the viewer's, never on a secret puzzle, never with a member hidden from the viewer -
     * with the standing of whoever solved it on the puzzle's leaderboard of its category (the result detail's)
     */
    private function result(string $timeId, string $playerId, null|string $viewerId): null|PlayerCardResultContext
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('(CASE WHEN pst.team IS NULL THEN pst.player_id END)')
            . $this->hiddenPlayers->sqlExcludeTeam('pst.team');
        $soloBest = $this->getPuzzleResultDetail->soloBestCte('solo_best', '(SELECT focus.puzzle_id FROM focus)');
        $groupBest = $this->getPuzzleResultDetail->groupBestCte('group_best', '(SELECT focus.puzzle_id FROM focus)', '(SELECT focus.puzzling_type FROM focus)');

        $query = <<<SQL
WITH focus AS (
    SELECT pst.id, pst.puzzle_id, pst.player_id, pst.puzzling_type, pst.puzzling_team_id, pst.seconds_to_solve
    FROM puzzle_solving_time pst
        INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE pst.id = :timeId
        AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
        -- The card's player solved it: alone, or in the pair/team
        AND (
            (pst.team IS NULL AND pst.player_id = :playerId)
            OR (pst.team IS NOT NULL AND EXISTS (
                SELECT 1 FROM puzzling_team_member m WHERE m.team_id = pst.puzzling_team_id AND m.player_id = :playerId
            ))
        )
        -- Awaiting verification: only the people in it see it (docs/features/suspicious-times.md)
        AND (
            pst.suspicious = false
            OR pst.player_id = CAST(:viewerId AS UUID)
            OR EXISTS (
                SELECT 1 FROM puzzling_team_member vm WHERE vm.team_id = pst.puzzling_team_id AND vm.player_id = CAST(:viewerId AS UUID)
            )
        )
        {$notHidden}
),
{$soloBest},
{$groupBest}
SELECT
    focus.id AS time_id,
    focus.puzzle_id,
    focus.puzzling_type,
    focus.seconds_to_solve AS time,
    focus.player_id AS tracked_by_id,
    (SELECT json_agg(m.player_id) FROM puzzling_team_member m WHERE m.team_id = focus.puzzling_team_id AND m.player_id IS NOT NULL) AS member_ids,
    CASE WHEN focus.puzzling_team_id IS NULL
        THEN (SELECT solo_best.best_time FROM solo_best WHERE solo_best.subject_id = focus.player_id)
        ELSE (SELECT group_best.best_time FROM group_best WHERE group_best.subject_id = focus.puzzling_team_id)
    END AS subject_best,
    CASE WHEN focus.puzzling_team_id IS NULL
        THEN (SELECT COUNT(*) FROM solo_best)
        ELSE (SELECT COUNT(*) FROM group_best)
    END AS total,
    CASE WHEN focus.puzzling_team_id IS NULL
        THEN (SELECT COUNT(*) FROM solo_best WHERE solo_best.best_time < (SELECT s.best_time FROM solo_best s WHERE s.subject_id = focus.player_id))
        ELSE (SELECT COUNT(*) FROM group_best WHERE group_best.best_time < (SELECT g.best_time FROM group_best g WHERE g.subject_id = focus.puzzling_team_id))
    END AS faster_count,
    CASE WHEN focus.puzzling_team_id IS NULL
        THEN (SELECT solo_best.best_time FROM solo_best WHERE solo_best.subject_id = CAST(:viewerId AS UUID))
    END AS viewer_time
FROM focus
SQL;

        /** @var false|array{time_id: string, puzzle_id: string, puzzling_type: string, time: null|int|string, tracked_by_id: string, member_ids: null|string, subject_best: null|int|string, total: int|string, faster_count: int|string, viewer_time: null|int|string} $row */
        $row = $this->database->executeQuery($query, [
            'timeId' => $timeId,
            'playerId' => $playerId,
            'viewerId' => $viewerId,
            'now' => $this->now(),
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = $row['member_ids'] !== null ? json_decode($row['member_ids'], true) : [];
        $memberIds = [];

        foreach (is_array($decoded) ? $decoded : [] as $memberId) {
            if (is_string($memberId)) {
                $memberIds[] = strtolower($memberId);
            }
        }

        $isOwn = $viewerId === $playerId;
        $isSolo = $row['puzzling_type'] === 'solo';

        return new PlayerCardResultContext(
            timeId: $row['time_id'],
            puzzleId: $row['puzzle_id'],
            puzzlingType: $row['puzzling_type'],
            time: $row['time'] !== null ? (int) $row['time'] : null,
            rank: $row['subject_best'] !== null ? 1 + (int) $row['faster_count'] : null,
            total: (int) $row['total'],
            subjectBestTime: $row['subject_best'] !== null ? (int) $row['subject_best'] : null,
            viewerTime: $isSolo && $isOwn === false && $row['viewer_time'] !== null ? (int) $row['viewer_time'] : null,
            viewerSignedIn: $viewerId !== null,
            trackedByPlayerId: strtolower($row['tracked_by_id']),
            memberPlayerIds: $memberIds,
        );
    }

    /**
     * The player's result in a round of a publicly visible event (never a draft - not even for its team): the official
     * place and result once published, ranked over every ranked entry exactly like the round page
     * (OfficialResultsRanking; GetPublishedRoundResults::sqlShowsOfficialResults()), else their earliest linked time on
     * a puzzle the round no longer keeps secret, once the round started - the round page's unofficial list
     */
    private function round(string $roundId, string $playerId, null|string $viewerId): null|PlayerCardRoundContext
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $showsOfficial = GetPublishedRoundResults::sqlShowsOfficialResults('cr');
        $roundPuzzleHidden = RoundPuzzleReveal::sqlHidden('crp', 'cr');
        $groupNotHidden = $this->hiddenPlayers->sqlExcludeTeam('pst.team');

        $query = <<<SQL
SELECT
    cr.name AS round_name,
    cr.category,
    c.name AS competition_name,
    {$showsOfficial} AS shows_official,
    -- One puzzle in the round, not kept secret: its piece count ("479 / 500 pcs")
    (
        SELECT CASE WHEN COUNT(*) = 1 THEN MIN(p.pieces_count) END
        FROM competition_round_puzzle crp
            INNER JOIN puzzle p ON p.id = crp.puzzle_id
        WHERE crp.round_id = cr.id
            AND (p.hide_until IS NULL OR p.hide_until <= :now::timestamp)
            AND NOT {$roundPuzzleHidden}
    ) AS pieces_count,
    -- Every ranked official result of the round (the ranking needs them all), the player's own marked
    CASE WHEN cr.category = 'solo' THEN (
        SELECT json_agg(json_build_object(
            'seconds', cpr.result_seconds,
            'pieces', cpr.result_pieces_placed,
            'qualified', cpr.qualified_at IS NOT NULL,
            'mine', cp.player_id IS NOT NULL AND cp.player_id = :playerId,
            'members', NULL
        ))
        FROM competition_participant_round cpr
            INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
        WHERE cpr.round_id = cr.id
            AND (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL)
    ) ELSE (
        SELECT json_agg(json_build_object(
            'seconds', ct.result_seconds,
            'pieces', ct.result_pieces_placed,
            'qualified', ct.qualified_at IS NOT NULL,
            'mine', team_members.ids @> jsonb_build_array(CAST(:playerId AS TEXT)),
            'members', CASE WHEN team_members.ids @> jsonb_build_array(CAST(:playerId AS TEXT)) THEN team_members.ids END
        ))
        FROM competition_team ct
            LEFT JOIN LATERAL (
                SELECT COALESCE(jsonb_agg(cp.player_id::text) FILTER (WHERE cp.player_id IS NOT NULL), '[]'::jsonb) AS ids
                FROM competition_participant_round cpr
                    INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
                WHERE cpr.team_id = ct.id
            ) team_members ON true
        WHERE ct.round_id = cr.id
            AND (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL)
    ) END AS official_entries,
    -- Unofficial: the player's earliest linked result in the round, as the round page lists it - once the round started
    (
        SELECT json_build_object('seconds', linked.seconds_to_solve, 'pieces', linked.pieces_placed)
        FROM (
            SELECT pst.seconds_to_solve, pst.pieces_placed
            FROM puzzle_solving_time pst
                INNER JOIN puzzle p ON p.id = pst.puzzle_id
                INNER JOIN competition_round_puzzle crp ON crp.round_id = cr.id AND crp.puzzle_id = pst.puzzle_id
            WHERE pst.competition_round_id = cr.id
                AND cr.starts_at <= :now::timestamp
                AND pst.suspicious = false
                AND (pst.seconds_to_solve IS NOT NULL OR pst.pieces_placed IS NOT NULL)
                AND (p.hide_until IS NULL OR p.hide_until <= :now::timestamp)
                AND NOT {$roundPuzzleHidden}
                AND (
                    (pst.team IS NULL AND pst.player_id = :playerId)
                    OR (pst.team IS NOT NULL AND EXISTS (
                        SELECT 1 FROM puzzling_team_member m WHERE m.team_id = pst.puzzling_team_id AND m.player_id = :playerId
                    ))
                )
                {$groupNotHidden}
            ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
            LIMIT 1
        ) linked
    ) AS linked_result
FROM competition_round cr
    INNER JOIN competition c ON c.id = cr.competition_id
    LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE cr.id = :roundId
    AND {$visible}
SQL;

        /** @var false|array{round_name: string, category: string, competition_name: string, shows_official: bool, pieces_count: null|int|string, official_entries: null|string, linked_result: null|string} $row */
        $row = $this->database->executeQuery($query, [
            'roundId' => $roundId,
            'playerId' => $playerId,
            'now' => $this->now(),
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $piecesCount = $row['pieces_count'] !== null ? (int) $row['pieces_count'] : null;

        if ($row['shows_official'] === true) {
            /** @var list<array{seconds: null|int, pieces: null|int, qualified: bool, mine: bool, members: null|list<string>}> $entries */
            $entries = $row['official_entries'] !== null ? json_decode($row['official_entries'], true, flags: JSON_THROW_ON_ERROR) : [];
            $results = array_map(
                static fn (array $entry): RoundEntryResult => RoundEntryResult::fromColumns($entry['seconds'], $entry['pieces'], false),
                $entries,
            );
            $ranks = OfficialResultsRanking::rank($results);
            $mine = null;

            foreach ($entries as $index => $entry) {
                if ($entry['mine'] !== true || $ranks[$index] === null || $this->hidesTeam($entry['members'] ?? [], $viewerId)) {
                    continue;
                }

                if ($mine === null || $ranks[$index] < $ranks[$mine]) {
                    $mine = $index;
                }
            }

            if ($mine === null) {
                return null;
            }

            return new PlayerCardRoundContext(
                roundName: $row['round_name'],
                competitionName: $row['competition_name'],
                official: true,
                rank: $ranks[$mine],
                result: $results[$mine],
                qualified: $entries[$mine]['qualified'],
                piecesCount: $piecesCount,
            );
        }

        if ($row['linked_result'] === null) {
            return null;
        }

        /** @var array{seconds: null|int, pieces: null|int} $linked */
        $linked = json_decode($row['linked_result'], true, flags: JSON_THROW_ON_ERROR);

        return new PlayerCardRoundContext(
            roundName: $row['round_name'],
            competitionName: $row['competition_name'],
            official: false,
            rank: null,
            result: RoundEntryResult::fromColumns($linked['seconds'], $linked['pieces'], false),
            qualified: false,
            piecesCount: $piecesCount,
        );
    }

    /**
     * The player's place on the board the card was opened from, and the viewer's when they are on it - from the board's
     * own positionsOf(). The progression boards only while progression is visible to the viewer (XpFeatureGate), the
     * Achievement Points board only for a signed-in viewer (the page asks guests to sign in).
     */
    private function board(string $subject, string $playerId, null|PlayerProfile $viewer): null|PlayerCardBoardContext
    {
        $board = PlayerCardBoard::tryFromSubject($subject);

        if ($board === null) {
            return null;
        }

        $viewerId = $viewer !== null ? strtolower($viewer->playerId) : null;
        $ids = $viewerId !== null && $viewerId !== $playerId ? [$playerId, $viewerId] : [$playerId];
        $tab = $board->progressTab();

        if ($tab !== null) {
            if ($this->xpFeatureGate->isVisibleFor($viewer) === false || ($tab->requiresLogin() && $viewer === null)) {
                return null;
            }

            $positions = $this->getXpLeaderboard->positionsOf($tab, $board->country?->name, $ids);
        } elseif ($board->kind === PlayerCardBoard::MSP && $board->piecesCount !== null) {
            $positions = $this->getPlayerRatingRanking->positionsOf($board->piecesCount, $board->country?->name, $ids);
        } elseif ($board->piecesCount !== null) {
            $positions = $this->getFastestPlayers->positionsOf($board->piecesCount, $board->country, $ids);
        } else {
            return null;
        }

        $own = $positions['positions'][$playerId] ?? null;

        if ($own === null) {
            return null;
        }

        $viewerPosition = $viewerId !== null && $viewerId !== $playerId ? ($positions['positions'][$viewerId] ?? null) : null;

        return new PlayerCardBoardContext(
            board: $board,
            rank: $own['rank'],
            total: $positions['total'],
            value: $own['value'],
            viewerRank: $viewerPosition['rank'] ?? null,
        );
    }

    /**
     * A pair/team entry with a member hidden from the viewer is not on the round page for them - unless they are in it
     * (GetPublishedRoundResults::isHiddenFromViewer())
     *
     * @param null|list<string> $memberIds
     */
    private function hidesTeam(null|array $memberIds, null|string $viewerId): bool
    {
        $memberIds = array_map('strtolower', $memberIds ?? []);

        if ($viewerId !== null && in_array($viewerId, $memberIds, true)) {
            return false;
        }

        foreach ($memberIds as $memberId) {
            if ($this->hiddenPlayers->isHidden($memberId)) {
                return true;
            }
        }

        return false;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
