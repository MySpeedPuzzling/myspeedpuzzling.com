<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * `puzzle_key`: what makes two rows of a player's spreadsheets the same puzzle (docs/features/data-import/README.md
 * "Keys, exactly", docs/features/data-import/value-readers.md "Keys").
 *
 * **of() is a pure function of what the row says** - the name, the piece count, the brand as written. Nothing of the
 * catalogue is in it: whether the brand text is a catalogue brand today, what the player answered about "MB", a brand
 * created or merged later - none of it changes the key. So a remembered decision (`import_puzzle_decision`), an
 * unlisted puzzle and every source key of the puzzle stay found for ever. No barcode either: adding an EAN column must
 * not make a second puzzle.
 *
 * The value is `1:` + the sha256 (64 hex characters) of this text, its four lines joined by "\n":
 *
 *     puzzle-key:1
 *     <the name through ImportKeyFold::v1() - a "(…)" part stays>
 *     <the piece count in digits - nothing when unknown: null, zero and a negative number are all "unknown">
 *     <the brand text reduced to letters and digits (ImportBrandText) - nothing without a brand>
 *
 * A row without a name has no key: of() returns null.
 *
 * **resolved() is a second, lookup key** for "the same puzzle once its brand is known": the same name and pieces with
 * the catalogue brand's id instead of the text, so `Ravensburger` and `Ravensburger Spieleverlag` meet. The layer
 * above looks a puzzle up by its resolved key first and falls back to the text key. It is never part of another key,
 * and it is stored only next to its inputs (name, pieces, brand id), so a brand answer or a brand merge can compute it
 * again:
 *
 *     puzzle-key-by-brand:1
 *     <the name, as above>
 *     <the piece count, as above>
 *     <the catalogue brand's id, trimmed, in lower case>
 *
 * The `1` in front of a value is the key's version: the fold (ImportKeyFold::v1(), frozen) and the text above. A
 * change of either is version 2, computed side by side - a stored `1:` key is never rewritten. ImportKeysTest pins
 * exact outputs.
 */
final readonly class ImportPuzzleKey
{
    public const string VERSION = '1';

    public static function of(string $name, null|int $pieces, null|string $brandText): null|string
    {
        $canonical = self::canonical($name, $pieces, $brandText);

        return $canonical === null ? null : self::VERSION . ':' . hash('sha256', $canonical);
    }

    public static function resolved(string $name, null|int $pieces, null|string $brandId): null|string
    {
        $canonical = self::canonicalResolved($name, $pieces, $brandId);

        return $canonical === null ? null : self::VERSION . ':' . hash('sha256', $canonical);
    }

    /**
     * The text the key is the hash of - for tests and for explaining why two rows are (not) one puzzle.
     */
    public static function canonical(string $name, null|int $pieces, null|string $brandText): null|string
    {
        $folded = ImportKeyFold::v1($name);

        if ($folded === '') {
            return null;
        }

        return implode("\n", [
            'puzzle-key:' . self::VERSION,
            $folded,
            self::pieces($pieces),
            ImportBrandText::lettersAndDigits($brandText),
        ]);
    }

    /**
     * Null without a name or without a brand id.
     */
    public static function canonicalResolved(string $name, null|int $pieces, null|string $brandId): null|string
    {
        $folded = ImportKeyFold::v1($name);
        $brandId = strtolower(trim($brandId ?? ''));

        if ($folded === '' || $brandId === '') {
            return null;
        }

        return implode("\n", [
            'puzzle-key-by-brand:' . self::VERSION,
            $folded,
            self::pieces($pieces),
            $brandId,
        ]);
    }

    private static function pieces(null|int $pieces): string
    {
        return $pieces === null || $pieces <= 0 ? '' : (string) $pieces;
    }
}
