<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use LogicException;
use Transliterator;

/**
 * The one fold of puzzle text for search (docs/features/puzzle-names/README.md, "Search"): the stored search keys
 * (PuzzleSearchKeys) and the typed query go through it, Postgres never folds puzzle text, so the two cannot disagree.
 *
 * NFKC first (full-width `％＿＼４` become ASCII before anything is escaped, ligatures and half-width katakana are
 * normalised), then Latin letters to ASCII (Łódź → lodz, Straße → strasse), lower case. Other scripts stay as they are.
 * Control and format characters go, every whitespace run becomes one space: a folded text never holds a newline,
 * which is what separates the lines of a search key.
 */
readonly final class SearchText
{
    /**
     * Bump whenever fold() changes, then run myspeedpuzzling:rebuild-puzzle-search-keys
     */
    public const int VERSION = 1;

    private const string RULES = 'NFKC; [:Latin:] Latin-ASCII; Lower(); NFC';

    public static function fold(string $text): string
    {
        $folded = self::transliterator()->transliterate(mb_scrub($text, 'UTF-8'));

        if ($folded === false) {
            throw new LogicException('The search fold failed: ' . self::transliterator()->getErrorMessage());
        }

        // Whitespace first (tabs and newlines are control characters too), then whatever control or format
        // character is left (zero-width spaces, soft hyphens, direction marks)
        $folded = preg_replace('/[\s\p{Z}]+/u', ' ', $folded) ?? '';
        $folded = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $folded) ?? '';
        $folded = preg_replace('/ {2,}/', ' ', $folded) ?? '';

        return trim($folded, ' ');
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
