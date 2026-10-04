<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * A puzzle's catalogue record as it is now, for moderators: nothing hidden (the image of a puzzle still
 * under embargo included), with who added and approved it.
 */
readonly final class PuzzleRecord
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        // The main title's language when the box has no English title
        public null|string $nameLanguage,
        public PuzzleNames $alternativeNames,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public bool $manufacturerApproved,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public null|string $image,
        public bool $approved,
        public null|DateTimeImmutable $addedAt,
        public null|string $addedById,
        public null|string $addedByName,
        public null|string $addedByCode,
        public null|DateTimeImmutable $hideUntil,
        public null|DateTimeImmutable $hideImageUntil,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $puzzleId = $row['puzzle_id'];
        assert(is_string($puzzleId));
        $name = $row['name'];
        assert(is_string($name));
        $piecesCount = $row['pieces_count'];
        assert(is_int($piecesCount));

        return new self(
            puzzleId: $puzzleId,
            name: $name,
            nameLanguage: is_string($row['name_language'] ?? null) ? $row['name_language'] : null,
            alternativeNames: PuzzleNames::fromJson(is_string($row['alternative_names']) ? $row['alternative_names'] : null),
            manufacturerId: is_string($row['manufacturer_id']) ? $row['manufacturer_id'] : null,
            manufacturerName: is_string($row['manufacturer_name']) ? $row['manufacturer_name'] : null,
            manufacturerApproved: $row['manufacturer_approved'] === true,
            piecesCount: $piecesCount,
            ean: is_string($row['ean']) ? $row['ean'] : null,
            identificationNumber: is_string($row['identification_number']) ? $row['identification_number'] : null,
            image: is_string($row['image']) ? $row['image'] : null,
            approved: $row['approved'] === true,
            addedAt: is_string($row['added_at']) ? new DateTimeImmutable($row['added_at']) : null,
            addedById: is_string($row['added_by_id']) ? $row['added_by_id'] : null,
            addedByName: is_string($row['added_by_name']) ? $row['added_by_name'] : null,
            addedByCode: is_string($row['added_by_code']) ? $row['added_by_code'] : null,
            hideUntil: is_string($row['hide_until']) ? new DateTimeImmutable($row['hide_until']) : null,
            hideImageUntil: is_string($row['hide_image_until']) ? new DateTimeImmutable($row['hide_image_until']) : null,
        );
    }

    /**
     * What a form editing this record sends back (PuzzleRecordVersion)
     */
    public function recordVersion(): string
    {
        return PuzzleRecordVersion::of(
            name: $this->name,
            nameLanguage: $this->nameLanguage,
            alternativeNames: $this->alternativeNames,
            manufacturerId: $this->manufacturerId,
            piecesCount: $this->piecesCount,
            ean: $this->ean,
            identificationNumber: $this->identificationNumber,
            image: $this->image,
        );
    }
}
