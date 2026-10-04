<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A typed puzzle search, folded once (SearchText) and turned into the patterns of the stored search keys
 * (PuzzleSearchKeys) - docs/features/puzzle-names/README.md, "Search". PuzzleTextSearch puts them into SQL.
 *
 * The query is folded FIRST and escaped afterwards: a full-width `％＿＼` becomes `%_\` in the fold and is then
 * escaped like the typed one, so it stays a literal. Name patterns match lines of `search_names` (one line per name,
 * a newline before the first and after the last). Code patterns match lines of `search_codes`: `e:` lines are barcode
 * numbers without leading zeros, `c:` lines brand codes of letters and digits only - neither holds a colon after its
 * tag, so `e:`/`c:` in a key always start a line.
 *
 * A pattern is null when the query cannot match that way (and every pattern when nothing was typed).
 */
readonly final class PuzzleSearchQuery
{
    /**
     * A part of a code matches only from 5 letters/digits on: "1000" is a piece count or a name, and as a part of any
     * code it found half of the catalogue. A shorter search matches a whole code only.
     */
    public const int MIN_CODE_PART_LENGTH = 5;

    private function __construct(
        public string $folded,
        /** LIKE: a name contains it */
        public null|string $namesContains = null,
        /** LIKE: a whole name */
        public null|string $namesWhole = null,
        /** LIKE: a name starts with it */
        public null|string $namesStart = null,
        /** LIKE: a word of a name starts with it */
        public null|string $namesWordStart = null,
        /** LIKE: a whole barcode - the query is a number (digits, spaces, dashes, dots), without leading zeros */
        public null|string $eanExact = null,
        /** LIKE: a whole brand code - the query's letters and digits */
        public null|string $codeExact = null,
        /** LIKE: a part of any code, 5+ letters/digits */
        public null|string $codePart = null,
        /** Literal: the whole barcode line, for the barcode lookup */
        public null|string $eanLine = null,
        /** LIKE: a code line ending with the number, the index part of the barcode lookup */
        public null|string $eanLineEnd = null,
    ) {
    }

    public static function fromUserInput(null|string $raw): self
    {
        $folded = SearchText::fold($raw ?? '');

        if ($folded === '') {
            return new self('');
        }

        $escaped = addcslashes($folded, '\\%_');
        $alnum = preg_replace('/[^\p{L}\p{N}]+/u', '', $folded) ?? '';
        // A barcode number as printed or typed - compared without leading zeros, which scanners and boxes add or drop
        $isNumber = preg_match('/^[0-9 .\-]+$/', $folded) === 1;
        $digits = $isNumber ? ltrim(preg_replace('/[^0-9]+/', '', $folded) ?? '', '0') : '';
        $part = $isNumber ? $digits : $alnum;

        return new self(
            folded: $folded,
            namesContains: '%' . $escaped . '%',
            namesWhole: "%\n" . $escaped . "\n%",
            namesStart: "%\n" . $escaped . '%',
            namesWordStart: '% ' . $escaped . '%',
            // No newline before the tag (it always starts a line): the trigram index then skips the tag letter, which
            // is in nearly every key
            eanExact: $digits !== '' ? '%e:' . $digits . "\n%" : null,
            codeExact: $alnum !== '' ? '%c:' . $alnum . "\n%" : null,
            codePart: mb_strlen($part) >= self::MIN_CODE_PART_LENGTH ? '%' . $part . '%' : null,
            eanLine: $digits !== '' ? "\ne:" . $digits . "\n" : null,
            eanLineEnd: $digits !== '' ? '%' . $digits . "\n%" : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->folded === '';
    }
}
