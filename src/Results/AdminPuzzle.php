<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A puzzle as the internal API shows it to an admin - every puzzle, approved or not, hidden or not.
 */
readonly final class AdminPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public int $piecesCount,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public null|string $ean,
        public null|string $identificationNumber,
        public bool $approved,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     pieces_count: int,
     *     manufacturer_id: null|string,
     *     manufacturer_name: null|string,
     *     puzzle_ean: null|string,
     *     puzzle_identification_number: null|string,
     *     puzzle_approved: bool,
     *     ...
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            name: $row['puzzle_name'],
            piecesCount: $row['pieces_count'],
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            ean: $row['puzzle_ean'],
            identificationNumber: $row['puzzle_identification_number'],
            approved: $row['puzzle_approved'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'puzzleId' => $this->puzzleId,
            'name' => $this->name,
            'piecesCount' => $this->piecesCount,
            'manufacturerId' => $this->manufacturerId,
            'manufacturerName' => $this->manufacturerName,
            'ean' => $this->ean,
            'identificationNumber' => $this->identificationNumber,
            'approved' => $this->approved,
        ];
    }
}
