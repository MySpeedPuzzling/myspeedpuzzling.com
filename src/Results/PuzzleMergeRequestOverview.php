<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
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
        public string $sourcePuzzleId,
        public string $sourcePuzzleName,
        public int $sourcePuzzlePiecesCount,
        public null|string $sourcePuzzleImage,
        public null|string $sourcePuzzleManufacturerName,
        public string $reporterId,
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
        $sourcePuzzleId = $row['source_puzzle_id'];
        assert(is_string($sourcePuzzleId));
        $sourcePuzzleName = $row['source_puzzle_name'];
        assert(is_string($sourcePuzzleName));
        $sourcePuzzlePiecesCount = $row['source_puzzle_pieces_count'];
        assert(is_int($sourcePuzzlePiecesCount));
        $reporterId = $row['reporter_id'];
        assert(is_string($reporterId));

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
            id: Uuid::fromBytes($id)->toString(),
            status: PuzzleReportStatus::from($status),
            submittedAt: new DateTimeImmutable($submittedAt),
            reviewedAt: $reviewedAt !== null ? new DateTimeImmutable($reviewedAt) : null,
            rejectionReason: is_string($row['rejection_reason']) ? $row['rejection_reason'] : null,
            reportedDuplicatePuzzleIds: $reportedDuplicateIds,
            finalMergedPuzzleId: is_string($row['final_merged_puzzle_id'])
                ? Uuid::fromBytes($row['final_merged_puzzle_id'])->toString()
                : null,
            mergedPuzzleIds: $mergedIds,
            sourcePuzzleId: Uuid::fromBytes($sourcePuzzleId)->toString(),
            sourcePuzzleName: $sourcePuzzleName,
            sourcePuzzlePiecesCount: $sourcePuzzlePiecesCount,
            sourcePuzzleImage: is_string($row['source_puzzle_image']) ? $row['source_puzzle_image'] : null,
            sourcePuzzleManufacturerName: is_string($row['source_puzzle_manufacturer_name']) ? $row['source_puzzle_manufacturer_name'] : null,
            reporterId: Uuid::fromBytes($reporterId)->toString(),
            reporterName: is_string($row['reporter_name']) ? $row['reporter_name'] : null,
            reporterCode: is_string($row['reporter_code']) ? $row['reporter_code'] : null,
            reviewerId: is_string($row['reviewer_id']) ? Uuid::fromBytes($row['reviewer_id'])->toString() : null,
            reviewerName: is_string($row['reviewer_name']) ? $row['reviewer_name'] : null,
        );
    }

    public function getDuplicateCount(): int
    {
        return count($this->reportedDuplicatePuzzleIds);
    }
}
