<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

readonly final class GetPuzzleChangeRequests
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{pending: int, approved: int, rejected: int}
     */
    public function countByStatus(): array
    {
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE status = 'pending') as pending,
    COUNT(*) FILTER (WHERE status = 'approved') as approved,
    COUNT(*) FILTER (WHERE status = 'rejected') as rejected
FROM puzzle_change_request
SQL;

        $row = $this->database->fetchAssociative($query);

        if ($row === false) {
            return ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        }

        /** @var int $pending */
        $pending = $row['pending'];
        /** @var int $approved */
        $approved = $row['approved'];
        /** @var int $rejected */
        $rejected = $row['rejected'];

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
        ];
    }

    /**
     * @return array<PuzzleChangeRequestOverview>
     */
    public function allPending(): array
    {
        return $this->byStatus(PuzzleReportStatus::Pending, 'pcr.submitted_at DESC');
    }

    /**
     * @return array<PuzzleChangeRequestOverview>
     */
    public function allApproved(): array
    {
        return $this->byStatus(PuzzleReportStatus::Approved, 'pcr.reviewed_at DESC');
    }

    /**
     * @return array<PuzzleChangeRequestOverview>
     */
    public function allRejected(): array
    {
        return $this->byStatus(PuzzleReportStatus::Rejected, 'pcr.reviewed_at DESC');
    }

    public function byId(string $id): null|PuzzleChangeRequestOverview
    {
        $query = <<<SQL
SELECT
    pcr.id,
    pcr.status,
    pcr.submitted_at,
    pcr.reviewed_at,
    pcr.rejection_reason,
    pcr.proposed_name,
    pcr.proposed_pieces_count,
    pcr.proposed_ean,
    pcr.proposed_identification_number,
    pcr.proposed_image,
    pcr.original_name,
    pcr.original_pieces_count,
    pcr.original_ean,
    pcr.original_identification_number,
    pcr.original_image,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.pieces_count as puzzle_pieces_count,
    p.image as puzzle_image,
    pm.name as puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name,
    proposed_m.id as proposed_manufacturer_id,
    proposed_m.name as proposed_manufacturer_name,
    original_m.id as original_manufacturer_id,
    original_m.name as original_manufacturer_name
FROM puzzle_change_request pcr
JOIN puzzle p ON p.id = pcr.puzzle_id
LEFT JOIN manufacturer pm ON pm.id = p.manufacturer_id
JOIN player reporter ON reporter.id = pcr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pcr.reviewed_by_id
LEFT JOIN manufacturer proposed_m ON proposed_m.id = pcr.proposed_manufacturer_id
LEFT JOIN manufacturer original_m ON original_m.id = pcr.original_manufacturer_id
WHERE pcr.id = :id
SQL;

        $row = $this->database->fetchAssociative($query, [
            'id' => $id,
        ]);

        if ($row === false) {
            return null;
        }

        return PuzzleChangeRequestOverview::fromDatabaseRow($row);
    }

    /**
     * @return array<PuzzleChangeRequestOverview>
     */
    private function byStatus(PuzzleReportStatus $status, string $orderBy): array
    {
        $query = <<<SQL
SELECT
    pcr.id,
    pcr.status,
    pcr.submitted_at,
    pcr.reviewed_at,
    pcr.rejection_reason,
    pcr.proposed_name,
    pcr.proposed_pieces_count,
    pcr.proposed_ean,
    pcr.proposed_identification_number,
    pcr.proposed_image,
    pcr.original_name,
    pcr.original_pieces_count,
    pcr.original_ean,
    pcr.original_identification_number,
    pcr.original_image,
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.pieces_count as puzzle_pieces_count,
    p.image as puzzle_image,
    pm.name as puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name,
    proposed_m.id as proposed_manufacturer_id,
    proposed_m.name as proposed_manufacturer_name,
    original_m.id as original_manufacturer_id,
    original_m.name as original_manufacturer_name
FROM puzzle_change_request pcr
JOIN puzzle p ON p.id = pcr.puzzle_id
LEFT JOIN manufacturer pm ON pm.id = p.manufacturer_id
JOIN player reporter ON reporter.id = pcr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pcr.reviewed_by_id
LEFT JOIN manufacturer proposed_m ON proposed_m.id = pcr.proposed_manufacturer_id
LEFT JOIN manufacturer original_m ON original_m.id = pcr.original_manufacturer_id
WHERE pcr.status = :status
ORDER BY {$orderBy}
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'status' => $status->value,
        ]);

        return array_map(
            static fn(array $row): PuzzleChangeRequestOverview => PuzzleChangeRequestOverview::fromDatabaseRow($row),
            $rows,
        );
    }
}
