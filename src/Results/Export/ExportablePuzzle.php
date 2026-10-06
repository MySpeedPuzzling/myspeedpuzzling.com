<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * The puzzle facts every library section carries, so each sheet reads on its own. Every export query selects
 * them through SQL_COLUMNS (aliases `p` = puzzle, `m` = manufacturer, parameter `:now`), so they cannot drift.
 */
readonly final class ExportablePuzzle
{
    public const string SQL_COLUMNS = <<<SQL
p.id AS puzzle_id,
p.name AS puzzle_name,
p.name_language AS puzzle_name_language,
p.alternative_names AS puzzle_alternative_names,
m.name AS puzzle_brand_name,
p.pieces_count AS puzzle_pieces_count,
p.ean AS puzzle_ean,
p.identification_number AS puzzle_identification_number,
CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS puzzle_image
SQL;

    public function __construct(
        public string $puzzleId,
        public string $name,
        public null|string $nameLanguage,
        public PuzzleNames $otherNames,
        public null|string $brandName,
        public int $piecesCount,
        public EanList $eans,
        public BrandCodeList $brandCodes,
        public null|string $imageUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row selected with SQL_COLUMNS
     * @return null|self null when the puzzle no longer exists (lend/borrow history keeps rows of deleted puzzles)
     */
    public static function fromDatabaseRow(array $row, string $uploadedAssetsBaseUrl): null|self
    {
        /**
         * @var array{
         *     puzzle_id: null|string,
         *     puzzle_name: null|string,
         *     puzzle_name_language: null|string,
         *     puzzle_alternative_names: null|string,
         *     puzzle_brand_name: null|string,
         *     puzzle_pieces_count: null|int,
         *     puzzle_ean: null|string,
         *     puzzle_identification_number: null|string,
         *     puzzle_image: null|string,
         * } $row
         */
        if ($row['puzzle_id'] === null || $row['puzzle_name'] === null || $row['puzzle_pieces_count'] === null) {
            return null;
        }

        return new self(
            puzzleId: $row['puzzle_id'],
            name: $row['puzzle_name'],
            nameLanguage: $row['puzzle_name_language'],
            otherNames: PuzzleNames::fromJson($row['puzzle_alternative_names']),
            brandName: $row['puzzle_brand_name'],
            piecesCount: $row['puzzle_pieces_count'],
            eans: EanList::fromStored($row['puzzle_ean']),
            brandCodes: BrandCodeList::fromStored($row['puzzle_identification_number']),
            imageUrl: $row['puzzle_image'] !== null ? $uploadedAssetsBaseUrl . '/' . $row['puzzle_image'] : null,
        );
    }

    /**
     * "Name (de), Name (fr)"; a name without a language has no bracket.
     */
    public function otherNamesText(): string
    {
        $names = [];

        foreach ($this->otherNames->all() as $name) {
            $names[] = $name->language !== null ? sprintf('%s (%s)', $name->name, $name->language) : $name->name;
        }

        return implode(', ', $names);
    }
}
