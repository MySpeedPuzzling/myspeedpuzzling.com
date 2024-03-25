<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class AutocompletePuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public bool $puzzleApproved,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_image: null|string,
     *     puzzle_alternative_name: null|string,
     *     puzzle_approved: bool,
     *     manufacturer_name: string,
     *     pieces_count: int,
     *     puzzle_ean: null|string,
     *     puzzle_identification_number: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            puzzleApproved: $row['puzzle_approved'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            puzzleImage: $row['puzzle_image'],
            puzzleEan: $row['puzzle_ean'],
            puzzleIdentificationNumber: $row['puzzle_identification_number'],
        );
    }
}
