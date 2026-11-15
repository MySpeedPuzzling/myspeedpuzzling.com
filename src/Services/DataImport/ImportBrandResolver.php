<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Value\DataImport\ImportBrandText;

/**
 * The catalogue brand a spreadsheet's brand text stands for (docs/features/data-import/README.md "Brands") - for
 * many texts at once, from one read of the brands:
 *
 * 1. the catalogue's own rule for a typed brand (ManufacturerResolver: the name trimmed, inner whitespace collapsed,
 *    lower case),
 * 2. then letters and digits only (ImportBrandText: `Master Pieces` = `MasterPieces` = `master-pieces`).
 *
 * Among several brands of one spelling the pick is ManufacturerRepository::findByNameIgnoringCase()'s: approved
 * first, then the most puzzles, then the oldest. The import never creates or renames a brand - a text nothing
 * matches stays a text. (The player's own aliases - "MB is Milton Bradley" - are a later stage's.)
 */
readonly final class ImportBrandResolver
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $brandTexts
     *
     * @return array<string, string> the brand's id by the text as given - a text without a brand is not in it
     */
    public function resolve(array $brandTexts): array
    {
        $brandTexts = array_values(array_unique(array_filter($brandTexts, static fn (string $text): bool => trim($text) !== '')));

        if ($brandTexts === []) {
            return [];
        }

        /** @var list<array{id: string, name: string}> $brands the best of a spelling first */
        $brands = $this->database->executeQuery(<<<SQL
SELECT manufacturer.id, manufacturer.name
FROM manufacturer
ORDER BY
    manufacturer.approved DESC,
    (SELECT COUNT(*) FROM puzzle WHERE puzzle.manufacturer_id = manufacturer.id) DESC,
    manufacturer.added_at ASC NULLS FIRST,
    manufacturer.id ASC
SQL)->fetchAllAssociative();

        $byName = [];
        $byLettersAndDigits = [];

        foreach ($brands as $brand) {
            $byName[self::asTyped($brand['name'])] ??= $brand['id'];
            $letters = ImportBrandText::lettersAndDigits($brand['name']);

            if ($letters !== '') {
                $byLettersAndDigits[$letters] ??= $brand['id'];
            }
        }

        $resolved = [];

        foreach ($brandTexts as $text) {
            $id = $byName[self::asTyped($text)] ?? $byLettersAndDigits[ImportBrandText::lettersAndDigits($text)] ?? null;

            if ($id !== null) {
                $resolved[$text] = $id;
            }
        }

        return $resolved;
    }

    private static function asTyped(string $name): string
    {
        return mb_strtolower(ManufacturerResolver::cleanName($name));
    }
}
