<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ManufacturerOverview
{
    public function __construct(
        public string $manufacturerId,
        public string $manufacturerName,
        public bool $approved,
    ) {
    }

    /**
     * @param array{
     *      manufacturer_id: string,
     *      manufacturer_name: string,
     *      approved: bool
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            approved: $row['approved'],
        );
    }
}
