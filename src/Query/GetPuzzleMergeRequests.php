<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleMergeRequestOverview;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

readonly final class GetPuzzleMergeRequests
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
FROM puzzle_merge_request
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
    pmr.survivor_puzzle_id,
    pmr.merged_puzzle_ids,
    pmr.source_puzzle_name as stored_source_puzzle_name,
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
LEFT JOIN puzzle source_p ON source_p.id = pmr.source_puzzle_id
LEFT JOIN manufacturer source_m ON source_m.id = source_p.manufacturer_id
LEFT JOIN player reporter ON reporter.id = pmr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pmr.reviewed_by_id
WHERE pmr.id = :id
SQL;

        $row = $this->database->fetchAssociative($query, [
            'id' => $id,
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
    pmr.survivor_puzzle_id,
    pmr.merged_puzzle_ids,
    pmr.source_puzzle_name as stored_source_puzzle_name,
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
LEFT JOIN puzzle source_p ON source_p.id = pmr.source_puzzle_id
LEFT JOIN manufacturer source_m ON source_m.id = source_p.manufacturer_id
LEFT JOIN player reporter ON reporter.id = pmr.reporter_id
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
