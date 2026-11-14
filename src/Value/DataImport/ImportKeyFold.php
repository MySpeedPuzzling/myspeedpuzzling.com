<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use LogicException;
use Transliterator;

/**
 * The fold of the import's keys (ImportPuzzleKey, ImportSourceKey) - FROZEN.
 *
 * **Never edit v1().** Keys are stored for ever and an import whose content was wiped keeps only the hash, so a key
 * can never be computed again from a changed rule. A new rule set is a new method (`v2()`) and a new key version
 * computed side by side with the old one; v1 stays for as long as a v1 key is stored anywhere.
 *
 * v1 is what SearchText::fold() was on 2026-11-14 (SearchText::VERSION 3), copied - not called - on purpose: the
 * search fold changes whenever search needs it, and the keys must not change with it.
 *
 * The rules: apostrophes go ("Where's" = "Wheres"); NFKC (full-width forms, ligatures, half-width katakana); Latin
 * letters to ASCII (Łódź → lodz, Straße → strasse); lower case; other scripts stay; whitespace runs are one space;
 * control and format characters go; a combining mark with no letter to sit on goes. A folded text never holds a
 * newline. It depends on the ICU of the image - ImportKeysTest pins exotic characters with literal keys, so an ICU
 * that folds differently turns a test red before a key is stored with it.
 */
final readonly class ImportKeyFold
{
    private const string V1_RULES = 'NFKC; [:Latin:] Latin-ASCII; Lower(); NFC';

    // ' ` ´ ʹ ʼ ‘ ’ ‛ ′ and the full-width ＇
    private const string V1_APOSTROPHES = "/['`\u{00B4}\u{02B9}\u{02BC}\u{2018}\u{2019}\u{201B}\u{2032}\u{FF07}]+/u";

    public static function v1(string $text): string
    {
        $folded = preg_replace(self::V1_APOSTROPHES, '', mb_scrub($text, 'UTF-8')) ?? '';
        $folded = self::v1Transliterator()->transliterate($folded);

        if ($folded === false) {
            throw new LogicException('The key fold failed: ' . self::v1Transliterator()->getErrorMessage());
        }

        $folded = preg_replace(self::V1_APOSTROPHES, '', $folded) ?? '';
        $folded = preg_replace('/[\s\p{Z}]+/u', ' ', $folded) ?? '';
        $folded = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $folded) ?? '';
        $folded = preg_replace('/(^| )\p{M}+/u', '$1', $folded) ?? '';
        $folded = preg_replace('/ {2,}/', ' ', $folded) ?? '';

        return trim($folded, ' ');
    }

    /**
     * v1 reduced to letters and digits: "Master Pieces", "MasterPieces" and "master-pieces" are "masterpieces".
     */
    public static function lettersAndDigitsV1(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', self::v1($text)) ?? '';
    }

    private static function v1Transliterator(): Transliterator
    {
        static $transliterator;

        if ($transliterator instanceof Transliterator === false) {
            $transliterator = Transliterator::create(self::V1_RULES)
                ?? throw new LogicException('The ICU transliterator "' . self::V1_RULES . '" is not available.');
        }

        return $transliterator;
    }
}
