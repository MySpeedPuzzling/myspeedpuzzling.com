<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A newly added puzzle waiting in the approval queue.
 */
readonly final class PendingPuzzleApproval
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $image,
        public null|string $ean,
        public null|string $identificationNumber,
        public bool $approved,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public bool $manufacturerApproved,
        public int $manufacturerPuzzlesCount,
        public null|string $addedById,
        public null|string $addedByName,
        public null|string $addedByCode,
        public null|DateTimeImmutable $addedAt,
        public int $solvedTimes,
        public bool $hasSameEanPuzzle,
        public bool $inPendingMergeRequest,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        /**
         * @var array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     image: null|string,
         *     ean: null|string,
         *     identification_number: null|string,
         *     approved: bool,
         *     manufacturer_id: null|string,
         *     manufacturer_name: null|string,
         *     manufacturer_approved: null|bool,
         *     manufacturer_puzzles_count: int|string,
         *     added_by_id: null|string,
         *     added_by_name: null|string,
         *     added_by_code: null|string,
         *     added_at: null|string,
         *     solved_times: int|string,
         *     has_same_ean_puzzle: bool,
         *     in_pending_merge_request: bool,
         * } $row
         */
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            piecesCount: $row['pieces_count'],
            image: $row['image'],
            ean: $row['ean'],
            identificationNumber: $row['identification_number'],
            approved: $row['approved'],
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            manufacturerApproved: $row['manufacturer_approved'] === true,
            manufacturerPuzzlesCount: (int) $row['manufacturer_puzzles_count'],
            addedById: $row['added_by_id'],
            addedByName: $row['added_by_name'],
            addedByCode: $row['added_by_code'],
            addedAt: $row['added_at'] !== null ? new DateTimeImmutable($row['added_at']) : null,
            solvedTimes: (int) $row['solved_times'],
            hasSameEanPuzzle: $row['has_same_ean_puzzle'],
            inPendingMergeRequest: $row['in_pending_merge_request'],
        );
    }
}
