<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

readonly final class PuzzleMergeRequestOverview
{
    /**
     * @param array<string> $reportedDuplicatePuzzleIds
     * @param array<string> $mergedPuzzleIds
     */
    public function __construct(
        public string $id,
        public PuzzleReportStatus $status,
        public DateTimeImmutable $submittedAt,
        public null|DateTimeImmutable $reviewedAt,
        public null|string $rejectionReason,
        public array $reportedDuplicatePuzzleIds,
        public null|string $finalMergedPuzzleId,
        public array $mergedPuzzleIds,
        public null|string $sourcePuzzleId,
        public string $sourcePuzzleName,
        public null|int $sourcePuzzlePiecesCount,
        public null|string $sourcePuzzleImage,
        public null|string $sourcePuzzleManufacturerName,
        public null|string $reporterId,
        public null|string $reporterName,
        public null|string $reporterCode,
        public null|string $reviewerId,
        public null|string $reviewerName,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $id = $row['id'];
        assert(is_string($id));
        $status = $row['status'];
        assert(is_string($status));
        $submittedAt = $row['submitted_at'];
        assert(is_string($submittedAt));
        $reviewedAt = $row['reviewed_at'];
        assert(is_string($reviewedAt) || $reviewedAt === null);

        // Use stored puzzle name as fallback when puzzle is deleted
        $sourcePuzzleName = is_string($row['source_puzzle_name'])
            ? $row['source_puzzle_name']
            : (is_string($row['stored_source_puzzle_name']) ? $row['stored_source_puzzle_name'] : 'Deleted puzzle');

        $reportedDuplicateIdsJson = $row['reported_duplicate_puzzle_ids'];
        assert(is_string($reportedDuplicateIdsJson));
        /** @var array<string> $reportedDuplicateIds */
        $reportedDuplicateIds = json_decode($reportedDuplicateIdsJson, true) ?? [];

        $mergedIdsJson = $row['merged_puzzle_ids'] ?? null;
        /** @var array<string> $mergedIds */
        $mergedIds = is_string($mergedIdsJson)
            ? (json_decode($mergedIdsJson, true) ?? [])
            : [];

        return new self(
            id: $id,
            status: PuzzleReportStatus::from($status),
            submittedAt: new DateTimeImmutable($submittedAt),
            reviewedAt: $reviewedAt !== null ? new DateTimeImmutable($reviewedAt) : null,
            rejectionReason: is_string($row['rejection_reason']) ? $row['rejection_reason'] : null,
            reportedDuplicatePuzzleIds: $reportedDuplicateIds,
            finalMergedPuzzleId: is_string($row['survivor_puzzle_id']) ? $row['survivor_puzzle_id'] : null,
            mergedPuzzleIds: $mergedIds,
            sourcePuzzleId: is_string($row['source_puzzle_id']) ? $row['source_puzzle_id'] : null,
            sourcePuzzleName: $sourcePuzzleName,
            sourcePuzzlePiecesCount: is_int($row['source_puzzle_pieces_count']) ? $row['source_puzzle_pieces_count'] : null,
            sourcePuzzleImage: is_string($row['source_puzzle_image']) ? $row['source_puzzle_image'] : null,
            sourcePuzzleManufacturerName: is_string($row['source_puzzle_manufacturer_name']) ? $row['source_puzzle_manufacturer_name'] : null,
            reporterId: is_string($row['reporter_id']) ? $row['reporter_id'] : null,
            reporterName: is_string($row['reporter_name']) ? $row['reporter_name'] : null,
            reporterCode: is_string($row['reporter_code']) ? $row['reporter_code'] : null,
            reviewerId: is_string($row['reviewer_id']) ? $row['reviewer_id'] : null,
            reviewerName: is_string($row['reviewer_name']) ? $row['reviewer_name'] : null,
        );
    }

    public function getDuplicateCount(): int
    {
        return count($this->reportedDuplicatePuzzleIds);
    }
}
