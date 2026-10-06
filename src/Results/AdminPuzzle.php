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
        // The site-wide hide (a secret competition puzzle, a placeholder) - UTC, ISO 8601
        public null|string $hiddenUntil = null,
        public null|string $imageHiddenUntil = null,
    ) {
    }

    public function isImageHiddenAt(\DateTimeImmutable $now): bool
    {
        return $this->imageHiddenUntil !== null && new \DateTimeImmutable($this->imageHiddenUntil) > $now;
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
     *     puzzle_hide_until: null|string,
     *     puzzle_hide_image_until: null|string,
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
            hiddenUntil: AdminCompetition::isoDateTime($row['puzzle_hide_until']),
            imageHiddenUntil: AdminCompetition::isoDateTime($row['puzzle_hide_image_until']),
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
            'hiddenUntil' => $this->hiddenUntil,
            'imageHiddenUntil' => $this->imageHiddenUntil,
        ];
    }
}
