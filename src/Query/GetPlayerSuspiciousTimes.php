<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\FirstTryPuzzle;
use SpeedPuzzling\Web\Results\PlayerSuspiciousTime;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;

/**
 * The player's own results awaiting verification, for "Review your results" (docs/features/suspicious-time-review.md,
 * "Where they see it"): every mark in force this person was told about - by the notice run or by hand (via does not
 * matter here, it is their own data) - including pair/team results they are in and results of a private profile.
 * Plus the moderators' answers to their replies of the last 30 days. Only results the person is still in
 * (sqlStillInTime()): a member an edit took out of the group sees nothing of it any more.
 *
 * Only the reasons a player may read are returned (SuspiciousTimeReasonCode::isShownToPlayer()), and never "another
 * edition" naming a puzzle that is secret or hidden by now (the scan never proposes one, but a puzzle can be hidden
 * after the mark - one more statement only when such a reason is there at all). No player row is read: whoever saved
 * a pair/team result is not named.
 */
readonly final class GetPlayerSuspiciousTimes
{
    public const int ANSWERED_DAYS = 30;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private GetSuspiciousTimeEvidence $getSuspiciousTimeEvidence,
    ) {
    }

    /**
     * True while the notice's person is still one of the time's people: its tracker, or a registered member of its
     * pair/team (puzzling_team_member - the people the notice run tells). Somebody an edit took out of the group keeps
     * the notice row, but the result is not theirs any more: nothing is shown, counted, answered or e-mailed to them.
     */
    public static function sqlStillInTime(string $noticeAlias, string $timeAlias): string
    {
        return "({$timeAlias}.player_id = {$noticeAlias}.player_id OR EXISTS ("
            . "SELECT 1 FROM puzzling_team_member still_member "
            . "WHERE still_member.team_id = {$timeAlias}.puzzling_team_id AND still_member.player_id = {$noticeAlias}.player_id))";
    }

    /**
     * @return list<PlayerSuspiciousTime> the latest mark first
     */
    public function openOf(string $playerId): array
    {
        $stillInTime = self::sqlStillInTime('notice', 'pst');
        $query = $this->select() . <<<SQL
WHERE notice.player_id = :playerId
    AND stc.status = :marked
    AND stc.marked_at = notice.marked_at
    AND pst.suspicious = true
    AND {$stillInTime}
ORDER BY stc.marked_at DESC, stc.id
SQL;

        return $this->fetch($query, [
            'playerId' => $playerId,
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
        ]);
    }

    /**
     * Moderators' answers to "The time is correct" of the last 30 days whose result is not awaiting verification under
     * the same mark any more (a "stays marked" answer is shown on the open card instead).
     *
     * @return list<PlayerSuspiciousTime> the latest answer first
     */
    public function recentlyAnsweredOf(string $playerId): array
    {
        $stillInTime = self::sqlStillInTime('notice', 'pst');
        $query = $this->select() . <<<SQL
WHERE notice.player_id = :playerId
    AND notice.answer IS NOT NULL
    AND notice.answered_at > :since
    AND NOT (stc.status = :marked AND stc.marked_at = notice.marked_at AND pst.suspicious = true)
    AND {$stillInTime}
ORDER BY notice.answered_at DESC, notice.id
SQL;

        return $this->fetch($query, [
            'playerId' => $playerId,
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
            'since' => $this->clock->now()->modify('-' . self::ANSWERED_DAYS . ' days')->format('Y-m-d H:i:s'),
        ]);
    }

    private function select(): string
    {
        return <<<SQL
SELECT
    stc.id AS case_id,
    stc.reasons_shown,
    stc.moderator_note,
    notice.marked_at,
    notice.response,
    notice.response_text,
    notice.answer,
    notice.answer_note,
    notice.answered_at,
    pst.id AS time_id,
    pst.player_id AS tracker_id,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.puzzling_type,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.pieces_count AS puzzle_pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > CAST(:now AS TIMESTAMP) THEN NULL ELSE puzzle.image END AS puzzle_image,
    manufacturer.name AS manufacturer_name
FROM suspicious_time_notice notice
INNER JOIN suspicious_time_case stc ON stc.id = notice.case_id
INNER JOIN puzzle_solving_time pst ON pst.id = stc.time_id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id

SQL;
    }

    /**
     * @param array<string, string> $parameters
     * @return list<PlayerSuspiciousTime>
     */
    private function fetch(string $query, array $parameters): array
    {
        /** @var list<array{case_id: string, reasons_shown: string, moderator_note: null|string, marked_at: string, response: null|string, response_text: null|string, answer: null|string, answer_note: null|string, answered_at: null|string, time_id: string, tracker_id: string, seconds_to_solve: null|int, solved_at: string, puzzling_type: string, puzzle_id: string, puzzle_name: string, puzzle_pieces_count: int, puzzle_image: null|string, manufacturer_name: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            ...$parameters,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $reasonsByCase = [];

        foreach ($rows as $row) {
            /** @var array<mixed> $reasons */
            $reasons = json_decode($row['reasons_shown'], true, flags: JSON_THROW_ON_ERROR);
            $reasonsByCase[$row['case_id']] = array_values(array_filter(
                SuspiciousTimeReason::listFromArray($reasons),
                static fn (SuspiciousTimeReason $reason): bool => $reason->code->isShownToPlayer(),
            ));
        }

        $reasonsByCase = $this->getSuspiciousTimeEvidence->withoutUnnameable($reasonsByCase);

        return array_map(static function (array $row) use ($reasonsByCase): PlayerSuspiciousTime {
            return new PlayerSuspiciousTime(
                caseId: $row['case_id'],
                timeId: $row['time_id'],
                trackerId: $row['tracker_id'],
                puzzle: new FirstTryPuzzle(
                    puzzleId: $row['puzzle_id'],
                    name: $row['puzzle_name'],
                    manufacturerName: $row['manufacturer_name'],
                    piecesCount: $row['puzzle_pieces_count'],
                    image: $row['puzzle_image'],
                ),
                secondsToSolve: $row['seconds_to_solve'],
                solvedAt: new DateTimeImmutable($row['solved_at']),
                puzzlingType: PuzzlingType::from($row['puzzling_type']),
                reasonsShown: $reasonsByCase[$row['case_id']] ?? [],
                moderatorNote: $row['moderator_note'],
                markedAt: new DateTimeImmutable($row['marked_at']),
                response: $row['response'] !== null ? SuspiciousTimeResponse::from($row['response']) : null,
                responseText: $row['response_text'],
                answer: $row['answer'] !== null ? SuspiciousTimeReplyAnswer::from($row['answer']) : null,
                answerNote: $row['answer_note'],
                answeredAt: $row['answered_at'] !== null ? new DateTimeImmutable($row['answered_at']) : null,
            );
        }, $rows);
    }
}
