<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use LogicException;
use Transliterator;

/**
 * The one fold of puzzle text for search (docs/features/puzzle-names/README.md, "Search"): the stored search keys
 * (PuzzleSearchKeys) and the typed query go through it, Postgres never folds puzzle text, so the two cannot disagree.
 *
 * Apostrophes go first - "Where's", "Where’s", "Where´s" and "Wheres" are one name. Then NFKC (full-width `％＿＼４`
 * become ASCII before anything is escaped, ligatures and half-width katakana are normalised), Latin letters to ASCII
 * (Łódź → lodz, Straße → strasse), lower case. Other scripts stay as they are. Control and format characters go, and
 * so does a combining mark with no letter to sit on (NFKC turns spacing accents like `˘` into a space and the mark).
 * Every whitespace run becomes one space: a folded text never holds a newline, which is what separates the lines of
 * a search key.
 */
readonly final class SearchText
{
    /**
     * Bump whenever fold() or the format of the stored keys (PuzzleSearchKeys) changes, then run
     * myspeedpuzzling:rebuild-puzzle-search-keys. Informational - printed by the command, never stored or compared.
     * 3: brand codes with and without the EAN's check digit (BrandCodeCheckDigit).
     */
    public const int VERSION = 3;

    private const string RULES = 'NFKC; [:Latin:] Latin-ASCII; Lower(); NFC';

    // Apostrophes and what is typed for one: ' ` ´ ʹ ʼ ‘ ’ ‛ ′ and the full-width ＇
    private const string APOSTROPHES = "/['`\u{00B4}\u{02B9}\u{02BC}\u{2018}\u{2019}\u{201B}\u{2032}\u{FF07}]+/u";

    public static function fold(string $text): string
    {
        // Before NFKC, which turns ´ into a space and a combining accent - and again after it (ŉ becomes ʼn)
        $folded = preg_replace(self::APOSTROPHES, '', mb_scrub($text, 'UTF-8')) ?? '';
        $folded = self::transliterator()->transliterate($folded);

        if ($folded === false) {
            throw new LogicException('The search fold failed: ' . self::transliterator()->getErrorMessage());
        }

        $folded = preg_replace(self::APOSTROPHES, '', $folded) ?? '';

        // Whitespace first (tabs and newlines are control characters too), then whatever control or format
        // character is left (zero-width spaces, soft hyphens, direction marks), then a combining mark that has no
        // letter before it - at the start or after a space
        $folded = preg_replace('/[\s\p{Z}]+/u', ' ', $folded) ?? '';
        $folded = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $folded) ?? '';
        $folded = preg_replace('/(^| )\p{M}+/u', '$1', $folded) ?? '';
        $folded = preg_replace('/ {2,}/', ' ', $folded) ?? '';

        return trim($folded, ' ');
    }

    /**
     * A code as the `c:` lines of the code key hold it and a typed code is compared: folded, letters and digits only
     * ("12 002 028", "RB-500" → "12002028", "rb500").
     */
    public static function code(string $code): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', self::fold($code)) ?? '';
    }

    private static function transliterator(): Transliterator
    {
        static $transliterator;

        if ($transliterator instanceof Transliterator === false) {
            $transliterator = Transliterator::create(self::RULES)
                ?? throw new LogicException('The ICU transliterator "' . self::RULES . '" is not available.');
        }

        return $transliterator;
    }
}
