<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\SuspiciousTimeCaseCard;
use SpeedPuzzling\Web\Results\SuspiciousTimeCaseNoticeRow;
use SpeedPuzzling\Web\Results\SuspiciousTimeSameDayResult;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseOrigin;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * What the cards of one page of the time verification queue show (docs/features/suspicious-time-review.md,
 * "Moderator queue"), for the case ids GetSuspiciousTimeQueue picked: a constant number of statements whatever the
 * page holds - the cases, the players' numbers, their baselines, other results of the same day, the notices and the
 * people of pair/team results.
 *
 * Admin / moderator tooling: every queued time is shown, a private player's included - and only their queued times
 * (Jan, 2026-10-07). The leaderboard place is counted for these cases only, and not for slow times: the number of
 * other people (pairs, teams) with a faster unflagged time of the same kind on the puzzle, plus one.
 */
readonly final class GetSuspiciousTimeCaseDetail
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private GetSuspiciousTimeEvidence $getSuspiciousTimeEvidence,
    ) {
    }

    /**
     * @param list<string> $caseIds
     * @return list<SuspiciousTimeCaseCard> in the order of $caseIds
     */
    public function cards(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $rows = $this->caseRows($caseIds);

        if ($rows === []) {
            return [];
        }

        $playerIds = array_values(array_unique(array_column($rows, 'player_id')));
        $playerResults = $this->playerResults($playerIds);
        $baselines = $this->baselines($playerIds);
        $sameDay = $this->sameDayResults($caseIds);
        $notices = $this->notices($caseIds);
        $groupMembers = $this->groupMembers(array_values(array_map(
            static fn (array $row): string => $row['time_id'],
            array_filter($rows, static fn (array $row): bool => $row['puzzling_team_id'] !== null),
        )));

        // Never naming a puzzle that became secret or hidden after the reason was found (one statement, only then)
        $reasons = [];

        foreach ($rows as $row) {
            $reasons['found:' . $row['case_id']] = self::reasons($row['reasons']);
            $reasons['shown:' . $row['case_id']] = self::reasons($row['reasons_shown']);
        }

        $reasons = $this->getSuspiciousTimeEvidence->withoutUnnameable($reasons);

        $cards = [];

        foreach ($rows as $row) {
            $puzzlingType = PuzzlingType::from($row['puzzling_type']);
            [$puzzleResults, $puzzleMedian, $puzzleFastest] = match ($puzzlingType) {
                PuzzlingType::Solo => [$row['solved_times_solo_count'], $row['median_time_solo'], $row['fastest_time_solo']],
                PuzzlingType::Duo => [$row['solved_times_duo_count'], $row['median_time_duo'], $row['fastest_time_duo']],
                PuzzlingType::Team => [$row['solved_times_team_count'], $row['median_time_team'], $row['fastest_time_team']],
            };

            $cards[$row['case_id']] = new SuspiciousTimeCaseCard(
                caseId: $row['case_id'],
                status: SuspiciousTimeCaseStatus::from($row['status']),
                origin: SuspiciousTimeCaseOrigin::from($row['origin']),
                direction: $row['direction'] !== null ? SuspicionDirection::from($row['direction']) : null,
                tier: $row['tier'] !== null ? SuspiciousTimeTier::from($row['tier']) : null,
                score: $row['score'] !== null ? (float) $row['score'] : null,
                reasons: $reasons['found:' . $row['case_id']] ?? [],
                expectedSeconds: $row['expected_seconds'],
                expectedSource: $row['expected_source'] !== null ? ExpectedTimeSource::from($row['expected_source']) : null,
                detectorVersion: $row['detector_version'],
                caseFingerprint: $row['fingerprint'],
                currentFingerprint: $row['current_fingerprint'],
                detectedAt: new DateTimeImmutable($row['detected_at']),
                decidedAt: self::moment($row['decided_at']),
                decidedByName: $row['decided_by_name'],
                decidedByCode: $row['decided_by_code'],
                reasonsShown: $reasons['shown:' . $row['case_id']] ?? [],
                moderatorNote: $row['moderator_note'],
                markedAt: self::moment($row['marked_at']),
                playerEditedAt: self::moment($row['player_edited_at']),
                timeId: $row['time_id'],
                seconds: $row['seconds_to_solve'],
                solvedAt: new DateTimeImmutable($row['finished_at'] ?? $row['tracked_at']),
                trackedAt: new DateTimeImmutable($row['tracked_at']),
                puzzlingType: $puzzlingType,
                puzzlersCount: $row['puzzlers_count'],
                comment: $row['comment'],
                finishedPuzzlePhoto: $row['finished_puzzle_photo'],
                firstAttempt: $row['first_attempt'],
                unboxed: $row['unboxed'],
                flagged: $row['suspicious'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: $row['pieces_count'],
                puzzleImage: $row['puzzle_image'],
                manufacturerName: $row['manufacturer_name'],
                puzzleResults: $puzzleResults ?? 0,
                puzzleMedian: $puzzleMedian,
                puzzleFastest: $puzzleFastest,
                difficultyScore: $row['difficulty_score'] !== null ? (float) $row['difficulty_score'] : null,
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                playerPrivate: $row['is_private'],
                competitionName: $row['competition_name'],
                roundName: $row['round_name'],
                leaderboardPlace: $row['ahead'] !== null ? (int) $row['ahead'] + 1 : null,
                soloResults: $playerResults[$row['player_id']]['solo'] ?? 0,
                groupResults: $playerResults[$row['player_id']]['group'] ?? 0,
                groupMembers: $groupMembers[$row['time_id']] ?? [],
                baselines: $baselines[$row['player_id']] ?? [],
                sameDay: $sameDay[$row['case_id']] ?? [],
                notices: $notices[$row['case_id']] ?? [],
            );
        }

        $ordered = [];

        foreach ($caseIds as $caseId) {
            if (isset($cards[$caseId])) {
                $ordered[] = $cards[$caseId];
            }
        }

        return $ordered;
    }

    /**
     * @param list<string> $caseIds
     * @return list<array{case_id: string, status: string, origin: string, direction: null|string, tier: null|string, score: null|float|string, reasons: string, expected_seconds: null|int, expected_source: null|string, detector_version: null|int, fingerprint: string, current_fingerprint: string, detected_at: string, decided_at: null|string, decided_by_name: null|string, decided_by_code: null|string, reasons_shown: string, moderator_note: null|string, marked_at: null|string, player_edited_at: null|string, time_id: string, seconds_to_solve: null|int, finished_at: null|string, tracked_at: string, puzzling_type: string, puzzlers_count: int, puzzling_team_id: null|string, comment: null|string, finished_puzzle_photo: null|string, first_attempt: bool, unboxed: bool, suspicious: bool, puzzle_id: string, puzzle_name: string, pieces_count: int, puzzle_image: null|string, manufacturer_name: null|string, solved_times_solo_count: null|int, median_time_solo: null|int, fastest_time_solo: null|int, solved_times_duo_count: null|int, median_time_duo: null|int, fastest_time_duo: null|int, solved_times_team_count: null|int, median_time_team: null|int, fastest_time_team: null|int, difficulty_score: null|float|string, player_id: string, player_name: null|string, player_code: string, is_private: bool, competition_name: null|string, round_name: null|string, ahead: null|int|string}>
     */
    private function caseRows(array $caseIds): array
    {
        $fingerprint = SuspicionFingerprint::sql('pst', 'p');
        $query = <<<SQL
SELECT
    c.id AS case_id, c.status, c.origin, c.direction, c.tier, c.score, c.reasons, c.expected_seconds, c.expected_source,
    c.detector_version, c.fingerprint, {$fingerprint} AS current_fingerprint, c.detected_at, c.decided_at,
    decider.name AS decided_by_name, decider.code AS decided_by_code, c.reasons_shown, c.moderator_note, c.marked_at,
    c.player_edited_at,
    pst.id AS time_id, pst.seconds_to_solve, pst.finished_at, pst.tracked_at, pst.puzzling_type, pst.puzzlers_count,
    pst.puzzling_team_id, pst.comment, pst.finished_puzzle_photo, pst.first_attempt, pst.unboxed, pst.suspicious,
    p.id AS puzzle_id, p.name AS puzzle_name, p.pieces_count, p.image AS puzzle_image, m.name AS manufacturer_name,
    ps.solved_times_solo_count, ps.median_time_solo, ps.fastest_time_solo,
    ps.solved_times_duo_count, ps.median_time_duo, ps.fastest_time_duo,
    ps.solved_times_team_count, ps.median_time_team, ps.fastest_time_team,
    pd.difficulty_score,
    pl.id AS player_id, pl.name AS player_name, pl.code AS player_code, pl.is_private,
    -- A series-level time (a series pick without an edition) names its series
    COALESCE(comp.name, pick_series.name) AS competition_name, cr.name AS round_name,
    CASE WHEN c.direction IS DISTINCT FROM :slow THEN board.ahead END AS ahead
FROM suspicious_time_case c
JOIN puzzle_solving_time pst ON pst.id = c.time_id
JOIN puzzle p ON p.id = pst.puzzle_id
JOIN player pl ON pl.id = pst.player_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
LEFT JOIN player decider ON decider.id = c.decided_by_id
LEFT JOIN competition comp ON comp.id = pst.competition_id
LEFT JOIN competition_series pick_series ON pick_series.id = pst.competition_series_id
LEFT JOIN competition_round cr ON cr.id = pst.competition_round_id
LEFT JOIN LATERAL (
    SELECT COUNT(DISTINCT COALESCE(o.puzzling_team_id, o.player_id)) AS ahead
    FROM puzzle_solving_time o
    WHERE o.puzzle_id = pst.puzzle_id
        AND o.puzzling_type = pst.puzzling_type
        AND o.suspicious = false
        AND o.seconds_to_solve < pst.seconds_to_solve
        AND COALESCE(o.puzzling_team_id, o.player_id) <> COALESCE(pst.puzzling_team_id, pst.player_id)
        -- A slow time's place says nothing - and would count nearly every result of the puzzle
        AND c.direction IS DISTINCT FROM :slow
) board ON pst.seconds_to_solve IS NOT NULL
WHERE c.id IN (:caseIds)
SQL;

        /** @var list<array{case_id: string, status: string, origin: string, direction: null|string, tier: null|string, score: null|float|string, reasons: string, expected_seconds: null|int, expected_source: null|string, detector_version: null|int, fingerprint: string, current_fingerprint: string, detected_at: string, decided_at: null|string, decided_by_name: null|string, decided_by_code: null|string, reasons_shown: string, moderator_note: null|string, marked_at: null|string, player_edited_at: null|string, time_id: string, seconds_to_solve: null|int, finished_at: null|string, tracked_at: string, puzzling_type: string, puzzlers_count: int, puzzling_team_id: null|string, comment: null|string, finished_puzzle_photo: null|string, first_attempt: bool, unboxed: bool, suspicious: bool, puzzle_id: string, puzzle_name: string, pieces_count: int, puzzle_image: null|string, manufacturer_name: null|string, solved_times_solo_count: null|int, median_time_solo: null|int, fastest_time_solo: null|int, solved_times_duo_count: null|int, median_time_duo: null|int, fastest_time_duo: null|int, solved_times_team_count: null|int, median_time_team: null|int, fastest_time_team: null|int, difficulty_score: null|float|string, player_id: string, player_name: null|string, player_code: string, is_private: bool, competition_name: null|string, round_name: null|string, ahead: null|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['caseIds' => $caseIds, 'slow' => SuspicionDirection::Slow->value],
            ['caseIds' => ArrayParameterType::STRING],
        );

        return $rows;
    }

    /**
     * Every result of the players - solo ones they saved, and pair/team results they are a registered member of.
     *
     * @param list<string> $playerIds
     * @return array<string, array{solo: int, group: int}>
     */
    private function playerResults(array $playerIds): array
    {
        $query = <<<SQL
SELECT
    pl.id AS player_id,
    (SELECT COUNT(*) FROM puzzle_solving_time s WHERE s.player_id = pl.id AND s.puzzling_type = :solo) AS solo,
    (
        SELECT COUNT(*)
        FROM puzzling_team_member ptm
        JOIN puzzle_solving_time g ON g.puzzling_team_id = ptm.team_id
        WHERE ptm.player_id = pl.id
    ) AS "group"
FROM player pl
WHERE pl.id IN (:playerIds)
SQL;

        /** @var list<array{player_id: string, solo: int|string, group: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['playerIds' => $playerIds, 'solo' => PuzzlingType::Solo->value],
            ['playerIds' => ArrayParameterType::STRING],
        );

        $results = [];

        foreach ($rows as $row) {
            $results[$row['player_id']] = ['solo' => (int) $row['solo'], 'group' => (int) $row['group']];
        }

        return $results;
    }

    /**
     * @param list<string> $playerIds
     * @return array<string, list<array{pieces: int, seconds: int, type: string, solves: int}>>
     */
    private function baselines(array $playerIds): array
    {
        /** @var list<array{player_id: string, pieces_count: int, baseline_seconds: int, baseline_type: string, qualifying_solves_count: int}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT player_id, pieces_count, baseline_seconds, baseline_type, qualifying_solves_count FROM player_baseline WHERE player_id IN (:playerIds) ORDER BY pieces_count',
            ['playerIds' => $playerIds],
            ['playerIds' => ArrayParameterType::STRING],
        );

        $baselines = [];

        foreach ($rows as $row) {
            $baselines[$row['player_id']][] = [
                'pieces' => $row['pieces_count'],
                'seconds' => $row['baseline_seconds'],
                'type' => $row['baseline_type'],
                'solves' => $row['qualifying_solves_count'],
            ];
        }

        return $baselines;
    }

    /**
     * Other results the tracker saved for the day of the queued time (the day solved, else the day saved) - never one
     * of a puzzle a competition keeps secret (PuzzleSecrecy), like in every moderator queue.
     *
     * @param list<string> $caseIds
     * @return array<string, list<SuspiciousTimeSameDayResult>>
     */
    private function sameDayResults(array $caseIds): array
    {
        $notSecret = PuzzleSecrecy::sqlNotSecret('p');
        $query = <<<SQL
SELECT
    c.id AS case_id, o.id AS time_id, o.puzzle_id, p.name AS puzzle_name, p.pieces_count, o.seconds_to_solve,
    o.puzzling_type, o.suspicious
FROM suspicious_time_case c
JOIN puzzle_solving_time t ON t.id = c.time_id
JOIN puzzle_solving_time o ON o.player_id = t.player_id AND o.id <> t.id
JOIN puzzle p ON p.id = o.puzzle_id
WHERE c.id IN (:caseIds)
    AND CAST(COALESCE(o.finished_at, o.tracked_at) AS date) = CAST(COALESCE(t.finished_at, t.tracked_at) AS date)
    AND {$notSecret}
ORDER BY o.tracked_at, o.id
SQL;

        /** @var list<array{case_id: string, time_id: string, puzzle_id: string, puzzle_name: string, pieces_count: int, seconds_to_solve: null|int, puzzling_type: string, suspicious: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['caseIds' => $caseIds, 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['caseIds' => ArrayParameterType::STRING],
        );

        $results = [];

        foreach ($rows as $row) {
            $results[$row['case_id']][] = new SuspiciousTimeSameDayResult(
                timeId: $row['time_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: $row['pieces_count'],
                seconds: $row['seconds_to_solve'],
                puzzlingType: PuzzlingType::from($row['puzzling_type']),
                flagged: $row['suspicious'],
            );
        }

        return $results;
    }

    /**
     * @param list<string> $caseIds
     * @return array<string, list<SuspiciousTimeCaseNoticeRow>>
     */
    private function notices(array $caseIds): array
    {
        $query = <<<SQL
SELECT
    n.case_id, n.player_id, pl.name AS player_name, pl.code AS player_code, n.marked_at, n.notified_at, n.via,
    n.response, n.response_text, n.responded_at, n.answer, n.answer_note, n.answered_at
FROM suspicious_time_notice n
JOIN player pl ON pl.id = n.player_id
WHERE n.case_id IN (:caseIds)
ORDER BY n.notified_at, n.id
SQL;

        /** @var list<array{case_id: string, player_id: string, player_name: null|string, player_code: string, marked_at: string, notified_at: string, via: string, response: null|string, response_text: null|string, responded_at: null|string, answer: null|string, answer_note: null|string, answered_at: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['caseIds' => $caseIds], ['caseIds' => ArrayParameterType::STRING]);

        $notices = [];

        foreach ($rows as $row) {
            $notices[$row['case_id']][] = new SuspiciousTimeCaseNoticeRow(
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                markedAt: new DateTimeImmutable($row['marked_at']),
                notifiedAt: new DateTimeImmutable($row['notified_at']),
                via: SuspiciousTimeNoticeVia::from($row['via']),
                response: $row['response'] !== null ? SuspiciousTimeResponse::from($row['response']) : null,
                responseText: $row['response_text'],
                respondedAt: self::moment($row['responded_at']),
                answer: $row['answer'] !== null ? SuspiciousTimeReplyAnswer::from($row['answer']) : null,
                answerNote: $row['answer_note'],
                answeredAt: self::moment($row['answered_at']),
            );
        }

        return $notices;
    }

    /**
     * The people of the pair/team results among the cases - registered by name or code, guests by the name given.
     *
     * @param list<string> $timeIds
     * @return array<string, list<string>>
     */
    private function groupMembers(array $timeIds): array
    {
        if ($timeIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT pst.id AS time_id, COALESCE(pl.name, '#' || pl.code, ptm.guest_name) AS member
FROM puzzle_solving_time pst
JOIN puzzling_team_member ptm ON ptm.team_id = pst.puzzling_team_id
LEFT JOIN player pl ON pl.id = ptm.player_id
WHERE pst.id IN (:timeIds)
ORDER BY ptm.position
SQL;

        /** @var list<array{time_id: string, member: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, ['timeIds' => $timeIds], ['timeIds' => ArrayParameterType::STRING]);

        $members = [];

        foreach ($rows as $row) {
            $members[$row['time_id']][] = $row['member'] ?? '?';
        }

        return $members;
    }

    /**
     * @return list<SuspiciousTimeReason>
     */
    private static function reasons(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? SuspiciousTimeReason::listFromArray($decoded) : [];
    }

    private static function moment(null|string $value): null|DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }
}
