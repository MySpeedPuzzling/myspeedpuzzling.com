<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ManufacturerOverview
{
    public function __construct(
        public string $manufacturerId,
        public string $manufacturerName,
        public bool $manufacturerApproved,
        public null|string $manufacturerLogo,
        public null|string $manufacturerEanPrefix,
        public int $puzzlesCount,
    ) {
    }

    /**
     * @param array{
     *      manufacturer_id: string,
     *      manufacturer_name: string,
     *      manufacturer_approved: bool,
     *      manufacturer_logo: string|null,
     *      manufacturer_ean_prefix: string|null,
     *      puzzles_count: int,
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            manufacturerApproved: $row['manufacturer_approved'],
            manufacturerLogo: $row['manufacturer_logo'],
            manufacturerEanPrefix: $row['manufacturer_ean_prefix'],
            puzzlesCount: $row['puzzles_count'],
        );
    }
}
