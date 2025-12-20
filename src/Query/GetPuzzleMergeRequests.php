<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PuzzleMergeRequestOverview;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

readonly final class GetPuzzleMergeRequests
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    public function allPending(): array
    {
        return $this->byStatus(PuzzleReportStatus::Pending);
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    public function allApproved(): array
    {
        return $this->byStatus(PuzzleReportStatus::Approved);
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    public function allRejected(): array
    {
        return $this->byStatus(PuzzleReportStatus::Rejected);
    }

    public function byId(string $id): null|PuzzleMergeRequestOverview
    {
        $query = <<<SQL
SELECT
    pmr.id,
    pmr.status,
    pmr.submitted_at,
    pmr.reviewed_at,
    pmr.rejection_reason,
    pmr.reported_duplicate_puzzle_ids,
    pmr.final_merged_puzzle_id,
    pmr.merged_puzzle_ids,
    source_p.id as source_puzzle_id,
    source_p.name as source_puzzle_name,
    source_p.pieces_count as source_puzzle_pieces_count,
    source_p.image as source_puzzle_image,
    source_m.name as source_puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name
FROM puzzle_merge_request pmr
JOIN puzzle source_p ON source_p.id = pmr.source_puzzle_id
LEFT JOIN manufacturer source_m ON source_m.id = source_p.manufacturer_id
JOIN player reporter ON reporter.id = pmr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pmr.reviewed_by_id
WHERE pmr.id = :id
SQL;

        $row = $this->database->fetchAssociative($query, [
            'id' => Uuid::fromString($id)->getBytes(),
        ]);

        if ($row === false) {
            return null;
        }

        return PuzzleMergeRequestOverview::fromDatabaseRow($row);
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    private function byStatus(PuzzleReportStatus $status): array
    {
        $query = <<<SQL
SELECT
    pmr.id,
    pmr.status,
    pmr.submitted_at,
    pmr.reviewed_at,
    pmr.rejection_reason,
    pmr.reported_duplicate_puzzle_ids,
    pmr.final_merged_puzzle_id,
    pmr.merged_puzzle_ids,
    source_p.id as source_puzzle_id,
    source_p.name as source_puzzle_name,
    source_p.pieces_count as source_puzzle_pieces_count,
    source_p.image as source_puzzle_image,
    source_m.name as source_puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name
FROM puzzle_merge_request pmr
JOIN puzzle source_p ON source_p.id = pmr.source_puzzle_id
LEFT JOIN manufacturer source_m ON source_m.id = source_p.manufacturer_id
JOIN player reporter ON reporter.id = pmr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pmr.reviewed_by_id
WHERE pmr.status = :status
ORDER BY pmr.submitted_at DESC
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'status' => $status->value,
        ]);

        return array_map(
            static fn(array $row): PuzzleMergeRequestOverview => PuzzleMergeRequestOverview::fromDatabaseRow($row),
            $rows,
        );
    }
}
