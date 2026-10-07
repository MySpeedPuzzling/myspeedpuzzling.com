<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PuzzleMergeRequestOverview;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;

readonly final class GetPuzzleMergeRequests
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{pending: int, approved: int, rejected: int, outdated: int}
     */
    public function countByStatus(): array
    {
        $noSecretPuzzle = self::sqlNoSecretPuzzle();
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE pmr.status = 'pending') as pending,
    COUNT(*) FILTER (WHERE pmr.status = 'approved') as approved,
    COUNT(*) FILTER (WHERE pmr.status = 'rejected') as rejected,
    COUNT(*) FILTER (WHERE pmr.status = 'outdated') as outdated
FROM puzzle_merge_request pmr
WHERE {$noSecretPuzzle}
SQL;

        $row = $this->database->fetchAssociative($query, ['now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        if ($row === false) {
            return ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'outdated' => 0];
        }

        /** @var int $pending */
        $pending = $row['pending'];
        /** @var int $approved */
        $approved = $row['approved'];
        /** @var int $rejected */
        $rejected = $row['rejected'];
        /** @var int $outdated */
        $outdated = $row['outdated'];

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'outdated' => $outdated,
        ];
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    public function allPending(): array
    {
        return $this->byStatus(PuzzleReportStatus::Pending, 'pmr.submitted_at DESC');
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    public function allApproved(): array
    {
        return $this->byStatus(PuzzleReportStatus::Approved, 'pmr.reviewed_at DESC');
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    public function allRejected(): array
    {
        return $this->byStatus(PuzzleReportStatus::Rejected, 'pmr.reviewed_at DESC');
    }

    /**
     * Closed without a review - nothing was left to merge (PuzzleReportStatus::Outdated).
     *
     * @return array<PuzzleMergeRequestOverview>
     */
    public function allOutdated(): array
    {
        return $this->byStatus(PuzzleReportStatus::Outdated, 'pmr.reviewed_at DESC');
    }

    /**
     * The puzzles a merge request reports, lower-case ids - what its approval may keep (the survivor) and merge. Null
     * when there is no such request.
     *
     * @return null|list<string>
     */
    public function reportedPuzzleIdsOf(string $id): null|array
    {
        $reported = $this->database->fetchOne(
            'SELECT reported_duplicate_puzzle_ids FROM puzzle_merge_request WHERE id = :id',
            ['id' => $id],
        );

        if (is_string($reported) === false) {
            return null;
        }

        $puzzleIds = json_decode($reported, true);

        return is_array($puzzleIds) ? array_values(array_map(strtolower(...), array_filter($puzzleIds, is_string(...)))) : [];
    }

    public function byId(string $id): null|PuzzleMergeRequestOverview
    {
        $noSecretPuzzle = self::sqlNoSecretPuzzle();
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
    pmr.reported_name_languages,
    pmr.outdated_reason,
    pmr.outdated_by_merge_request_id,
    pmr.source_puzzle_name as stored_source_puzzle_name,
    source_p.id as source_puzzle_id,
    source_p.name as source_puzzle_name,
    source_p.pieces_count as source_puzzle_pieces_count,
    CASE WHEN source_p.hide_image_until IS NOT NULL AND source_p.hide_image_until > :now::timestamp THEN NULL ELSE source_p.image END AS source_puzzle_image,
    CASE WHEN source_p.hide_image_until IS NOT NULL AND source_p.hide_image_until > :now::timestamp THEN NULL ELSE source_p.image_ratio END AS source_puzzle_image_ratio,
    source_m.name as source_puzzle_manufacturer_name,
    survivor_p.name as survivor_puzzle_name,
    survivor_p.pieces_count as survivor_puzzle_pieces_count,
    CASE WHEN survivor_p.hide_image_until IS NOT NULL AND survivor_p.hide_image_until > :now::timestamp THEN NULL ELSE survivor_p.image END AS survivor_puzzle_image,
    CASE WHEN survivor_p.hide_image_until IS NOT NULL AND survivor_p.hide_image_until > :now::timestamp THEN NULL ELSE survivor_p.image_ratio END AS survivor_puzzle_image_ratio,
    survivor_m.name as survivor_puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name
FROM puzzle_merge_request pmr
LEFT JOIN puzzle source_p ON source_p.id = pmr.source_puzzle_id
LEFT JOIN manufacturer source_m ON source_m.id = source_p.manufacturer_id
LEFT JOIN puzzle survivor_p ON survivor_p.id = pmr.survivor_puzzle_id
LEFT JOIN manufacturer survivor_m ON survivor_m.id = survivor_p.manufacturer_id
LEFT JOIN player reporter ON reporter.id = pmr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pmr.reviewed_by_id
WHERE pmr.id = :id
    AND {$noSecretPuzzle}
SQL;

        $row = $this->database->fetchAssociative($query, [
            'id' => $id,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        if ($row === false) {
            return null;
        }

        return PuzzleMergeRequestOverview::fromDatabaseRow($row);
    }

    /**
     * @return array<PuzzleMergeRequestOverview>
     */
    private function byStatus(PuzzleReportStatus $status, string $orderBy): array
    {
        $noSecretPuzzle = self::sqlNoSecretPuzzle();
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
    pmr.outdated_reason,
    pmr.outdated_by_merge_request_id,
    pmr.source_puzzle_name as stored_source_puzzle_name,
    source_p.id as source_puzzle_id,
    source_p.name as source_puzzle_name,
    source_p.pieces_count as source_puzzle_pieces_count,
    CASE WHEN source_p.hide_image_until IS NOT NULL AND source_p.hide_image_until > :now::timestamp THEN NULL ELSE source_p.image END AS source_puzzle_image,
    CASE WHEN source_p.hide_image_until IS NOT NULL AND source_p.hide_image_until > :now::timestamp THEN NULL ELSE source_p.image_ratio END AS source_puzzle_image_ratio,
    source_m.name as source_puzzle_manufacturer_name,
    survivor_p.name as survivor_puzzle_name,
    survivor_p.pieces_count as survivor_puzzle_pieces_count,
    CASE WHEN survivor_p.hide_image_until IS NOT NULL AND survivor_p.hide_image_until > :now::timestamp THEN NULL ELSE survivor_p.image END AS survivor_puzzle_image,
    CASE WHEN survivor_p.hide_image_until IS NOT NULL AND survivor_p.hide_image_until > :now::timestamp THEN NULL ELSE survivor_p.image_ratio END AS survivor_puzzle_image_ratio,
    survivor_m.name as survivor_puzzle_manufacturer_name,
    reporter.id as reporter_id,
    reporter.name as reporter_name,
    reporter.code as reporter_code,
    reviewer.id as reviewer_id,
    reviewer.name as reviewer_name
FROM puzzle_merge_request pmr
LEFT JOIN puzzle source_p ON source_p.id = pmr.source_puzzle_id
LEFT JOIN manufacturer source_m ON source_m.id = source_p.manufacturer_id
LEFT JOIN puzzle survivor_p ON survivor_p.id = pmr.survivor_puzzle_id
LEFT JOIN manufacturer survivor_m ON survivor_m.id = survivor_p.manufacturer_id
LEFT JOIN player reporter ON reporter.id = pmr.reporter_id
LEFT JOIN player reviewer ON reviewer.id = pmr.reviewed_by_id
WHERE pmr.status = :status
    AND {$noSecretPuzzle}
ORDER BY {$orderBy}
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'status' => $status->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        return array_map(
            static fn(array $row): PuzzleMergeRequestOverview => PuzzleMergeRequestOverview::fromDatabaseRow($row),
            $rows,
        );
    }

    /**
     * A merge request is out of the queue while any puzzle in it is a secret competition puzzle (PuzzleSecrecy) - it
     * cannot be merged before the reveal anyway.
     */
    public static function sqlNoSecretPuzzle(): string
    {
        $notSecret = PuzzleSecrecy::sqlNotSecret('secret_p');

        return <<<SQL
NOT EXISTS (
    SELECT 1 FROM puzzle secret_p
    WHERE (
        secret_p.id = pmr.source_puzzle_id
        OR secret_p.id::text IN (SELECT jsonb_array_elements_text(pmr.reported_duplicate_puzzle_ids::jsonb))
    )
        AND NOT {$notSecret}
)
SQL;
    }
}
