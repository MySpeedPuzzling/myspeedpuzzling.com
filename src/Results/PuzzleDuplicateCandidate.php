<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleDuplicateCandidate
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $image,
        public null|string $ean,
        public null|string $identificationNumber,
        public null|string $manufacturerName,
        public bool $approved,
        public int $solvedTimes,
        public bool $sameEan,
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
         *     manufacturer_name: null|string,
         *     approved: bool,
         *     solved_times: int|string,
         *     same_ean: null|bool,
         * } $row
         */
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            piecesCount: $row['pieces_count'],
            image: $row['image'],
            ean: $row['ean'],
            identificationNumber: $row['identification_number'],
            manufacturerName: $row['manufacturer_name'],
            approved: $row['approved'],
            solvedTimes: (int) $row['solved_times'],
            sameEan: $row['same_ean'] === true,
        );
    }
}
