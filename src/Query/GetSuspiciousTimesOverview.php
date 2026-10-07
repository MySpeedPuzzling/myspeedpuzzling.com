<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\SuspiciousTimeDecisionLogItem;
use SpeedPuzzling\Web\Results\SuspiciousTimesOverview;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;

/**
 * The "Numbers" and "Decision log" tabs of the time verification queue (docs/features/suspicious-time-review.md,
 * "Moderator queue"). Aggregates over the case and notice tables; the log reads the append-only
 * suspicious_time_decision, whose snapshot outlives the time, the puzzle and the people. Admin / moderator tooling.
 */
readonly final class GetSuspiciousTimesOverview
{
    public const int LOG_PER_PAGE = 30;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private GetSuspiciousTimeEvidence $getSuspiciousTimeEvidence,
    ) {
    }

    public function numbers(): SuspiciousTimesOverview
    {
        return new SuspiciousTimesOverview(
            byVersion: $this->byVersion(),
            byReason: $this->byReason(),
            players: $this->players(),
            decisions: $this->decisions(),
        );
    }

    /**
     * Newest first. A decision about a puzzle a competition keeps secret (PuzzleSecrecy) shows neither the puzzle's
     * name and piece count nor what the player reads until the reveal - like every moderator queue, which leaves such
     * puzzles out; an "another edition" whose puzzle became secret or hidden since is left out of the reasons.
     *
     * @return list<SuspiciousTimeDecisionLogItem>
     */
    public function log(int $page): array
    {
        $secret = PuzzleSecrecy::sqlSecret('p');
        $query = <<<SQL
SELECT
    d.id, d.decision, d.decided_at, d.puzzle_id, d.time_id, d.tracker_id, d.reasons_shown, d.note, d.snapshot,
    d.decided_by_id, d.decided_by_name, d.decided_by_code,
    tracker.name AS tracker_name, tracker.code AS tracker_code,
    EXISTS (SELECT 1 FROM puzzle p WHERE p.id = d.puzzle_id) AS puzzle_exists,
    EXISTS (SELECT 1 FROM puzzle p WHERE p.id = d.puzzle_id AND {$secret}) AS puzzle_secret,
    d.time_id IS NOT NULL AND EXISTS (SELECT 1 FROM puzzle_solving_time t WHERE t.id = d.time_id) AS time_exists
FROM suspicious_time_decision d
LEFT JOIN player tracker ON tracker.id = d.tracker_id
ORDER BY d.decided_at DESC, d.id DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{id: string, decision: string, decided_at: string, puzzle_id: string, time_id: null|string, tracker_id: null|string, reasons_shown: string, note: null|string, snapshot: string, decided_by_id: null|string, decided_by_name: null|string, decided_by_code: null|string, tracker_name: null|string, tracker_code: null|string, puzzle_exists: bool, puzzle_secret: bool, time_exists: bool}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'limit' => self::LOG_PER_PAGE,
            'offset' => (max(1, $page) - 1) * self::LOG_PER_PAGE,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $reasonsShown = [];

        foreach ($rows as $row) {
            /** @var array<mixed> $decoded */
            $decoded = json_decode($row['reasons_shown'], true, flags: JSON_THROW_ON_ERROR);
            $reasonsShown[$row['id']] = $row['puzzle_secret'] ? [] : SuspiciousTimeReason::listFromArray($decoded);
        }

        $reasonsShown = $this->getSuspiciousTimeEvidence->withoutUnnameable($reasonsShown);

        return array_map(static function (array $row) use ($reasonsShown): SuspiciousTimeDecisionLogItem {
            /** @var array<string, mixed> $snapshot */
            $snapshot = json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $secret = $row['puzzle_secret'];

            return new SuspiciousTimeDecisionLogItem(
                decisionId: $row['id'],
                decision: SuspiciousTimeDecisionKind::from($row['decision']),
                decidedAt: new DateTimeImmutable($row['decided_at']),
                puzzleId: $row['puzzle_id'],
                puzzleName: !$secret && is_string($snapshot['puzzle_name'] ?? null) ? $snapshot['puzzle_name'] : null,
                piecesCount: !$secret && is_int($snapshot['pieces'] ?? null) ? $snapshot['pieces'] : null,
                puzzleSecret: $secret,
                puzzleExists: $row['puzzle_exists'],
                timeId: $row['time_id'],
                timeExists: $row['time_exists'],
                seconds: is_int($snapshot['seconds'] ?? null) ? $snapshot['seconds'] : null,
                expectedSeconds: is_int($snapshot['expected_seconds'] ?? null) ? $snapshot['expected_seconds'] : null,
                trackerId: $row['tracker_id'],
                trackerName: $row['tracker_name'],
                trackerCode: $row['tracker_code'],
                reasonsShown: $reasonsShown[$row['id']] ?? [],
                note: $row['note'],
                decidedById: $row['decided_by_id'],
                decidedByName: $row['decided_by_name'],
                decidedByCode: $row['decided_by_code'],
                slowThreshold: is_int($snapshot['slow_threshold'] ?? null) || is_float($snapshot['slow_threshold'] ?? null) ? (float) $snapshot['slow_threshold'] : null,
            );
        }, $rows);
    }

    /**
     * @return list<array{version: null|int, direction: null|string, raised: int, pending: int, marked: int, trusted: int, corrected: int, gone: int}>
     */
    private function byVersion(): array
    {
        $query = <<<SQL
SELECT
    detector_version AS version,
    direction,
    COUNT(*) AS raised,
    COUNT(*) FILTER (WHERE status = :pending) AS pending,
    COUNT(*) FILTER (WHERE status = :marked) AS marked,
    COUNT(*) FILTER (WHERE status = :trusted) AS trusted,
    COUNT(*) FILTER (WHERE status = :corrected) AS corrected,
    COUNT(*) FILTER (WHERE status = :gone) AS gone
FROM suspicious_time_case
GROUP BY detector_version, direction
ORDER BY detector_version DESC NULLS LAST, direction NULLS LAST
SQL;

        /** @var list<array{version: null|int, direction: null|string, raised: int|string, pending: int|string, marked: int|string, trusted: int|string, corrected: int|string, gone: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, self::statusParameters());

        return array_map(static fn (array $row): array => [
            'version' => $row['version'],
            'direction' => $row['direction'],
            'raised' => (int) $row['raised'],
            'pending' => (int) $row['pending'],
            'marked' => (int) $row['marked'],
            'trusted' => (int) $row['trusted'],
            'corrected' => (int) $row['corrected'],
            'gone' => (int) $row['gone'],
        ], $rows);
    }

    /**
     * Every reason the scan gave, with what became of the cases carrying it.
     *
     * @return list<array{code: string, trigger: bool, shown_to_player: bool, raised: int, pending: int, marked: int, trusted: int, precision: null|int}>
     */
    private function byReason(): array
    {
        $query = <<<SQL
SELECT
    reason->>'code' AS code,
    COUNT(*) AS raised,
    COUNT(*) FILTER (WHERE c.status = :pending) AS pending,
    COUNT(*) FILTER (WHERE c.status IN (:marked, :corrected)) AS marked,
    COUNT(*) FILTER (WHERE c.status = :trusted) AS trusted
FROM suspicious_time_case c
CROSS JOIN LATERAL jsonb_array_elements(c.reasons) AS reason
GROUP BY reason->>'code'
SQL;

        /** @var list<array{code: string, raised: int|string, pending: int|string, marked: int|string, trusted: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, self::statusParameters());

        $byCode = [];

        foreach ($rows as $row) {
            $byCode[$row['code']] = $row;
        }

        $reasons = [];

        // In the enum's order: the triggers first, then the explanations and hints
        foreach (SuspiciousTimeReasonCode::cases() as $code) {
            $row = $byCode[$code->value] ?? null;
            $marked = (int) ($row['marked'] ?? 0);
            $trusted = (int) ($row['trusted'] ?? 0);

            $reasons[] = [
                'code' => $code->value,
                'trigger' => $code->isTrigger(),
                'shown_to_player' => $code->isShownToPlayer(),
                'raised' => (int) ($row['raised'] ?? 0),
                'pending' => (int) ($row['pending'] ?? 0),
                'marked' => $marked,
                'trusted' => $trusted,
                'precision' => $marked + $trusted > 0 ? (int) round($marked / ($marked + $trusted) * 100) : null,
            ];
        }

        return $reasons;
    }

    /**
     * What the people told about a mark did, by how they were told.
     *
     * @return list<array{via: string, notices: int, fixed: int, says_correct: int, left_as_is: int, no_reaction: int, answered_trusted: int, answered_kept: int}>
     */
    private function players(): array
    {
        $query = <<<SQL
SELECT
    via,
    COUNT(*) AS notices,
    COUNT(*) FILTER (WHERE response = :fixed) AS fixed,
    COUNT(*) FILTER (WHERE response = :saysCorrect) AS says_correct,
    COUNT(*) FILTER (WHERE response = :leftAsIs) AS left_as_is,
    COUNT(*) FILTER (WHERE response IS NULL) AS no_reaction,
    COUNT(*) FILTER (WHERE answer = :answeredTrusted) AS answered_trusted,
    COUNT(*) FILTER (WHERE answer = :answeredKept) AS answered_kept
FROM suspicious_time_notice
GROUP BY via
ORDER BY via
SQL;

        /** @var list<array{via: string, notices: int|string, fixed: int|string, says_correct: int|string, left_as_is: int|string, no_reaction: int|string, answered_trusted: int|string, answered_kept: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'fixed' => SuspiciousTimeResponse::Fixed->value,
            'saysCorrect' => SuspiciousTimeResponse::SaysCorrect->value,
            'leftAsIs' => SuspiciousTimeResponse::LeftAsIs->value,
            'answeredTrusted' => SuspiciousTimeReplyAnswer::Trusted->value,
            'answeredKept' => SuspiciousTimeReplyAnswer::Kept->value,
        ]);

        return array_map(static fn (array $row): array => [
            'via' => $row['via'],
            'notices' => (int) $row['notices'],
            'fixed' => (int) $row['fixed'],
            'says_correct' => (int) $row['says_correct'],
            'left_as_is' => (int) $row['left_as_is'],
            'no_reaction' => (int) $row['no_reaction'],
            'answered_trusted' => (int) $row['answered_trusted'],
            'answered_kept' => (int) $row['answered_kept'],
        ], $rows);
    }

    /**
     * @return array<string, int> every decision kind, also those never made
     */
    private function decisions(): array
    {
        /** @var list<array{decision: string, decisions: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT decision, COUNT(*) AS decisions FROM suspicious_time_decision GROUP BY decision',
        );

        $counts = [];

        foreach (SuspiciousTimeDecisionKind::cases() as $kind) {
            $counts[$kind->value] = 0;
        }

        foreach ($rows as $row) {
            $counts[$row['decision']] = (int) $row['decisions'];
        }

        return $counts;
    }

    /**
     * @return array<string, string>
     */
    private static function statusParameters(): array
    {
        return [
            'pending' => SuspiciousTimeCaseStatus::Pending->value,
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
            'trusted' => SuspiciousTimeCaseStatus::Trusted->value,
            'corrected' => SuspiciousTimeCaseStatus::Corrected->value,
            'gone' => SuspiciousTimeCaseStatus::Gone->value,
        ];
    }
}
