<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RelatedPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public int $piecesCount,
        public int $solvedTimes,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_image: null|string,
     *     puzzle_image_ratio: null|string|float,
     *     pieces_count: int,
     *     solved_times: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            piecesCount: $row['pieces_count'],
            solvedTimes: $row['solved_times'],
        );
    }
}
