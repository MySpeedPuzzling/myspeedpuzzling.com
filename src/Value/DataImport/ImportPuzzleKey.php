<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\SearchText;

/**
 * `puzzle_key`: what makes two rows of a player's spreadsheets the same puzzle (docs/features/data-import/README.md
 * "Keys, exactly") - the folded name, the piece count and the brand. No barcode and no raw text: adding an EAN column
 * or fixing a brand's spelling must not make a second puzzle.
 *
 * The key is the sha256 (64 hex characters) of this canonical text, its four lines joined by "\n":
 *
 *     puzzle-key:1
 *     <the name through SearchText::fold() - a "(…)" part stays>
 *     <the piece count in digits, or nothing when unknown>
 *     <"id:" + the catalogue brand's id in lower case when the text resolved to a brand,
 *      else "text:" + the brand text reduced to letters and digits (ImportBrandText) - "text:" alone without a brand>
 *
 * A folded text never holds a newline, so no line can run into the next. Keys are stored for ever
 * (`data_import_puzzle`, `import_puzzle_decision`, `unlisted_puzzle`): changing the canonical text - or
 * SearchText::fold() - changes every key and needs a migration of the stored ones. ImportKeysTest pins exact outputs.
 */
final readonly class ImportPuzzleKey
{
    private const string VERSION = 'puzzle-key:1';

    public static function of(string $name, null|int $pieces, null|string $resolvedBrandId, null|string $brandText): string
    {
        return hash('sha256', self::canonical($name, $pieces, $resolvedBrandId, $brandText));
    }

    /**
     * The text the key is the hash of - for tests and for explaining why two rows are (not) one puzzle.
     */
    public static function canonical(string $name, null|int $pieces, null|string $resolvedBrandId, null|string $brandText): string
    {
        $brandId = $resolvedBrandId === null ? '' : strtolower(trim($resolvedBrandId));

        return implode("\n", [
            self::VERSION,
            SearchText::fold($name),
            $pieces === null ? '' : (string) $pieces,
            $brandId !== '' ? 'id:' . $brandId : 'text:' . ImportBrandText::lettersAndDigits($brandText),
        ]);
    }
}
