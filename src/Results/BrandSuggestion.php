<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * An approved brand the puzzle's new brand probably duplicates.
 */
readonly final class BrandSuggestion
{
    public function __construct(
        public string $manufacturerId,
        public string $manufacturerName,
        public int $puzzlesCount,
        public bool $eanPrefixMatches,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        /**
         * @var array{
         *     manufacturer_id: string,
         *     manufacturer_name: string,
         *     puzzles_count: int|string,
         *     ean_prefix_matches: null|bool,
         * } $row
         */
        return new self(
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            puzzlesCount: (int) $row['puzzles_count'],
            eanPrefixMatches: $row['ean_prefix_matches'] === true,
        );
    }
}
