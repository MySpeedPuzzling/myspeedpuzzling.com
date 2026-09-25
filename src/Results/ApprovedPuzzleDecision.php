<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One approval from the moderation log - still readable when the puzzle or the
 * approver has since been deleted.
 */
readonly final class ApprovedPuzzleDecision
{
    public function __construct(
        public DateTimeImmutable $decidedAt,
        public string $decidedById,
        public null|string $decidedByName,
        public null|string $decidedByCode,
        public null|string $puzzleId,
        public null|string $puzzleName,
        public bool $puzzleExists,
        public null|string $image,
        public null|int $piecesCount,
        public null|string $manufacturerName,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        /**
         * @var array{
         *     decided_at: string,
         *     decided_by_id: string,
         *     decided_by_name: null|string,
         *     decided_by_code: null|string,
         *     puzzle_id: null|string,
         *     puzzle_name: null|string,
         *     puzzle_exists: bool,
         *     image: null|string,
         *     pieces_count: null|int,
         *     manufacturer_name: null|string,
         * } $row
         */
        return new self(
            decidedAt: new DateTimeImmutable($row['decided_at']),
            decidedById: $row['decided_by_id'],
            decidedByName: $row['decided_by_name'],
            decidedByCode: $row['decided_by_code'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleExists: $row['puzzle_exists'],
            image: $row['image'],
            piecesCount: $row['pieces_count'],
            manufacturerName: $row['manufacturer_name'],
        );
    }
}
