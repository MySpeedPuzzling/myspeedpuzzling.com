<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Value\DataImport\ImportBrandText;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The catalogue brand a spreadsheet's brand text stands for (docs/features/data-import/README.md "Brands") - for
 * many texts at once, from one read of the brands (resolveApproved() keeps its read for the request or the queued
 * message - ResetInterface):
 *
 * 1. the catalogue's own rule for a typed brand (ManufacturerResolver: the name trimmed, inner whitespace collapsed,
 *    lower case),
 * 2. then letters and digits only (ImportBrandText: `Master Pieces` = `MasterPieces` = `master-pieces`).
 *
 * Among several brands of one spelling the pick is ManufacturerRepository::findByNameIgnoringCase()'s: approved
 * first, then the most puzzles, then the oldest. The import never creates or renames a brand - a text nothing
 * matches stays a text. (The player's own aliases - "MB is Milton Bradley" - are a later stage's.)
 */
final class ImportBrandResolver implements ResetInterface
{
    /**
     * The catalogue's brands, the best of a spelling first, as resolveApproved() read them - once per request or
     * queued message (the worker resets services between messages; FrankenPHP between requests), however many
     * sheets ask.
     *
     * @var null|list<array{id: string, name: string, approved: bool}>
     */
    private null|array $brands = null;

    public function __construct(
        readonly private Connection $database,
    ) {
    }

    public function reset(): void
    {
        $this->brands = null;
    }

    /**
     * The same for **approved brands only** - for guessing which column of a sheet is the brand
     * (ColumnRecogniser::withBrandByValues()): a brand somebody typed in and nobody approved ("Unknown", a shop's
     * name) must not make a column the brand.
     *
     * @param list<string> $brandTexts
     *
     * @return array<string, string>
     */
    public function resolveApproved(array $brandTexts): array
    {
        return $this->resolve($brandTexts, approvedOnly: true);
    }

    /**
     * @param list<string> $brandTexts
     *
     * @return array<string, string> the brand's id by the text as given - a text without a brand is not in it
     */
    public function resolve(array $brandTexts, bool $approvedOnly = false): array
    {
        $brandTexts = array_values(array_unique(array_filter($brandTexts, static fn (string $text): bool => trim($text) !== '')));

        if ($brandTexts === []) {
            return [];
        }

        $byName = [];
        $byLettersAndDigits = [];

        // The matching of an import's puzzles reads the brands as they are now (a brand may have been added a moment
        // ago); the guess which column is the brand - asked once per sheet of a file - shares one read
        foreach ($approvedOnly ? $this->brands ??= $this->read() : $this->read() as $brand) {
            if ($approvedOnly && $brand['approved'] === false) {
                continue;
            }

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

    /**
     * @return list<array{id: string, name: string, approved: bool}>
     */
    private function read(): array
    {
        /** @var list<array{id: string, name: string, approved: bool}> $brands the best of a spelling first */
        $brands = $this->database->executeQuery(<<<SQL
SELECT manufacturer.id, manufacturer.name, manufacturer.approved
FROM manufacturer
ORDER BY
    manufacturer.approved DESC,
    (SELECT COUNT(*) FROM puzzle WHERE puzzle.manufacturer_id = manufacturer.id) DESC,
    manufacturer.added_at ASC NULLS FIRST,
    manufacturer.id ASC
SQL)->fetchAllAssociative();

        return $brands;
    }

    private static function asTyped(string $name): string
    {
        return mb_strtolower(ManufacturerResolver::cleanName($name));
    }
}
