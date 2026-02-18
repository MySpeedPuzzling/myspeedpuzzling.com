<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\ConversationReportNotFound;
use SpeedPuzzling\Web\Results\ReportDetail;
use SpeedPuzzling\Web\Results\ReportOverview;
use SpeedPuzzling\Web\Value\ReportStatus;

readonly final class GetReports
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<ReportOverview>
     */
    public function pending(): array
    {
        $query = <<<SQL
SELECT
    cr.id AS report_id,
    reporter.name AS reporter_name,
    reporter.code AS reporter_code,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN rp.name
        ELSE ip.name
    END AS reported_player_name,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN rp.code
        ELSE ip.code
    END AS reported_player_code,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN c.recipient_id
        ELSE c.initiator_id
    END AS reported_player_id,
    cr.conversation_id,
    cr.reason,
    cr.status,
    cr.reported_at,
    p.name AS puzzle_name
FROM conversation_report cr
JOIN player reporter ON cr.reporter_id = reporter.id
JOIN conversation c ON cr.conversation_id = c.id
JOIN player ip ON c.initiator_id = ip.id
JOIN player rp ON c.recipient_id = rp.id
LEFT JOIN puzzle p ON c.puzzle_id = p.id
WHERE cr.status = :status
ORDER BY cr.reported_at ASC
SQL;

        $data = $this->database
            ->executeQuery($query, ['status' => ReportStatus::Pending->value])
            ->fetchAllAssociative();

        return array_map(static fn(array $row): ReportOverview => self::mapToOverview($row), $data);
    }

    /**
     * @return array<ReportOverview>
     */
    public function all(int $limit = 50, int $offset = 0): array
    {
        $query = <<<SQL
SELECT
    cr.id AS report_id,
    reporter.name AS reporter_name,
    reporter.code AS reporter_code,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN rp.name
        ELSE ip.name
    END AS reported_player_name,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN rp.code
        ELSE ip.code
    END AS reported_player_code,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN c.recipient_id
        ELSE c.initiator_id
    END AS reported_player_id,
    cr.conversation_id,
    cr.reason,
    cr.status,
    cr.reported_at,
    p.name AS puzzle_name
FROM conversation_report cr
JOIN player reporter ON cr.reporter_id = reporter.id
JOIN conversation c ON cr.conversation_id = c.id
JOIN player ip ON c.initiator_id = ip.id
JOIN player rp ON c.recipient_id = rp.id
LEFT JOIN puzzle p ON c.puzzle_id = p.id
ORDER BY cr.reported_at DESC
LIMIT :limit OFFSET :offset
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return array_map(static fn(array $row): ReportOverview => self::mapToOverview($row), $data);
    }

    /**
     * @throws ConversationReportNotFound
     */
    public function byId(string $reportId): ReportDetail
    {
        $query = <<<SQL
SELECT
    cr.id AS report_id,
    reporter.name AS reporter_name,
    reporter.code AS reporter_code,
    cr.reporter_id,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN rp.name
        ELSE ip.name
    END AS reported_player_name,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN rp.code
        ELSE ip.code
    END AS reported_player_code,
    CASE
        WHEN c.initiator_id = cr.reporter_id THEN c.recipient_id
        ELSE c.initiator_id
    END AS reported_player_id,
    cr.conversation_id,
    cr.reason,
    cr.status,
    cr.reported_at,
    cr.resolved_at,
    resolved_by.name AS resolved_by_name,
    cr.admin_note,
    p.name AS puzzle_name
FROM conversation_report cr
JOIN player reporter ON cr.reporter_id = reporter.id
JOIN conversation c ON cr.conversation_id = c.id
JOIN player ip ON c.initiator_id = ip.id
JOIN player rp ON c.recipient_id = rp.id
LEFT JOIN player resolved_by ON cr.resolved_by_id = resolved_by.id
LEFT JOIN puzzle p ON c.puzzle_id = p.id
WHERE cr.id = :reportId
SQL;

        $row = $this->database
            ->executeQuery($query, ['reportId' => $reportId])
            ->fetchAssociative();

        if ($row === false) {
            throw new ConversationReportNotFound();
        }

        /** @var array{
         *     report_id: string,
         *     reporter_name: null|string,
         *     reporter_code: string,
         *     reporter_id: string,
         *     reported_player_name: null|string,
         *     reported_player_code: string,
         *     reported_player_id: string,
         *     conversation_id: string,
         *     reason: string,
         *     status: string,
         *     reported_at: string,
         *     resolved_at: null|string,
         *     resolved_by_name: null|string,
         *     admin_note: null|string,
         *     puzzle_name: null|string,
         * } $row
         */

        return new ReportDetail(
            reportId: $row['report_id'],
            reporterName: $row['reporter_name'] ?? $row['reporter_code'],
            reporterCode: $row['reporter_code'],
            reporterId: $row['reporter_id'],
            reportedPlayerName: $row['reported_player_name'] ?? $row['reported_player_code'],
            reportedPlayerCode: $row['reported_player_code'],
            reportedPlayerId: $row['reported_player_id'],
            conversationId: $row['conversation_id'],
            reason: $row['reason'],
            status: ReportStatus::from($row['status']),
            reportedAt: new DateTimeImmutable($row['reported_at']),
            puzzleName: $row['puzzle_name'],
            resolvedAt: $row['resolved_at'] !== null ? new DateTimeImmutable($row['resolved_at']) : null,
            resolvedByName: $row['resolved_by_name'],
            adminNote: $row['admin_note'],
        );
    }

    /**
     * @return array{pending: int, all: int}
     */
    public function countByStatus(): array
    {
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE status = :pending) AS pending,
    COUNT(*) AS all_count
FROM conversation_report
SQL;

        /** @var array{pending: int|string, all_count: int|string}|false $row */
        $row = $this->database
            ->executeQuery($query, ['pending' => ReportStatus::Pending->value])
            ->fetchAssociative();

        return [
            'pending' => (int) ($row !== false ? $row['pending'] : 0),
            'all' => (int) ($row !== false ? $row['all_count'] : 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapToOverview(array $row): ReportOverview
    {
        /** @var array{
         *     report_id: string,
         *     reporter_name: null|string,
         *     reporter_code: string,
         *     reported_player_name: null|string,
         *     reported_player_code: string,
         *     reported_player_id: string,
         *     conversation_id: string,
         *     reason: string,
         *     status: string,
         *     reported_at: string,
         *     puzzle_name: null|string,
         * } $row
         */

        return new ReportOverview(
            reportId: $row['report_id'],
            reporterName: $row['reporter_name'] ?? $row['reporter_code'],
            reporterCode: $row['reporter_code'],
            reportedPlayerName: $row['reported_player_name'] ?? $row['reported_player_code'],
            reportedPlayerCode: $row['reported_player_code'],
            reportedPlayerId: $row['reported_player_id'],
            conversationId: $row['conversation_id'],
            reason: $row['reason'],
            status: ReportStatus::from($row['status']),
            reportedAt: new DateTimeImmutable($row['reported_at']),
            puzzleName: $row['puzzle_name'],
        );
    }
}
