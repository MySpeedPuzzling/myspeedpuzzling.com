<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ManufacturerOverview
{
    public function __construct(
        public string $manufacturerId,
        public string $manufacturerName,
        public bool $manufacturerApproved,
        public int $puzzlesCount,
    ) {
    }

    /**
     * @param array{
     *      manufacturer_id: string,
     *      manufacturer_name: string,
     *      manufacturer_approved: bool,
     *      puzzles_count: int,
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            manufacturerApproved: $row['manufacturer_approved'],
            puzzlesCount: $row['puzzles_count'],
        );
    }
}
