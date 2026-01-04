<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

readonly final class PuzzleChangeRequestOverview
{
    public function __construct(
        public string $id,
        public PuzzleReportStatus $status,
        public DateTimeImmutable $submittedAt,
        public null|DateTimeImmutable $reviewedAt,
        public null|string $rejectionReason,
        public string $puzzleId,
        public string $puzzleName,
        public int $puzzlePiecesCount,
        public null|string $puzzleImage,
        public null|string $puzzleManufacturerName,
        public string $reporterId,
        public null|string $reporterName,
        public null|string $reporterCode,
        public null|string $reviewerId,
        public null|string $reviewerName,
        public null|string $proposedName,
        public null|string $proposedManufacturerId,
        public null|string $proposedManufacturerName,
        public null|int $proposedPiecesCount,
        public null|string $proposedEan,
        public null|string $proposedIdentificationNumber,
        public null|string $proposedImage,
        public null|string $originalName,
        public null|string $originalManufacturerId,
        public null|string $originalManufacturerName,
        public null|int $originalPiecesCount,
        public null|string $originalEan,
        public null|string $originalIdentificationNumber,
        public null|string $originalImage,
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
        $puzzleId = $row['puzzle_id'];
        assert(is_string($puzzleId));
        $puzzleName = $row['puzzle_name'];
        assert(is_string($puzzleName));
        $puzzlePiecesCount = $row['puzzle_pieces_count'];
        assert(is_int($puzzlePiecesCount));
        $reporterId = $row['reporter_id'];
        assert(is_string($reporterId));

        return new self(
            id: $id,
            status: PuzzleReportStatus::from($status),
            submittedAt: new DateTimeImmutable($submittedAt),
            reviewedAt: $reviewedAt !== null ? new DateTimeImmutable($reviewedAt) : null,
            rejectionReason: is_string($row['rejection_reason']) ? $row['rejection_reason'] : null,
            puzzleId: $puzzleId,
            puzzleName: $puzzleName,
            puzzlePiecesCount: $puzzlePiecesCount,
            puzzleImage: is_string($row['puzzle_image']) ? $row['puzzle_image'] : null,
            puzzleManufacturerName: is_string($row['puzzle_manufacturer_name']) ? $row['puzzle_manufacturer_name'] : null,
            reporterId: $reporterId,
            reporterName: is_string($row['reporter_name']) ? $row['reporter_name'] : null,
            reporterCode: is_string($row['reporter_code']) ? $row['reporter_code'] : null,
            reviewerId: is_string($row['reviewer_id']) ? $row['reviewer_id'] : null,
            reviewerName: is_string($row['reviewer_name']) ? $row['reviewer_name'] : null,
            proposedName: is_string($row['proposed_name']) ? $row['proposed_name'] : null,
            proposedManufacturerId: is_string($row['proposed_manufacturer_id']) ? $row['proposed_manufacturer_id'] : null,
            proposedManufacturerName: is_string($row['proposed_manufacturer_name']) ? $row['proposed_manufacturer_name'] : null,
            proposedPiecesCount: is_int($row['proposed_pieces_count']) ? $row['proposed_pieces_count'] : null,
            proposedEan: is_string($row['proposed_ean']) ? $row['proposed_ean'] : null,
            proposedIdentificationNumber: is_string($row['proposed_identification_number']) ? $row['proposed_identification_number'] : null,
            proposedImage: is_string($row['proposed_image']) ? $row['proposed_image'] : null,
            originalName: is_string($row['original_name']) ? $row['original_name'] : null,
            originalManufacturerId: is_string($row['original_manufacturer_id']) ? $row['original_manufacturer_id'] : null,
            originalManufacturerName: is_string($row['original_manufacturer_name']) ? $row['original_manufacturer_name'] : null,
            originalPiecesCount: is_int($row['original_pieces_count']) ? $row['original_pieces_count'] : null,
            originalEan: is_string($row['original_ean']) ? $row['original_ean'] : null,
            originalIdentificationNumber: is_string($row['original_identification_number']) ? $row['original_identification_number'] : null,
            originalImage: is_string($row['original_image']) ? $row['original_image'] : null,
        );
    }

    public function hasNameChange(): bool
    {
        return $this->proposedName !== null && $this->proposedName !== $this->originalName;
    }

    public function hasManufacturerChange(): bool
    {
        return $this->proposedManufacturerId !== null && $this->proposedManufacturerId !== $this->originalManufacturerId;
    }

    public function hasPiecesCountChange(): bool
    {
        return $this->proposedPiecesCount !== null && $this->proposedPiecesCount !== $this->originalPiecesCount;
    }

    public function hasEanChange(): bool
    {
        return $this->proposedEan !== null && $this->proposedEan !== $this->originalEan;
    }

    public function hasIdentificationNumberChange(): bool
    {
        return $this->proposedIdentificationNumber !== null && $this->proposedIdentificationNumber !== $this->originalIdentificationNumber;
    }

    public function hasImageChange(): bool
    {
        return $this->proposedImage !== null;
    }

    public function hasAnyChange(): bool
    {
        return $this->hasNameChange()
            || $this->hasManufacturerChange()
            || $this->hasPiecesCountChange()
            || $this->hasEanChange()
            || $this->hasIdentificationNumberChange()
            || $this->hasImageChange();
    }
}
